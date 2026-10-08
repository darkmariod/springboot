<?php

namespace App\Support;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Contra-asiento: anula un asiento SIN borrarlo, con otro que invierte Debe y Haber de cada línea.
 * El original queda en el libro diario como rastro de auditoría (igual que la anulación de una factura).
 */
final class ContraAsiento
{
    /** Reversa un asiento. El estado se copia del original: si está mayorizado, el contra también, para que el cuadre se mantenga. */
    public static function de(JournalEntry $original, string $concepto, string $referencia, ?Model $origen = null): JournalEntry
    {
        $companyId = $original->company_id;
        $reves = JournalEntry::create([
            'company_id' => $companyId,
            'numero' => 'AS-'.str_pad((string) (JournalEntry::where('company_id', $companyId)->count() + 1), 6, '0', STR_PAD_LEFT),
            'fecha' => now(),
            'concepto' => $concepto,
            'origen_type' => $origen ? $origen->getMorphClass() : $original->origen_type,
            'origen_id' => $origen ? $origen->getKey() : $original->origen_id,
            'total_debe' => 0,
            'total_haber' => 0,
            'estado' => $original->estado ?? 'pendiente',
        ]);

        $debe = 0;
        $haber = 0;
        foreach ($original->lines as $linea) {
            $reves->lines()->create([
                'account_id' => $linea->account_id,
                'debe' => $linea->haber,
                'haber' => $linea->debe,
                'referencia' => $referencia,
            ]);
            $debe += $linea->haber;
            $haber += $linea->debe;
        }
        $reves->update(['total_debe' => round($debe, 2), 'total_haber' => round($haber, 2)]);

        return $reves;
    }

    /**
     * Reversa los asientos que generó un documento (el que nació con él), sin tocar los contra-asientos
     * que ya tenga. Devuelve cuántos reversó.
     */
    public static function deDocumento(Model $documento, string $concepto, string $referencia): int
    {
        $originales = JournalEntry::where('company_id', $documento->company_id)
            ->where('origen_type', $documento->getMorphClass())->where('origen_id', $documento->getKey())
            ->where('concepto', 'not like', 'Reversión%')->orderBy('id')->get();

        foreach ($originales as $original) {
            self::de($original, $concepto, $referencia);
        }

        return $originales->count();
    }
}
