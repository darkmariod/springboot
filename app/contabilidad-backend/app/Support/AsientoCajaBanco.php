<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Bank;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Asientos de la caja y los bancos (T5.3): comprobantes de caja, depósitos, movimientos manuales de banco y liquidaciones
 * de tarjetas. Todos nacen aquí con el mismo cuidado: líneas validadas (cuentas de la empresa, que no sean encabezados,
 * Debe = Haber al centavo), fecha propia y el documento como origen. Un mes cerrado lo rechaza la guarda de `JournalEntry`
 * (422), así que quien llama debe hacerlo dentro de su transacción para que no quede nada a medias.
 */
final class AsientoCajaBanco
{
    /**
     * Cuentas que el contable:chequeo compara con sus documentos (cartera, cuentas por pagar y anticipos a proveedores): se mueven
     * desde Cobrar, Pagar o Anticipos, no con un ingreso, egreso o movimiento de banco a mano, que las descuadraría del mayor.
     */
    private const CONTROLADAS = ['cxc', 'cxc_relacionadas', 'cxp', 'cxp_relacionadas', 'anticipos_proveedores', 'anticipos_empleados'];

    /**
     * Cuenta contable de un banco: la que tiene escrita en su ficha si existe en el plan de la empresa; si no, 1.1.02 Bancos
     * (la que usan los pagos y cobros por banco).
     */
    public static function cuentaBanco(Bank $banco): Account
    {
        $codigo = trim((string) $banco->cuenta_contable);
        if ($codigo !== '') {
            $cuenta = Account::where('company_id', $banco->company_id)->where('codigo', $codigo)->first();
            if ($cuenta) {
                return $cuenta;
            }
        }

        return Cuentas::cuenta((int) $banco->company_id, 'bancos');
    }

    /** Línea Debe de un asiento para ::publicar. */
    public static function debe(Account $cuenta, float $valor, ?string $ref = null): array
    {
        return ['account_id' => $cuenta->id, 'debe' => round($valor, 2), 'haber' => 0, 'referencia' => $ref];
    }

    /** Línea Haber de un asiento para ::publicar. */
    public static function haber(Account $cuenta, float $valor, ?string $ref = null): array
    {
        return ['account_id' => $cuenta->id, 'debe' => 0, 'haber' => round($valor, 2), 'referencia' => $ref];
    }

    /** La contrapartida debe ser de la empresa y distinta de la cuenta del propio movimiento (caja o banco). 422 con mensaje en español. */
    public static function exigirContrapartida(int $companyId, mixed $cuentaId, Account $propia, string $campo = 'cuenta_contrapartida_id'): Account
    {
        $cuenta = $cuentaId ? Account::where('company_id', $companyId)->find($cuentaId) : null;
        if (! $cuenta) {
            throw ValidationException::withMessages([$campo => ['Elige la cuenta de contrapartida: no existe en el plan de cuentas de esta empresa.']]);
        }
        if ($cuenta->id === $propia->id) {
            throw ValidationException::withMessages([$campo => ["La contrapartida no puede ser la misma cuenta del movimiento ({$propia->codigo} {$propia->nombre})."]]);
        }
        if (AsientoManual::esEncabezado($cuenta)) {
            throw ValidationException::withMessages([$campo => ["La cuenta {$cuenta->codigo} {$cuenta->nombre} no admite movimientos porque tiene subcuentas. Elige una cuenta de detalle."]]);
        }
        if (in_array($cuenta->codigo, array_map(fn ($c) => Cuentas::codigo($c), self::CONTROLADAS), true)) {
            throw ValidationException::withMessages([$campo => ["La cuenta {$cuenta->codigo} {$cuenta->nombre} se mueve desde Cartera (cobros, pagos y anticipos) para que siga cuadrando con sus documentos. Registra el movimiento allí o elige otra cuenta."]]);
        }

        return $cuenta;
    }

    /**
     * Crea el asiento (estado pendiente, como el de cualquier documento) con las líneas dadas y el documento como origen.
     *
     * @param  array<int,array{account_id:int,debe:float|int,haber:float|int,referencia:?string}>  $lineas
     */
    public static function publicar(int $companyId, mixed $fecha, string $concepto, Model $origen, array $lineas): JournalEntry
    {
        $n = AsientoManual::normalizarLineas($companyId, $lineas);
        $asiento = JournalEntry::create([
            'company_id' => $companyId,
            'numero' => NumeroAsiento::siguiente($companyId),
            'fecha' => $fecha,
            'concepto' => mb_substr($concepto, 0, 250),
            'origen_type' => $origen->getMorphClass(),
            'origen_id' => $origen->getKey(),
            'total_debe' => $n['debe'],
            'total_haber' => $n['haber'],
            'estado' => JournalEntry::PENDIENTE,
        ]);
        $asiento->lines()->createMany($n['lineas']);

        return $asiento;
    }

    /**
     * Efectivo disponible en caja = saldo (Debe − Haber) de la cuenta 1.1.01 Caja en el libro mayor, con los asientos
     * contabilizados (todo menos borradores) de fecha hasta la indicada, nunca menos de cero.
     *
     * Es lo que se ha cobrado en efectivo (ventas, cobros, ingresos de caja) menos lo que ha salido (pagos, egresos y depósitos
     * anteriores). No se usa la sesión de caja (saldo inicial + ingresos − egresos) porque las ventas y cobros en efectivo nunca la
     * tocan, y por eso depositar contra ella dejaría la cuenta Caja del mayor en negativo.
     */
    public static function efectivoDisponible(int $companyId, mixed $hasta = null): float
    {
        $cuenta = Account::where('company_id', $companyId)->where('codigo', Cuentas::codigo('caja'))->first();
        if (! $cuenta) {
            return 0.0;
        }
        $lineas = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.estado', '!=', JournalEntry::BORRADOR)
            ->where('journal_entry_lines.account_id', $cuenta->id)
            ->when($hasta, fn ($q) => $q->whereDate('journal_entries.fecha', '<=', $hasta));
        $saldo = round((float) $lineas->sum('journal_entry_lines.debe') - (float) (clone $lineas)->sum('journal_entry_lines.haber'), 2);

        return max(0.0, $saldo);
    }
}
