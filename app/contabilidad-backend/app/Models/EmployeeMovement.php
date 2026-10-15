<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento de un empleado que el rol de pagos toma solo (T7.1): anticipo, préstamo (a cuotas), descuento o ingreso extra.
 * `saldo` es lo que falta por descontar (o por pagar, en un ingreso extra); baja cuando el rol se CIERRA, no cuando solo se genera.
 */
class EmployeeMovement extends Model {
    use Auditable;

    public const TIPOS = ['anticipo', 'prestamo', 'descuento', 'ingreso_extra'];
    public const SUBTIPOS = ['horas_extra', 'comisiones', 'bono'];
    public const PENDIENTE = 'pendiente';
    public const APLICADO = 'aplicado';
    public const CANCELADO = 'cancelado';

    protected $fillable = ['company_id','employee_id','tipo','subtipo','concepto','fecha','aplicar_desde','monto','cuotas',
        'saldo','estado','entregado','forma_entrega','bank_id','documento','cancelado_at','motivo_cancelacion'];
    protected $casts = ['fecha'=>'date:Y-m-d','aplicar_desde'=>'date:Y-m-d','monto'=>'float','saldo'=>'float','cuotas'=>'integer',
        'entregado'=>'boolean','cancelado_at'=>'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }

    /** Lo que ya se descontó (o pagó): monto menos saldo. Un movimiento sin aplicar nunca tiene nada aplicado. */
    public function aplicado(): float { return round($this->monto - $this->saldo, 2); }

    /** ¿Alguna vez entró a un rol cerrado? Solo entonces deja de poder cancelarse. */
    public function tuvoAplicacion(): bool { return $this->aplicado() > 0.004 || $this->estado === self::APLICADO; }
}
