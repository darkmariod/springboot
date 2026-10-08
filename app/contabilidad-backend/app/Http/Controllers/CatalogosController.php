<?php

namespace App\Http\Controllers;

use App\Support\ClaseContribuyente;
use App\Support\ComprobantesCompra;

class CatalogosController extends Controller
{
    public function formasPago()
    {
        return collect(config('formas_pago'))->map(fn($v, $k) => [
            'value' => $k,
            'label' => $v['label'],
            'sri' => $v['sri'],
            'pide_banco' => $v['pide_banco'] ?? false,
            'pide_documento' => $v['pide_documento'] ?? false,
            'pide_cuenta' => $v['pide_cuenta'] ?? false,
            'es_cruce' => $v['es_cruce'] ?? false,
        ])->values();
    }

    /** Clases de contribuyente que se pueden asignar a un cliente o proveedor. */
    public function clasesContribuyente()
    {
        return ClaseContribuyente::opciones();
    }

    public function sustentos()
    {
        // PHP vuelve enteros las claves como '10' o '11': se fuerza a texto para que el
        // selector las compare bien con el sustento guardado en la compra.
        return collect(config('sustentos'))->map(fn($v, $k) => [
            'value' => (string) $k,
            'label' => $k . ' — ' . $v,
        ])->values();
    }

    /** Códigos de retención del SRI: renta (formulario 103) e IVA (formulario 104), con su porcentaje. */
    public function retenciones()
    {
        return config('retenciones');
    }

    /** Tipos de comprobante que se pueden elegir al registrar una compra a mano. */
    public function tiposComprobanteCompra()
    {
        return ComprobantesCompra::opciones();
    }
}
