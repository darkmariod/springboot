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
            "email_envio" => ["nullable", "email"],
            "ambiente" => ["nullable", "in:1,2"],
        ]);
        $contenido = file_get_contents($request->file("certificado")->getRealPath());
        if (! @openssl_pkcs12_read($contenido, $info, $request->input("clave"))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "clave" => ["El certificado no abre con esa clave, o el archivo no es un .p12 válido."],
            ]);
        }
        // Datos del certificado para mostrar titular y vencimiento en la pantalla
        $cert = openssl_x509_parse($info["cert"] ?? "");
        $company->update([
            "certificado_p12" => $contenido,
            "certificado_clave" => $request->input("clave"),
            "email_envio" => $request->input("email_envio") ?: $company->email_envio,
            "ambiente" => $request->input("ambiente") ?: $company->ambiente,
            "cert_sujeto" => $cert["subject"]["CN"] ?? null,
            "cert_valido_hasta" => isset($cert["validTo_time_t"]) ? date("Y-m-d", $cert["validTo_time_t"]) : null,
        ]);
        return ["ok" => true, "mensaje" => "Firma cargada y validada. La facturación ya firma y envía al SRI."];
    }
    public function plan(Company $company) {
        return [
            'plan' => $company->plan,
            'nombre' => config('planes.'.$company->plan.'.nombre'),
            'precio_anual' => config('planes.'.$company->plan.'.precio_anual'),
            'vence' => optional($company->plan_vence)->format('Y-m-d'),
            'vencido' => $company->planVencido(),
            'features' => $company->features(),
        ];
    }
    public function cambiarPlan(\Illuminate\Http\Request $r, Company $company) {
        $d = $r->validate([
            'plan' => ['required', 'in:basico,pro,corporativo'],
            'plan_vence' => ['nullable', 'date'],
        ]);
        $company->update($d);
        return $this->plan($company);
    }
}
