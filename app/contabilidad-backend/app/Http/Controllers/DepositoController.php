<?php

namespace App\Http\Controllers;

use App\Models\BankMovement;
use App\Models\Bank;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Support\AsientoCajaBanco;
use App\Support\Cuentas;
use App\Support\NumeroDocumento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Depósito de caja a banco (T5.3). Asiento: Debe Bancos (la cuenta de ese banco) / Haber Caja, más su movimiento de banco
 * ('credito', clase 'deposito', con el número de la papeleta) para conciliarlo. Número DP-000001 por empresa.
 *
 * No se puede depositar más de lo que hay en caja: "efectivo disponible" es el saldo de la cuenta Caja del libro mayor
 * a la fecha del depósito (AsientoCajaBanco::efectivoDisponible). Depositar exactamente ese saldo se permite; un centavo
 * más da 422.
 */
class DepositoController extends Controller
{
    /** Lo que se puede depositar hoy (o a la fecha indicada). */
    public function disponible(Request $r)
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'exists:companies,id'], 'fecha' => ['nullable', 'date']]);
        $fecha = isset($d['fecha']) ? Carbon::parse($d['fecha'])->toDateString() : now()->toDateString();

        return [
            'disponible' => AsientoCajaBanco::efectivoDisponible((int) $d['company_id'], $fecha),
            'fecha' => $fecha,
            'cuenta' => Cuentas::codigo('caja'),
        ];
    }

    /** Depósitos hechos (más recientes primero). */
    public function index(Request $r)
    {
        $d = $r->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);

        return BankMovement::with('banco:id,nombre')->where('company_id', $d['company_id'])->where('clase', BankMovement::DEPOSITO)
            ->orderByDesc('fecha')->orderByDesc('id')->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'bank_id' => ['required', 'integer', Rule::exists('banks', 'id')->where('company_id', $r->input('company_id'))],
            'cash_register_id' => ['nullable', 'integer', Rule::exists('cash_registers', 'id')->where('company_id', $r->input('company_id'))],
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'documento' => ['required', 'string', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [
            'bank_id.required' => 'Elige el banco donde se hace el depósito.',
            'bank_id.exists' => 'El banco elegido no existe en esta empresa.',
            'cash_register_id.exists' => 'La caja elegida no existe en esta empresa.',
            'documento.required' => 'Escribe el número de la papeleta de depósito.',
            'monto.min' => 'El monto debe ser mayor que cero.',
        ]);
        $cid = (int) $d['company_id'];
        $fecha = Carbon::parse($d['fecha'])->toDateString();
        $monto = round((float) $d['monto'], 2);

        $resultado = DB::transaction(function () use ($d, $cid, $fecha, $monto) {
            $banco = Bank::where('company_id', $cid)->findOrFail($d['bank_id']);
            $numero = NumeroDocumento::siguiente($cid, BankMovement::where('company_id', $cid), 'DP');

            // El número y el saldo se leen con la empresa bloqueada (dentro de siguiente()): dos depósitos a la vez no pasan del disponible
            $disponible = AsientoCajaBanco::efectivoDisponible($cid, $fecha);
            if ((int) round($monto * 100) > (int) round($disponible * 100)) {
                throw ValidationException::withMessages(['monto' => ['No puedes depositar más de lo que hay en caja: disponible $'.number_format($disponible, 2, '.', '')]]);
            }

            $caja = Cuentas::cuenta($cid, 'caja');
            $cuentaBanco = AsientoCajaBanco::cuentaBanco($banco);
            $registro = ! empty($d['cash_register_id'])
                ? CashRegister::where('company_id', $cid)->find($d['cash_register_id'])
                : CashRegister::where('company_id', $cid)->orderBy('id')->first();
            $papeleta = trim($d['documento']);
            $detalle = trim((string) ($d['observacion'] ?? ''));

            $mov = BankMovement::create([
                'company_id' => $cid, 'bank_id' => $banco->id, 'fecha' => $fecha, 'tipo' => 'credito', 'monto' => $monto,
                'concepto' => 'Depósito de caja '.$numero.($detalle !== '' ? ' · '.$detalle : ''),
                'documento' => $papeleta, 'conciliado' => false,
                'numero' => $numero, 'clase' => BankMovement::DEPOSITO, 'forma' => 'deposito',
                'cuenta_contrapartida_id' => $caja->id,
                'origen_type' => $registro?->getMorphClass(), 'origen_id' => $registro?->getKey(),
            ]);
            $asiento = AsientoCajaBanco::publicar($cid, $fecha, 'Depósito a banco '.$numero.' · '.$banco->nombre.' · papeleta '.$papeleta, $mov, [
                AsientoCajaBanco::debe($cuentaBanco, $monto, $papeleta),
                AsientoCajaBanco::haber($caja, $monto, $papeleta),
            ]);

            // La plata sale físicamente de la caja abierta: queda como egreso de la sesión para que el arqueo cuadre.
            // Sin asiento propio (ya lo tiene el depósito): el movimiento apunta al depósito como origen.
            $sesion = $fecha === now()->toDateString()
                ? CashSession::where('company_id', $cid)->where('estado', 'abierta')->latest('id')->first() : null;
            if ($sesion) {
                $sesion->movements()->create([
                    'tipo' => 'egreso', 'monto' => $monto, 'concepto' => 'Depósito a banco '.$numero.' · papeleta '.$papeleta,
                    'company_id' => $cid, 'numero' => $numero, 'fecha' => $fecha, 'cuenta_contrapartida_id' => $cuentaBanco->id,
                    'origen_type' => $mov->getMorphClass(), 'origen_id' => $mov->getKey(),
                ]);
                $sesion->increment('egresos', $monto);
            }

            return ['mov' => $mov, 'asiento' => $asiento, 'disponible' => round($disponible - $monto, 2)];
        });

        $mov = $resultado['mov'];

        return response()->json([
            'id' => $mov->id, 'numero' => $mov->numero, 'fecha' => $fecha, 'monto' => $monto, 'bank_id' => $mov->bank_id,
            'documento' => $mov->documento, 'concepto' => $mov->concepto, 'asiento' => $resultado['asiento']->numero,
            'disponible' => $resultado['disponible'],
        ], 201);
    }
}
