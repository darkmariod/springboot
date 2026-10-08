<?php

namespace App\Support;

use App\Models\SriDocument;

/**
 * Qué debe hacer el usuario ante el SRI después de anular un documento en el sistema.
 * Anular aquí NUNCA anula el comprobante en el SRI: solo el portal SRI en línea lo hace. Por eso cada respuesta de anulación
 * trae este resumen y la pantalla no tiene que adivinar:
 *   requiere_anulacion_sri  true solo si el SRI lo tiene AUTORIZADO (seguirá siendo válido allá hasta anularlo en el portal);
 *   sri_estado              el estado del comprobante electrónico en minúsculas, o null si nunca tuvo (nota interna, sin emitir);
 *   aviso_sri               el mensaje para mostrar, en español neutro.
 */
class AnulacionSri
{
    /**
     * @param string $documento  cómo se nombra al documento con su artículo: 'La factura', 'La nota de crédito', 'La nota de débito'
     * @param bool   $interna    documento interno: nunca va al SRI
     */
    public static function resumen(string $documento, ?string $numero, ?SriDocument $sri, bool $interna = false): array
    {
        $nombre = trim($documento.' '.($numero ?? ''));
        $estado = $sri ? strtolower(trim((string) $sri->estado)) : null;

        if ($sri && SriDocument::esAutorizado($sri->estado)) {
            return [
                'requiere_anulacion_sri' => true,
                'sri_estado' => $estado,
                'aviso_sri' => "$nombre quedó anulada en el sistema, pero el SRI la tiene autorizada. Para que también quede anulada ante el SRI, "
                    .'debes anularla en el portal SRI en línea; mientras no lo hagas, seguirá siendo válida para el SRI.',
            ];
        }
        if ($sri && $estado === 'enviado') {
            return [
                'requiere_anulacion_sri' => false,
                'sri_estado' => $estado,
                'aviso_sri' => "$nombre quedó anulada en el sistema. Ya se envió al SRI y todavía no responde la autorización: "
                    .'si el SRI la autoriza más adelante, deberás anularla también en el portal SRI en línea.',
            ];
        }

        return [
            'requiere_anulacion_sri' => false,
            'sri_estado' => $estado,
            'aviso_sri' => "$nombre quedó anulada en el sistema. Como "
                .($interna ? 'es un documento interno que nunca se envía al SRI' : 'nunca fue autorizada por el SRI')
                .', no hace falta ninguna acción en el SRI.',
        ];
    }
}
