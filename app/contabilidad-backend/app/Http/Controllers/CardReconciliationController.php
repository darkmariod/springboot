<?php
namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\CardSettlement;
use App\Models\CardTransaction;
use App\Support\AsientoCajaBanco;
use App\Support\ContraAsiento;
use App\Support\Cuentas;
use App\Support\NumeroDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CardReconciliationController extends Controller
{
    /** Cobros con tarjeta que el procesador todavía no deposita. */
    public function pendientes(Request $r)
    {
        return CardTransaction::with('invoice:id,numero,fecha_emision')
            ->where('company_id', $r->company_id)
            ->pendientes()
            ->when($r->desde, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($r->hasta, fn ($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->orderBy('fecha')->get();
    }

    public function liquidaciones(Request $r)
    {
        return CardSettlement::withCount('transactions')
            ->withSum('transactions', 'monto')
            ->where('company_id', $r->company_id)
            ->orderByDesc('fecha')->get()
            ->map(function ($s) {
                $s->asignado  = round((float) $s->transactions_sum_monto, 2);
                $s->diferencia = round($s->asignado - (float) $s->monto_bruto, 2);
                return $s;
            });
    }

    /**
     * Registra la liquidación que depositó el procesador y la asienta (T5.3):
     *   Debe Bancos (neto) + Comisiones bancarias (comisión) + Retenciones anticipadas (retención de IVA y de renta)
     *   / Haber Tarjetas por liquidar (bruto)
     * más el movimiento del banco ('credito' por el neto) para conciliarlo. Número LQ-000001 por empresa. Bruto = neto + comisión
     * + retenciones al centavo; si no, 422 y no se crea nada. Un mes contable cerrado también lo rechaza.
     * Si no se manda `monto_neto`, se calcula. Las liquidaciones de antes (sin asiento) no se tocan.
     */
    public function crearLiquidacion(Request $r)
    {
        $d = $r->validate([
            'company_id'  => ['required', 'exists:companies,id'],
            'bank_id'     => ['required', 'integer', Rule::exists('banks', 'id')->where('company_id', $r->input('company_id'))],
            'fecha'       => ['required', 'date'],
            'procesador'  => ['required', 'string', 'max:80'],
            'lote'        => ['nullable', 'string', 'max:60'],
            'monto_bruto' => ['required', 'numeric', 'min:0.01'],
            'comision'    => ['nullable', 'numeric', 'min:0'],
            'retencion_iva'   => ['nullable', 'numeric', 'min:0'],
            'retencion_renta' => ['nullable', 'numeric', 'min:0'],
            'monto_neto'  => ['nullable', 'numeric', 'min:0'],
            'notas'       => ['nullable', 'string', 'max:400'],
        ], [
            'bank_id.required' => 'Elige el banco donde el procesador acreditó el depósito.',
            'bank_id.exists' => 'El banco elegido no existe en esta empresa.',
            'monto_bruto.min' => 'El monto bruto debe ser mayor que cero.',
        ]);
        $cid = (int) $d['company_id'];
        $centavos = fn ($v) => (int) round(((float) ($v ?? 0)) * 100);
        $bruto = $centavos($d['monto_bruto']);
        $comision = $centavos($d['comision'] ?? 0);
        $retIva = $centavos($d['retencion_iva'] ?? 0);
        $retRenta = $centavos($d['retencion_renta'] ?? 0);
        // Lo que el banco realmente deposita es el bruto menos la comisión y las retenciones.
        $neto = array_key_exists('monto_neto', $d) && $d['monto_neto'] !== null ? $centavos($d['monto_neto']) : $bruto - $comision - $retIva - $retRenta;
        if ($neto < 0 || $bruto !== $neto + $comision + $retIva + $retRenta) {
            throw ValidationException::withMessages(['monto_neto' => [sprintf(
                'El bruto debe ser igual al neto más la comisión y las retenciones: bruto $%s, neto $%s, comisión $%s, retenciones $%s.',
                number_format($bruto / 100, 2, '.', ''), number_format($neto / 100, 2, '.', ''),
                number_format($comision / 100, 2, '.', ''), number_format(($retIva + $retRenta) / 100, 2, '.', ''))]]);
        }
        $fecha = \Carbon\Carbon::parse($d['fecha'])->toDateString();

        $liquidacion = DB::transaction(function () use ($d, $cid, $fecha, $bruto, $comision, $retIva, $retRenta, $neto) {
            $banco = Bank::where('company_id', $cid)->findOrFail($d['bank_id']);
            $numero = NumeroDocumento::siguiente($cid, CardSettlement::where('company_id', $cid), 'LQ');
            $s = CardSettlement::create([
                'company_id' => $cid, 'bank_id' => $banco->id, 'numero' => $numero, 'fecha' => $fecha,
                'procesador' => $d['procesador'], 'lote' => $d['lote'] ?? null, 'notas' => $d['notas'] ?? null,
                'monto_bruto' => $bruto / 100, 'comision' => $comision / 100,
                'retencion_iva' => $retIva / 100, 'retencion_renta' => $retRenta / 100, 'monto_neto' => $neto / 100,
            ]);

            $ref = $d['lote'] ?? null ?: $numero;
            $lineas = [];
            if ($neto > 0) {
                $lineas[] = AsientoCajaBanco::debe(AsientoCajaBanco::cuentaBanco($banco), $neto / 100, $ref);
            }
            if ($comision > 0) {
                $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($cid, 'comisiones_bancarias'), $comision / 100, $ref);
            }
            if ($retIva > 0) {
                $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($cid, 'retenciones_anticipadas'), $retIva / 100, 'Retención IVA '.$ref);
            }
            if ($retRenta > 0) {
                $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($cid, 'retenciones_anticipadas'), $retRenta / 100, 'Retención renta '.$ref);
            }
            $lineas[] = AsientoCajaBanco::haber(Cuentas::cuenta($cid, 'tarjetas_por_liquidar'), $bruto / 100, $ref);
            AsientoCajaBanco::publicar($cid, $fecha, 'Liquidación de tarjetas '.$numero.' · '.$d['procesador'].($d['lote'] ?? null ? ' · lote '.$d['lote'] : ''), $s, $lineas);

            if ($neto > 0) {
                BankMovement::create([
                    'company_id' => $cid, 'bank_id' => $banco->id, 'fecha' => $fecha, 'tipo' => 'credito', 'monto' => $neto / 100,
                    'concepto' => 'Liquidación de tarjetas '.$numero.' · '.$d['procesador'], 'documento' => $d['lote'] ?? null, 'conciliado' => false,
                    'numero' => $numero, 'clase' => BankMovement::LIQUIDACION_TARJETA, 'forma' => 'deposito',
                    'origen_type' => $s->getMorphClass(), 'origen_id' => $s->getKey(),
                ]);
            }

            return $s;
        });

        return response()->json($liquidacion->fresh(), 201);
    }

    /** Asigna cobros a una liquidación (o los libera si se manda vacío). */
    public function asignar(Request $r, CardSettlement $settlement)
    {
        $d = $r->validate(['transaction_ids' => ['present', 'array'],
                           'transaction_ids.*' => ['integer', 'exists:card_transactions,id']]);

        DB::transaction(function () use ($d, $settlement) {
            CardTransaction::where('card_settlement_id', $settlement->id)
                ->update(['card_settlement_id' => null]);
            if ($d['transaction_ids']) {
                CardTransaction::whereIn('id', $d['transaction_ids'])
                    ->where('company_id', $settlement->company_id)
                    ->update(['card_settlement_id' => $settlement->id]);
            }
            $asignado = (float) $settlement->transactions()->sum('monto');
            $settlement->update([
                'estado' => abs($asignado - (float) $settlement->monto_bruto) < 0.01 && $asignado > 0
                    ? 'conciliada' : 'abierta',
            ]);
        });

        return $settlement->fresh()->loadCount('transactions');
    }

    /** Sugiere qué cobros cuadran con el monto de la liquidación. */
    public function sugerir(Request $r, CardSettlement $settlement)
    {
        $pend = CardTransaction::where('company_id', $settlement->company_id)
            ->pendientes()->whereDate('fecha', '<=', $settlement->fecha)
            ->orderBy('fecha')->get();

        $objetivo = (float) $settlement->monto_bruto;
        $suma = 0; $elegidos = [];
        foreach ($pend as $t) {                     // se acumulan sin pasarse del monto
            if (round($suma + (float) $t->monto, 2) <= $objetivo + 0.001) {
                $elegidos[] = $t->id;
                $suma = round($suma + (float) $t->monto, 2);
            }
        }

        return ['transaction_ids' => $elegidos, 'suma' => $suma,
                'objetivo' => $objetivo, 'cuadra' => abs($suma - $objetivo) < 0.01];
    }

    /**
     * Elimina una liquidación. Si ya tenía asiento, se reversa con un contra-asiento (el original queda de rastro) y se quita su
     * movimiento del banco; si ese movimiento ya está conciliado con el estado de cuenta, no se puede: primero se desconcilia.
     */
    public function eliminarLiquidacion(CardSettlement $settlement)
    {
        DB::transaction(function () use ($settlement) {
            $mov = BankMovement::where('company_id', $settlement->company_id)->where('clase', BankMovement::LIQUIDACION_TARJETA)
                ->where('origen_type', $settlement->getMorphClass())->where('origen_id', $settlement->id)->first();
            if ($mov?->conciliado) {
                throw ValidationException::withMessages(['liquidacion' => ['El depósito de esta liquidación ya está conciliado con el banco: desconcílialo en Conciliación bancaria antes de eliminarla.']]);
            }
            ContraAsiento::deDocumento($settlement, 'Reversión liquidación de tarjetas '.$settlement->numero, (string) $settlement->numero);
            $mov?->delete();
            CardTransaction::where('card_settlement_id', $settlement->id)->update(['card_settlement_id' => null]);
            $settlement->delete();
        });

        return response()->noContent();
    }

    /** Resumen para la cabecera de la pantalla. */
    public function resumen(Request $r)
    {
        $base = CardTransaction::where('company_id', $r->company_id);

        return [
            'pendientes'       => (clone $base)->pendientes()->count(),
            'monto_pendiente'  => round((float) (clone $base)->pendientes()->sum('monto'), 2),
            'conciliado'       => round((float) (clone $base)->whereNotNull('card_settlement_id')->sum('monto'), 2),
            'comisiones'       => round((float) CardSettlement::where('company_id', $r->company_id)->sum('comision'), 2),
            'liquidaciones_abiertas' => CardSettlement::where('company_id', $r->company_id)->where('estado', 'abierta')->count(),
        ];
    }
}
