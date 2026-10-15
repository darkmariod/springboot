<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use App\Support\NumeroAsiento;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model {
    use Auditable;

    /** Guardado sin contar en ningún reporte (libro mayor, estados financieros, chequeo). Solo lo usan los asientos manuales. */
    public const BORRADOR = 'borrador';
    /** Cuenta en los reportes y se puede editar. Es el estado con que nacen los asientos que genera un documento. */
    public const PENDIENTE = 'pendiente';
    /** Cuenta en los reportes y no se edita hasta desmayorizarlo. */
    public const MAYORIZADO = 'mayorizado';

    protected $fillable = ['company_id','numero','fecha','concepto','origen_type','origen_id','total_debe','total_haber','estado',
        'manual','editado_manualmente','editado_at','editado_por','fue_mayorizado'];
    // es_cierre no es asignable en masa: solo lo marca el cierre del ejercicio (PeriodosContables::cerrarEjercicio)
    protected $casts = ['fecha'=>'date','manual'=>'boolean','editado_manualmente'=>'boolean','fue_mayorizado'=>'boolean','editado_at'=>'datetime','es_cierre'=>'boolean'];

    protected static function booted(): void {
        // Los documentos numeran con "cuántos hay + 1", que choca con otro número cuando se elimina un asiento manual:
        // si el número ya está en uso en la empresa, el asiento toma el siguiente libre.
        static::creating(function (JournalEntry $e) {
            if (! $e->company_id) return;
            $repetido = static::where('company_id', $e->company_id)->where('numero', $e->numero)->exists();
            if (! $e->numero || $repetido) {
                $e->numero = NumeroAsiento::siguiente((int) $e->company_id);
            }
        });

        // Períodos contables (T4.3a): ningún asiento nace, cambia ni se borra en un mes cerrado. Esta es la única guarda:
        // las facturas, compras, cobros y notas crean sus asientos dentro de su propia transacción, así que el 422
        // deshace el documento entero. El asiento de cierre del ejercicio (31/12) es la única excepción.
        static::creating(function (JournalEntry $e) {
            if (! $e->es_cierre) {
                AccountingPeriod::exigirAbierto((int) $e->company_id, $e->fecha);
            }
        });
        static::updating(function (JournalEntry $e) {
            // Un asiento que está en un mes cerrado no cambia; y tampoco se le cambia la fecha hacia uno cerrado
            AccountingPeriod::exigirAbierto((int) $e->company_id, $e->getOriginal('fecha'));
            if ($e->isDirty('fecha')) {
                AccountingPeriod::exigirAbierto((int) $e->company_id, $e->fecha);
            }
        });
        static::deleting(function (JournalEntry $e) {
            // La fecha guardada, no una que se haya cambiado en memoria sin guardar
            AccountingPeriod::exigirAbierto((int) $e->company_id, $e->getOriginal('fecha'));
        });
    }

    public function lines() { return $this->hasMany(JournalEntryLine::class); }

    /** Contra-asiento que generó una anulación (concepto "Reversión …"). Un asiento manual nunca lo es: su concepto lo escribe una persona. */
    public function esContraAsiento(): bool {
        return ! $this->manual && str_starts_with((string) $this->concepto, 'Reversión');
    }
}
