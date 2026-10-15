<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\SeriesDeCompra;
use Illuminate\Http\Request;

class SerieController extends Controller {
    /**
     * Series de la empresa (o de un producto). Con `warehouse_id` solo las que están en esa bodega: una serie sin bodega
     * (de antes de que existiera la columna) cuenta como de la bodega principal. Cada fila trae su `bodega` en texto.
     */
    public function index(Request $r) {
        $series = ProductSerie::with('product:id,codigo,descripcion','purchase:id,numero','invoice:id,numero','warehouse:id,codigo,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->product_id, fn($q,$id)=>$q->where('product_id',$id))
            ->when($r->estado, fn($q,$e)=>$q->where('estado',$e))
            // Las series de una factura: la pantalla de notas de crédito ofrece las vendidas para elegir cuáles regresan
            ->when($r->invoice_id, fn($q,$id)=>$q->where('invoice_id',$id))
            ->when($r->warehouse_id && $r->company_id, fn($q)=>$q->enBodega((int)$r->warehouse_id, (int)$r->company_id))
            ->orderBy('serie')->get();

        $principales = [];
        return $series->each(function ($s) use (&$principales) {
            $principales[$s->company_id] ??= Warehouse::whereKey(ProductSerie::bodegaPrincipalId((int)$s->company_id))->value('nombre');
            $s->setAttribute('bodega', $s->warehouse?->nombre ?? $principales[$s->company_id]);
        });
    }

    /**
     * Registra series de un producto cuyo stock ya está en el kárdex (lo normal es que entren con la compra). No pueden ser
     * más que las unidades de esa bodega: el stock y las series disponibles no se separan. Una serie que ya existe da 422.
     */
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'product_id'=>['required','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'warehouse_id'=>['nullable','exists:warehouses,id'],
            'series'=>['required','array','min:1'],
            'series.*'=>['required','string'],
        ]);
        $producto = Product::findOrFail($d['product_id']);
        $series = SeriesDeCompra::limpiar($d['series']);

        $repetidas = array_keys(array_filter(array_count_values($series), fn($n)=>$n>1));
        if ($repetidas) {
            return response()->json(['message'=>'Hay series repetidas en la lista: '.implode(', ', $repetidas).'.'], 422);
        }
        $existentes = ProductSerie::where('company_id',$d['company_id'])->whereIn('serie',$series)->orderBy('serie')->pluck('serie')->all();
        if ($existentes) {
            return response()->json(['message'=>'Estas series ya existen en el sistema: '.implode(', ', $existentes).'.'], 422);
        }

        $bodegaId = $d['warehouse_id'] ?? ProductSerie::bodegaPrincipalId((int)$d['company_id']);
        if ($bodegaId) {
            $enBodega = (float) (WarehouseStock::where('warehouse_id',$bodegaId)->where('product_id',$producto->id)->value('stock') ?? 0);
            // El stock anterior a las bodegas (movimientos sin bodega) está en la principal
            if ((int)$bodegaId === ProductSerie::bodegaPrincipalId((int)$d['company_id'])) {
                $enBodega += max(0, (float)$producto->stock - (float) WarehouseStock::where('product_id',$producto->id)->sum('stock'));
            }
            $ya = ProductSerie::where('product_id',$producto->id)->where('estado','disponible')->enBodega((int)$bodegaId,(int)$d['company_id'])->count();
            $caben = max(0, (int) floor($enBodega) - $ya);
            if (count($series) > $caben) {
                $nombre = Warehouse::whereKey($bodegaId)->value('nombre');
                $unidades = rtrim(rtrim(number_format($enBodega, 4, '.', ''), '0'), '.');
                return response()->json(['message'=>"El producto {$producto->codigo} tiene {$unidades} unidades en la bodega {$nombre} y ya tiene {$ya} series disponibles: solo puedes registrar {$caben} más."], 422);
            }
        }

        $creadas = [];
        foreach ($series as $serie) {
            $creadas[] = ProductSerie::create([
                'company_id'=>$d['company_id'],'product_id'=>$d['product_id'],'serie'=>$serie,
                'purchase_id'=>$d['purchase_id'] ?? null,'estado'=>'disponible','warehouse_id'=>$bodegaId,
            ]);
        }
        return response()->json($creadas, 201);
    }

    /**
     * Para el punto de venta: la serie exacta, si está disponible. Con `warehouse_id` (la bodega de la venta) avisa si está en otra.
     */
    public function lookup(Request $r) {
        $serie = trim($r->serie ?? '');
        $s = ProductSerie::with('product','warehouse:id,codigo,nombre')
            ->where('company_id',$r->company_id)->where('serie',$serie)
            ->where('estado','disponible')->first();
        if (! $s) return response()->json(['message'=>'Serie no encontrada o ya vendida'], 404);
        if ($r->warehouse_id && ($motivo = $s->motivoFueraDeBodega((int)$r->warehouse_id))) {
            return response()->json(['message'=>$motivo], 404);
        }
        $bodegaId = $s->bodegaEfectivaId();
        $s->setAttribute('bodega', $s->warehouse ?: ($bodegaId ? Warehouse::select('id','codigo','nombre')->find($bodegaId) : null));
        return $s;
    }

    /** Mensaje cuando se intenta sacar del inventario una serie disponible por esta pantalla: el stock no se movería. */
    private const BAJA_POR_AJUSTE = 'Para dar de baja una serie usa Ajuste de inventario.';

    public function update(Request $r, ProductSerie $serie) {
        $d = $r->validate([
            'product_id'=>['sometimes','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'estado'=>['sometimes','in:disponible,vendida,devuelto,danado'],
        ]);
        // Una serie DISPONIBLE es una unidad del stock: sacarla de ahí (dañada, vendida, devuelta) sin un movimiento dejaría el stock
        // y las series separados. Eso lo hace el Ajuste de inventario, que baja el stock y la serie juntos. Los demás estados no cambian.
        if ($serie->estado === 'disponible' && isset($d['estado']) && $d['estado'] !== 'disponible') {
            return response()->json(['message'=>self::BAJA_POR_AJUSTE], 422);
        }
        $serie->update($d);
        return $serie->load('product:id,codigo,descripcion');
    }
    public function destroy(ProductSerie $serie) {
        if ($serie->estado === 'vendida') {
            return response()->json(['message'=>'No se puede eliminar una serie vendida.'], 422);
        }
        // Lo mismo que al marcarla dañada: eliminar una disponible no baja el stock
        if ($serie->estado === 'disponible') {
            return response()->json(['message'=>self::BAJA_POR_AJUSTE], 422);
        }
        $serie->delete();
        return response()->noContent();
    }
    public function trace(Request $r) {
        $s = ProductSerie::with('product:id,codigo,descripcion',
                'purchase.contact:id,razon_social','invoice.contact:id,razon_social')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada'], 404);
    }

    /**
     * Búsqueda por un pedazo del número de serie (mínimo 3 caracteres, sin distinguir mayúsculas). Cada coincidencia trae el
     * producto, el estado, la bodega, la compra (y su proveedor), la venta (y su cliente) y la nota de crédito que la devolvió,
     * más el `historial` en orden: compra -> venta -> devolución / baja. Si una serie se vende más de una vez, el historial
     * cuenta el ciclo vigente (la última venta); lo anterior queda en el kárdex.
     */
    public function buscar(Request $r) {
        $mensaje = 'Escribe al menos 3 caracteres del número de serie.';
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'q'=>['required','string','min:3','max:100'],
        ], ['q.required'=>$mensaje, 'q.min'=>$mensaje]);
        if (mb_strlen(trim($d['q'])) < 3) {
            return response()->json(['message'=>$mensaje, 'errors'=>['q'=>[$mensaje]]], 422);
        }

        $series = ProductSerie::with('product:id,codigo,descripcion','warehouse:id,codigo,nombre',
                'purchase:id,numero,fecha_emision,contact_id','purchase.contact:id,razon_social',
                'invoice:id,numero,fecha_emision,contact_id','invoice.contact:id,razon_social',
                'creditNote:id,numero,fecha,invoice_id,motivo','creditNote.invoice:id,numero,fecha_emision,contact_id','creditNote.invoice.contact:id,razon_social')
            ->where('company_id',$d['company_id'])->serieContiene($d['q'])
            ->orderBy('serie')->limit(100)->get();

        $principal = Warehouse::select('id','codigo','nombre')->find(ProductSerie::bodegaPrincipalId((int)$d['company_id']));

        return $series->map(function (ProductSerie $s) use ($principal) {
            $compra = $s->purchase;
            $nota = $s->creditNote;
            $factura = $s->invoice ?? $nota?->invoice;   // devuelta: la venta se ve por la factura de la nota
            $bodega = $s->warehouse ?? $principal;

            $historial = [];
            $historial[] = $compra
                ? ['tipo'=>'compra','fecha'=>$compra->fecha_emision?->toDateString(),'documento'=>$compra->numero,'contraparte'=>$compra->contact?->razon_social,'detalle'=>'Compra al proveedor']
                : ['tipo'=>'compra','fecha'=>$s->created_at?->toDateString(),'documento'=>null,'contraparte'=>null,'detalle'=>'Ingreso sin compra (registro manual o ajuste de inventario)'];
            if ($factura) {
                $historial[] = ['tipo'=>'venta','fecha'=>$factura->fecha_emision?->toDateString(),'documento'=>$factura->numero,'contraparte'=>$factura->contact?->razon_social,'detalle'=>'Venta al cliente'];
            }
            if ($nota) {
                $historial[] = ['tipo'=>'devolucion','fecha'=>$nota->fecha?->toDateString(),'documento'=>$nota->numero ?? 'NC-'.$nota->id,'contraparte'=>$factura?->contact?->razon_social,'detalle'=>'Devuelta por nota de crédito: '.$nota->motivo];
            }
            if ($s->estado === 'danado') {
                $historial[] = ['tipo'=>'baja','fecha'=>$s->updated_at?->toDateString(),'documento'=>null,'contraparte'=>null,'detalle'=>'Dada de baja (dañada o faltante en un ajuste de inventario)'];
            }

            return [
                'id'=>$s->id,
                'serie'=>$s->serie,
                'estado'=>$s->estado,
                'producto'=>$s->product ? ['id'=>$s->product->id,'codigo'=>$s->product->codigo,'descripcion'=>$s->product->descripcion] : null,
                'bodega'=>$bodega ? ['id'=>$bodega->id,'codigo'=>$bodega->codigo,'nombre'=>$bodega->nombre,'inferida'=>! $s->warehouse_id] : null,
                'compra'=>$compra ? ['id'=>$compra->id,'numero'=>$compra->numero,'fecha'=>$compra->fecha_emision?->toDateString(),'proveedor'=>$compra->contact?->razon_social] : null,
                'venta'=>$factura ? ['id'=>$factura->id,'numero'=>$factura->numero,'fecha'=>$factura->fecha_emision?->toDateString(),'cliente'=>$factura->contact?->razon_social] : null,
                'devolucion'=>$nota ? ['id'=>$nota->id,'numero'=>$nota->numero ?? 'NC-'.$nota->id,'fecha'=>$nota->fecha?->toDateString()] : null,
                'historial'=>$historial,
            ];
        })->values();
    }

    /** Cuáles de estas series ya existen en la empresa (para avisar mientras se escriben, antes de guardar la compra). */
    public function existentes(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'series'=>['required','array','max:500'],
            'series.*'=>['nullable','string'],
        ]);
        $series = array_values(array_unique(SeriesDeCompra::limpiar($d['series'])));
        $encontradas = ProductSerie::with('product:id,codigo')->where('company_id',$d['company_id'])->whereIn('serie',$series)->get()->keyBy('serie');

        $existentes = [];
        foreach ($series as $serie) {
            if ($f = $encontradas->get($serie)) {
                $existentes[] = ['serie'=>$f->serie,'producto'=>$f->product?->codigo,'estado'=>$f->estado];
            }
        }
        return ['existentes'=>$existentes];
    }
}
