<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class Product extends Model {
    use Auditable;
    protected $fillable = ['company_id','codigo','descripcion','tipo','imagen','precio','tarifa_iva','stock','costo_promedio','maneja_series','stock_minimo','stock_maximo','ubicacion','es_combo','unidad_base','unidad_fraccion','factor_conversion','reserva_stock'];
    public function series() { return $this->hasMany(ProductSerie::class); }
    public function components() { return $this->hasMany(ProductComponent::class); }
    public function priceLists() { return $this->hasMany(PriceList::class); }
    public function codes() { return $this->hasMany(ProductCode::class); }
}
