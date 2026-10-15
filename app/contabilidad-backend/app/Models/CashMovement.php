<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento de la caja diaria. Los de T5.3 llevan número (CI- ingreso, CE- egreso), la cuenta de contrapartida y su asiento
 * (el asiento nace del propio movimiento). Los de antes quedan sin número ni asiento. La salida que deja un depósito a banco
 * lleva el número del depósito (DP-) y el depósito como origen: su asiento es el del depósito, no se asienta dos veces.
 */
class CashMovement extends Model {
    use Auditable;
    protected $fillable = ['cash_session_id','tipo','monto','concepto','company_id','numero','fecha','cuenta_contrapartida_id','origen_type','origen_id'];
    protected $casts = ['fecha' => 'date:Y-m-d'];

    public function session() { return $this->belongsTo(CashSession::class, 'cash_session_id'); }
    public function cuenta() { return $this->belongsTo(Account::class, 'cuenta_contrapartida_id'); }
    public function origen() { return $this->morphTo(); }

    /** Empresa del movimiento: la propia o, en los de antes, la de su sesión de caja. */
    public function empresaId(): ?int {
        return $this->company_id ? (int) $this->company_id : ($this->session ? (int) $this->session->company_id : null);
    }
}
