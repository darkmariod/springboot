<?php

namespace App\Http\Controllers;

use App\Models\Company;

class CompanyController extends Controller
{
    public function index()
    {
        return Company::orderBy('razon_social')->get();
    }

    public function show(Company $company)
    {
        return $company->load('emissionPoints');
    }

    public function uploadCertificate(\Illuminate\Http\Request $request, Company $company)
    {
        $request->validate([
            "certificado" => ["required", "file", "max:5120"],
            "clave" => ["required", "string"],
        ]);
        $contenido = file_get_contents($request->file("certificado")->getRealPath());
        if (! @openssl_pkcs12_read($contenido, $info, $request->input("clave"))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "clave" => ["El certificado no abre con esa clave, o el archivo no es un .p12 válido."],
            ]);
        }
        $company->update(["certificado_p12" => $contenido, "certificado_clave" => $request->input("clave")]);
        return ["ok" => true, "mensaje" => "Certificado cargado y validado. La facturación ya firma y envía al SRI."];
    }
}
