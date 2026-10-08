<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T1.7: `contable:chequeo` revisa también la cartera contra el libro mayor y los movimientos
 * de inventario sin asiento de origen. Por defecto solo falla (código != 0) por lo de siempre
 * (asientos descuadrados...); con --estricto también falla por lo nuevo.
 */
class ChequeoContableTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Chequeo Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05',
            'identificacion' => '1700000001', 'razon_social' => 'Cliente Test',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_empresa_nueva_sin_movimientos_pasa_el_chequeo_estricto(): void
    {
        $this->chequeo(['--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_despues_de_compra_venta_pago_nota_de_credito_y_ajuste_el_chequeo_estricto_pasa(): void
    {
        $this->recorridoCompleto();

        $this->chequeo(['--estricto' => true])
            ->expectsOutputToContain('Cuentas por cobrar')
            ->expectsOutputToContain('Cuentas por pagar')
            ->expectsOutputToContain('Inventario')
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);
    }

    public function test_el_chequeo_muestra_las_cifras_de_cartera_contra_el_mayor(): void
    {
        $this->recorridoCompleto();

        // CxC: factura a crédito 23.00 - cobro 10.00 - NC 11.50 = 1.50; CxP: 57.50 - pago 20.00 = 37.50
        // (cada línea de salida cumple una sola expectativa, por eso se piden completas)
        $this->chequeo()
            ->expectsOutputToContain('OK  Cuentas por cobrar (1.1.03): facturas pendientes − notas de crédito disponibles $1.50 = mayor $1.50')
            ->expectsOutputToContain('OK  Cuentas por pagar (2.1.01): compras pendientes $37.50 = mayor $37.50')
            ->assertExitCode(0);
    }

    public function test_diferencia_en_cuentas_por_cobrar_solo_falla_con_estricto(): void
    {
        $this->recorridoCompleto();
        Invoice::where('company_id', $this->company->id)->where('forma_pago', 'credito')->increment('saldo_pendiente', 5);

        $this->chequeo()->expectsOutputToContain('DIFERENCIA  Cuentas por cobrar')->assertExitCode(0);
        $this->chequeo(['--estricto' => true])->expectsOutputToContain('DIFERENCIA  Cuentas por cobrar')->assertExitCode(1);
    }

    public function test_diferencia_en_cuentas_por_pagar_solo_falla_con_estricto(): void
    {
        $this->recorridoCompleto();
        Purchase::where('company_id', $this->company->id)->increment('saldo_pendiente', 3);

        $this->chequeo()->expectsOutputToContain('DIFERENCIA  Cuentas por pagar')->assertExitCode(0);
        $this->chequeo(['--estricto' => true])->expectsOutputToContain('DIFERENCIA  Cuentas por pagar')->assertExitCode(1);
    }

    public function test_las_facturas_y_notas_anuladas_no_cuentan_en_la_cartera(): void
    {
        $this->comprar();
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        // La factura anulada conserva su saldo_pendiente, pero el contra-asiento ya cuadró el mayor
        $this->assertGreaterThan(0, (float) $factura->fresh()->saldo_pendiente);
        $this->chequeo(['--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_el_inventario_es_solo_informativo_aunque_no_coincida_con_el_mayor(): void
    {
        $this->recorridoCompleto();
        // Costo del kárdex distinto al del libro (como una compra anterior que fue a gasto)
        Product::where('company_id', $this->company->id)->update(['costo_promedio' => 99]);

        $this->chequeo(['--estricto' => true])
            ->expectsOutputToContain('INFORMATIVO  Inventario')
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);
    }

    public function test_movimiento_de_inventario_sin_asiento_de_origen_se_lista_y_falla_con_estricto(): void
    {
        $this->recorridoCompleto();
        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        // Entra mercadería sin documento ni asiento (p. ej. cargado a mano)
        $mov = app(RegisterInventoryMovement::class)->handle($producto, 'ingreso', 2, 5, 'Carga manual sin documento', '2026-02-01');

        $this->chequeo()
            ->expectsOutputToContain('DIFERENCIA  Movimientos de inventario sin asiento de origen: 1 de ')
            ->expectsOutputToContain("#{$mov->id} 2026-02-01 MERC-1 ingreso 2 — Carga manual sin documento")
            ->assertExitCode(0);
        $this->chequeo(['--estricto' => true])->assertExitCode(1);
    }

    public function test_movimientos_internos_entre_bodegas_no_necesitan_asiento(): void
    {
        $this->recorridoCompleto();
        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $destino = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Secundaria']);

        $this->postJson('/api/inventory/transferencia', [
            'company_id' => $this->company->id, 'product_id' => $producto->id,
            'warehouse_origen_id' => Warehouse::where('codigo', 'B01')->value('id'),
            'warehouse_destino_id' => $destino->id, 'cantidad' => 1, 'motivo' => 'Reposición',
        ])->assertOk();

        $this->chequeo(['--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_un_asiento_descuadrado_sigue_haciendo_fallar_el_chequeo_normal(): void
    {
        $this->recorridoCompleto();
        JournalEntry::where('company_id', $this->company->id)->orderBy('id')->firstOrFail()->update(['total_debe' => 999]);

        $this->chequeo()->assertExitCode(1);
    }

    public function test_todas_revisa_cada_empresa_y_falla_si_alguna_falla_en_estricto(): void
    {
        $this->recorridoCompleto();
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);

        $this->artisan('contable:chequeo', ['--todas' => true, '--estricto' => true])
            ->expectsOutputToContain('Empresa Chequeo Test SA')
            ->expectsOutputToContain('Otra Empresa SA')
            ->assertExitCode(0);

        $p = Product::create(['company_id' => $otra->id, 'codigo' => 'X', 'descripcion' => 'X', 'tipo' => 'bien']);
        app(RegisterInventoryMovement::class)->handle($p, 'ingreso', 1, 1, 'Sin documento', '2026-01-01');

        $this->artisan('contable:chequeo', ['--todas' => true, '--estricto' => true])->assertExitCode(1);
    }

    // ------------------------------------------------------------- helpers

    private function chequeo(array $opciones = [])
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id] + $opciones);
    }

    /**
     * Compra con IVA (10 u. x $5), pago parcial al proveedor, venta a crédito con cobro parcial,
     * venta en efectivo, nota de crédito con devolución y un ajuste de inventario por faltante.
     */
    private function recorridoCompleto(): void
    {
        $compra = $this->comprar();
        $this->postJson("/api/payables/{$compra->id}/pay", ['monto' => 20, 'forma_pago' => 'transferencia'])->assertOk();

        $credito = $this->facturar([['MERC-1', 2, 10]], 'credito');          // 23.00 a crédito
        $this->postJson("/api/receivables/{$credito->id}/pay", ['monto' => 10, 'forma_pago' => 'efectivo'])->assertOk();
        $this->facturar([['MERC-1', 4, 10]], 'efectivo');                    // cobrada al instante

        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $credito->id,
            'tipo' => 'sri', 'motivo' => 'Devolución',
            'items' => [['codigo_principal' => 'MERC-1', 'descripcion' => 'MERC-1', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $this->postJson('/api/inventory/ajuste', [
            'company_id' => $this->company->id, 'product_id' => $producto->id,
            'warehouse_id' => Warehouse::where('codigo', 'B01')->value('id'),
            'stock_fisico' => (float) $producto->fresh()->stock - 1, 'motivo' => 'Faltante en conteo',
        ])->assertOk();
    }

    private function comprar(): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => '001-001-000000001', 'fecha_emision' => '2026-01-10',
            'items' => [['codigo_principal' => 'MERC-1', 'descripcion' => 'MERC-1', 'cantidad' => 10, 'precio_unitario' => 5, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->firstOrFail();
    }

    private function facturar(array $lineas, string $formaPago): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => $formaPago,
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }
}
