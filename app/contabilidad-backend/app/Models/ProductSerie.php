<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class ProductSerie extends Model {
    use Auditable;
    protected $table = 'product_series';
    protected $fillable = ['company_id','product_id','serie','estado','purchase_id','invoice_id','warehouse_id','credit_note_id'];
    public function product() { return $this->belongsTo(Product::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function creditNote() { return $this->belongsTo(CreditNote::class); }

    /**
     * Bodega principal de la empresa: la marcada por defecto (o la primera). Es la misma a la que va un movimiento de
     * inventario sin bodega, y la que se supone para una serie anterior sin bodega (warehouse_id NULL). Null si la empresa
     * no tiene bodegas.
     */
    public static function bodegaPrincipalId(int $companyId): ?int
    {
        return Warehouse::where('company_id', $companyId)->orderByDesc('por_defecto')->orderBy('id')->value('id');
    }

    /**
     * Series que están en la bodega dada. REGLA: una serie sin bodega (de antes de que existiera la columna) cuenta como de
     * la bodega principal de la empresa. Sin bodega pedida no se filtra.
     */
    public function scopeEnBodega($query, ?int $warehouseId, int $companyId)
    {
        if (! $warehouseId) {
            return $query;
        }
        $esLaPrincipal = $warehouseId === static::bodegaPrincipalId($companyId);

        return $query->where(function ($w) use ($warehouseId, $esLaPrincipal) {
            $w->where('product_series.warehouse_id', $warehouseId);
            if ($esLaPrincipal) {
                $w->orWhereNull('product_series.warehouse_id');
            }
        });
    }

    /** Series cuyo número contiene el texto (sin distinguir mayúsculas; % y _ se buscan tal cual, no como comodines). */
    public function scopeSerieContiene($query, string $texto)
    {
        $t = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($texto)));

        return $query->whereRaw("LOWER(product_series.serie) LIKE ? ESCAPE '!'", ['%'.$t.'%']);
    }

    /** Id de la bodega donde está la serie, ya aplicada la regla de las series sin bodega. */
    public function bodegaEfectivaId(): ?int
    {
        return $this->warehouse_id ?: static::bodegaPrincipalId((int) $this->company_id);
    }

    public function estaEnBodega(?int $warehouseId): bool
    {
        return ! $warehouseId || $this->bodegaEfectivaId() === (int) $warehouseId;
    }

    /** Motivo, en español, por el que la serie no puede salir de la bodega dada (null si sí puede). */
    public function motivoFueraDeBodega(?int $warehouseId): ?string
    {
        if ($this->estaEnBodega($warehouseId)) {
            return null;
        }
        $pedida = Warehouse::whereKey($warehouseId)->value('nombre') ?? '#'.$warehouseId;
        $actual = Warehouse::whereKey($this->bodegaEfectivaId())->value('nombre');

        return "La serie {$this->serie} no está en la bodega {$pedida}: está en la bodega ".($actual ?? 'sin identificar').'.';
    }
}
