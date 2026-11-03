<?php

namespace App\Support;

use App\Models\Company;
use App\Models\EmissionPoint;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Alta de una empresa cliente (T11.6c): lo que necesita para trabajar el primer día, en UNA transacción.
 *
 *   - la empresa (plan, vencimiento y ambiente de PRUEBAS del SRI),
 *   - su plan de cuentas (`Cuentas::sembrar`) y su bodega principal (`Warehouse::crearPrincipal`),
 *   - el punto de emisión 001-001,
 *   - y su PRIMER administrador (`rol = admin`, activo, de esa empresa y nunca superadministrador).
 *
 * Lo usan el endpoint `POST /api/companies` (solo superadmin) y el comando `php artisan empresa:crear`: una sola lógica.
 * Si algo falla a medias no queda nada. La contraseña del administrador nunca sale de aquí: ni en la respuesta, ni en el log,
 * ni en el mensaje de una excepción (una `QueryException` trae la consulta con sus valores, por eso aquí no se propaga).
 */
class NuevaEmpresa
{
    /** Largo mínimo de la contraseña del primer administrador. */
    public const CLAVE_MINIMA = 10;

    /** Un plan es anual: si no se indica el vencimiento, vence a los 365 días. */
    public const VIGENCIA_DIAS = 365;

    public const PLAN_INICIAL = 'basico';

    public const MENSAJE_FALLO = 'No se pudo crear la empresa. No se guardó nada.';

    /** Reglas de validación de la petición y del comando. */
    public static function reglas(): array
    {
        return [
            'ruc' => ['required', 'string', 'regex:/^\d{13}$/', 'unique:companies,ruc'],
            'razon_social' => ['required', 'string', 'max:300'],
            'nombre_comercial' => ['nullable', 'string', 'max:300'],
            'dir_matriz' => ['required', 'string', 'max:300'],
            'plan' => ['nullable', Rule::in(array_keys(config('planes', [])))],
            'plan_vence' => ['nullable', 'date'],
            'ambiente' => ['nullable', 'in:1,2'],
            'admin_nombre' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:'.self::CLAVE_MINIMA, 'max:200'],
            'admin_password_confirmation' => ['sometimes', 'same:admin_password'],
        ];
    }

    public static function mensajes(): array
    {
        return [
            'ruc.required' => 'Escribe el RUC.',
            'ruc.regex' => 'El RUC debe tener exactamente 13 dígitos.',
            'ruc.unique' => 'Ya existe una empresa con ese RUC.',
            'razon_social.required' => 'Escribe la razón social.',
            'dir_matriz.required' => 'Escribe la dirección de la matriz.',
            'plan.in' => 'El plan debe ser básico, negocio o completo.',
            'plan_vence.date' => 'La fecha de vencimiento no es válida.',
            'admin_nombre.required' => 'Escribe el nombre del administrador.',
            'admin_email.required' => 'Escribe el correo del administrador.',
            'admin_email.email' => 'El correo del administrador no es válido.',
            'admin_email.unique' => 'Ya existe un usuario con ese correo.',
            'admin_password.required' => 'Escribe la contraseña del administrador.',
            'admin_password.min' => 'La contraseña debe tener al menos '.self::CLAVE_MINIMA.' caracteres.',
            'admin_password_confirmation.same' => 'Las dos contraseñas no coinciden.',
        ];
    }

    /**
     * Crea la empresa y su administrador. $d ya viene validado con `reglas()`.
     *
     * @return array{empresa: Company, admin: User}
     *
     * @throws RuntimeException con un mensaje fijo si algo falla; no queda nada creado
     */
    public function crear(array $d): array
    {
        try {
            return DB::transaction(function () use ($d) {
                $empresa = Company::create([
                    'ruc' => $d['ruc'],
                    'razon_social' => $d['razon_social'],
                    'nombre_comercial' => $d['nombre_comercial'] ?? null,
                    'dir_matriz' => $d['dir_matriz'],
                    'estab' => '001', 'pto_emi' => '001', 'secuencial' => 1,
                    'ambiente' => (int) ($d['ambiente'] ?? 1),   // 1 = pruebas del SRI: producción se activa con la firma cargada
                    'plan' => $d['plan'] ?? self::PLAN_INICIAL,
                    'plan_vence' => ! empty($d['plan_vence']) ? Carbon::parse($d['plan_vence'])->toDateString()
                        : now()->addDays(self::VIGENCIA_DIAS)->toDateString(),
                ]);

                // El mismo plan de cuentas, bodega y punto de emisión que le pone el DatabaseSeeder a la primera empresa
                Cuentas::sembrar($empresa->id);
                Warehouse::crearPrincipal($empresa);
                EmissionPoint::create([
                    'company_id' => $empresa->id, 'estab' => '001', 'punto' => '001', 'nombre' => 'Caja principal', 'secuencial' => 1,
                ]);

                // `es_superadmin` no es asignable en masa: se fija a propósito. La contraseña se cifra por el cast `hashed`.
                $admin = new User;
                $admin->forceFill([
                    'name' => $d['admin_nombre'], 'email' => $d['admin_email'], 'password' => $d['admin_password'],
                    'company_id' => $empresa->id, 'rol' => 'admin', 'activo' => true, 'es_superadmin' => false,
                ])->save();

                return ['empresa' => $empresa->fresh(), 'admin' => $admin->fresh()];
            });
        } catch (Throwable $e) {
            // Sin `$e` como causa: su mensaje y el de su cadena pueden traer la consulta con los valores (la clave ya cifrada)
            Log::error('Alta de empresa fallida', [
                'excepcion' => $e::class,
                'en' => basename($e->getFile()).':'.$e->getLine(),
                'detalle' => $e instanceof QueryException ? ($e->errorInfo[2] ?? null) : null,
                'ruc' => $d['ruc'] ?? null,
            ]);

            throw new RuntimeException(self::MENSAJE_FALLO);
        }
    }
}
