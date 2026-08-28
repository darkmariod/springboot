<?php
namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Un cobro con tarjeta. Nace pendiente hasta que el procesador lo deposita. */
class CardTransaction extends Model
{
    use Auditable;

    protected $fillable = ['company_id', 'invoice_id', 'card_settlement_id',
        'fecha', 'tarjeta', 'voucher', 'monto'];

    protected $casts = ['fecha' => 'date', 'monto' => 'decimal:2'];

    public function invoice()    { return $this->belongsTo(Invoice::class); }
    public function settlement() { return $this->belongsTo(CardSettlement::class, 'card_settlement_id'); }

    public function scopePendientes($q) { return $q->whereNull('card_settlement_id'); }
}
