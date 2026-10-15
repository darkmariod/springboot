<?php

namespace App\Support;

/**
 * Piezas comunes de los comprobantes electrónicos que arman los controladores antes de llamar a la librería del SRI.
 */
class ComprobanteSri
{
    /** Forma de pago del sistema → código de la tabla 24 del SRI (los códigos del SRI pasan tal cual). */
    private const FORMA_PAGO = ['efectivo' => '01', 'transferencia' => '20', 'tarjeta' => '19', 'credito' => '20', 'cheque' => '20'];

    /**
     * "001-002-000000123" → ['001', '002', '000000123'], o null si no tiene ese formato.
     * Las notas de débito, las liquidaciones y las guías guardan su número al crearse (gastan ese secuencial en ese momento);
     * al emitirse tienen que salir con ese mismo número en la clave de acceso.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    public static function partesDelNumero(?string $numero): ?array
    {
        if (preg_match('/^(\d{3})-(\d{3})-(\d{9})$/', trim((string) $numero), $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2], $m[3]];
    }

    /**
     * `infoTributaria` mínimo que espera EmitirSriDocument. Con un número ya reservado fija establecimiento, punto de emisión y
     * secuencial; sin él, el secuencial sale del contador de la empresa y quien emite debe avanzarlo (ver `reservado()`).
     */
    public static function infoTributaria(string $codDoc, ?string $numeroReservado = null): array
    {
        $info = ['codDoc' => $codDoc];
        if ($partes = self::partesDelNumero($numeroReservado)) {
            [$info['estab'], $info['ptoEmi'], $info['secuencial']] = $partes;
        }

        return $info;
    }

    /** ¿Este número ya se reservó al guardar el documento (y por tanto emitir no debe gastar otro)? */
    public static function reservado(?string $numero): bool
    {
        return self::partesDelNumero($numero) !== null;
    }

    /**
     * Campos adicionales: la librería escribe teléfono y correo siempre, y el SRI rechaza un campo adicional vacío.
     * Sin dato va "N/A".
     */
    public static function infoAdicional(?string $email, ?string $telefono): array
    {
        return [
            'telefono' => trim((string) $telefono) !== '' ? trim((string) $telefono) : 'N/A',
            'email' => trim((string) $email) !== '' ? trim((string) $email) : 'N/A',
        ];
    }

    /** Código SRI de la forma de pago: "01" tal cual, o "efectivo"/"transferencia"/… traducido. Sin dato: 01. */
    public static function formaPago(?string $valor): string
    {
        $valor = strtolower(trim((string) $valor));
        if (preg_match('/^\d{2}$/', $valor) === 1) {
            return $valor;
        }

        return self::FORMA_PAGO[$valor] ?? '01';
    }
}
