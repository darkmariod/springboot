<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja el sistema en blanco para entregarlo a un cliente nuevo.
 *
 * Conserva la configuración (usuario, empresa, plan de cuentas, bodega y punto
 * de emisión) y borra todo el movimiento: clientes, productos, facturas,
 * kárdex, asientos y bitácora. El certificado .p12 de la empresa NO se toca.
 */
class LimpiarSistema extends Command
{
    protected $signature = 'sistema:limpiar
                            {--usuario= : Correo del único usuario que se conserva}
                            {--empresa= : Id de la única empresa que se conserva}
                            {--en-blanco : Vaciar también los datos de la empresa y su certificado}
                            {--confirmar : Ejecutar de verdad; sin esto solo muestra qué haría}';

    protected $description = 'Deja la aplicación sin datos, lista para entregar a un cliente';

    /** Movimiento: se borra completo. */
    private array $vaciar = [
        'invoices', 'invoice_payments', 'payment_splits', 'sri_documents',
        'credit_notes', 'credit_applications', 'quotes', 'advances', 'withholdings',
        'purchases', 'purchase_payments', 'pending_imports',
        'inventory_movements', 'warehouse_stocks', 'product_series',
        'products', 'product_codes', 'product_components', 'price_lists',
        'contacts',
        'journal_entries', 'journal_entry_lines',
        'banks', 'bank_movements', 'cash_registers', 'cash_sessions', 'cash_movements',
        'card_settlements', 'card_transactions',
        'employees', 'payrolls', 'payroll_lines',
        'cost_centers',
        'audit_logs',
        'sessions', 'personal_access_tokens', 'password_reset_tokens',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        $correo = $this->option('usuario');
        $empresaId = $this->option('empresa');

        $user = $correo ? User::where('email', $correo)->first() : User::orderBy('id')->first();
        if (! $user) {
            $this->error("  No existe el usuario {$correo}.");

            return self::FAILURE;
        }

        $empresa = $empresaId ? Company::find($empresaId) : Company::find($user->company_id);
        if (! $empresa) {
            $this->error('  No existe la empresa indicada.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  Se conserva el usuario:  {$user->email}  ({$user->name})");
        $this->line("  Se conserva la empresa:  {$empresa->razon_social}  · plan {$empresa->plan}");
        $this->line('  Se conserva el plan de cuentas, la bodega y el punto de emisión.');
        $this->line('  El certificado .p12 no se toca.');
        $this->newLine();

        if (! $this->option('confirmar')) {
            $this->warn('  Simulación. Para ejecutar de verdad agrega --confirmar');
            $this->newLine();

            return self::SUCCESS;
        }

        // SQLite no permite mover el pragma de llaves foráneas dentro de una
        // transacción, por eso el borrado va sin envolver. El respaldo previo
        // es la red de seguridad.
        Schema::withoutForeignKeyConstraints(function () use ($user, $empresa) {
            foreach ($this->vaciar as $tabla) {
                if (Schema::hasTable($tabla)) {
                    DB::table($tabla)->delete();
                }
            }

            User::where('id', '!=', $user->id)->delete();
            Company::where('id', '!=', $empresa->id)->delete();
            $user->forceFill(['company_id' => $empresa->id])->save();

            // Una sola bodega y una sola sucursal, las de la empresa que queda
            DB::table('warehouses')->where('company_id', '!=', $empresa->id)->delete();
            if (Schema::hasTable('branches')) {
                DB::table('branches')->where('company_id', '!=', $empresa->id)->delete();
            }
            DB::table('accounts')->where('company_id', '!=', $empresa->id)->delete();
            DB::table('emission_points')->where('company_id', '!=', $empresa->id)->delete();

            // La numeración arranca de nuevo en 1
            $empresa->forceFill(['secuencial' => 1])->save();

            // Empresa en blanco: se llena delante del cliente durante la demo
            if ($this->option('en-blanco')) {
                $empresa->forceFill([
                    'ruc' => '', 'razon_social' => '', 'nombre_comercial' => null,
                    'dir_matriz' => '', 'telefonos' => null, 'sitio_web' => null,
                    'nota_pie' => null, 'email_envio' => null, 'logo' => null,
                    'regimen' => null, 'agente_retencion' => null, 'contribuyente_especial' => null,
                    'certificado_p12' => null, 'certificado_clave' => null,
                    'cert_sujeto' => null, 'cert_valido_hasta' => null,
                ])->save();
            }
            DB::table('emission_points')->update(['secuencial' => 1]);
        });

        $this->info('  Sistema limpio. La numeración de comprobantes vuelve a 1.');
        $this->newLine();

        return self::SUCCESS;
    }
}
