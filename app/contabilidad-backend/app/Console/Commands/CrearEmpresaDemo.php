<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\EmpresaDemo;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Crea la empresa "DEMO HASRESET" para grabar el demo de ventas: plan Completo, ambiente de pruebas del SRI,
 * bodega, caja, banco, clientes, proveedores y productos ficticios, y movimientos de muestra hechos por el
 * mismo camino que usa la pantalla (compras, ventas, pagos, nota de crédito, ajuste, caja y banco).
 *
 * php artisan demo:crear                    (si ya existe, solo lo informa)
 * php artisan demo:crear --rehacer          (pide confirmación; borra solo los datos de esa empresa y la arma de nuevo)
 * php artisan demo:crear --rehacer --force  (sin preguntar)
 *
 * No toca ninguna otra empresa ni ningún usuario. Como la lista de empresas es la misma para todos los
 * usuarios con sesión, el administrador de siempre ve "DEMO HASRESET" en el selector sin cambiarle nada.
 * Termina con las mismas revisiones de `contable:chequeo --estricto` y falla (código 1) si alguna no cuadra.
 */
class CrearEmpresaDemo extends Command
{
    protected $signature = 'demo:crear
        {--rehacer : Borra los datos de la empresa demo (solo los suyos) y la vuelve a crear}
        {--force : No pide confirmación al rehacer}';

    protected $description = 'Crea (o rehace) la empresa DEMO HASRESET con datos ficticios para el demo de ventas';

    public function handle(EmpresaDemo $demo): int
    {
        $existente = $demo->buscar();

        if ($existente && ! $this->option('rehacer')) {
            $this->newLine();
            $this->info('  La empresa '.EmpresaDemo::NOMBRE.' ya existe; no se cambió nada.');
            $this->mostrarResumen($demo->resumen($existente));
            $this->line('  Para borrar sus datos y armarla de nuevo usa: php artisan demo:crear --rehacer');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($existente) {
            // Se borra únicamente lo que cuelga de la empresa demo: nunca una que tenga ese RUC pero no sea ella
            if ($existente->razon_social !== EmpresaDemo::NOMBRE) {
                $this->error("  El RUC de demostración lo usa otra empresa ({$existente->razon_social}). No se borra nada.");

                return self::FAILURE;
            }
            if (! $this->option('force')
                && ! $this->confirm('Se borrarán los datos de '.EmpresaDemo::NOMBRE.' (solo de esa empresa) y se volverá a crear. ¿Continuar?', false)) {
                $this->warn('  Cancelado: no se borró nada. Para ejecutarlo sin preguntar agrega --force');

                return self::FAILURE;
            }
        }

        try {
            $empresa = $demo->construir($existente);
        } catch (\Throwable $e) {
            $this->error('  No se pudo crear la empresa demo; no quedó nada a medias.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Empresa '.($existente ? 'rehecha' : 'creada').': '.$empresa->razon_social.' (id '.$empresa->id.')');
        $this->mostrarResumen($demo->resumen($empresa));

        return $this->chequear($empresa) ? self::SUCCESS : self::FAILURE;
    }

    private function mostrarResumen(array $resumen): void
    {
        $this->table(['Concepto', 'Valor'], array_map(fn ($k, $v) => [$k, (string) $v], array_keys($resumen), $resumen));
    }

    /**
     * Las mismas revisiones de `contable:chequeo --estricto` (cartera y cuentas por pagar contra el mayor,
     * movimientos de inventario con asiento, asientos cuadrados, ecuación contable, sin stock negativo).
     * Muestra una línea por revisión con OK o DIFERENCIA.
     */
    private function chequear(Company $empresa): bool
    {
        $salida = new BufferedOutput;
        $codigo = $this->runCommand('contable:chequeo', ['--company' => $empresa->id, '--estricto' => true], $salida);

        $this->line('  <fg=yellow>CHEQUEO CONTABLE ESTRICTO</>');
        foreach (preg_split('/\R/', $salida->fetch()) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || ! preg_match('/✓|✗|\bOK\b|DIFERENCIA|NO CUADRA|PROBLEMA|INFORMATIVO/u', $linea)) {
                continue;
            }
            $linea = '    '.$linea;
            if (preg_match('/✗|DIFERENCIA|NO CUADRA|PROBLEMA/u', $linea)) {
                $this->error($linea);
            } else {
                $this->line($linea);
            }
        }

        $this->newLine();
        if ($codigo === self::SUCCESS) {
            $this->info('  Chequeo estricto: OK. La empresa demo está lista.');
        } else {
            $this->error('  Chequeo estricto: DIFERENCIA. Revisa lo anterior; con --rehacer se arma de nuevo.');
        }
        $this->newLine();

        return $codigo === self::SUCCESS;
    }
}
