<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PhysicalCount;
use App\Models\Purchase;
use App\Models\StockTransformation;
use Illuminate\Support\Collection;

/**
 * De dónde vino cada movimiento del kárdex (T5.2): compra, venta, nota de crédito, anulación, ajuste, toma física,
 * transferencia, fraccionamiento / conversión o saldo inicial. Agrega a cada movimiento cuatro campos para la API:
 *   origen_tipo     clave estable ('compra', 'venta', 'toma_fisica'...), la que usan los filtros;
 *   origen_label    texto corto en español para la columna "Origen";
 *   origen_numero   el número del documento (factura, compra, NC-12, TF-000001, FRA-000001, asiento del ajuste) o null;
 *   origen_detalle  proveedor, cliente, motivo, bodega o los productos de la conversión, según el caso; o null.
 *
 * Se reconoce por la RELACIÓN real, en este orden: documento de origen (fraccionamiento, conversión, toma física, nota de
 * crédito) -> compra -> factura -> asiento que nació del propio movimiento (ajuste). Solo los movimientos de antes, que
 * no guardaban su documento, se reconocen por el número que lleva su concepto ("Devolución NC 12", "Anulación 001-001-...").
 * Se resuelve en bloque (unas pocas consultas para toda la lista), no movimiento por movimiento.
 */
final class OrigenMovimiento
{
    /** Clave => etiqueta base (la transferencia añade "(salida)" o "(entrada)"). */
    public const TIPOS = [
        'compra' => 'Compra',
        'venta' => 'Venta',
        'nota_credito' => 'Nota de crédito',
        'anulacion_factura' => 'Anulación de factura',
        'anulacion_nota_credito' => 'Anulación de nota de crédito',
        'ajuste' => 'Ajuste de inventario',
        'toma_fisica' => 'Toma física',
        'transferencia' => 'Transferencia',
        'fraccionamiento' => 'Fraccionamiento',
        'conversion' => 'Conversión',
        'saldo_inicial' => 'Saldo inicial',
        'otro' => 'Otro movimiento',
    ];

    /**
     * Anota la lista y la devuelve (los mismos objetos, con los cuatro campos puestos).
     *
     * @param  iterable<InventoryMovement>  $movimientos
     * @return Collection<int,InventoryMovement>
     */
    public static function anotar(iterable $movimientos): Collection
    {
        $movs = collect($movimientos)->values();
        if ($movs->isEmpty()) {
            return $movs;
        }

        $morph = [
            'transformacion' => (new StockTransformation)->getMorphClass(),
            'toma' => (new PhysicalCount)->getMorphClass(),
            'nota' => (new CreditNote)->getMorphClass(),
            'movimiento' => (new InventoryMovement)->getMorphClass(),
        ];

        // 1. Qué documentos hay que traer
        $idsCompras = $movs->pluck('purchase_id')->filter()->unique()->all();
        $idsFacturas = $movs->pluck('invoice_id')->filter()->unique()->all();
        $idsTransformaciones = $movs->where('origen_type', $morph['transformacion'])->pluck('origen_id')->unique()->all();
        $idsTomas = $movs->where('origen_type', $morph['toma'])->pluck('origen_id')->unique()->all();
        $idsNotas = $movs->where('origen_type', $morph['nota'])->pluck('origen_id')->unique()->all();

        // Sin documento de origen ni compra ni factura: puede ser un ajuste (su asiento nació del propio movimiento) o uno de antes
        $sueltos = $movs->filter(fn ($m) => ! $m->origen_type && ! $m->purchase_id && ! $m->invoice_id);
        $asientosDeAjuste = $sueltos->isEmpty() ? collect() : JournalEntry::where('origen_type', $morph['movimiento'])
            ->whereIn('origen_id', $sueltos->pluck('id')->all())->orderBy('id')->pluck('numero', 'origen_id');

        $porConcepto = [];   // movimiento id => [clase, referencia] leído del concepto (solo los de antes)
        $numerosFactura = [];
        $numerosCompra = [];
        foreach ($sueltos as $m) {
            if ($asientosDeAjuste->has($m->id)) {
                continue;
            }
            $c = (string) $m->concepto;
            if (preg_match('/^(Devolución NC|Reverso NC anulada) (\d+)/u', $c, $x)) {
                $porConcepto[$m->id] = [$x[1] === 'Devolución NC' ? 'nota_credito' : 'anulacion_nota_credito', (int) $x[2]];
                $idsNotas[] = (int) $x[2];
            } elseif (preg_match('/^Anulación (.+)$/u', $c, $x)) {
                $porConcepto[$m->id] = ['anulacion_factura', trim($x[1])];
                $numerosFactura[] = [$m->company_id, trim($x[1])];
            } elseif (preg_match('/^Venta(?: combo)? (.+)$/u', $c, $x)) {
                $porConcepto[$m->id] = ['venta', trim($x[1])];
                $numerosFactura[] = [$m->company_id, trim($x[1])];
            } elseif (preg_match('/^Compra (.+)$/u', $c, $x)) {
                $porConcepto[$m->id] = ['compra', trim($x[1])];
                $numerosCompra[] = [$m->company_id, trim($x[1])];
            }
        }

        // 2. Se traen en bloque, con lo que se muestra de cada uno
        $facturasPorNumero = collect();
        if ($numerosFactura) {
            $facturasPorNumero = Invoice::with('contact:id,razon_social')
                ->whereIn('company_id', array_unique(array_column($numerosFactura, 0)))->whereIn('numero', array_unique(array_column($numerosFactura, 1)))
                ->get()->keyBy(fn ($f) => $f->company_id.'|'.$f->numero);
        }
        $comprasPorNumero = collect();
        if ($numerosCompra) {
            $comprasPorNumero = Purchase::with('contact:id,razon_social')
                ->whereIn('company_id', array_unique(array_column($numerosCompra, 0)))->whereIn('numero', array_unique(array_column($numerosCompra, 1)))
                ->get()->keyBy(fn ($p) => $p->company_id.'|'.$p->numero);
        }
        $compras = $idsCompras ? Purchase::with('contact:id,razon_social')->whereIn('id', $idsCompras)->get()->keyBy('id') : collect();
        $facturas = $idsFacturas ? Invoice::with('contact:id,razon_social')->whereIn('id', $idsFacturas)->get()->keyBy('id') : collect();
        $transformaciones = $idsTransformaciones
            ? StockTransformation::with('productoOrigen:id,codigo', 'productoDestino:id,codigo')->whereIn('id', $idsTransformaciones)->get()->keyBy('id') : collect();
        $tomas = $idsTomas ? PhysicalCount::with('warehouse:id,nombre')->whereIn('id', $idsTomas)->get()->keyBy('id') : collect();
        $notas = $idsNotas ? CreditNote::with('contact:id,razon_social')->whereIn('id', array_unique($idsNotas))->get()->keyBy('id') : collect();

        // 3. Cada movimiento, por su relación real y, si no la tiene, por su concepto
        foreach ($movs as $m) {
            $concepto = (string) $m->concepto;
            $tipo = 'otro';
            $numero = null;
            $detalle = null;

            if ($m->origen_type === $morph['transformacion']) {
                $t = $transformaciones->get($m->origen_id);
                $tipo = $t?->tipo === StockTransformation::CONVERSION ? 'conversion' : 'fraccionamiento';
                $numero = $t?->numero;
                $detalle = $t ? $t->productoOrigen?->codigo.' → '.$t->productoDestino?->codigo : null;
            } elseif ($m->origen_type === $morph['toma']) {
                $t = $tomas->get($m->origen_id);
                $tipo = 'toma_fisica';
                $numero = $t?->numero;
                $detalle = $t?->warehouse?->nombre;
            } elseif ($m->origen_type === $morph['nota']) {
                $n = $notas->get($m->origen_id);
                $tipo = $m->tipo === 'ingreso' ? 'nota_credito' : 'anulacion_nota_credito';
                [$numero, $detalle] = self::datosNota($n, (int) $m->origen_id);
            } elseif ($m->purchase_id) {
                $p = $compras->get($m->purchase_id);
                $tipo = 'compra';
                $numero = $p?->numero;
                $detalle = $p?->contact?->razon_social;
            } elseif ($m->invoice_id) {
                $f = $facturas->get($m->invoice_id);
                $tipo = $m->tipo === 'ingreso' ? 'anulacion_factura' : 'venta';   // un ingreso ligado a la factura solo puede ser su anulación
                $numero = $f?->numero;
                $detalle = $f?->contact?->razon_social;
            } elseif ($asientosDeAjuste->has($m->id)) {
                $tipo = 'ajuste';
                $numero = $asientosDeAjuste->get($m->id);
                $detalle = self::despuesDeDosPuntos($concepto);
            } elseif (isset($porConcepto[$m->id])) {
                [$tipo, $ref] = $porConcepto[$m->id];
                if (in_array($tipo, ['nota_credito', 'anulacion_nota_credito'], true)) {
                    [$numero, $detalle] = self::datosNota($notas->get($ref), $ref);
                } elseif ($tipo === 'compra') {
                    $p = $comprasPorNumero->get($m->company_id.'|'.$ref);
                    $numero = $ref;
                    $detalle = $p?->contact?->razon_social;
                } else {                                                    // venta o anulación de factura
                    $f = $facturasPorNumero->get($m->company_id.'|'.$ref);
                    $numero = $ref;
                    $detalle = $f?->contact?->razon_social;
                }
            } elseif (preg_match('/^Ajuste inventario/u', $concepto)) {
                $tipo = 'ajuste';
                $detalle = self::despuesDeDosPuntos($concepto);
            } elseif (preg_match('/^Transferencia (?:salida|entrada)/u', $concepto)) {
                $tipo = 'transferencia';
                $detalle = self::despuesDeDosPuntos($concepto);
            } elseif (preg_match('/^Conversión/u', $concepto)) {
                $tipo = 'conversion';
            } elseif (preg_match('/^Fraccionamiento/u', $concepto)) {
                $tipo = 'fraccionamiento';
            } elseif (preg_match('/^(?:Saldo|Inventario) inicial/iu', $concepto)) {
                $tipo = 'saldo_inicial';
            }

            $m->setAttribute('origen_tipo', $tipo);
            $m->setAttribute('origen_label', self::etiqueta($tipo, (string) $m->tipo));
            $m->setAttribute('origen_numero', $numero);
            $m->setAttribute('origen_detalle', $detalle !== null && trim($detalle) !== '' ? trim($detalle) : null);
        }

        return $movs;
    }

    /** Etiqueta corta en español; la transferencia dice si fue salida o entrada. */
    public static function etiqueta(string $tipo, string $sentido = ''): string
    {
        $base = self::TIPOS[$tipo] ?? self::TIPOS['otro'];
        if ($tipo === 'transferencia' && in_array($sentido, ['ingreso', 'egreso'], true)) {
            return $base.($sentido === 'egreso' ? ' (salida)' : ' (entrada)');
        }

        return $base;
    }

    /** @return array{0:string,1:?string} número visible de la nota (NC-12 si todavía no tiene el suyo) y su cliente */
    private static function datosNota(?CreditNote $n, int $id): array
    {
        return [$n?->numero ?? 'NC-'.$id, $n?->contact?->razon_social];
    }

    /** "Ajuste inventario: Rotura" -> "Rotura"; sin dos puntos, null. */
    private static function despuesDeDosPuntos(string $concepto): ?string
    {
        $pos = strpos($concepto, ':');

        return $pos === false ? null : trim(substr($concepto, $pos + 1));
    }
}
