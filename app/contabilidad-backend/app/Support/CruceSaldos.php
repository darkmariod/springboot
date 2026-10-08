<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSplit;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Services\SimpleEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cruce de saldos como forma de cobro y de pago.
 *
 * Un cruce cancela una factura abierta de un contacto contra una compra abierta del MISMO contacto
 * (un contacto puede ser cliente y proveedor a la vez). Los dos saldos bajan en el monto cruzado y el
 * asiento es Debe CxP / Haber CxC, cada una en la cuenta con que nació su documento (normal o de
 * partes relacionadas). No mueve caja ni bancos. Queda el rastro de pagos en las dos puntas
 * (InvoicePayment y PurchasePayment con forma cruce_saldos).
 *
 * Todo debe correr dentro de la transacción del cobro o pago que lo invoca: cualquier 422 la deshace.
 */
final class CruceSaldos
{
    public const LADO_COBRO = 'cobro'; // se cobra una factura: se cruza contra compras del contacto
    public const LADO_PAGO = 'pago';   // se paga una compra: se cruza contra facturas del contacto

    /** ¿Esta forma de pago es un cruce de saldos? (marca `es_cruce` de config/formas_pago.php) */
    public static function esCruce(string $tipo): bool
    {
        return (bool) config("formas_pago.$tipo.es_cruce", false);
    }

    /**
     * Documentos abiertos del contacto contra los que se puede cruzar.
     * lado cobro → compras con saldo; lado pago → facturas con saldo (sin anuladas).
     *
     * @return Collection<int,array{id:int,numero:string,fecha:?string,saldo:float}>
     */
    public static function documentos(int $companyId, int $contactId, string $lado): Collection
    {
        $abiertos = $lado === self::LADO_COBRO
            ? Purchase::query()
            : Invoice::query()->where('estado', '!=', 'anulado');

        return $abiertos->where('company_id', $companyId)->where('contact_id', $contactId)
            ->where('saldo_pendiente', '>', 0)->orderBy('fecha_emision')->orderBy('id')->get()
            ->map(fn (Model $d) => [
                'id' => $d->id,
                'numero' => $d->numero,
                'fecha' => optional($d->fecha_emision)->format('Y-m-d'),
                'saldo' => round((float) $d->saldo_pendiente, 2),
            ]);
    }

    /** Cobra (en parte o todo) una factura contra una compra del mismo cliente. */
    public static function cobrar(Invoice $factura, mixed $compraId, float $monto): void
    {
        $compra = self::contraparte(Purchase::class, $compraId, $factura->company_id, 'una compra del mismo cliente');
        self::aplicar($factura, $compra, $monto, $factura);
    }

    /** Paga (en parte o todo) una compra contra una factura del mismo proveedor. */
    public static function pagar(Purchase $compra, mixed $facturaId, float $monto): void
    {
        $factura = self::contraparte(Invoice::class, $facturaId, $compra->company_id, 'una factura del mismo proveedor');
        self::aplicar($factura, $compra, $monto, $compra);
    }

    /** El documento contrario, de la misma empresa y con el saldo al día; 422 si no se indicó o no existe. $ejemplo describe qué se puede elegir. */
    private static function contraparte(string $modelo, mixed $id, int $companyId, string $ejemplo): Model
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            self::falla("Elige el documento contra el que se cruza el saldo ($ejemplo).");
        }
        $doc = $modelo::where('company_id', $companyId)->whereKey((int) $id)->lockForUpdate()->first();
        if (! $doc) {
            self::falla('No se encontró el documento elegido para el cruce en esta empresa.');
        }

        return $doc;
    }

    private static function aplicar(Invoice $factura, Purchase $compra, float $monto, Model $origen): void
    {
        $monto = round($monto, 2);

        if ((int) $factura->company_id !== (int) $compra->company_id || ! $factura->contact_id || (int) $factura->contact_id !== (int) $compra->contact_id) {
            self::falla('El cruce solo se puede hacer entre documentos del mismo cliente o proveedor.');
        }
        if ($factura->estado === 'anulado') {
            self::falla("La factura {$factura->numero} está anulada y no se puede cruzar.");
        }
        if ($monto <= 0) {
            self::falla('El valor del cruce debe ser mayor que cero.');
        }
        // La factura que se manda en memoria ya refleja los cruces anteriores de esta misma operación
        foreach ([[$factura, 'La factura'], [$compra, 'La compra']] as [$doc, $nombre]) {
            $saldo = round((float) $doc->saldo_pendiente, 2);
            if ($monto > $saldo + 0.004) {
                self::falla(sprintf('El cruce ($%s) supera el saldo de %s %s ($%s).',
                    number_format($monto, 2, '.', ''), mb_strtolower($nombre), $doc->numero, number_format($saldo, 2, '.', '')));
            }
        }

        $hoy = now()->toDateString();
        $factura->decrement('saldo_pendiente', $monto);
        $compra->decrement('saldo_pendiente', $monto);

        InvoicePayment::create([
            'invoice_id' => $factura->id, 'fecha' => $hoy, 'monto' => $monto,
            'forma_pago' => 'cruce_saldos', 'nota' => 'Cruce con compra '.$compra->numero,
        ]);
        PurchasePayment::create([
            'purchase_id' => $compra->id, 'fecha' => $hoy, 'monto' => $monto, 'forma_pago' => 'cruce_saldos',
        ]);
        foreach ([[$factura, $compra], [$compra, $factura]] as [$propio, $contrario]) {
            PaymentSplit::create([
                'company_id' => $propio->company_id, 'pagable_type' => $propio->getMorphClass(), 'pagable_id' => $propio->getKey(),
                'tipo' => 'cruce_saldos', 'fecha' => $hoy, 'valor' => $monto,
                'documento' => $contrario->numero, 'detalle' => 'Cruce de saldos',
            ]);
        }

        // Debe CxP (donde nació la compra) / Haber CxC (donde nació la factura). Sin caja ni bancos.
        SimpleEntry::make($factura->company_id, 'Cruce de saldos '.$factura->numero.' / '.$compra->numero, [
            Cuentas::linea(Cuentas::cxpDe($compra), $monto, 0, $compra->numero),
            Cuentas::linea(Cuentas::cxcDe($factura), 0, $monto, $factura->numero),
        ], $origen);
    }

    private static function falla(string $mensaje): never
    {
        throw ValidationException::withMessages(['pagos' => [$mensaje]]);
    }
}
