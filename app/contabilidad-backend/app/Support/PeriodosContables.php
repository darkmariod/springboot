<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Períodos contables (T4.3a): el resumen de los 12 meses, cerrar y reabrir un mes y cerrar el ejercicio.
 *
 * Qué es cerrar: mayorizar todo lo pendiente del mes y marcar el mes como cerrado. Desde entonces ningún asiento nace,
 * cambia ni se borra con fecha de ese mes (la guarda vive en JournalEntry; aquí solo se administra el estado).
 * Un mes con borradores o con asientos descuadrados no se cierra: la respuesta los lista.
 */
final class PeriodosContables
{
    public const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /** Cuántos bloqueos se muestran por mes (el resto se resume en el mensaje). */
    private const MAX_BLOQUEOS = 50;

    // ------------------------------------------------------------- listado

    /**
     * Los 12 meses del año con su estado y lo que hay en cada uno.
     *
     * @return array<string,mixed>
     */
    public static function resumen(int $companyId, int $anio): array
    {
        $filas = DB::table('journal_entries as e')
            ->leftJoin('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.company_id', $companyId)
            ->whereDate('e.fecha', '>=', sprintf('%04d-01-01', $anio))->whereDate('e.fecha', '<=', sprintf('%04d-12-31', $anio))
            ->groupBy('e.id', 'e.numero', 'e.concepto', 'e.fecha', 'e.estado', 'e.total_debe', 'e.total_haber')
            ->selectRaw('e.id, e.numero, e.concepto, e.fecha, e.estado, e.total_debe, e.total_haber, COALESCE(SUM(l.debe), 0) as ld, COALESCE(SUM(l.haber), 0) as lh, COUNT(l.id) as nl')
            ->orderBy('e.fecha')->orderBy('e.id')->get()->groupBy(fn ($f) => (int) substr((string) $f->fecha, 5, 2));

        $periodos = AccountingPeriod::where('company_id', $companyId)->where('anio', $anio)->get()->keyBy('mes');
        $usuarios = User::whereIn('id', $periodos->pluck('cerrado_por')->merge($periodos->pluck('reabierto_por'))->filter()->unique()->all())->pluck('name', 'id');

        $meses = [];
        foreach (range(1, 12) as $mes) {
            $entradas = $filas->get($mes, collect());
            $conteo = ['borrador' => 0, 'pendiente' => 0, 'mayorizado' => 0];
            $debe = 0.0;
            $haber = 0.0;
            $bloqueos = [];
            $descuadrados = 0;
            foreach ($entradas as $f) {
                $conteo[$f->estado] = ($conteo[$f->estado] ?? 0) + 1;
                if ($f->estado === JournalEntry::BORRADOR) {
                    $bloqueos[] = self::bloqueoBorrador($f->numero, $f->concepto);
                    continue;
                }
                $debe += (float) $f->total_debe;
                $haber += (float) $f->total_haber;
                if ($motivo = self::motivoDescuadre($f)) {
                    $descuadrados++;
                    $bloqueos[] = ['tipo' => 'descuadrado', 'numero' => $f->numero, 'concepto' => $f->concepto, 'motivo' => $motivo];
                }
            }
            $p = $periodos->get($mes);
            $cerrado = $p && $p->estado === AccountingPeriod::CERRADO;
            $meses[] = [
                'mes' => $mes,
                'periodo' => sprintf('%04d-%02d', $anio, $mes),
                'nombre' => self::MESES[$mes - 1],
                'estado' => $cerrado ? AccountingPeriod::CERRADO : AccountingPeriod::ABIERTO,
                'cerrado_at' => $cerrado ? $p->cerrado_at : null,
                'cerrado_por' => $cerrado ? ($usuarios[$p->cerrado_por] ?? null) : null,
                'reabierto_at' => $p?->reabierto_at,
                'reabierto_por' => $p && $p->reabierto_por ? ($usuarios[$p->reabierto_por] ?? null) : null,
                'motivo_reapertura' => $p?->motivo_reapertura,
                'asientos' => $conteo + ['total' => array_sum($conteo)],
                'descuadrados' => $descuadrados,
                'total_debe' => round($debe, 2),
                'total_haber' => round($haber, 2),
                'puede_cerrar' => ! $cerrado && ! $bloqueos,
                'bloqueos' => array_slice($bloqueos, 0, self::MAX_BLOQUEOS),
            ];
        }

        $abiertos = array_values(array_map(fn ($m) => $m['mes'], array_filter($meses, fn ($m) => $m['estado'] !== AccountingPeriod::CERRADO)));
        $cierre = self::cierreDelEjercicio($companyId, $anio);

        return [
            'company_id' => $companyId,
            'anio' => $anio,
            'meses' => $meses,
            'ejercicio' => [
                'cerrado' => (bool) $cierre,
                'asiento' => $cierre ? ['id' => $cierre->id, 'numero' => $cierre->numero, 'fecha' => $cierre->fecha->toDateString()] : null,
                'meses_abiertos' => $abiertos,
                'puede_cerrar' => ! $cierre && ! $abiertos,
            ],
        ];
    }

    // ------------------------------------------------------------- cerrar un mes

    /**
     * Cierra el mes: mayoriza todos sus asientos pendientes y lo marca cerrado. Todo o nada.
     *
     * @return array{periodo:string,mayorizados:int,numeros:array<int,string>}
     *
     * @throws ReglaDePeriodo si ya está cerrado o tiene borradores o asientos descuadrados
     */
    public static function cerrar(int $companyId, int $anio, int $mes, ?int $userId): array
    {
        $etiqueta = sprintf('%04d-%02d', $anio, $mes);

        return DB::transaction(function () use ($companyId, $anio, $mes, $userId, $etiqueta) {
            $periodo = AccountingPeriod::where('company_id', $companyId)->where('anio', $anio)->where('mes', $mes)->first();
            if ($periodo && $periodo->estado === AccountingPeriod::CERRADO) {
                throw new ReglaDePeriodo("El período {$etiqueta} ya está cerrado.");
            }

            $entradas = self::entradasDelMes($companyId, $anio, $mes);
            $bloqueos = [];
            foreach ($entradas as $e) {
                if ($e->estado === JournalEntry::BORRADOR) {
                    $bloqueos[] = self::bloqueoBorrador($e->numero, $e->concepto);
                } elseif ($falla = AsientoManual::descuadre($e)) {
                    $bloqueos[] = ['tipo' => 'descuadrado', 'numero' => $e->numero, 'concepto' => $e->concepto, 'motivo' => $falla['motivo']];
                }
            }
            if ($bloqueos) {
                $borradores = count(array_filter($bloqueos, fn ($b) => $b['tipo'] === 'borrador'));
                $descuadrados = count($bloqueos) - $borradores;
                $partes = array_filter([
                    $borradores ? $borradores.($borradores === 1 ? ' asiento en borrador' : ' asientos en borrador') : null,
                    $descuadrados ? $descuadrados.($descuadrados === 1 ? ' asiento descuadrado' : ' asientos descuadrados') : null,
                ]);
                throw new ReglaDePeriodo(
                    "No se puede cerrar el período {$etiqueta}: tiene ".implode(' y ', $partes).'. Contabilízalos, corrígelos o elimínalos y vuelve a intentarlo.',
                    ['bloqueos' => array_slice($bloqueos, 0, self::MAX_BLOQUEOS)],
                );
            }

            $pendientes = $entradas->where('estado', JournalEntry::PENDIENTE);
            if ($pendientes->isNotEmpty()) {
                JournalEntry::where('company_id', $companyId)->whereIn('id', $pendientes->pluck('id')->all())
                    ->update(['estado' => JournalEntry::MAYORIZADO, 'fue_mayorizado' => true]);
            }

            $periodo ??= new AccountingPeriod(['company_id' => $companyId, 'anio' => $anio, 'mes' => $mes]);
            $periodo->fill(['estado' => AccountingPeriod::CERRADO, 'cerrado_at' => now(), 'cerrado_por' => $userId])->save();

            $numeros = $pendientes->pluck('numero')->values()->all();
            self::auditar('cerro_periodo', $periodo, $etiqueta, $userId, ['mayorizados' => count($numeros), 'numeros' => $numeros]);

            return ['periodo' => $etiqueta, 'mayorizados' => count($numeros), 'numeros' => $numeros];
        });
    }

    // ------------------------------------------------------------- reabrir

    /** Reabre un mes cerrado (solo el administrador, lo exige la ruta). Deja el motivo y quién lo hizo. */
    public static function reabrir(int $companyId, int $anio, int $mes, string $motivo, ?int $userId): string
    {
        $etiqueta = sprintf('%04d-%02d', $anio, $mes);

        return DB::transaction(function () use ($companyId, $anio, $mes, $motivo, $userId, $etiqueta) {
            $periodo = AccountingPeriod::where('company_id', $companyId)->where('anio', $anio)->where('mes', $mes)->first();
            if (! $periodo || $periodo->estado !== AccountingPeriod::CERRADO) {
                throw new ReglaDePeriodo("El período {$etiqueta} ya está abierto.");
            }
            if ($cierre = self::cierreDelEjercicio($companyId, $anio)) {
                throw new ReglaDePeriodo("El ejercicio {$anio} ya tiene su asiento de cierre ({$cierre->numero}): no se puede reabrir ninguno de sus meses.");
            }

            $periodo->fill([
                'estado' => AccountingPeriod::ABIERTO, 'reabierto_at' => now(), 'reabierto_por' => $userId, 'motivo_reapertura' => $motivo,
            ])->save();
            self::auditar('reabrio_periodo', $periodo, $etiqueta, $userId, ['motivo' => $motivo]);

            return $etiqueta;
        });
    }

    // ------------------------------------------------------------- cierre del ejercicio

    /**
     * Cierra el ejercicio: UN asiento fechado el 31/12 que lleva a cero cada cuenta de ingreso y de gasto del año contra
     * "Resultado del ejercicio" (3.1.02). Nace mayorizado y marcado `es_cierre`; es el único asiento que puede crearse en
     * un mes cerrado (diciembre lo está: se exigen los 12 meses cerrados).
     *
     * Cómo se evita que el cierre ponga en cero el estado de resultados de su propio año: el asiento lleva `es_cierre`
     * y EstadosFinancieros lo deja fuera del estado de resultados (cualquier rango) pero lo cuenta en el balance, donde
     * mueve el resultado de las cuentas de ingresos y gastos a patrimonio.
     *
     * @return array<string,mixed>
     *
     * @throws ReglaDePeriodo
     */
    public static function cerrarEjercicio(int $companyId, int $anio, ?int $userId): array
    {
        return DB::transaction(function () use ($companyId, $anio, $userId) {
            if ($cierre = self::cierreDelEjercicio($companyId, $anio)) {
                throw new ReglaDePeriodo("El ejercicio {$anio} ya está cerrado: su asiento de cierre es {$cierre->numero}.");
            }

            $cerrados = AccountingPeriod::where('company_id', $companyId)->where('anio', $anio)->where('estado', AccountingPeriod::CERRADO)->pluck('mes')->all();
            $abiertos = array_values(array_diff(range(1, 12), $cerrados));
            if ($abiertos) {
                $nombres = implode(', ', array_map(fn ($m) => self::MESES[$m - 1], $abiertos));
                throw new ReglaDePeriodo("Para cerrar el ejercicio {$anio} primero cierra los 12 meses. Faltan: {$nombres}.", ['meses_abiertos' => $abiertos]);
            }

            // Saldo de cada cuenta de ingreso y de gasto en el año, sin contar un cierre anterior ni los borradores (en centavos)
            $filas = JournalEntryLine::query()
                ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
                ->where('e.company_id', $companyId)->where('e.estado', '!=', JournalEntry::BORRADOR)->where('e.es_cierre', false)
                ->whereDate('e.fecha', '>=', sprintf('%04d-01-01', $anio))->whereDate('e.fecha', '<=', sprintf('%04d-12-31', $anio))
                ->whereIn('a.tipo', ['ingreso', 'gasto'])
                ->groupBy('a.id', 'a.codigo', 'a.tipo')
                ->selectRaw('a.id as cuenta_id, a.codigo, a.tipo, SUM(journal_entry_lines.debe) as d, SUM(journal_entry_lines.haber) as h')
                ->orderBy('a.codigo')->get();

            $lineas = [];
            $debeCent = 0;
            $haberCent = 0;
            $ingresosCent = 0;
            $gastosCent = 0;
            foreach ($filas as $f) {
                $neto = (int) round(((float) $f->d - (float) $f->h) * 100);   // + saldo deudor, − saldo acreedor
                if ($f->tipo === 'ingreso') {
                    $ingresosCent -= $neto;
                } else {
                    $gastosCent += $neto;
                }
                if ($neto === 0) {
                    continue;
                }
                // La cuenta se lleva a cero: un saldo deudor se acredita y uno acreedor se debita
                $lineas[] = ['account_id' => (int) $f->cuenta_id, 'debe' => $neto < 0 ? -$neto / 100 : 0, 'haber' => $neto > 0 ? $neto / 100 : 0, 'referencia' => "Cierre {$anio}"];
                if ($neto < 0) {
                    $debeCent += -$neto;
                } else {
                    $haberCent += $neto;
                }
            }
            if (! $lineas) {
                throw new ReglaDePeriodo("El ejercicio {$anio} no tiene ingresos ni gastos que cerrar.");
            }

            // La diferencia va al resultado del ejercicio: utilidad al haber, pérdida al debe
            $resultadoCent = $debeCent - $haberCent;
            if ($resultadoCent !== 0) {
                $lineas[] = [
                    'account_id' => Cuentas::cuenta($companyId, 'resultado_ejercicio')->id,
                    'debe' => $resultadoCent < 0 ? -$resultadoCent / 100 : 0, 'haber' => $resultadoCent > 0 ? $resultadoCent / 100 : 0,
                    'referencia' => "Cierre {$anio}",
                ];
                if ($resultadoCent > 0) {
                    $haberCent += $resultadoCent;
                } else {
                    $debeCent += -$resultadoCent;
                }
            }

            $asiento = new JournalEntry([
                'company_id' => $companyId,
                'numero' => NumeroAsiento::siguiente($companyId),
                'fecha' => sprintf('%04d-12-31', $anio),
                'concepto' => "Cierre del ejercicio {$anio}",
                'origen_type' => null, 'origen_id' => null,
                'total_debe' => $debeCent / 100, 'total_haber' => $haberCent / 100,
                'estado' => JournalEntry::MAYORIZADO, 'fue_mayorizado' => true,
            ]);
            $asiento->es_cierre = true;   // no es asignable en masa; salta la guarda de período (diciembre está cerrado)
            $asiento->save();
            $asiento->lines()->createMany($lineas);

            $resultado = round($resultadoCent / 100, 2);
            self::auditar('cerro_ejercicio', $asiento, (string) $anio, $userId, [
                'asiento' => $asiento->numero, 'ingresos' => $ingresosCent / 100, 'gastos' => $gastosCent / 100, 'resultado' => $resultado,
            ], 'JournalEntry');

            return [
                'anio' => $anio,
                'asiento' => ['id' => $asiento->id, 'numero' => $asiento->numero, 'fecha' => $asiento->fecha->toDateString()],
                'ingresos' => round($ingresosCent / 100, 2),
                'gastos' => round($gastosCent / 100, 2),
                'resultado' => $resultado,
            ];
        });
    }

    /** El asiento que cerró el ejercicio de ese año, o null. */
    public static function cierreDelEjercicio(int $companyId, int $anio): ?JournalEntry
    {
        return JournalEntry::where('company_id', $companyId)->where('es_cierre', true)
            ->whereDate('fecha', '>=', sprintf('%04d-01-01', $anio))->whereDate('fecha', '<=', sprintf('%04d-12-31', $anio))
            ->orderBy('id')->first();
    }

    // ------------------------------------------------------------- piezas

    /** @return \Illuminate\Database\Eloquent\Collection<int,JournalEntry> con sus líneas */
    private static function entradasDelMes(int $companyId, int $anio, int $mes)
    {
        $inicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fin = date('Y-m-t', strtotime($inicio));

        return JournalEntry::where('company_id', $companyId)->whereDate('fecha', '>=', $inicio)->whereDate('fecha', '<=', $fin)
            ->with('lines')->orderBy('fecha')->orderBy('id')->get();
    }

    private static function bloqueoBorrador(string $numero, ?string $concepto): array
    {
        return ['tipo' => 'borrador', 'numero' => $numero, 'concepto' => $concepto, 'motivo' => 'Está en borrador: contabilízalo o elimínalo.'];
    }

    /** Mismo criterio que AsientoManual::descuadre, con las sumas ya calculadas en la consulta del año. */
    private static function motivoDescuadre(object $f): ?string
    {
        $ld = round((float) $f->ld, 2);
        $lh = round((float) $f->lh, 2);
        if ((int) $f->nl === 0) {
            return 'No tiene líneas.';
        }
        if ($ld !== $lh || round((float) $f->total_debe, 2) !== $ld || round((float) $f->total_haber, 2) !== $lh) {
            return 'Descuadrado: debe '.number_format($ld, 2, '.', '').', haber '.number_format($lh, 2, '.', '');
        }

        return null;
    }

    /** Constancia en la auditoría (quién, cuándo y con qué datos). */
    private static function auditar(string $accion, $modelo, string $descripcion, ?int $userId, array $datos, string $nombreModelo = 'AccountingPeriod'): void
    {
        AuditLog::create([
            'company_id' => $modelo->company_id,
            'user_id' => $userId,
            'accion' => $accion,
            'modelo' => $nombreModelo,
            'modelo_id' => $modelo->getKey(),
            'descripcion' => $descripcion,
            'cambios' => $datos ?: null,
            'ip' => Request::ip(),
        ]);
    }
}
