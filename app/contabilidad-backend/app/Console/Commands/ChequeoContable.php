<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Advance;
use App\Models\CardTransaction;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\EmployeeMovement;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockTransformation;
use App\Support\CostosInventario;
use App\Support\Cuentas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

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
 * Los fraccionamientos y conversiones no llevan asiento: se revisa que lo que sale del inventario valga lo mismo
 * que lo que entra (misma regla: solo hace fallar con --estricto).
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
            ->where('journal_entries.company_id', $companyId)->where('journal_entries.estado', '!=', JournalEntry::BORRADOR)
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

        // 3b. Períodos cerrados: cerrar mayoriza todo lo del mes, así que dentro de un mes cerrado no debe quedar nada pendiente ni en borrador
        $this->newLine();
        $sueltos = $this->asientosSueltosEnPeriodosCerrados($companyId);
        if (! $sueltos) {
            $cerrados = AccountingPeriod::where('company_id', $companyId)->where('estado', AccountingPeriod::CERRADO)->count();
            $this->info($cerrados ? "  ✓ Los $cerrados períodos cerrados no tienen asientos pendientes ni en borrador" : '  ✓ Ningún período está cerrado todavía');
        } else {
            $problemas++;
            $this->error('  ✗ '.count($sueltos).' asientos sin mayorizar dentro de períodos cerrados:');
            foreach (array_slice($sueltos, 0, 20) as $linea) $this->line("      $linea");
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
                ['  · borradores (no cuentan en ningún reporte)', JournalEntry::where('company_id', $companyId)->where('estado', JournalEntry::BORRADOR)->count()],
                ['  · manuales', JournalEntry::where('company_id', $companyId)->where('manual', true)->count()],
                ['  · generados y editados a mano', JournalEntry::where('company_id', $companyId)->where('editado_manualmente', true)->count()],
                ['  · de cierre de ejercicio', JournalEntry::where('company_id', $companyId)->where('es_cierre', true)->count()],
                ['Períodos cerrados', AccountingPeriod::where('company_id', $companyId)->where('estado', AccountingPeriod::CERRADO)->count()],
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

    /** @return array<int,string> "AS-000123 (2026-03-05) pendiente" de cada asiento que sigue sin mayorizar en un mes cerrado */
    private function asientosSueltosEnPeriodosCerrados(int $companyId): array
    {
        $sueltos = [];
        foreach (AccountingPeriod::where('company_id', $companyId)->where('estado', AccountingPeriod::CERRADO)->orderBy('anio')->orderBy('mes')->get() as $p) {
            $inicio = sprintf('%04d-%02d-01', $p->anio, $p->mes);
            $fin = date('Y-m-t', strtotime($inicio));
            JournalEntry::where('company_id', $companyId)->where('estado', '!=', JournalEntry::MAYORIZADO)
                ->whereDate('fecha', '>=', $inicio)->whereDate('fecha', '<=', $fin)->orderBy('id')->get()
                ->each(function ($e) use (&$sueltos) { $sueltos[] = "{$e->numero} ({$e->fecha->toDateString()}) {$e->estado}"; });
        }

        return $sueltos;
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

        // Anticipos y préstamos a empleados: el saldo (Debe - Haber) de 1.1.11 es lo que falta por descontar de los movimientos
        // cuyo dinero entregó la empresa por el sistema (el rol de pagos acredita la cuenta al descontar cada cuota)
        $aEmpleados = round((float) EmployeeMovement::where('company_id', $companyId)->where('entregado', true)
            ->where('estado', EmployeeMovement::PENDIENTE)->sum('saldo'), 2);
        if ($aEmpleados != 0.0 || $this->tieneMovimientos($companyId, 'anticipos_empleados')) {
            $diferencias += $this->compararConMayor('Anticipos y préstamos a empleados', ['anticipos_empleados'], $aEmpleados, 'movimientos entregados por descontar', $companyId, 1);
        }

        // Activos fijos: la depreciación acumulada de las fichas contra la cuenta, y que ningún valor en libros sea negativo
        $diferencias += $this->chequearActivosFijos($companyId);

        // Inventario: informativo, nunca falla
        $kardex = round((float) Product::where('company_id', $companyId)->where('tipo', '!=', 'servicio')
            ->get()->sum(fn ($p) => (float) $p->stock * (float) $p->costo_promedio), 2);
        $mayor = $this->saldoCuenta($companyId, 'inventario');
        $dif = round($kardex - $mayor, 2);
        $this->line(sprintf('      INFORMATIVO  Inventario (%s): kárdex $%s · mayor $%s · diferencia $%s',
            Cuentas::codigo('inventario'), number_format($kardex, 2), number_format($mayor, 2), number_format($dif, 2)));
        if (abs($dif) >= 0.005) {
            $this->line('        <fg=gray>Las compras anteriores a la cuenta de inventario se contabilizaron como gasto; no se corrigen hacia atrás.</>');
            $this->pistaDeEdicionesManuales($companyId, ['inventario']);
        }

        // Tarjetas por liquidar: informativo. Cada venta con tarjeta debita 1.1.08 y cada liquidación registrada (T5.3) la acredita;
        // las liquidaciones anteriores no asentaron nada y los vouchers asignados a una liquidación aún sin asiento tampoco cuentan,
        // por eso una diferencia aquí no hace fallar el chequeo.
        $voucherPendiente = round((float) CardTransaction::where('company_id', $companyId)->whereNull('card_settlement_id')->sum('monto'), 2);
        $mayorTarjetas = $this->saldoCuenta($companyId, 'tarjetas_por_liquidar');
        if ($voucherPendiente != 0.0 || $mayorTarjetas != 0.0 || $this->tieneMovimientos($companyId, 'tarjetas_por_liquidar')) {
            $this->line(sprintf('      INFORMATIVO  Tarjetas por liquidar (%s): ventas pendientes $%s · mayor $%s · diferencia $%s',
                Cuentas::codigo('tarjetas_por_liquidar'), number_format($voucherPendiente, 2), number_format($mayorTarjetas, 2),
                number_format(round($voucherPendiente - $mayorTarjetas, 2), 2)));
        }

        // Fraccionamientos y conversiones: lo que sale del inventario vale lo mismo que lo que entra
        $diferencias += $this->chequearTransformaciones($companyId);

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
        $this->pistaDeEdicionesManuales($companyId, $conceptos);

        return 1;
    }

    /**
     * Una diferencia de cartera o inventario puede venir de una corrección hecha a mano: el contador mueve una línea
     * del asiento de una factura a otra cuenta y el subauxiliar deja de coincidir con el mayor. No se bloquea (tiene derecho),
     * pero el mensaje debe decirlo. Lista los asientos generados que se editaron y los manuales que mueven esas cuentas.
     *
     * @param  string[]  $conceptos
     */
    private function pistaDeEdicionesManuales(int $companyId, array $conceptos): void
    {
        $editados = JournalEntry::where('company_id', $companyId)->where('editado_manualmente', true)
            ->where('estado', '!=', JournalEntry::BORRADOR)->orderBy('id')->pluck('numero')->all();
        $codigos = array_map(fn ($c) => Cuentas::codigo($c), $conceptos);
        $manuales = JournalEntry::where('company_id', $companyId)->where('manual', true)->where('estado', '!=', JournalEntry::BORRADOR)
            ->whereHas('lines', fn ($q) => $q->whereHas('account', fn ($a) => $a->whereIn('codigo', $codigos)))
            ->orderBy('id')->pluck('numero')->all();

        if ($editados) {
            $this->line('        <fg=gray>Posible causa: hay asientos editados a mano ('.implode(', ', $editados).'). Si movieron una línea de '
                .implode(' / ', $codigos).' a otra cuenta, esa puede ser la causa de la diferencia.</>');
        }
        if ($manuales) {
            $this->line('        <fg=gray>Hay asientos manuales que mueven '.implode(' / ', $codigos).' ('.implode(', ', $manuales).'): no pasan por los documentos.</>');
        }
    }

    /** ¿La cuenta de ese concepto tiene al menos una línea de asiento en esta empresa? */
    private function tieneMovimientos(int $companyId, string $concepto): bool
    {
        $cuenta = Account::where('company_id', $companyId)->where('codigo', Cuentas::codigo($concepto))->first();

        return $cuenta !== null && JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $cuenta->id)->where('journal_entries.estado', '!=', JournalEntry::BORRADOR)->exists();
    }

    /** Saldo Debe − Haber de la cuenta de un concepto del plan central. */
    private function saldoCuenta(int $companyId, string $concepto): float
    {
        $cuenta = Account::where('company_id', $companyId)->where('codigo', Cuentas::codigo($concepto))->first();
        if (! $cuenta) {
            return 0.0;
        }
        $lineas = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)->where('journal_entries.estado', '!=', JournalEntry::BORRADOR)
            ->where('journal_entry_lines.account_id', $cuenta->id);

        return round((float) $lineas->sum('journal_entry_lines.debe') - (float) (clone $lineas)->sum('journal_entry_lines.haber'), 2);
    }

    /**
     * Activos fijos (T7.2). La suma de lo depreciado de las fichas que siguen activas debe ser el saldo de 1.2.02 Depreciación acumulada
     * (las fichas dadas de baja ya salieron de la cuenta con su asiento de baja), y ningún activo puede tener un valor en libros negativo
     * ni haber depreciado más que costo − valor residual. El costo de las fichas contra 1.2.01 es solo informativo: un activo que se
     * registró sin asiento de alta o una compra capitalizada sin ficha todavía no cuadran y no son un error.
     * Imprime OK / DIFERENCIA y devuelve cuántas DIFERENCIA hubo.
     */
    private function chequearActivosFijos(int $companyId): int
    {
        if (! Schema::hasTable('fixed_assets')) {
            return 0;
        }
        $fichas = FixedAsset::where('company_id', $companyId)->orderBy('codigo')->get();
        $enElMayor = $this->tieneMovimientos($companyId, 'depreciacion_acumulada') || $this->tieneMovimientos($companyId, 'activos_fijos');
        if ($fichas->isEmpty() && ! $enElMayor) {
            return 0;
        }

        $this->newLine();
        $this->line('  <fg=yellow>ACTIVOS FIJOS</>');
        $diferencias = 0;

        // Lo depreciado de cada ficha, en centavos
        $depreciado = [];
        FixedAssetDepreciation::where('company_id', $companyId)->get(['fixed_asset_id', 'monto'])->each(function ($f) use (&$depreciado) {
            $depreciado[$f->fixed_asset_id] = ($depreciado[$f->fixed_asset_id] ?? 0) + (int) round(((float) $f->monto) * 100);
        });
        $vigentes = $fichas->where('estado', FixedAsset::ACTIVO);

        $enFichas = round($vigentes->sum(fn ($a) => $depreciado[$a->id] ?? 0) / 100, 2);
        $diferencias += $this->compararConMayor('Depreciación acumulada de activos fijos', ['depreciacion_acumulada'], $enFichas, 'depreciación de las fichas activas', $companyId, -1);

        $negativos = [];
        foreach ($vigentes as $a) {
            $hecho = $depreciado[$a->id] ?? 0;
            $costo = (int) round(((float) $a->costo) * 100);
            $residual = (int) round(((float) $a->valor_residual) * 100);
            if ($costo - $hecho < 0 || $hecho > max(0, $costo - $residual)) {
                $negativos[] = sprintf('          %s %s: costo $%s, depreciado $%s, residual $%s', $a->codigo, $a->nombre,
                    number_format($costo / 100, 2), number_format($hecho / 100, 2), number_format($residual / 100, 2));
            }
        }
        if (! $negativos) {
            $this->info(sprintf('      ✓ OK  Valor en libros de %d activos: ninguno negativo ni por debajo de su valor residual', $vigentes->count()));
        } else {
            $diferencias++;
            $this->error('      ✗ DIFERENCIA  Valor en libros negativo o por debajo del valor residual: '.count($negativos).' de '.$vigentes->count());
            foreach (array_slice($negativos, 0, 20) as $linea) {
                $this->line($linea);
            }
        }

        $costoFichas = round($vigentes->sum(fn ($a) => (int) round(((float) $a->costo) * 100)) / 100, 2);
        $mayor = $this->saldoCuenta($companyId, 'activos_fijos');
        $this->line(sprintf('      INFORMATIVO  Activos fijos (%s): costo de las fichas activas $%s · mayor $%s · diferencia $%s',
            Cuentas::codigo('activos_fijos'), number_format($costoFichas, 2), number_format($mayor, 2), number_format(round($costoFichas - $mayor, 2), 2)));

        return $diferencias;
    }

    /**
     * Un fraccionamiento o conversión no lleva asiento porque no cambia el valor del inventario: cada documento
     * debe tener su salida y su entrada, y las dos deben valer lo mismo (diferencia máxima de un centavo).
     * Imprime OK / DIFERENCIA y devuelve cuántas DIFERENCIA hubo.
     */
    private function chequearTransformaciones(int $companyId): int
    {
        $documentos = StockTransformation::where('company_id', $companyId)->orderBy('id')->get();
        $movimientos = InventoryMovement::where('company_id', $companyId)
            ->where('origen_type', (new StockTransformation)->getMorphClass())
            ->whereIn('origen_id', $documentos->pluck('id'))->get()->groupBy('origen_id');

        $malos = [];
        foreach ($documentos as $t) {
            $movs = $movimientos[$t->id] ?? collect();
            $sale = round($movs->where('tipo', 'egreso')->sum(fn ($m) => CostosInventario::valor($m)), 2);
            $entra = round($movs->where('tipo', 'ingreso')->sum(fn ($m) => CostosInventario::valor($m)), 2);
            $incompleto = $movs->where('tipo', 'egreso')->isEmpty() || $movs->where('tipo', 'ingreso')->isEmpty();
            if ($incompleto || round(abs($sale - $entra), 2) > 0.01) {
                $malos[] = sprintf('          %s: sale $%s, entra $%s%s', $t->numero, number_format($sale, 2), number_format($entra, 2),
                    $incompleto ? ' (le falta la salida o la entrada)' : '');
            }
        }

        if (! $malos) {
            $this->info(sprintf('      ✓ OK  Fraccionamientos y conversiones (%d): lo que sale del inventario es igual a lo que entra', $documentos->count()));

            return 0;
        }
        $this->error(sprintf('      ✗ DIFERENCIA  Fraccionamientos y conversiones sin el mismo valor: %d de %d', count($malos), $documentos->count()));
        foreach (array_slice($malos, 0, 20) as $linea) {
            $this->line($linea);
        }
        if (count($malos) > 20) {
            $this->line('          … y '.(count($malos) - 20).' más');
        }

        return 1;
    }

    /**
     * Cada movimiento del kárdex debe nacer de un documento con asiento: factura, compra, nota de
     * crédito, anulación, toma física (su asiento nace de la toma, no de cada movimiento) o el propio ajuste. Las transferencias y los fraccionamientos y conversiones
     * (documento StockTransformation, y los anteriores por su concepto) son internos: no cambian el valor
     * del inventario y no necesitan asiento.
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
        $transformacion = (new StockTransformation)->getMorphClass();
        $tomaFisica = (new PhysicalCount)->getMorphClass();
        $notaCredito = (new CreditNote)->getMorphClass();
        $lista = collect();
        $revisados = 0;
        $internos = 0;

        foreach (InventoryMovement::where('company_id', $companyId)->orderBy('id')->get() as $m) {
            $m->codigo_producto = $codigos[$m->product_id] ?? '?';
            $concepto = (string) $m->concepto;

            // Los de ahora nacen de un StockTransformation; los anteriores solo se reconocen por el texto del concepto
            if ($m->origen_type === $transformacion || preg_match('/^(Transferencia|Conversión|Fraccionamiento)/u', $concepto)) {
                $internos++;
                continue;
            }
            $revisados++;

            $ok = $tiene(InventoryMovement::class, $m->id)               // ajuste: el asiento nace del propio movimiento
                || ($m->invoice_id && $tiene(Invoice::class, $m->invoice_id))
                || ($m->purchase_id && $tiene(Purchase::class, $m->purchase_id))
                || ($m->origen_type === $tomaFisica && $tiene(PhysicalCount::class, $m->origen_id))      // toma física: UN asiento para toda la toma
                || ($m->origen_type === $notaCredito && $tiene(CreditNote::class, $m->origen_id))
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
