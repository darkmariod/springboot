<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Advance;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\Cuentas;
use Illuminate\Console\Command;

/**
 * Accounting health check: run it any time to see if the books are sane
 * before the accountant tests the system.
 *
 * php artisan contable:chequeo                  (empresa 1)
 * php artisan contable:chequeo --company=3
 * php artisan contable:chequeo --todas          (cada empresa)
 * php artisan contable:chequeo --estricto       (las revisiones de cartera e inventario también hacen fallar)
 *
 * Código de salida: por defecto solo fallan las revisiones de siempre (asientos descuadrados,
 * ecuación contable, stock negativo). La cartera contra el mayor y los movimientos de inventario
 * sin asiento se muestran siempre, pero solo hacen fallar con --estricto; el valor del inventario
 * contra el mayor es solo informativo (las compras anteriores a la cuenta 1.1.05 fueron a gasto).
 */
class ChequeoContable extends Command
{
    protected $signature = 'contable:chequeo {--company=1} {--todas : Revisa todas las empresas}
        {--estricto : Falla también si la cartera no cuadra con el mayor o hay movimientos de inventario sin asiento}';
    protected $description = 'Revisa que la contabilidad cuadre y no haya datos inconsistentes';

    public function handle(): int
    {
        if ($this->option('todas')) {
            $fallas = 0;
            foreach (Company::orderBy('id')->get() as $company) {
                $fallas += $this->chequear($company);
            }

            return $fallas === 0 ? self::SUCCESS : self::FAILURE;
        }

        $companyId = (int) $this->option('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("No existe la empresa $companyId");
            return self::FAILURE;
        }

        return $this->chequear($company) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Revisa una empresa y devuelve cuántos problemas cuentan para el código de salida. */
    private function chequear(Company $company): int
    {
        $companyId = $company->id;

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

        // 5. Cartera e inventario contra el libro mayor (cuentan para el código de salida solo con --estricto)
        $diferencias = $this->chequearCarteraEInventario($companyId);
        if ($this->option('estricto')) {
            $problemas += $diferencias;
        }

        // 6. Resumen del movimiento
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
        if ($problemas === 0 && $diferencias === 0) {
            $this->line('  <bg=green;fg=white> TODO OK — la contabilidad está sana </>');
        } elseif ($problemas === 0) {
            $this->line("  <bg=yellow;fg=black> LO DE SIEMPRE CUADRA — $diferencias diferencia(s) en cartera o inventario (usa --estricto para que cuenten) </>");
        } else {
            $this->line("  <bg=red;fg=white> $problemas PROBLEMA(S) — revísalos antes del demo </>");
        }
        $this->newLine();

        return $problemas;
    }

    /**
     * Cartera y cuentas por pagar contra su cuenta del mayor, e inventario y movimientos sin asiento.
     * Imprime OK / DIFERENCIA con las cifras y devuelve cuántas DIFERENCIA hubo.
     */
    private function chequearCarteraEInventario(int $companyId): int
    {
        $this->newLine();
        $this->line('  <fg=yellow>CARTERA E INVENTARIO CONTRA EL MAYOR</>');
        $diferencias = 0;

        // CxC: facturas pendientes menos notas de crédito disponibles = saldo (Debe - Haber) de la cuenta;
        // la de partes relacionadas (1.1.09) se suma a la normal (1.1.03).
        // Una nota de débito nueva sube el saldo de SU factura (ella guarda saldo 0), así que la identidad no cambia.
        // Las notas de débito de antes (sin factura ligada ni asiento) llevaban su propio saldo fuera del libro mayor:
        // no se reprocesan hacia atrás, se excluyen de la suma y se muestran aparte.
        $vigentes = Invoice::where('company_id', $companyId)->where('estado', '!=', 'anulado');
        $anteriores = (clone $vigentes)->whereIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO)->whereNull('factura_referencia_id');
        $porCobrar = round(
            (float) (clone $vigentes)->where(fn ($q) => $q->whereNull('tipo_comprobante')
                ->orWhereNotIn('tipo_comprobante', Invoice::TIPOS_NOTA_DEBITO)->orWhereNotNull('factura_referencia_id'))->sum('saldo_pendiente')
            - (float) CreditNote::where('company_id', $companyId)->where('tipo', '!=', 'anulado')->sum('saldo_disponible'),
            2
        );
        $diferencias += $this->compararConMayor('Cuentas por cobrar', Cuentas::CXC, $porCobrar, 'facturas pendientes − notas de crédito disponibles', $companyId, 1);
        if ($anteriores->count() > 0) {
            $this->line(sprintf('      INFORMATIVO  Notas de débito anteriores sin asiento: %d por $%s de saldo (no cuentan; no se corrigen hacia atrás)',
                $anteriores->count(), number_format((float) $anteriores->sum('saldo_pendiente'), 2)));
        }

        // CxP: compras pendientes = saldo (Haber - Debe) de la cuenta; la de relacionadas (2.1.11) se suma a la normal (2.1.01)
        $porPagar = round((float) Purchase::where('company_id', $companyId)->sum('saldo_pendiente'), 2);
        $diferencias += $this->compararConMayor('Cuentas por pagar', Cuentas::CXP, $porPagar, 'compras pendientes', $companyId, -1);

        // Anticipos a proveedores: el saldo (Debe - Haber) de 1.1.10 es la suma de los anticipos de proveedor con saldo abierto
        $anticipos = round((float) Advance::where('company_id', $companyId)->where('tipo', 'proveedor')->sum('saldo'), 2);
        $diferencias += $this->compararConMayor('Anticipos a proveedores', ['anticipos_proveedores'], $anticipos, 'anticipos abiertos', $companyId, 1);

        // Inventario: informativo, nunca falla
        $kardex = round((float) Product::where('company_id', $companyId)->where('tipo', '!=', 'servicio')
            ->get()->sum(fn ($p) => (float) $p->stock * (float) $p->costo_promedio), 2);
        $mayor = $this->saldoCuenta($companyId, 'inventario');
        $dif = round($kardex - $mayor, 2);
        $this->line(sprintf('      INFORMATIVO  Inventario (%s): kárdex $%s · mayor $%s · diferencia $%s',
            Cuentas::codigo('inventario'), number_format($kardex, 2), number_format($mayor, 2), number_format($dif, 2)));
        if (abs($dif) >= 0.005) {
            $this->line('        <fg=gray>Las compras anteriores a la cuenta de inventario se contabilizaron como gasto; no se corrigen hacia atrás.</>');
        }

        // Movimientos de inventario sin documento de origen con asiento
        $sinAsiento = $this->movimientosSinAsiento($companyId);
        if ($sinAsiento['lista']->isEmpty()) {
            $this->info(sprintf('      ✓ OK  Movimientos de inventario con asiento de origen (%d revisados, %d internos sin efecto contable)',
                $sinAsiento['revisados'], $sinAsiento['internos']));
        } else {
            $diferencias++;
            $this->error('      ✗ DIFERENCIA  Movimientos de inventario sin asiento de origen: '.$sinAsiento['lista']->count().' de '.$sinAsiento['revisados']);
            foreach ($sinAsiento['lista']->take(20) as $m) {
                $this->line(sprintf('          #%d %s %s %s %s — %s',
                    $m->id, $m->fecha, $m->codigo_producto, $m->tipo, rtrim(rtrim((string) $m->cantidad, '0'), '.'), $m->concepto));
            }
            if ($sinAsiento['lista']->count() > 20) {
                $this->line('          … y '.($sinAsiento['lista']->count() - 20).' más');
            }
        }

        return $diferencias;
    }

    /**
     * Imprime OK / DIFERENCIA de un saldo de documentos contra su cuenta; $signo = 1 (Debe - Haber) o -1 (Haber - Debe).
     * $conceptos son las cuentas que lo componen: la normal y, si tuvo movimientos, la de partes relacionadas;
     * el saldo del mayor es la suma de todas.
     *
     * @param  string[]  $conceptos
     */
    private function compararConMayor(string $nombre, array $conceptos, float $documentos, string $detalle, int $companyId, int $signo): int
    {
        // La cuenta principal siempre se muestra; la de relacionadas solo cuando se ha usado
        $codigos = [Cuentas::codigo($conceptos[0])];
        $mayor = $this->saldoCuenta($companyId, $conceptos[0]);
        foreach (array_slice($conceptos, 1) as $concepto) {
            if ($this->tieneMovimientos($companyId, $concepto)) {
                $codigos[] = Cuentas::codigo($concepto);
                $mayor += $this->saldoCuenta($companyId, $concepto);
            }
        }
        $codigo = implode(' + ', $codigos);
        $mayor = round($signo * $mayor, 2);
        $dif = round($documentos - $mayor, 2);

        if (abs($dif) < 0.005) {
            $this->info(sprintf('      ✓ OK  %s (%s): %s $%s = mayor $%s', $nombre, $codigo, $detalle, number_format($documentos, 2), number_format($mayor, 2)));

            return 0;
        }
        $this->error(sprintf('      ✗ DIFERENCIA  %s (%s): %s $%s ≠ mayor $%s (diferencia $%s)',
            $nombre, $codigo, $detalle, number_format($documentos, 2), number_format($mayor, 2), number_format($dif, 2)));

        return 1;
    }

    /** ¿La cuenta de ese concepto tiene al menos una línea de asiento en esta empresa? */
    private function tieneMovimientos(int $companyId, string $concepto): bool
    {
        $cuenta = Account::where('company_id', $companyId)->where('codigo', Cuentas::codigo($concepto))->first();

        return $cuenta !== null && JournalEntryLine::where('account_id', $cuenta->id)->exists();
    }

    /** Saldo Debe − Haber de la cuenta de un concepto del plan central. */
    private function saldoCuenta(int $companyId, string $concepto): float
    {
        $cuenta = Account::where('company_id', $companyId)->where('codigo', Cuentas::codigo($concepto))->first();
        if (! $cuenta) {
            return 0.0;
        }
        $lineas = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)->where('journal_entry_lines.account_id', $cuenta->id);

        return round((float) $lineas->sum('journal_entry_lines.debe') - (float) (clone $lineas)->sum('journal_entry_lines.haber'), 2);
    }

    /**
     * Cada movimiento del kárdex debe nacer de un documento con asiento: factura, compra, nota de
     * crédito, anulación o el propio ajuste. Las transferencias, conversiones y fraccionamientos
     * son internos (no cambian el valor del inventario) y no necesitan asiento.
     *
     * @return array{lista:\Illuminate\Support\Collection,revisados:int,internos:int}
     */
    private function movimientosSinAsiento(int $companyId): array
    {
        $conAsiento = [];
        foreach (JournalEntry::where('company_id', $companyId)->whereNotNull('origen_id')->get(['origen_type', 'origen_id']) as $e) {
            $conAsiento[$e->origen_type][$e->origen_id] = true;
        }
        $tiene = fn (string $modelo, $id) => $id && isset($conAsiento[(new $modelo)->getMorphClass()][$id]);

        // Movimientos antiguos no guardan el documento: se reconoce por el número que va en el concepto
        $facturas = Invoice::where('company_id', $companyId)->pluck('id', 'numero');
        $compras = Purchase::where('company_id', $companyId)->pluck('id', 'numero');

        $codigos = Product::where('company_id', $companyId)->pluck('codigo', 'id');
        $lista = collect();
        $revisados = 0;
        $internos = 0;

        foreach (InventoryMovement::where('company_id', $companyId)->orderBy('id')->get() as $m) {
            $m->codigo_producto = $codigos[$m->product_id] ?? '?';
            $concepto = (string) $m->concepto;

            if (preg_match('/^(Transferencia|Conversión|Fraccionamiento)/u', $concepto)) {
                $internos++;
                continue;
            }
            $revisados++;

            $ok = $tiene(InventoryMovement::class, $m->id)               // ajuste: el asiento nace del propio movimiento
                || ($m->invoice_id && $tiene(Invoice::class, $m->invoice_id))
                || ($m->purchase_id && $tiene(Purchase::class, $m->purchase_id))
                || (preg_match('/^(?:Devolución NC|Reverso NC anulada) (\d+)/u', $concepto, $x) && $tiene(CreditNote::class, (int) $x[1]))
                || (preg_match('/^(?:Anulación|Venta combo|Venta) (.+)$/u', $concepto, $x) && $tiene(Invoice::class, $facturas[$x[1]] ?? null))
                || (preg_match('/^Compra (.+)$/u', $concepto, $x) && $tiene(Purchase::class, $compras[$x[1]] ?? null));

            if (! $ok) {
                $lista->push($m);
            }
        }

        return ['lista' => $lista, 'revisados' => $revisados, 'internos' => $internos];
    }
}
