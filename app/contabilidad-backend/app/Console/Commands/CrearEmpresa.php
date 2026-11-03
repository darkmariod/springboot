<?php

namespace App\Console\Commands;

use App\Support\NuevaEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Da de alta una empresa cliente desde la consola (por ejemplo, desde el VPS): la misma lógica que `POST /api/companies`
 * (`NuevaEmpresa`): plan de cuentas, bodega principal, punto de emisión 001-001 y su primer administrador, todo o nada.
 *
 * php artisan empresa:crear --ruc=1790000000001 --razon="Mi Cliente S.A." --dir="Av. Principal 1" --plan=negocio \
 *     --vence=2027-10-30 --admin-nombre="Ana Pérez" --admin-email=ana@micliente.com
 *
 * Lo que falte se pregunta. La contraseña del administrador se pide SIEMPRE por teclado, sin mostrarla: nunca va en un
 * argumento (quedaría en el historial del terminal) y este comando jamás la imprime.
 */
class CrearEmpresa extends Command
{
    protected $signature = 'empresa:crear
        {--ruc= : RUC de 13 dígitos}
        {--razon= : Razón social}
        {--comercial= : Nombre comercial (opcional)}
        {--dir= : Dirección de la matriz}
        {--plan= : basico, negocio o completo (por defecto basico)}
        {--vence= : Vencimiento del plan, AAAA-MM-DD (por defecto, dentro de un año)}
        {--admin-nombre= : Nombre del primer administrador}
        {--admin-email= : Correo del primer administrador}';

    protected $description = 'Crea una empresa cliente con su plan de cuentas, bodega, punto de emisión y primer administrador';

    public function handle(NuevaEmpresa $alta): int
    {
        $datos = [
            'ruc' => $this->valor('ruc', 'RUC (13 dígitos)'),
            'razon_social' => $this->valor('razon', 'Razón social'),
            'nombre_comercial' => $this->option('comercial') ?: null,
            'dir_matriz' => $this->valor('dir', 'Dirección de la matriz'),
            'plan' => $this->option('plan') ?: null,
            'plan_vence' => $this->option('vence') ?: null,
            'admin_nombre' => $this->valor('admin-nombre', 'Nombre del administrador'),
            'admin_email' => $this->valor('admin-email', 'Correo del administrador'),
        ];

        // Ya con los datos de la empresa válidos se pide la contraseña: así no se teclea para nada si algo de lo anterior falla
        $primero = Validator::make($datos, array_diff_key(NuevaEmpresa::reglas(), array_flip(['admin_password', 'admin_password_confirmation'])), NuevaEmpresa::mensajes());
        if ($primero->fails()) {
            return $this->rechazar($primero->errors()->all());
        }

        if (! $this->input->isInteractive()) {
            $this->error('  La contraseña del administrador se pide por teclado; no se puede dar como argumento. Quita --no-interaction.');

            return self::FAILURE;
        }
        $clave = (string) $this->secret('Contraseña del administrador (mínimo '.NuevaEmpresa::CLAVE_MINIMA.' caracteres; no se muestra)');
        $otra = (string) $this->secret('Repite la contraseña');
        $datos['admin_password'] = $clave;
        $datos['admin_password_confirmation'] = $otra;

        $validador = Validator::make($datos, NuevaEmpresa::reglas(), NuevaEmpresa::mensajes());
        if ($validador->fails()) {
            return $this->rechazar($validador->errors()->all());
        }

        try {
            $resultado = $alta->crear($datos);
        } catch (RuntimeException $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $empresa = $resultado['empresa'];
        $this->newLine();
        $this->info('  Empresa creada: '.$empresa->razon_social.' (id '.$empresa->id.')');
        $this->table(['Concepto', 'Valor'], [
            ['RUC', $empresa->ruc],
            ['Plan', $empresa->plan],
            ['Vence', optional($empresa->plan_vence)->format('Y-m-d')],
            ['Ambiente del SRI', (int) $empresa->ambiente === 1 ? 'pruebas' : 'producción'],
            ['Administrador', $resultado['admin']->email],
            ['Contraseña', 'la que escribiste (no se muestra)'],
        ]);
        $this->line('  Siguiente paso: que el administrador ingrese, cargue su firma (.p12) y revise el punto de emisión.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function valor(string $opcion, string $pregunta): ?string
    {
        $dado = $this->option($opcion);
        if ($dado !== null && $dado !== '') {
            return (string) $dado;
        }

        return $this->input->isInteractive() ? ((string) $this->ask($pregunta) ?: null) : null;
    }

    private function rechazar(array $errores): int
    {
        $this->error('  No se creó nada:');
        foreach ($errores as $e) {
            $this->line('    - '.$e);
        }

        return self::FAILURE;
    }
}
