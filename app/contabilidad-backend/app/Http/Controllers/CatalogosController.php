<?php

namespace App\Http\Controllers;

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
        ])->values();
    }

    public function sustentos()
    {
        return collect(config('sustentos'))->map(fn($v, $k) => [
            'value' => $k,
            'label' => $k . ' — ' . $v,
        ])->values();
    }
}
