<?php

namespace App\Support;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Activos fijos (T7.2): la matemática de la cuota, el código de la ficha, el asiento de alta y la capitalización de una compra.
 *
 * La depreciación es en línea recta por meses completos: depreciable = costo − valor residual; cuota = depreciable / vida útil
 * redondeada a centavos; el último mes absorbe el redondeo, así que lo depreciado nunca pasa del depreciable. Todo se calcula en
 * centavos enteros para no arrastrar decimales. El cronograma de una ficha es una función de su costo, residual, vida útil y mes de
 * inicio: el mes N (1 = el de inicio) acumula min(depreciable, cuota × N), y el último mes (N = vida útil) acumula todo.
 */
final class ActivosFijos
{
    /** Prefijo del código que la pantalla sugiere: AF-000001. */
    public const PREFIJO = 'AF';

    // ------------------------------------------------------------- matemática (en centavos)

    public static function centavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    /** Lo que se puede depreciar: costo − valor residual (≥ 0). */
    public static function depreciableCentavos(FixedAsset|array $a): int
    {
        $costo = $a instanceof FixedAsset ? $a->costo : $a['costo'];
        $residual = $a instanceof FixedAsset ? $a->valor_residual : $a['valor_residual'];

        return max(0, self::centavos($costo) - self::centavos($residual));
    }

    /** Cuota mensual redondeada a centavos (al menos 1 centavo si hay algo que depreciar, para que ningún mes quede en cero). */
    public static function cuotaCentavos(int $depreciable, int $vidaMeses): int
    {
        if ($depreciable <= 0 || $vidaMeses <= 0) {
            return 0;
        }

        return max(1, (int) round($depreciable / $vidaMeses));
    }

    /** Depreciación acumulada al cerrar el mes N del cronograma (N = 1 es el mes de inicio). */
    public static function acumuladaTrasMes(int $depreciable, int $vidaMeses, int $n): int
    {
        if ($n <= 0 || $depreciable <= 0) {
            return 0;
        }
        if ($n >= $vidaMeses) {
            return $depreciable;
        }

        return min($depreciable, self::cuotaCentavos($depreciable, $vidaMeses) * $n);
    }

    // ------------------------------------------------------------- meses como número (año × 12 + mes − 1)

    public static function ym(int $anio, int $mes): int
    {
        return $anio * 12 + $mes - 1;
    }

    public static function ymDe(mixed $fecha): int
    {
        $f = Carbon::parse($fecha);

        return self::ym($f->year, $f->month);
    }

    /** "2026-03" */
    public static function periodo(int $ym): string
    {
        return sprintf('%04d-%02d', intdiv($ym, 12), $ym % 12 + 1);
    }

    /** [anio, mes] */
    public static function anioMes(int $ym): array
    {
        return [intdiv($ym, 12), $ym % 12 + 1];
    }

    /** "2026-03" → ym; null si no tiene la forma AAAA-MM. */
    public static function ymDePeriodo(string $periodo): ?int
    {
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodo, $m) ? self::ym((int) $m[1], (int) $m[2]) : null;
    }

    // ------------------------------------------------------------- lo ya depreciado

    /**
     * Lo contabilizado de cada activo: id => ['acumulada' => centavos, 'meses' => cuántos, 'ultimo' => ym del último mes depreciado].
     *
     * @param  int[]|null  $ids  limita a esos activos; null = todos los de la empresa
     * @return array<int,array{acumulada:int,meses:int,ultimo:int}>
     */
    public static function resumen(int $companyId, ?array $ids = null): array
    {
        $filas = FixedAssetDepreciation::where('company_id', $companyId)
            ->when($ids !== null, fn ($q) => $q->whereIn('fixed_asset_id', $ids))
            ->selectRaw('fixed_asset_id, COUNT(*) as meses, MAX(anio * 12 + mes - 1) as ultimo')
            ->groupBy('fixed_asset_id')->get();

        // La suma se hace en centavos fila por fila: sumar decimales de la base arrastra residuos de coma flotante
        $sumas = [];
        FixedAssetDepreciation::where('company_id', $companyId)
            ->when($ids !== null, fn ($q) => $q->whereIn('fixed_asset_id', $ids))
            ->get(['fixed_asset_id', 'monto'])
            ->each(function ($f) use (&$sumas) {
                $sumas[$f->fixed_asset_id] = ($sumas[$f->fixed_asset_id] ?? 0) + self::centavos($f->monto);
            });

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f->fixed_asset_id] = ['acumulada' => $sumas[$f->fixed_asset_id] ?? 0, 'meses' => (int) $f->meses, 'ultimo' => (int) $f->ultimo];
        }

        return $out;
    }

    // ------------------------------------------------------------- código de la ficha

    /** Código siguiente de la empresa: AF-000001. Va por el MAYOR número usado + 1, no por cuántas fichas hay. */
    public static function siguienteCodigo(int $companyId): string
    {
        $ultimo = FixedAsset::where('company_id', $companyId)->where('codigo', 'like', self::PREFIJO.'-%')
            ->orderByRaw('LENGTH(codigo) DESC')->orderByDesc('codigo')->value('codigo');
        $n = $ultimo !== null && preg_match('/^'.self::PREFIJO.'-(\d+)$/', (string) $ultimo, $m) ? (int) $m[1] : 0;

        do {
            $codigo = sprintf('%s-%06d', self::PREFIJO, ++$n);
        } while (FixedAsset::where('company_id', $companyId)->where('codigo', $codigo)->exists());

        return $codigo;
    }

    // ------------------------------------------------------------- alta contable

    /**
     * La contrapartida del alta: una cuenta de detalle de la empresa distinta de Activos fijos y que no sea una de las que se mueven
     * solo desde su documento (cuentas por cobrar y por pagar, anticipos). 422 con mensaje en español si no sirve.
     */
    public static function contrapartida(int $companyId, mixed $cuentaId): Account
    {
        return AsientoCajaBanco::exigirContrapartida($companyId, $cuentaId, Cuentas::cuenta($companyId, 'activos_fijos'));
    }

    /** Debe Activos fijos / Haber la contrapartida elegida, con la fecha de adquisición y la ficha como origen. */
    public static function asentarAlta(FixedAsset $a, Account $contrapartida): JournalEntry
    {
        $companyId = (int) $a->company_id;
        $costo = (float) $a->costo;

        return AsientoCajaBanco::publicar($companyId, $a->fecha_adquisicion, "Alta de activo fijo {$a->codigo} {$a->nombre}", $a, [
            AsientoCajaBanco::debe(Cuentas::cuenta($companyId, 'activos_fijos'), $costo, $a->codigo),
            AsientoCajaBanco::haber($contrapartida, $costo, $a->codigo),
        ]);
    }

    // ------------------------------------------------------------- compras que son activos fijos

    /**
     * Asiento de una compra manual con sustento de activo fijo (03 o 04): la base va a Activos fijos y no a inventario ni a gasto.
     *   Debe Activos fijos (base) · Debe Crédito tributario IVA · Haber Cuentas por pagar (total)
     * La ficha del activo se crea aparte (Contabilidad > Activos fijos) y, ligada a esta compra, no vuelve a asentar el costo.
     */
    public static function asentarCompra(Purchase $p): JournalEntry
    {
        $companyId = (int) $p->company_id;
        $total = self::centavos($p->importe_total);
        $iva = min($total, self::centavos($p->total_impuesto));
        $base = $total - $iva;

        $e = JournalEntry::create([
            'company_id' => $companyId, 'numero' => NumeroAsiento::siguiente($companyId),
            'fecha' => $p->fecha_emision ?? now(), 'concepto' => 'Compra factura '.$p->numero.' (activo fijo)',
            'origen_type' => $p->getMorphClass(), 'origen_id' => $p->getKey(),
            'total_debe' => $total / 100, 'total_haber' => $total / 100, 'estado' => JournalEntry::PENDIENTE,
        ]);
        $lineas = [['account_id' => Cuentas::cuenta($companyId, 'activos_fijos')->id, 'debe' => $base / 100, 'haber' => 0, 'referencia' => $p->numero]];
        if ($iva > 0) {
            $lineas[] = ['account_id' => Cuentas::cuenta($companyId, 'credito_tributario_iva')->id, 'debe' => $iva / 100, 'haber' => 0, 'referencia' => $p->numero];
        }
        $lineas[] = ['account_id' => Cuentas::cuenta($companyId, Cuentas::cxpPara($p->contact))->id, 'debe' => 0, 'haber' => $total / 100, 'referencia' => $p->numero];
        $e->lines()->createMany($lineas);

        return $e;
    }

    /** Lo que la compra mandó a Activos fijos (Debe de 1.2.01 en el asiento de la compra). 0 si no se capitalizó. */
    public static function capitalizadoDe(Purchase $p): float
    {
        $cuenta = Account::where('company_id', $p->company_id)->where('codigo', Cuentas::codigo('activos_fijos'))->value('id');
        if (! $cuenta) {
            return 0.0;
        }

        return round((float) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $p->company_id)
            ->where('journal_entries.origen_type', $p->getMorphClass())->where('journal_entries.origen_id', $p->getKey())
            ->where('journal_entries.concepto', 'not like', 'Reversión%')
            ->where('journal_entry_lines.account_id', $cuenta)->sum('journal_entry_lines.debe'), 2);
    }

    /** Costo ya repartido en fichas ligadas a la compra (se puede excluir una ficha: la que se está editando). */
    public static function usadoDe(Purchase $p, ?int $exceptoActivo = null): float
    {
        $centavos = FixedAsset::where('purchase_id', $p->id)->when($exceptoActivo, fn ($q) => $q->where('id', '!=', $exceptoActivo))
            ->get(['costo'])->sum(fn ($a) => self::centavos($a->costo));

        return $centavos / 100;
    }

    /**
     * Cuánto de la compra queda para repartir en fichas: lo capitalizado menos lo ya repartido; si la compra no se capitalizó
     * (importada del XML o con otro sustento), el límite es su base sin impuestos.
     */
    public static function disponibleDe(Purchase $p, ?int $exceptoActivo = null): float
    {
        $tope = self::capitalizadoDe($p);
        if ($tope <= 0) {
            $tope = (float) $p->total_sin_impuestos;
        }

        return round($tope - self::usadoDe($p, $exceptoActivo), 2);
    }

    /** Compras de activo fijo (sustento 03 o 04) con algo por repartir en fichas. */
    public static function comprasPendientes(int $companyId): Collection
    {
        return Purchase::with('contact:id,razon_social')->where('company_id', $companyId)
            ->whereIn('sustento_tributario', Sustentos::ACTIVO_FIJO)
            ->where(fn ($q) => $q->whereNull('tipo_comprobante')->orWhere('tipo_comprobante', '!=', 'liquidacion_compra'))
            ->orderByDesc('fecha_emision')->orderByDesc('id')->limit(300)->get()
            ->map(function (Purchase $p) {
                $capitalizado = self::capitalizadoDe($p);

                return [
                    'id' => $p->id, 'numero' => $p->numero, 'fecha_emision' => $p->fecha_emision?->toDateString(),
                    'proveedor' => $p->contact?->razon_social, 'sustento_tributario' => $p->sustento_tributario,
                    'importe_total' => (float) $p->importe_total, 'base' => (float) $p->total_sin_impuestos,
                    'capitalizada' => $capitalizado > 0, 'disponible' => self::disponibleDe($p),
                ];
            })->filter(fn ($c) => $c['disponible'] > 0.004)->values();
    }
}
