<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

/** Un mes ya depreciado de un activo fijo (T7.2): el monto, lo acumulado después de ese mes y el asiento del mes. */
class FixedAssetDepreciation extends Model {
    protected $fillable = ['fixed_asset_id','company_id','anio','mes','monto','depreciacion_acumulada_despues','journal_entry_id'];
    protected $casts = ['anio'=>'integer','mes'=>'integer','monto'=>'float','depreciacion_acumulada_despues'=>'float'];

    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function asiento() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
