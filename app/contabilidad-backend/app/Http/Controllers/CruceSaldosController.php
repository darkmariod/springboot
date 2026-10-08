<?php

namespace App\Http\Controllers;

use App\Support\CruceSaldos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CruceSaldosController extends Controller
{
    /**
     * Documentos abiertos del contacto contra los que se puede cruzar un saldo.
     * lado=cobro (cobrando una factura): compras con saldo · lado=pago (pagando una compra): facturas con saldo.
     */
    public function documentos(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'contact_id' => ['required', 'exists:contacts,id'],
            'lado' => ['required', Rule::in([CruceSaldos::LADO_COBRO, CruceSaldos::LADO_PAGO])],
        ], [
            'lado.in' => 'El lado del cruce debe ser "cobro" o "pago".',
        ]);

        return CruceSaldos::documentos((int) $d['company_id'], (int) $d['contact_id'], $d['lado'])->values();
    }
}
