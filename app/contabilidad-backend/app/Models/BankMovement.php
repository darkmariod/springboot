<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del estado de cuenta de un banco ('credito' = entra dinero, 'debito' = sale).
 *  - Los de pagos y cobros por banco llevan su origen (el documento) y ningún asiento propio: el asiento del pago ya pasa por Bancos.
 *  - clase 'manual': escrito a mano con su asiento (MB-). 'deposito': depósito de caja a banco (DP-). 'liquidacion_tarjeta':
 *    lo que acredita el procesador de tarjetas (LQ-). 'sin_asiento': escrito a mano y marcado como ya contabilizado.
 *  - forma 'cheque' con fecha_cobro futura = cheque posfechado: su asiento se registra en la fecha de emisión, pero no
 *    cuenta en el saldo del banco ni se concilia antes de esa fecha.
 */
class BankMovement extends Model {
    use Auditable;
    public const MANUAL = 'manual';
    public const DEPOSITO = 'deposito';
    public const LIQUIDACION_TARJETA = 'liquidacion_tarjeta';
    public const SIN_ASIENTO = 'sin_asiento';

    protected $fillable = ['company_id','bank_id','fecha','tipo','monto','concepto','conciliado','documento','origen_type','origen_id',
        'numero','clase','forma','fecha_cobro','cuenta_contrapartida_id'];
    protected $casts = ['conciliado'=>'boolean','fecha_cobro'=>'date:Y-m-d'];
    /** El documento que originó el movimiento (compra, factura, anticipo, liquidación...); vacío en los movimientos manuales. */
    public function origen() { return $this->morphTo(); }
    public function banco() { return $this->belongsTo(Bank::class, 'bank_id'); }
    public function cuenta() { return $this->belongsTo(Account::class, 'cuenta_contrapartida_id'); }

    /** Cheque con fecha de cobro todavía por llegar. */
    public function esPosfechado(?string $hoy = null): bool {
        return $this->forma === 'cheque' && $this->fecha_cobro !== null
            && $this->fecha_cobro->toDateString() > ($hoy ?? now()->toDateString());
    }

    /** Movimientos que ya cuentan en el banco: todo menos los cheques posfechados. */
    public function scopeVigentes(Builder $q, ?string $hoy = null): Builder {
        $hoy ??= now()->toDateString();
        return $q->where(fn ($w) => $w->whereNull('fecha_cobro')->orWhereDate('fecha_cobro', '<=', $hoy));
    }
}
