<?php

namespace App\Support;

use App\Models\JournalEntry;

/**
 * Número del siguiente asiento de una empresa: "AS-000123".
 * Se toma del MAYOR número usado + 1 y no de cuántos asientos hay: al eliminar un asiento manual,
 * "cuántos hay + 1" repetiría un número que ya existe.
 */
final class NumeroAsiento
{
    public static function siguiente(int $companyId): string
    {
        // Los números van rellenos con ceros: el más largo y, entre iguales, el mayor texto es el mayor número
        $ultimo = JournalEntry::where('company_id', $companyId)->where('numero', 'like', 'AS-%')
            ->orderByRaw('LENGTH(numero) DESC')->orderByDesc('numero')->value('numero');
        $n = $ultimo !== null && preg_match('/^AS-(\d+)$/', $ultimo, $m) ? (int) $m[1] : 0;

        do {
            $numero = 'AS-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT);
        } while (JournalEntry::where('company_id', $companyId)->where('numero', $numero)->exists());

        return $numero;
    }
}
