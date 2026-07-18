<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Accounting health check: run it any time to see if the books are sane
 * before the accountant tests the system.
 *
 * php artisan contable:chequeo
 */
class ChequeoContable extends Command
{
    protected $signature = 'contable:chequeo {--company=1}';
    protected $description = 'Revisa que la contabilidad cuadre y no haya datos inconsistentes';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("No existe la empresa $companyId");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <bg=blue;fg=white> CHEQUEO CONTABLE — '.$company->razon_social.' </>');
        $this->newLine();

        $problemas = 0;

        // 1. Cada asiento debe cuadrar (debe = haber)
        $descuadrados = JournalEntry::where('company_id', $companyId)
            ->whereRaw('ROUND(total_debe, 2) != ROUND(total_haber, 2)')->get();
        if ($descuadrados->isEmpty()) {
            $this->info('  ✓ Todos los asientos cuadran (debe = haber)');
        } else {
            $problemas++;
            $this->error('  ✗ '.$descuadrados->count().' asientos DESCUADRADOS:');
            foreach ($descuadrados as $e) {
                $this->line("      {$e->numero} — {$e->concepto}: debe {$e->total_debe} vs haber {$e->total_haber}");
            }
        }

        // 2. Las lineas de cada asiento deben sumar igual que su cabecera
        $malSumados = JournalEntry::where('company_id', $companyId)->get()
            ->filter(function ($e) {
                $d = round($e->lines()->sum('debe'), 2);
                $h = round($e->lines()->sum('haber'), 2);
                return $d !== round((float) $e->total_debe, 2) || $h !== round((float) $e->total_haber, 2);
            });
        if ($malSumados->isEmpty()) {
            $this->info('  ✓ Las líneas de cada asiento suman lo que dice su cabecera');
        } else {
            $problemas++;
            $this->error('  ✗ '.$malSumados->count().' asientos con líneas que no suman:');
            foreach ($malSumados as $e) $this->line("      {$e->numero} — {$e->concepto}");
        }

        // 3. Ecuacion contable: Activo = Pasivo + Patrimonio + (Ingresos - Gastos)
        $saldos = [];
        $rows = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.company_id', $companyId)
            ->selectRaw('accounts.tipo, SUM(journal_entry_lines.debe) d, SUM(journal_entry_lines.haber) h')
            ->groupBy('accounts.tipo')->get();
        foreach ($rows as $r) {
            $saldos[$r->tipo] = in_array($r->tipo, ['activo', 'gasto']) ? $r->d - $r->h : $r->h - $r->d;
        }
        $activo = round($saldos['activo'] ?? 0, 2);
        $pasivo = round($saldos['pasivo'] ?? 0, 2);
        $patrimonio = round($saldos['patrimonio'] ?? 0, 2);
        $utilidad = round(($saldos['ingreso'] ?? 0) - ($saldos['gasto'] ?? 0), 2);
        $derecha = round($pasivo + $patrimonio + $utilidad, 2);

        $this->newLine();
        $this->line('  <fg=yellow>ECUACIÓN CONTABLE</>');
        $this->line("      Activo .................. $".number_format($activo, 2));
        $this->line("      Pasivo .................. $".number_format($pasivo, 2));
        $this->line("      Patrimonio .............. $".number_format($patrimonio, 2));
        $this->line("      Utilidad del ejercicio .. $".number_format($utilidad, 2));
        $this->line('      '.str_repeat('─', 40));
        if (abs($activo - $derecha) < 0.02) {
            $this->info("      ✓ CUADRA: $activo = $derecha");
        } else {
            $problemas++;
            $this->error("      ✗ NO CUADRA: activo $activo ≠ pasivo+patrimonio+utilidad $derecha");
            $this->line('        <fg=gray>Diferencia: $'.number_format($activo - $derecha, 2).'</>');
        }

        // 4. Stock negativo (mata la credibilidad en un demo)
        $negativos = Product::where('company_id', $companyId)->where('stock', '<', 0)->get();
        $this->newLine();
        if ($negativos->isEmpty()) {
            $this->info('  ✓ No hay stock negativo');
        } else {
            $problemas++;
            $this->error('  ✗ '.$negativos->count().' productos con STOCK NEGATIVO (se vendió sin comprar):');
            foreach ($negativos as $p) $this->line("      {$p->codigo} — {$p->descripcion}: {$p->stock}");
        }

        // 5. Resumen del movimiento
        $this->newLine();
        $this->line('  <fg=yellow>RESUMEN</>');
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Asientos contables', JournalEntry::where('company_id', $companyId)->count()],
                ['  · pendientes', JournalEntry::where('company_id', $companyId)->where('estado', 'pendiente')->count()],
                ['  · mayorizados', JournalEntry::where('company_id', $companyId)->where('estado', 'mayorizado')->count()],
                ['Facturas de venta', \App\Models\Invoice::where('company_id', $companyId)->count()],
                ['Compras', \App\Models\Purchase::where('company_id', $companyId)->count()],
                ['Por cobrar', '$'.number_format(\App\Models\Invoice::where('company_id', $companyId)->sum('saldo_pendiente'), 2)],
                ['Por pagar', '$'.number_format(\App\Models\Purchase::where('company_id', $companyId)->sum('saldo_pendiente'), 2)],
            ]
        );

        $this->newLine();
        if ($problemas === 0) {
            $this->line('  <bg=green;fg=white> TODO OK — la contabilidad está sana </>');
        } else {
            $this->line("  <bg=red;fg=white> $problemas PROBLEMA(S) — revisalos antes del demo </>");
        }
        $this->newLine();

        return $problemas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
