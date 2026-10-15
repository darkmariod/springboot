<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Período contable (un mes de una empresa). Un mes SIN registro está abierto: solo se guarda lo que se cierra.
 * La guarda `exigirAbierto` es el único lugar donde se decide si una fecha admite movimientos; la llama
 * JournalEntry (creating / updating / deleting) y las rutas que cambian asientos sin pasar por sus eventos.
 */
class AccountingPeriod extends Model
{
    public const ABIERTO = 'abierto';
    public const CERRADO = 'cerrado';

    protected $fillable = ['company_id', 'anio', 'mes', 'estado', 'cerrado_at', 'cerrado_por', 'motivo_reapertura', 'reabierto_at', 'reabierto_por'];
    protected $casts = ['anio' => 'integer', 'mes' => 'integer', 'cerrado_at' => 'datetime', 'reabierto_at' => 'datetime'];

    /** Se vuelve true en cuanto se ve la tabla: si todavía no se corrió la migración, nada está cerrado. */
    private static bool $tablaLista = false;

    /** "2026-09" para cualquier fecha (texto con o sin hora, o Carbon). */
    public static function etiqueta(mixed $fecha): string
    {
        return Carbon::parse($fecha)->format('Y-m');
    }

    public static function estaCerrado(int $companyId, mixed $fecha): bool
    {
        if ($fecha === null || $fecha === '' || ! self::existeLaTabla()) {
            return false;
        }
        $f = Carbon::parse($fecha);

        return static::where('company_id', $companyId)->where('anio', $f->year)->where('mes', $f->month)
            ->where('estado', self::CERRADO)->exists();
    }

    /** 422 con el aviso de siempre si la fecha cae en un mes cerrado de esa empresa. */
    public static function exigirAbierto(?int $companyId, mixed $fecha): void
    {
        if (! $companyId || ! self::estaCerrado($companyId, $fecha)) {
            return;
        }

        throw ValidationException::withMessages(['fecha' => [self::mensajeCerrado(self::etiqueta($fecha))]]);
    }

    public static function mensajeCerrado(string $periodo): string
    {
        return "El período {$periodo} está cerrado. Reábrelo para registrar o modificar movimientos de esa fecha.";
    }

    /**
     * Meses cerrados de una empresa como ['2026-09' => true, ...] (una consulta; para marcar muchos asientos a la vez).
     *
     * @return array<string,true>
     */
    public static function cerradosDe(int $companyId): array
    {
        if (! self::existeLaTabla()) {
            return [];
        }

        return static::where('company_id', $companyId)->where('estado', self::CERRADO)->get(['anio', 'mes'])
            ->mapWithKeys(fn ($p) => [sprintf('%04d-%02d', $p->anio, $p->mes) => true])->all();
    }

    private static function existeLaTabla(): bool
    {
        if (! self::$tablaLista) {
            self::$tablaLista = Schema::hasTable('accounting_periods');
        }

        return self::$tablaLista;
    }
}
