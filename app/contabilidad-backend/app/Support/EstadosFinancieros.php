<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;

/**
 * Estado de resultados y estado de situación financiera (balance general) por período, con el detalle por cuenta (T4.3b).
 *
 * Cuentan los mismos asientos que el libro mayor y los reportes de siempre: todo lo que no es borrador (pendientes y
 * mayorizados). El árbol sale del código de la cuenta (1 → 1.1 → 1.1.01): cada cuenta cuelga de la cuenta más cercana
 * que el plan tenga por encima de su código, y los subtotales suman lo que cuelga de ella. Las cuentas sin movimiento
 * en el período no salen, salvo que tengan algo debajo.
 *
 * El asiento de cierre del ejercicio (`es_cierre`) se deja FUERA del estado de resultados —en cualquier rango— para que
 * el año que cierra siga mostrando lo vendido y lo gastado, y se cuenta en el balance, donde pasa el resultado de las
 * cuentas de ingresos y gastos a "Resultado del ejercicio" (patrimonio).
 */
final class EstadosFinancieros
{
    private const TITULOS = [
        'ingreso' => 'Ingresos', 'gasto' => 'Gastos',
        'activo' => 'Activo', 'pasivo' => 'Pasivo', 'patrimonio' => 'Patrimonio',
    ];

    /**
     * Ingresos y gastos del período.
     *
     * @return array<string,mixed>
     */
    public static function estadoResultados(int $companyId, ?string $desde, ?string $hasta): array
    {
        $cuentas = self::cuentasConSaldo($companyId, $desde, $hasta, excluirCierre: true);
        $secciones = [self::seccion($cuentas, 'ingreso'), self::seccion($cuentas, 'gasto')];
        $ingresos = $secciones[0]['total'];
        $gastos = $secciones[1]['total'];

        return [
            'company_id' => $companyId,
            'desde' => $desde,
            'hasta' => $hasta,
            'secciones' => $secciones,
            'totales' => ['ingresos' => $ingresos, 'gastos' => $gastos, 'resultado' => round($ingresos - $gastos, 2)],
        ];
    }

    /**
     * Activo, pasivo y patrimonio a una fecha, más el resultado del período (ingresos − gastos todavía sin cerrar).
     * La ecuación contable es Activo = Pasivo + Patrimonio + Resultado del período; `cuadra` y `diferencia` lo dicen.
     *
     * @return array<string,mixed>
     */
    public static function balanceGeneral(int $companyId, ?string $hasta): array
    {
        $cuentas = self::cuentasConSaldo($companyId, null, $hasta, excluirCierre: false);
        $secciones = [self::seccion($cuentas, 'activo'), self::seccion($cuentas, 'pasivo'), self::seccion($cuentas, 'patrimonio')];
        [$activo, $pasivo, $patrimonio] = array_column($secciones, 'total');

        // Lo que todavía no se pasó a patrimonio con el cierre del ejercicio: ingresos − gastos acumulados a la fecha
        $resultado = round(self::seccion($cuentas, 'ingreso')['total'] - self::seccion($cuentas, 'gasto')['total'], 2);
        $derecha = round($pasivo + $patrimonio + $resultado, 2);
        $diferencia = round($activo - $derecha, 2);

        return [
            'company_id' => $companyId,
            'hasta' => $hasta,
            'secciones' => $secciones,
            'resultado_periodo' => $resultado,
            'totales' => [
                'activo' => $activo, 'pasivo' => $pasivo, 'patrimonio' => $patrimonio, 'resultado_periodo' => $resultado,
                'pasivo_patrimonio_resultado' => $derecha, 'diferencia' => $diferencia, 'cuadra' => abs($diferencia) < 0.005,
            ],
        ];
    }

    // ------------------------------------------------------------- saldos y árbol

    /**
     * Todas las cuentas de la empresa con lo que sumaron en el período.
     *
     * @return array<int,array{id:int,codigo:string,nombre:string,tipo:string,debe:float,haber:float}>
     */
    private static function cuentasConSaldo(int $companyId, ?string $desde, ?string $hasta, bool $excluirCierre): array
    {
        $sumas = JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('e.company_id', $companyId)->where('e.estado', '!=', JournalEntry::BORRADOR)
            ->when($excluirCierre, fn ($q) => $q->where('e.es_cierre', false))
            ->when($desde, fn ($q) => $q->whereDate('e.fecha', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('e.fecha', '<=', $hasta))
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id as id, SUM(journal_entry_lines.debe) as d, SUM(journal_entry_lines.haber) as h')
            ->get()->keyBy('id');

        return Account::where('company_id', $companyId)->get(['id', 'codigo', 'nombre', 'tipo'])->map(fn ($a) => [
            'id' => $a->id, 'codigo' => (string) $a->codigo, 'nombre' => $a->nombre, 'tipo' => $a->tipo,
            'debe' => round((float) ($sumas[$a->id]->d ?? 0), 2), 'haber' => round((float) ($sumas[$a->id]->h ?? 0), 2),
        ])->all();
    }

    /**
     * La sección de un tipo de cuenta: su árbol y su total.
     *
     * @param  array<int,array<string,mixed>>  $todas  todas las cuentas de la empresa (para saber cuáles son encabezados)
     * @return array{tipo:string,titulo:string,total:float,cuentas:array<int,array<string,mixed>>}
     */
    private static function seccion(array $todas, string $tipo): array
    {
        $codigos = array_column($todas, 'codigo');
        $delTipo = array_values(array_filter($todas, fn ($c) => $c['tipo'] === $tipo));
        usort($delTipo, fn ($a, $b) => self::compararCodigos($a['codigo'], $b['codigo']));

        $natural = in_array($tipo, ['activo', 'gasto'], true) ? 1 : -1;   // activo y gasto suman por el debe; el resto por el haber
        $nodos = [];
        foreach ($delTipo as $c) {
            $nodos[$c['codigo']] = [
                'codigo' => $c['codigo'], 'nombre' => $c['nombre'], 'tipo' => $tipo,
                'nivel' => substr_count($c['codigo'], '.') + 1,
                'debe' => $c['debe'], 'haber' => $c['haber'],
                'es_encabezado' => self::tieneSubcuentas($c['codigo'], $codigos),
            ];
        }

        // Cada cuenta cuelga de la más cercana por encima de su código que exista en el plan para este tipo
        $hijosDe = [];
        $raices = [];
        foreach (array_keys($nodos) as $codigo) {
            $padre = self::codigoPadre((string) $codigo, $nodos);
            if ($padre === null) {
                $raices[] = (string) $codigo;
            } else {
                $hijosDe[$padre][] = (string) $codigo;
            }
        }

        $cuentas = [];
        foreach ($raices as $raiz) {
            if ($arbol = self::armarNodo($raiz, $nodos, $hijosDe, $natural)) {
                $cuentas[] = $arbol;
            }
        }

        return [
            'tipo' => $tipo, 'titulo' => self::TITULOS[$tipo],
            'total' => round(array_sum(array_column($cuentas, 'saldo')), 2),
            'cuentas' => $cuentas,
        ];
    }

    /**
     * Arma el nodo con sus hijos, sumando lo de ellos a su Debe y Haber, y descarta las ramas sin movimiento (devuelve null).
     *
     * @param  array<string,array<string,mixed>>  $nodos
     * @param  array<string,array<int,string>>  $hijosDe
     */
    private static function armarNodo(string $codigo, array $nodos, array $hijosDe, int $natural): ?array
    {
        $nodo = $nodos[$codigo];
        $propio = $nodo['debe'] != 0 || $nodo['haber'] != 0;
        $hijos = [];
        foreach ($hijosDe[$codigo] ?? [] as $codigoHijo) {
            if ($hijo = self::armarNodo($codigoHijo, $nodos, $hijosDe, $natural)) {
                $hijos[] = $hijo;
                $nodo['debe'] += $hijo['debe'];
                $nodo['haber'] += $hijo['haber'];
            }
        }
        if (! $hijos && ! $propio) {
            return null;
        }
        $nodo['debe'] = round($nodo['debe'], 2);
        $nodo['haber'] = round($nodo['haber'], 2);

        return $nodo + ['saldo' => round($natural * ($nodo['debe'] - $nodo['haber']), 2), 'hijos' => $hijos];
    }

    /** El código de la cuenta más cercana por encima (un código de cuatro tramos busca el de tres, luego el de dos y el de uno) que exista entre los nodos. */
    private static function codigoPadre(string $codigo, array $nodos): ?string
    {
        while (($pos = strrpos($codigo, '.')) !== false) {
            $codigo = substr($codigo, 0, $pos);
            if (isset($nodos[$codigo])) {
                return $codigo;
            }
        }

        return null;
    }

    private static function tieneSubcuentas(string $codigo, array $codigos): bool
    {
        foreach ($codigos as $otro) {
            if (str_starts_with($otro, $codigo.'.')) {
                return true;
            }
        }

        return false;
    }

    /** Orden por número en cada tramo: 1.1.2 antes que 1.1.10. */
    public static function compararCodigos(string $a, string $b): int
    {
        $pa = explode('.', $a);
        $pb = explode('.', $b);
        foreach (range(0, max(count($pa), count($pb)) - 1) as $i) {
            if (! isset($pa[$i])) {
                return -1;
            }
            if (! isset($pb[$i])) {
                return 1;
            }
            $c = ctype_digit($pa[$i]) && ctype_digit($pb[$i]) ? ((int) $pa[$i] <=> (int) $pb[$i]) : strcmp($pa[$i], $pb[$i]);
            if ($c !== 0) {
                return $c;
            }
        }

        return 0;
    }

    // ------------------------------------------------------------- para exportar

    /**
     * El árbol en filas planas [código, nombre con sangría, saldo].
     *
     * @param  array<int,array<string,mixed>>  $nodos
     * @return array<int,array{0:string,1:string,2:string}>
     */
    public static function filas(array $nodos, int $profundidad = 0): array
    {
        $filas = [];
        foreach ($nodos as $n) {
            $filas[] = [$n['codigo'], str_repeat('    ', $profundidad).$n['nombre'], self::n($n['saldo'])];
            array_push($filas, ...self::filas($n['hijos'], $profundidad + 1));
        }

        return $filas;
    }

    /** Número como texto con dos decimales y guion normal para los negativos (dompdf no dibuja el signo menos Unicode). */
    public static function n(float|int $valor): string
    {
        $t = number_format((float) $valor, 2, '.', '');

        return $t === '-0.00' ? '0.00' : $t;
    }
}
