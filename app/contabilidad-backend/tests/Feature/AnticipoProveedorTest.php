<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.6b: anticipos a proveedores.
 *   Entregar:  Debe 1.1.10 Anticipos a proveedores / Haber Caja o Bancos.
 *   Aplicar a una compra:  Debe CxP (la cuenta con que nació la compra) / Haber 1.1.10; bajan el saldo de la
 *   compra y el del anticipo (nunca por encima de ninguno).
 * Los anticipos de clientes siguen igual (tipo 'cliente' por defecto) y no se mezclan con los de proveedores.
 */
class AnticipoProveedorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;
    private Contact $proveedorRel;
    private Contact $otroProveedor;
    private Contact $cliente;
    private Bank $banco;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Anticipos Prov SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedor = $this->contacto('1790011223001', 'Proveedor Uno SA', ['tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true]);
        $this->proveedorRel = $this->contacto('1790011224001', 'Proveedor Relacionado SA', ['tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true, 'parte_relacionada' => true]);
        $this->otroProveedor = $this->contacto('1790011225001', 'Otro Proveedor SA', ['tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true]);
        $this->cliente = $this->contacto('1700000001', 'Cliente Test', ['es_cliente' => true, 'es_proveedor' => true]);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test']);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ plan de cuentas

    public function test_el_plan_central_trae_anticipos_a_proveedores_con_un_codigo_libre(): void
    {
        $plan = config('cuentas.cuentas');
        $this->assertSame(['codigo' => '1.1.10', 'nombre' => 'Anticipos a proveedores', 'tipo' => 'activo'], $plan['anticipos_proveedores']);
        $codigos = array_column($plan, 'codigo');
        $this->assertSame($codigos, array_values(array_unique($codigos)), 'Un código = un concepto');

        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();
        $this->assertSame('Anticipos a proveedores', Account::where('company_id', $demo->id)->where('codigo', '1.1.10')->value('nombre'));
    }

    // ------------------------------------------------------------ entregar el anticipo

    public function test_anticipo_a_proveedor_en_efectivo_debita_1_1_10_y_acredita_caja(): void
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'tipo' => 'proveedor',
            'monto' => 80, 'forma_pago' => 'efectivo', 'nota' => 'Anticipo de obra',
        ])->assertCreated()->assertJsonPath('tipo', 'proveedor');

        $anticipo = Advance::firstOrFail();
        $this->assertSame('proveedor', $anticipo->tipo);
        $this->assertEquals(80.0, (float) $anticipo->saldo);
        $this->assertLineas($this->asientoDe($anticipo), [['1.1.10', 80, 0], ['1.1.01', 0, 80]]);
        $this->assertSame(0, BankMovement::count());
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_anticipo_a_proveedor_por_transferencia_acredita_bancos_y_deja_el_egreso_para_conciliar(): void
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'tipo' => 'proveedor',
            'monto' => 200, 'forma_pago' => 'transferencia', 'bank_id' => $this->banco->id, 'documento' => 'TRF-5521',
        ])->assertCreated();

        $anticipo = Advance::firstOrFail();
        $this->assertLineas($this->asientoDe($anticipo), [['1.1.10', 200, 0], ['1.1.02', 0, 200]]);
        $mov = BankMovement::firstOrFail();
        $this->assertSame('debito', $mov->tipo);                         // sale dinero
        $this->assertEquals(200.0, (float) $mov->monto);
        $this->assertSame('TRF-5521', $mov->documento);
        $this->assertSame($anticipo->getMorphClass(), $mov->origen_type);
        $this->assertSame($anticipo->id, (int) $mov->origen_id);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_el_anticipo_de_cliente_sigue_igual_y_por_banco_deja_un_ingreso(): void
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'monto' => 60, 'forma_pago' => 'cheque', 'bank_id' => $this->banco->id, 'documento' => 'CH-77',
        ])->assertCreated()->assertJsonPath('tipo', 'cliente');

        $anticipo = Advance::firstOrFail();
        $this->assertSame('cliente', $anticipo->tipo);
        $this->assertLineas($this->asientoDe($anticipo), [['1.1.02', 60, 0], ['2.1.03', 0, 60]]);
        $mov = BankMovement::firstOrFail();
        $this->assertSame('credito', $mov->tipo);
        $this->assertSame('CH-77', $mov->documento);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_la_lista_se_filtra_por_tipo_y_un_tipo_desconocido_da_422(): void
    {
        $this->anticipar($this->cliente, 'cliente', 10);
        $this->anticipar($this->proveedor, 'proveedor', 20);

        $this->assertCount(2, $this->getJson('/api/advances?company_id='.$this->company->id)->assertOk()->json());
        $clientes = $this->getJson('/api/advances?company_id='.$this->company->id.'&tipo=cliente')->assertOk()->json();
        $this->assertSame(['cliente'], array_unique(array_column($clientes, 'tipo')));
        $this->assertCount(1, $clientes);
        $proveedores = $this->getJson('/api/advances?company_id='.$this->company->id.'&tipo=proveedor')->assertOk()->json();
        $this->assertCount(1, $proveedores);
        $this->assertSame('Proveedor Uno SA', $proveedores[0]['contact']['razon_social']);

        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'tipo' => 'otro', 'monto' => 5, 'forma_pago' => 'efectivo',
        ])->assertStatus(422)->assertJsonValidationErrors('tipo');
    }

    // ------------------------------------------------------------ aplicarlo a una compra

    public function test_aplicar_el_anticipo_a_una_compra_debita_cxp_y_acredita_1_1_10(): void
    {
        $compra = $this->comprar($this->proveedor, 100);                  // 115
        $anticipo = $this->anticipar($this->proveedor, 'proveedor', 80);

        $resp = $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->id, 'monto' => 50])->assertOk();

        $this->assertEquals(65.0, $resp->json('saldo_compra'));
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(30.0, (float) $anticipo->fresh()->saldo);

        $uso = JournalEntry::where('origen_type', $compra->getMorphClass())->where('origen_id', $compra->id)
            ->where('concepto', 'like', 'Uso de anticipo%')->firstOrFail();
        $this->assertLineas($uso, [['2.1.01', 50, 0], ['1.1.10', 0, 50]]);

        // Rastro en los pagos de la compra
        $pago = PurchasePayment::where('purchase_id', $compra->id)->firstOrFail();
        $this->assertSame('anticipo', $pago->forma_pago);
        $this->assertEquals(50.0, (float) $pago->monto);

        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_aplicar_a_una_compra_de_proveedor_relacionado_debita_cxp_relacionadas(): void
    {
        $compra = $this->comprar($this->proveedorRel, 100);
        $anticipo = $this->anticipar($this->proveedorRel, 'proveedor', 115);

        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->id, 'monto' => 115])->assertOk();

        $uso = JournalEntry::where('concepto', 'like', 'Uso de anticipo%')->firstOrFail();
        $this->assertLineas($uso, [['2.1.11', 115, 0], ['1.1.10', 0, 115]]);
        $this->assertEquals(0.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $anticipo->fresh()->saldo);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_nunca_se_aplica_mas_que_el_saldo_del_anticipo_ni_que_el_de_la_compra(): void
    {
        $compra = $this->comprar($this->proveedor, 100);                  // 115
        $anticipo = $this->anticipar($this->proveedor, 'proveedor', 80);
        $asientos = JournalEntry::count();

        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->id, 'monto' => 90])
            ->assertStatus(422)->assertJsonValidationErrors('monto');
        $grande = $this->anticipar($this->proveedor, 'proveedor', 500);
        $asientos = JournalEntry::count();
        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $grande->id, 'monto' => 200])
            ->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(80.0, (float) $anticipo->fresh()->saldo);
        $this->assertEquals(500.0, (float) $grande->fresh()->saldo);
        $this->assertSame($asientos, JournalEntry::count());
        $this->assertSame(0, PurchasePayment::count());
    }

    public function test_solo_se_aplica_un_anticipo_de_proveedor_del_mismo_proveedor_y_empresa(): void
    {
        $compra = $this->comprar($this->proveedor, 100);
        $deOtro = $this->anticipar($this->otroProveedor, 'proveedor', 50);
        $deCliente = $this->anticipar($this->cliente, 'cliente', 50);

        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $deOtro->id, 'monto' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('id');
        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $deCliente->id, 'monto' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('id');

        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(0, PurchasePayment::count());
    }

    // ------------------------------------------------------------ separación entre clientes y proveedores

    public function test_los_anticipos_de_proveedor_no_aparecen_ni_se_aplican_a_las_facturas_del_mismo_contacto(): void
    {
        // El contacto es cliente y proveedor a la vez
        $this->anticipar($this->cliente, 'cliente', 30);
        $proveedorAnticipo = $this->anticipar($this->cliente, 'proveedor', 70);
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();
        $factura = Invoice::firstOrFail();

        $clientes = $this->getJson('/api/credits/available?company_id='.$this->company->id.'&contact_id='.$this->cliente->id)->assertOk();
        $this->assertCount(1, $clientes->json('saldos'));
        $this->assertEquals(30.0, $clientes->json('total'));

        $this->postJson("/api/credits/apply/{$factura->id}", ['tipo' => 'anticipo', 'id' => $proveedorAnticipo->id, 'monto' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('id');
        $this->assertEquals(115.0, (float) $factura->fresh()->saldo_pendiente);

        // Y del lado de compras solo se ve el de proveedor
        $proveedores = $this->getJson('/api/credits/available-supplier?company_id='.$this->company->id.'&contact_id='.$this->cliente->id)->assertOk();
        $this->assertCount(1, $proveedores->json('saldos'));
        $this->assertSame($proveedorAnticipo->id, $proveedores->json('saldos.0.id'));
        $this->assertSame('anticipo', $proveedores->json('saldos.0.tipo'));
        $this->assertEquals(70.0, $proveedores->json('saldos.0.disponible'));
        $this->assertEquals(70.0, $proveedores->json('total'));
    }

    public function test_el_listado_para_pagar_trae_solo_anticipos_abiertos_del_proveedor(): void
    {
        $uno = $this->anticipar($this->proveedor, 'proveedor', 40);
        $agotado = $this->anticipar($this->proveedor, 'proveedor', 25);
        $agotado->update(['saldo' => 0]);
        $this->anticipar($this->otroProveedor, 'proveedor', 99);

        $r = $this->getJson('/api/credits/available-supplier?company_id='.$this->company->id.'&contact_id='.$this->proveedor->id)->assertOk();
        $this->assertSame([$uno->id], array_column($r->json('saldos'), 'id'));
        $this->assertEquals(40.0, $r->json('total'));
    }

    // ------------------------------------------------------------ chequeo estricto

    public function test_el_chequeo_estricto_compara_1_1_10_con_los_anticipos_abiertos_de_proveedor(): void
    {
        $this->anticipar($this->proveedor, 'proveedor', 80);
        $this->anticipar($this->otroProveedor, 'proveedor', 20);

        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('Anticipos a proveedores (1.1.10): anticipos abiertos $100.00 = mayor $100.00')
            ->assertExitCode(0);

        // Un saldo que no corresponde a lo asentado hace fallar el chequeo estricto (y solo el estricto)
        Advance::where('tipo', 'proveedor')->orderBy('id')->first()->update(['saldo' => 50]);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('DIFERENCIA  Anticipos a proveedores (1.1.10)')
            ->assertExitCode(1);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id])->assertExitCode(0);
    }

    // ------------------------------------------------------------ helpers

    private function contacto(string $identificacion, string $nombre, array $extra = []): Contact
    {
        return Contact::create(array_merge(['company_id' => $this->company->id, 'tipo_identificacion' => '05',
            'identificacion' => $identificacion, 'razon_social' => $nombre], $extra));
    }

    private function anticipar(Contact $contacto, string $tipo, float $monto): Advance
    {
        $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $contacto->id, 'tipo' => $tipo,
            'monto' => $monto, 'forma_pago' => 'efectivo',
        ])->assertCreated();

        return Advance::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function comprar(Contact $proveedor, float $base): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id,
            'numero' => sprintf('001-001-%09d', ++$this->secuencia), 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function asientoDe(Advance $anticipo): JournalEntry
    {
        return JournalEntry::where('origen_type', $anticipo->getMorphClass())->where('origen_id', $anticipo->id)->firstOrFail();
    }

    private function chequeoEstricto()
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true]);
    }

    private function assertLineas(JournalEntry $entry, array $esperado): void
    {
        $real = $entry->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->codigo, round((float) $l->debe, 2), round((float) $l->haber, 2)])->all();
        $ordenar = function (array $filas): array {
            $filas = array_map(fn ($f) => [(string) $f[0], round((float) $f[1], 2), round((float) $f[2], 2)], $filas);
            usort($filas, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            return $filas;
        };
        $this->assertEquals($ordenar($esperado), $ordenar($real), 'Líneas del asiento '.$entry->concepto);
        $this->assertEquals(array_sum(array_column($real, 1)), array_sum(array_column($real, 2)), 'El asiento no cuadra');
    }
}
