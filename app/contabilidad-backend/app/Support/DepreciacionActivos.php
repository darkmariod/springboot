<?php

namespace App\Support;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Depreciación mensual y baja de activos fijos (T7.2). Todo corre dentro de la transacción de quien llama: un 422 (mes cerrado,
 * activo ya dado de baja...) deshace el rango completo y no deja nada a medias.
 *
 * Planear (`planear`) calcula qué se depreciaría sin escribir nada; es lo que muestra "Simular". Contabilizar escribe lo planeado:
 * una fila por activo y mes, y UN asiento por mes para todos los activos (Debe Gasto por depreciación / Haber Depreciación acumulada)
 * con fecha del último día del mes. Los meses van en orden y sin saltos: un activo solo se deprecia desde el mes siguiente al último
 * que ya tiene, así lo acumulado de cada fila es siempre la suma real y nada se calcula dos veces.
 */
final class DepreciacionActivos
{
    /** Meses que se pueden recorrer en una sola llamada. */
    public const MAX_MESES = 60;

    /**
     * Qué se depreciaría entre dos meses (ym, ambos incluidos).
     *
     * @param  int[]|null  $soloIds  limita a esos activos (la baja deprecia solo el suyo)
     * @param  bool  $bloquear  bloquea las fichas hasta el fin de la transacción (al contabilizar)
     * @param  Carbon|null  $fechaTope  fecha del asiento del mes de esa fecha, si cae antes del fin de mes (la baja)
     * @return array{meses:array<int,array<string,mixed>>,omitidos:array<int,array<string,mixed>>,pendiente_desde:?string}
     */
    public static function planear(int $companyId, int $desde, int $hasta, ?array $soloIds = null, bool $bloquear = false, ?Carbon $fechaTope = null): array
    {
        $activos = FixedAsset::where('company_id', $companyId)->where('estado', FixedAsset::ACTIVO)
            ->when($soloIds !== null, fn ($q) => $q->whereIn('id', $soloIds))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->orderBy('codigo')->orderBy('id')->get();
        $hecho = ActivosFijos::resumen($companyId, $activos->pluck('id')->all());

        // Estado de cada activo mientras se recorren los meses: lo acumulado y el último mes depreciado
        $estado = [];
        foreach ($activos as $a) {
            $estado[$a->id] = [
                'activo' => $a, 'inicio' => ActivosFijos::ymDe($a->fecha_inicio_depreciacion),
                'depreciable' => ActivosFijos::depreciableCentavos($a),
                'acumulada' => $hecho[$a->id]['acumulada'] ?? 0, 'ultimo' => $hecho[$a->id]['ultimo'] ?? null,
                'motivo' => null, 'pendiente' => null, 'lineas' => 0,
            ];
        }

        $meses = [];
        for ($ym = $desde; $ym <= $hasta; $ym++) {
            [$anio, $mes] = ActivosFijos::anioMes($ym);
            $fin = Carbon::create($anio, $mes, 1)->endOfMonth()->startOfDay();
            $fecha = $fechaTope && ActivosFijos::ymDe($fechaTope) === $ym && $fechaTope->lt($fin) ? $fechaTope->copy()->startOfDay() : $fin;
            $lineas = [];

            foreach ($estado as $id => &$s) {
                $a = $s['activo'];
                if ($s['inicio'] > $ym) {
                    continue;   // todavía no empieza a depreciarse
                }
                if ($s['depreciable'] <= 0 || $s['acumulada'] >= $s['depreciable']) {
                    $s['motivo'] = 'completo';
                    continue;   // ya llegó a su valor residual
                }
                $n = $ym - $s['inicio'] + 1;
                $siguiente = $s['ultimo'] === null ? $s['inicio'] : $s['ultimo'] + 1;
                if ($siguiente > $ym) {
                    $s['motivo'] = 'ya_depreciado';
                    continue;
                }
                if ($siguiente < $ym || $n > $a->vida_util_meses) {
                    $s['motivo'] = 'pendiente_anterior';
                    $s['pendiente'] = $siguiente;
                    continue;   // hay meses anteriores sin depreciar: se recuperan con un rango que empiece ahí
                }

                // Lo que falta para llegar a lo acumulado que le toca a ese mes (el último mes absorbe el redondeo)
                $monto = ActivosFijos::acumuladaTrasMes($s['depreciable'], (int) $a->vida_util_meses, $n) - $s['acumulada'];
                if ($monto <= 0) {
                    continue;
                }
                $s['acumulada'] += $monto;
                $s['ultimo'] = $ym;
                $s['motivo'] = null;
                $s['lineas']++;
                $lineas[] = [
                    'activo' => $a, 'monto' => $monto, 'acumulada' => $s['acumulada'],
                    'valor_en_libros' => ActivosFijos::centavos($a->costo) - $s['acumulada'],
                ];
            }
            unset($s);

            if ($lineas) {
                $meses[] = [
                    'ym' => $ym, 'periodo' => ActivosFijos::periodo($ym), 'anio' => $anio, 'mes' => $mes, 'fecha' => $fecha,
                    'lineas' => $lineas, 'total' => array_sum(array_column($lineas, 'monto')),
                ];
            }
        }

        $omitidos = [];
        $pendienteDesde = null;
        foreach ($estado as $s) {
            if ($s['lineas'] > 0 || $s['motivo'] === null) {
                continue;
            }
            $a = $s['activo'];
            $fila = ['id' => $a->id, 'codigo' => $a->codigo, 'nombre' => $a->nombre, 'motivo' => $s['motivo'], 'pendiente_desde' => null,
                'detalle' => match ($s['motivo']) {
                    'completo' => 'Ya llegó a su valor residual.',
                    'ya_depreciado' => 'Ya está depreciado en ese período.',
                    default => 'Tiene meses anteriores sin depreciar (desde '.ActivosFijos::periodo($s['pendiente']).').',
                }];
            if ($s['motivo'] === 'pendiente_anterior') {
                $fila['pendiente_desde'] = ActivosFijos::periodo($s['pendiente']);
                $pendienteDesde = $pendienteDesde === null ? $s['pendiente'] : min($pendienteDesde, $s['pendiente']);
            }
            $omitidos[] = $fila;
        }

        return ['meses' => $meses, 'omitidos' => $omitidos, 'pendiente_desde' => $pendienteDesde === null ? null : ActivosFijos::periodo($pendienteDesde)];
    }

    /**
     * Escribe lo planeado: por cada mes, las filas del cronograma y UN asiento (Debe 5.4.01 / Haber 1.2.02). El asiento apunta a la
     * primera fila del mes como origen y cada fila guarda el asiento. Un mes cerrado lo rechaza la guarda de JournalEntry (422).
     *
     * @return array<int,JournalEntry> el asiento de cada mes, por ym
     */
    public static function contabilizar(int $companyId, array $plan): array
    {
        $gasto = Cuentas::cuenta($companyId, 'gasto_depreciacion');
        $acumulada = Cuentas::cuenta($companyId, 'depreciacion_acumulada');
        $asientos = [];

        foreach ($plan['meses'] as $m) {
            $filas = [];
            foreach ($m['lineas'] as $l) {
                $filas[] = FixedAssetDepreciation::create([
                    'fixed_asset_id' => $l['activo']->id, 'company_id' => $companyId, 'anio' => $m['anio'], 'mes' => $m['mes'],
                    'monto' => $l['monto'] / 100, 'depreciacion_acumulada_despues' => $l['acumulada'] / 100,
                ]);
            }
            $n = count($filas);
            $ref = 'DEP '.$m['periodo'];
            $asiento = AsientoCajaBanco::publicar($companyId, $m['fecha'], "Depreciación de activos fijos {$m['periodo']} ($n ".($n === 1 ? 'activo' : 'activos').')', $filas[0], [
                AsientoCajaBanco::debe($gasto, $m['total'] / 100, $ref),
                AsientoCajaBanco::haber($acumulada, $m['total'] / 100, $ref),
            ]);
            FixedAssetDepreciation::whereIn('id', array_map(fn ($f) => $f->id, $filas))->update(['journal_entry_id' => $asiento->id]);
            $asientos[$m['ym']] = $asiento;
        }

        return $asientos;
    }

    /**
     * Da de baja un activo. Primero deprecia lo pendiente hasta el mes de la baja (el mes de la baja se deprecia completo y su asiento
     * lleva la fecha de la baja); luego UN asiento saca el costo y toda la depreciación acumulada, registra lo cobrado si se vendió y
     * deja la pérdida o la utilidad contra el valor en libros:
     *   Debe  Depreciación acumulada (toda)            Haber Activos fijos (el costo)
     *   Debe  Bancos o Caja (si se vendió)             Haber Utilidad en baja de activos (si lo cobrado > valor en libros)
     *   Debe  Pérdida en baja de activos (si lo cobrado < valor en libros)
     * Va dentro de una transacción: un mes cerrado en cualquiera de los dos asientos deshace todo.
     *
     * @return array{asiento:JournalEntry,depreciacion_meses:int,depreciacion_total:float,resultado:float}
     */
    public static function darDeBaja(FixedAsset $activo, Carbon $fecha, string $motivo, ?float $valorVenta, string $cuentaCobro): array
    {
        $a = FixedAsset::whereKey($activo->id)->lockForUpdate()->firstOrFail();
        $companyId = (int) $a->company_id;
        if ($a->estado === FixedAsset::DADO_DE_BAJA) {
            throw ValidationException::withMessages(['activo' => ["El activo {$a->codigo} ya está dado de baja."]]);
        }

        // 1. Lo pendiente de depreciar hasta el mes de la baja
        $hecho = ActivosFijos::resumen($companyId, [$a->id])[$a->id] ?? null;
        $inicio = ActivosFijos::ymDe($a->fecha_inicio_depreciacion);
        $desde = $hecho ? $hecho['ultimo'] + 1 : $inicio;
        $hasta = ActivosFijos::ymDe($fecha);
        $meses = 0;
        if ($desde <= $hasta) {
            $plan = self::planear($companyId, $desde, $hasta, [$a->id], true, $fecha);
            self::contabilizar($companyId, $plan);
            $meses = count($plan['meses']);
        }

        // 2. El asiento de la baja con lo que ya quedó depreciado
        $acumulada = ActivosFijos::resumen($companyId, [$a->id])[$a->id]['acumulada'] ?? 0;
        $costo = ActivosFijos::centavos($a->costo);
        $libros = $costo - $acumulada;
        $venta = $valorVenta !== null ? ActivosFijos::centavos($valorVenta) : 0;
        $resultado = $venta - $libros;

        $lineas = [];
        if ($acumulada > 0) {
            $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($companyId, 'depreciacion_acumulada'), $acumulada / 100, $a->codigo);
        }
        if ($venta > 0) {
            $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($companyId, $cuentaCobro), $venta / 100, $a->codigo);
        }
        if ($resultado < 0) {
            $lineas[] = AsientoCajaBanco::debe(Cuentas::cuenta($companyId, 'perdida_baja_activos'), -$resultado / 100, $a->codigo);
        }
        if ($resultado > 0) {
            $lineas[] = AsientoCajaBanco::haber(Cuentas::cuenta($companyId, 'utilidad_baja_activos'), $resultado / 100, $a->codigo);
        }
        $lineas[] = AsientoCajaBanco::haber(Cuentas::cuenta($companyId, 'activos_fijos'), $costo / 100, $a->codigo);

        $asiento = AsientoCajaBanco::publicar($companyId, $fecha, "Baja de activo fijo {$a->codigo} {$a->nombre}", $a, $lineas);
        $a->update([
            'estado' => FixedAsset::DADO_DE_BAJA, 'fecha_baja' => $fecha->toDateString(), 'motivo_baja' => mb_substr($motivo, 0, 250),
            'valor_venta' => $valorVenta !== null && $venta > 0 ? $venta / 100 : null, 'journal_entry_baja_id' => $asiento->id,
        ]);

        $depreciado = $hecho ? $acumulada - $hecho['acumulada'] : $acumulada;

        return ['asiento' => $asiento, 'depreciacion_meses' => $meses, 'depreciacion_total' => $depreciado / 100, 'resultado' => $resultado / 100];
    }
}
