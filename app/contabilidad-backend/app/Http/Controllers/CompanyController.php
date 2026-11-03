<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\AuditLog;
use App\Support\NuevaEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CompanyController extends Controller
{
    public const SOLO_SUPERADMIN = 'Solo el administrador de la plataforma puede hacer esto.';

    /**
     * Las empresas que el usuario puede abrir: el superadministrador (y quien no tiene empresa) ve todas; el usuario de un cliente,
     * solo la suya. El selector de empresas del menú se llena con esta lista, así que a un cliente no le deja cambiar de empresa.
     */
    public function index(Request $r)
    {
        $u = $r->user();

        return Company::when($u?->estaConfinadoAEmpresa(), fn ($q) => $q->whereKey($u->company_id))
            ->orderBy('razon_social')->get();
    }

    /**
     * Alta de una empresa cliente con lo que necesita para trabajar: plan de cuentas, bodega, punto de emisión y su primer
     * administrador (T11.6c). Solo el superadministrador. No devuelve la contraseña.
     */
    public function store(Request $r, NuevaEmpresa $alta)
    {
        $this->exigirSuperadmin($r);
        $d = $r->validate(NuevaEmpresa::reglas(), NuevaEmpresa::mensajes());

        try {
            $resultado = $alta->crear($d);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
        $admin = $resultado['admin'];

        return response()->json([
            'ok' => true,
            'mensaje' => 'Empresa creada. Su administrador ya puede ingresar con el correo y la contraseña indicados.',
            'company' => $resultado['empresa'],
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'rol' => $admin->rol],
        ], 201);
    }

    /** El plan y su vencimiento los cambia solo el operador de la plataforma: de lo contrario un cliente se "mejoraría" el plan gratis. */
    private function exigirSuperadmin(Request $r): void
    {
        abort_unless($r->user()?->esSuperadmin(), 403, self::SOLO_SUPERADMIN);
    }

    public function show(Company $company)
    {
        return $company->load('emissionPoints');
    }

    /**
     * Editar los datos de la empresa.
     * OJO: el RUC debe ser el del titular del certificado .p12, si no el SRI rechaza la factura.
     */
    public function update(\Illuminate\Http\Request $request, Company $company)
    {
        $data = $request->validate([
            'ruc' => ['sometimes', 'string', 'size:13', Rule::unique('companies', 'ruc')->ignore($company->id)],
            'razon_social' => ['sometimes', 'string', 'max:300'],
            'nombre_comercial' => ['nullable', 'string', 'max:300'],
            'dir_matriz' => ['sometimes', 'string', 'max:300'],
            'estab' => ['sometimes', 'string', 'size:3'],
            'pto_emi' => ['sometimes', 'string', 'size:3'],
            'secuencial' => ['sometimes', 'integer', 'min:1'],
            'regimen' => ['nullable', 'string', 'max:100'],
            'obligado_contabilidad' => ['sometimes', 'boolean'],
            'ambiente' => ['sometimes', 'in:1,2'],
            'telefonos' => ['nullable','string','max:120'],
            'agente_retencion' => ['nullable','string','max:120'],
            'contribuyente_especial' => ['nullable','string','max:120'],
            'sitio_web' => ['nullable','string','max:160'],
            'nota_pie' => ['nullable','string','max:600'],
            'email_envio' => ['nullable', 'email'],
            'plan' => ['sometimes', 'in:basico,negocio,completo'],
            'plan_vence' => ['sometimes', 'nullable', 'date'],
        ]);

        // El plan y su vencimiento no los cambia el administrador de la empresa. Si su formulario los reenvía tal cual están no pasa
        // nada; si traen otro valor se le dice por qué no, y no se cambia nada.
        if (! $request->user()?->esSuperadmin()) {
            $errores = [];
            if (array_key_exists('plan', $data) && $data['plan'] !== $company->plan) {
                $errores['plan'] = ['Solo el administrador de la plataforma cambia el plan.'];
            }
            if (array_key_exists('plan_vence', $data)
                && ($data['plan_vence'] === null ? null : Carbon::parse($data['plan_vence'])->toDateString()) !== optional($company->plan_vence)->toDateString()) {
                $errores['plan_vence'] = ['Solo el administrador de la plataforma cambia el vencimiento del plan.'];
            }
            if ($errores) {
                throw ValidationException::withMessages($errores);
            }
            unset($data['plan'], $data['plan_vence']);
        }

        $company->update($data);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Empresa actualizada.',
            'company' => $company->fresh(),
        ]);
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
        $this->exigirSuperadmin($r);
        $d = $r->validate([
            'plan' => ['required', 'in:basico,negocio,completo'],
            'plan_vence' => ['nullable', 'date'],
        ]);
        $company->update($d);
        return $this->plan($company);
    }

    /** Sube el logo del cliente que se imprime en el RIDE. Se guarda como data URI. */
    public function logo(\Illuminate\Http\Request $r, Company $company) {
        $r->validate(['logo' => ['required','image','mimes:png,jpg,jpeg','max:1024']]);
        $f = $r->file('logo');
        $company->update([
            'logo' => 'data:'.$f->getMimeType().';base64,'.base64_encode(file_get_contents($f->getRealPath())),
        ]);
        AuditLog::create(['company_id'=>$company->id,'user_id'=>\Illuminate\Support\Facades\Auth::id(),
            'accion'=>'cambio_logo','modelo'=>'Company','modelo_id'=>$company->id,
            'descripcion'=>$company->razon_social,'ip'=>$r->ip()]);
        return response()->json(['ok' => true, 'mensaje' => 'Logo actualizado.', 'logo' => $company->logo]);
    }
}
