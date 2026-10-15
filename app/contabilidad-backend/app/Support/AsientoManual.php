<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;

/**
 * Reglas de los asientos escritos o corregidos a mano (T4.1): validar las líneas, saber si un asiento se puede
 * editar o eliminar y dejar una foto del asiento para la auditoría. Los errores salen como 422 con un mensaje
 * en español que la pantalla muestra tal cual.
 */
final class AsientoManual
{
    /** Estados con que se puede guardar un asiento manual. */
    public const ESTADOS_GUARDABLES = [JournalEntry::BORRADOR, JournalEntry::PENDIENTE];

    /**
     * Valida las líneas y devuelve las líneas listas para guardar con sus totales.
     *  - al menos dos líneas, cada una con una cuenta de ESTA empresa y un valor en Debe o en Haber (no en los dos);
     *  - Debe y Haber cuadran al centavo;
     *  - con $descartarCeros (al editar) las líneas con Debe y Haber en cero se descartan: los asientos que genera un
     *    documento las traen (p. ej. el IVA de una venta tarifa 0) y no tienen efecto contable;
     *  - una cuenta que tiene subcuentas (encabezado del plan) no recibe movimientos. Al editar, las cuentas que el
     *    asiento ya usaba no se vuelven a juzgar: el plan pudo crecer después de que el documento generó el asiento.
     *
     * @param  array<int,mixed>  $lineas
     * @return array{lineas:array<int,array{account_id:int,debe:float,haber:float,referencia:?string}>,debe:float,haber:float}
     */
    public static function normalizarLineas(int $companyId, array $lineas, ?JournalEntry $existente = null, bool $descartarCeros = false): array
    {
        $lineas = array_values($lineas);
        if (count($lineas) < 2) {
            abort(422, 'El asiento necesita al menos dos líneas.');
        }

        $limpias = [];
        $debeCent = 0;
        $haberCent = 0;
        foreach ($lineas as $i => $l) {
            $n = $i + 1;
            $l = is_array($l) ? $l : [];
            $cuentaId = (int) ($l['account_id'] ?? 0);
            if ($cuentaId <= 0) {
                abort(422, "Línea $n: elige la cuenta.");
            }
            $debe = self::monto($l['debe'] ?? 0, $n, 'Debe');
            $haber = self::monto($l['haber'] ?? 0, $n, 'Haber');
            if ($debe > 0 && $haber > 0) {
                abort(422, "Línea $n: escribe el valor en Debe o en Haber, no en los dos.");
            }
            if ($debe == 0 && $haber == 0) {
                if ($descartarCeros) {
                    continue;
                }
                abort(422, "Línea $n: escribe un valor mayor que cero en Debe o en Haber.");
            }
            $referencia = isset($l['referencia']) ? trim((string) $l['referencia']) : '';
            $limpias[] = [
                'account_id' => $cuentaId,
                'debe' => round($debe / 100, 2),
                'haber' => round($haber / 100, 2),
                'referencia' => $referencia !== '' ? mb_substr($referencia, 0, 255) : null,
            ];
            $debeCent += $debe;
            $haberCent += $haber;
        }

        if (count($limpias) < 2) {
            abort(422, 'El asiento necesita al menos dos líneas con valor.');
        }

        $ids = array_values(array_unique(array_column($limpias, 'account_id')));
        $cuentas = Account::where('company_id', $companyId)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if (! $cuentas->has($id)) {
                abort(422, 'Una de las cuentas elegidas no pertenece a esta empresa.');
            }
        }

        $yaUsadas = $existente ? $existente->lines()->pluck('account_id')->all() : [];
        foreach ($cuentas as $cuenta) {
            if (! in_array($cuenta->id, $yaUsadas, true) && self::esEncabezado($cuenta)) {
                abort(422, "La cuenta {$cuenta->codigo} {$cuenta->nombre} no admite movimientos porque tiene subcuentas. Elige una cuenta de detalle.");
            }
        }

        if ($debeCent !== $haberCent) {
            abort(422, 'El asiento no cuadra: debe '.number_format($debeCent / 100, 2, '.', '').', haber '.number_format($haberCent / 100, 2, '.', ''));
        }

        return ['lineas' => $limpias, 'debe' => round($debeCent / 100, 2), 'haber' => round($haberCent / 100, 2)];
    }

    /** La cuenta es un encabezado del plan: tiene subcuentas por `parent_id` o por código (1.1 contiene a 1.1.01). */
    public static function esEncabezado(Account $cuenta): bool
    {
        return Account::where('company_id', $cuenta->company_id)->where('id', '!=', $cuenta->id)
            ->where(fn ($q) => $q->where('parent_id', $cuenta->id)->orWhere('codigo', 'like', $cuenta->codigo.'.%'))
            ->exists();
    }

    /** Valor en centavos (entero). Acepta hasta dos decimales. */
    private static function monto(mixed $valor, int $linea, string $columna): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }
        if (! is_numeric($valor) || (float) $valor < 0) {
            abort(422, "Línea $linea: el valor de $columna debe ser un número mayor o igual a cero.");
        }
        $centavos = (float) $valor * 100;
        if (abs($centavos - round($centavos)) > 0.0001) {
            abort(422, "Línea $linea: el valor de $columna lleva como máximo dos decimales.");
        }
        if ($centavos > 99999999999999) {
            abort(422, "Línea $linea: el valor de $columna es demasiado grande.");
        }

        return (int) round($centavos);
    }

    /**
     * Ids de los asientos (de la empresa) que ya fueron anulados: tienen un contra-asiento que invierte
     * exactamente sus líneas. Con $solo se revisa un único asiento.
     *
     * @return array<int,true>
     */
    public static function anulados(int $companyId, ?JournalEntry $solo = null): array
    {
        $reversos = JournalEntry::where('company_id', $companyId)->where('manual', false)
            ->where('concepto', 'like', 'Reversión%')->whereNotNull('origen_id')
            ->when($solo, fn ($q) => $q->where('origen_type', $solo->origen_type)->where('origen_id', $solo->origen_id))
            ->with('lines')->get();
        if ($reversos->isEmpty()) {
            return [];
        }

        $originales = JournalEntry::where('company_id', $companyId)->where('manual', false)
            ->where('concepto', 'not like', 'Reversión%')
            ->whereIn('origen_id', $reversos->pluck('origen_id')->unique()->all())
            ->when($solo, fn ($q) => $q->whereKey($solo->id))
            ->with('lines')->get();

        $anulados = [];
        foreach ($reversos as $reverso) {
            $espejo = self::firma($reverso->lines, true);
            foreach ($originales as $original) {
                if (isset($anulados[$original->id]) || $original->id >= $reverso->id
                    || $original->origen_type !== $reverso->origen_type || (int) $original->origen_id !== (int) $reverso->origen_id) {
                    continue;
                }
                if (self::firma($original->lines, false) === $espejo) {
                    $anulados[$original->id] = true;
                    break;
                }
            }
        }

        return $anulados;
    }

    /** Líneas como texto ordenado: cuenta, debe, haber. Con $invertir cambia Debe por Haber (lo que hace un contra-asiento). */
    private static function firma(Collection $lineas, bool $invertir): string
    {
        $filas = $lineas->map(fn ($l) => $l->account_id.'|'
            .number_format((float) ($invertir ? $l->haber : $l->debe), 2, '.', '').'|'
            .number_format((float) ($invertir ? $l->debe : $l->haber), 2, '.', ''))->all();
        sort($filas);

        return implode(';', $filas);
    }

    /** Si el asiento no se puede editar, corta con el 422 que explica por qué. */
    public static function exigirEditable(JournalEntry $e): void
    {
        if ($e->es_cierre) {
            abort(422, 'El asiento de cierre del ejercicio no se edita.');
        }
        if ($e->estado === JournalEntry::MAYORIZADO) {
            abort(422, 'Desmayoriza el asiento para poder editarlo.');
        }
        if ($e->esContraAsiento()) {
            abort(422, 'Un contra-asiento no se edita: es el reverso de una anulación.');
        }
        if (isset(self::anulados($e->company_id, $e)[$e->id])) {
            abort(422, 'Este asiento ya fue anulado con su contra-asiento y no se puede editar.');
        }
    }

    /**
     * ¿Guardar esto dejaría el asiento igual? Se ignoran las líneas en cero (no tienen efecto) y no se compara el estado de
     * un asiento generado. Si es así, editar no escribe nada ni marca el asiento como editado.
     *
     * @param  array<int,array{account_id:int,debe:float,haber:float,referencia:?string}>  $lineasNuevas
     */
    public static function sinCambios(JournalEntry $actual, string $fecha, string $concepto, string $estado, array $lineasNuevas): bool
    {
        $actual->loadMissing('lines');
        $clave = fn (int $cuenta, float $debe, float $haber, ?string $ref) => $cuenta.'|'.number_format($debe, 2, '.', '').'|'.number_format($haber, 2, '.', '').'|'.($ref ?? '');
        $antes = $actual->lines->filter(fn ($l) => (float) $l->debe != 0.0 || (float) $l->haber != 0.0)
            ->map(fn ($l) => $clave((int) $l->account_id, (float) $l->debe, (float) $l->haber, $l->referencia))->values()->all();
        $despues = array_map(fn ($l) => $clave($l['account_id'], $l['debe'], $l['haber'], $l['referencia']), $lineasNuevas);

        return $actual->fecha?->toDateString() === \Illuminate\Support\Carbon::parse($fecha)->toDateString()
            && $actual->concepto === $concepto && $actual->estado === $estado && $antes === $despues;
    }

    /** Foto del asiento para la auditoría: lo que había antes y lo que quedó después. */
    public static function foto(JournalEntry $e): array
    {
        $e->loadMissing('lines.account');

        return [
            'fecha' => $e->fecha?->toDateString(),
            'concepto' => $e->concepto,
            'estado' => $e->estado,
            'total_debe' => round((float) $e->total_debe, 2),
            'total_haber' => round((float) $e->total_haber, 2),
            'lineas' => $e->lines->map(fn ($l) => [
                'cuenta' => trim(($l->account?->codigo ?? '?').' '.($l->account?->nombre ?? '')),
                'debe' => round((float) $l->debe, 2),
                'haber' => round((float) $l->haber, 2),
                'referencia' => $l->referencia,
            ])->all(),
        ];
    }

    /** El asiento (cabecera y líneas) no cuadra. Solo ocurre con datos viejos o alterados por fuera del sistema. */
    public static function descuadre(JournalEntry $e): ?array
    {
        $e->loadMissing('lines');
        $debe = round((float) $e->lines->sum('debe'), 2);
        $haber = round((float) $e->lines->sum('haber'), 2);
        if ($e->lines->isEmpty()) {
            return ['debe' => $debe, 'haber' => $haber, 'motivo' => 'No tiene líneas.'];
        }
        if ($debe !== $haber || round((float) $e->total_debe, 2) !== $debe || round((float) $e->total_haber, 2) !== $haber) {
            return ['debe' => $debe, 'haber' => $haber, 'motivo' => 'Descuadrado: debe '.number_format($debe, 2, '.', '').', haber '.number_format($haber, 2, '.', '')];
        }

        return null;
    }
}
