<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.5: varias cotizaciones se facturan en UNA sola factura (gasolineras y hospitales juntan las del mes), que puede ir a
 * un cliente distinto del de las cotizaciones. La factura sale por el camino normal (`InvoiceEmitter`): egreso de inventario,
 * asiento con costo de ventas y comprobante del SRI. Todo en una sola transacción: si algo falla no queda nada hecho.
 */
class CotizacionesAFacturaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $hospital;
    private Contact $otroCliente;
    private Product $gas;
    private Product $diesel;
    private Product $servicio;
    private int $asientosIniciales = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Gasolinera Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->hospital = $this->contacto($this->company, 'Hospital Central', '1790000111001', '04');
        $this->otroCliente = $this->contacto($this->company, 'Constructora Norte', '1790000222001', '04');
        $this->gas = Product::create(['company_id' => $this->company->id, 'codigo' => 'GAS', 'descripcion' => 'Gasolina Extra', 'tipo' => 'bien']);
        $this->diesel = Product::create(['company_id' => $this->company->id, 'codigo' => 'DIE', 'descripcion' => 'Diésel', 'tipo' => 'bien']);
        $this->servicio = Product::create(['company_id' => $this->company->id, 'codigo' => 'SRV', 'descripcion' => 'Transporte', 'tipo' => 'servicio']);
        Sanctum::actingAs(User::factory()->create());

        // El inventario entra por una compra (con su asiento, como el chequeo contable pide): 100 de gasolina a costo 1.00 y 100 de diésel a 1.50
        $proveedor = Contact::create(['company_id' => $this->company->id, 'tipo_identificacion' => '04', 'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Combustibles', 'es_proveedor' => true]);
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $proveedor->id, 'numero' => '001-001-000000001', 'fecha_emision' => '2026-01-10',
            'items' => [
                ['codigo_principal' => 'GAS', 'descripcion' => 'Gasolina Extra', 'cantidad' => 100, 'precio_unitario' => 1, 'tarifa' => 15],
                ['codigo_principal' => 'DIE', 'descripcion' => 'Diésel', 'cantidad' => 100, 'precio_unitario' => 1.5, 'tarifa' => 15],
            ],
        ])->assertCreated();
        $this->asientosIniciales = JournalEntry::count();
    }

    private function contacto(Company $c, string $nombre, string $id, string $tipo = '05'): Contact
    {
        return Contact::create(['company_id' => $c->id, 'tipo_identificacion' => $tipo, 'identificacion' => $id, 'razon_social' => $nombre, 'es_cliente' => true]);
    }

    private function linea(string $codigo, float $cant, float $precio, array $extra = []): array
    {
        $nombres = ['GAS' => 'Gasolina Extra', 'DIE' => 'Diésel', 'SRV' => 'Transporte'];

        return $extra + ['codigo_principal' => $codigo, 'descripcion' => $nombres[$codigo] ?? $codigo, 'cantidad' => $cant, 'precio_unitario' => $precio, 'tarifa' => 15];
    }

    private function cotizacion(Contact $cliente, array $items, ?Company $company = null): Quote
    {
        $r = $this->postJson('/api/quotes', ['company_id' => ($company ?? $this->company)->id, 'contact_id' => $cliente->id, 'items' => $items])->assertCreated();

        return Quote::findOrFail($r->json('id'));
    }

    private function convertir(array $quotes, array $extra = [])
    {
        return $this->postJson('/api/quotes/convert-many', $extra + [
            'company_id' => $this->company->id, 'quote_ids' => array_map(fn ($q) => $q->id, $quotes), 'forma_pago' => 'credito',
        ]);
    }

    /** Debe y Haber por código de cuenta del asiento de la factura. */
    private function asiento(Invoice $f): array
    {
        $asiento = JournalEntry::where('origen_type', $f->getMorphClass())->where('origen_id', $f->id)->orderBy('id')->firstOrFail();
        $c = [];
        foreach ($asiento->lines as $l) {
            $k = $l->account->codigo;
            $c[$k]['debe'] = round(($c[$k]['debe'] ?? 0) + (float) $l->debe, 2);
            $c[$k]['haber'] = round(($c[$k]['haber'] ?? 0) + (float) $l->haber, 2);
        }

        return [$asiento, $c];
    }

    /** Nada se creó: ni factura, ni asiento, ni movimiento de inventario, y las cotizaciones siguen como estaban. */
    private function assertNadaCreado(): void
    {
        $this->assertSame(0, Invoice::count());
        $this->assertSame($this->asientosIniciales, JournalEntry::count(), 'Ningún asiento nuevo');
        $this->assertSame(0, InventoryMovement::where('tipo', 'egreso')->count());
        $this->assertEquals(100.0, (float) $this->gas->fresh()->stock);
        $this->assertEquals(100.0, (float) $this->diesel->fresh()->stock);
    }

    // ------------------------------------------------------------ dos cotizaciones, un cliente, una factura

    public function test_dos_cotizaciones_del_mismo_cliente_se_unen_en_una_factura_y_el_producto_repetido_suma_cantidad(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2), $this->linea('DIE', 5, 3)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2), $this->linea('SRV', 1, 50)]);

        $r = $this->convertir([$q1, $q2])->assertCreated();

        $this->assertSame(1, Invoice::count(), 'Una sola factura para las dos cotizaciones');
        $f = Invoice::firstOrFail();
        $this->assertSame($f->id, $r->json('invoice.id'));
        $this->assertSame($this->hospital->id, $f->contact_id, 'Sin cliente indicado va al de las cotizaciones');

        // GAS 10 + 20 al mismo precio = una línea de 30; DIE 5; SRV 1
        $porCodigo = collect($f->items)->keyBy('codigo_principal');
        $this->assertCount(3, $f->items);
        $this->assertEquals(30.0, (float) $porCodigo['GAS']['cantidad']);
        $this->assertEquals(2.0, (float) $porCodigo['GAS']['precio_unitario']);
        $this->assertEquals(5.0, (float) $porCodigo['DIE']['cantidad']);
        $this->assertEquals(1.0, (float) $porCodigo['SRV']['cantidad']);

        // 60 + 15 + 50 = 125; IVA 15% = 18.75; total 143.75
        $this->assertEquals(125.00, (float) $f->total_sin_impuestos);
        $this->assertEquals(18.75, (float) $f->total_impuesto);
        $this->assertEquals(143.75, (float) $f->importe_total);
        $this->assertEquals(143.75, (float) $f->saldo_pendiente, 'A crédito: queda por cobrar');

        // Las dos cotizaciones quedan facturadas y apuntan a la factura
        foreach ([$q1, $q2] as $q) {
            $q = $q->fresh();
            $this->assertSame('facturada', $q->estado);
            $this->assertSame($f->id, $q->invoice_id);
        }
    }

    public function test_el_listado_de_cotizaciones_muestra_la_factura_en_que_terminaron(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 5, 2)]);
        $q3 = $this->cotizacion($this->hospital, [$this->linea('GAS', 1, 2)]);
        $this->convertir([$q1, $q2])->assertCreated();
        $f = Invoice::firstOrFail();

        $lista = collect($this->getJson('/api/quotes?company_id='.$this->company->id)->assertOk()->json())->keyBy('id');

        $this->assertSame('facturada', $lista[$q1->id]['estado']);
        $this->assertSame($f->numero, $lista[$q1->id]['invoice']['numero']);
        $this->assertSame($f->numero, $lista[$q2->id]['invoice']['numero']);
        $this->assertSame('pendiente', $lista[$q3->id]['estado']);
        $this->assertNull($lista[$q3->id]['invoice']);
    }

    public function test_el_stock_baja_una_sola_vez_con_la_cantidad_unida_y_el_asiento_lleva_el_costo_de_ventas(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2), $this->linea('DIE', 5, 3)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2), $this->linea('SRV', 1, 50)]);

        $this->convertir([$q1, $q2])->assertCreated();
        $f = Invoice::firstOrFail();

        $this->assertEquals(70.0, (float) $this->gas->fresh()->stock, '100 - 30, no 100 - 10 - 20 - 30');
        $this->assertEquals(95.0, (float) $this->diesel->fresh()->stock);
        $egresosGas = InventoryMovement::where('product_id', $this->gas->id)->where('tipo', 'egreso')->get();
        $this->assertCount(1, $egresosGas, 'Un solo egreso de gasolina para toda la factura');
        $this->assertEquals(30.0, (float) $egresosGas[0]->cantidad);
        $this->assertSame($f->id, $egresosGas[0]->invoice_id);

        // Asiento normal de la venta: CxC / Ventas / IVA, y Costo de ventas 30 x 1.00 + 5 x 1.50 = 37.50 contra Inventario
        [$asiento, $c] = $this->asiento($f);
        $this->assertEquals(143.75, $c['1.1.03']['debe']);
        $this->assertEquals(125.00, $c['4.1.01']['haber']);
        $this->assertEquals(18.75, $c['2.1.02']['haber']);
        $this->assertEquals(37.50, $c['5.1.04']['debe']);
        $this->assertEquals(37.50, $c['1.1.05']['haber']);
        $this->assertEquals((float) $asiento->total_debe, (float) $asiento->total_haber);

        // Sale con su comprobante del SRI, como cualquier otra factura
        $this->assertNotNull($f->sriDocument);
        $this->assertNotEmpty($f->sriDocument->clave_acceso);
    }

    public function test_el_mismo_producto_a_distinto_precio_o_tarifa_no_se_une(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2.5), $this->linea('GAS', 4, 2, ['tarifa' => 0])]);

        $this->convertir([$q1, $q2])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertCount(3, $f->items, 'GAS a 2.00 al 15%, GAS a 2.50 y GAS a 2.00 al 0% son tres renglones');
        $this->assertEquals(66.0, (float) $this->gas->fresh()->stock, 'El stock baja 10 + 20 + 4');
        // 20 + 50 + 8 = 78 sin impuestos; IVA solo sobre 20 + 50
        $this->assertEquals(78.00, (float) $f->total_sin_impuestos);
        $this->assertEquals(10.50, (float) $f->total_impuesto);
    }

    public function test_los_descuentos_de_la_linea_se_suman_al_unir_y_llegan_a_la_factura(): void
    {
        // La cotización guarda el descuento (antes la validación lo descartaba)
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2, ['descuento' => 1])]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2, ['descuento' => 3])]);
        $this->assertEquals(1.0, (float) $q1->fresh()->items[0]['descuento']);
        $this->assertEquals(19.0, (float) $q1->fresh()->total_sin_impuestos);

        $this->convertir([$q1, $q2])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertCount(1, $f->items);
        $this->assertEquals(4.0, (float) $f->items[0]['descuento'], '1 + 3');
        // 30 x 2 = 60 - 4 = 56; IVA 8.40; total 64.40
        $this->assertEquals(56.00, (float) $f->total_sin_impuestos);
        $this->assertEquals(8.40, (float) $f->total_impuesto);
        $this->assertEquals(64.40, (float) $f->importe_total);
    }

    // ------------------------------------------------------------ factura a otro cliente

    public function test_la_factura_puede_ir_a_un_cliente_distinto_del_de_las_cotizaciones(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 5, 2)]);

        $this->convertir([$q1, $q2], ['contact_id' => $this->otroCliente->id])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertSame($this->otroCliente->id, $f->contact_id);
        $this->assertSame($f->id, $q1->fresh()->invoice_id);
        $this->assertSame('facturada', $q2->fresh()->estado);
        // La deuda es del cliente al que se facturó
        $this->assertEquals(34.50, (float) $f->saldo_pendiente);
        $this->assertSame(0, Invoice::where('contact_id', $this->hospital->id)->count());
    }

    public function test_cotizaciones_de_distintos_clientes_sin_elegir_a_quien_facturar_da_422_y_no_se_crea_nada(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->otroCliente, [$this->linea('GAS', 5, 2)]);

        $r = $this->convertir([$q1, $q2])->assertStatus(422);

        $this->assertStringContainsString('cliente', mb_strtolower($r->json('message')));
        $this->assertNadaCreado();
        $this->assertSame('pendiente', $q1->fresh()->estado);
        $this->assertSame('pendiente', $q2->fresh()->estado);

        // Eligiendo a quién se factura sí se puede
        $this->convertir([$q1, $q2], ['contact_id' => $this->hospital->id])->assertCreated();
        $this->assertSame(1, Invoice::count());
    }

    public function test_el_cliente_a_facturar_tiene_que_ser_de_la_empresa(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'estab' => '001', 'pto_emi' => '001']);
        $ajeno = $this->contacto($otra, 'Cliente Ajeno', '1700000009');
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);

        $this->convertir([$q1], ['contact_id' => $ajeno->id])->assertStatus(422);

        $this->assertNadaCreado();
        $this->assertSame('pendiente', $q1->fresh()->estado);
    }

    // ------------------------------------------------------------ lo que no se puede facturar

    public function test_una_cotizacion_ya_facturada_da_422_en_espanol_y_no_se_crea_nada(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 5, 2)]);
        $q2->update(['estado' => 'facturada']);

        $r = $this->convertir([$q1, $q2])->assertStatus(422);

        $this->assertStringContainsString('#'.$q2->id, $r->json('message'));
        $this->assertStringContainsString('ya', mb_strtolower($r->json('message')));
        $this->assertStringContainsString('factur', mb_strtolower($r->json('message')));
        $this->assertNadaCreado();
        $this->assertSame('pendiente', $q1->fresh()->estado, 'La que sí estaba pendiente no se toca');
        $this->assertNull($q1->fresh()->invoice_id);
    }

    public function test_una_cotizacion_anulada_da_422_y_no_se_crea_nada(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 5, 2)]);
        $q2->update(['estado' => 'anulada']);

        $r = $this->convertir([$q1, $q2])->assertStatus(422);

        $this->assertStringContainsString('anulada', mb_strtolower($r->json('message')));
        $this->assertNadaCreado();
    }

    public function test_si_la_suma_no_cabe_en_el_stock_nada_queda_hecho(): void
    {
        // Cada una cabe sola (60 de 100) pero juntas piden 120: falla al sacar el inventario, ya con la factura a medio crear
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 60, 2)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 60, 2)]);

        $r = $this->convertir([$q1, $q2])->assertStatus(422);

        $this->assertStringContainsString('Stock insuficiente', $r->json('message'));
        $this->assertNadaCreado();
        foreach ([$q1, $q2] as $q) {
            $this->assertSame('pendiente', $q->fresh()->estado, 'Una sola transacción: ninguna cotización queda marcada');
            $this->assertNull($q->fresh()->invoice_id);
        }
    }

    public function test_una_cotizacion_de_otra_empresa_da_404_y_no_se_crea_nada(): void
    {
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'estab' => '001', 'pto_emi' => '001']);
        $ajeno = $this->contacto($otra, 'Cliente Ajeno', '1700000009');
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $qAjena = $this->cotizacion($ajeno, [$this->linea('GAS', 1, 2)], $otra);

        $this->convertir([$q1, $qAjena])->assertNotFound();

        $this->assertNadaCreado();
        $this->assertSame('pendiente', $q1->fresh()->estado);
        $this->assertSame('pendiente', $qAjena->fresh()->estado);
    }

    public function test_sin_cotizaciones_o_con_ids_repetidos_da_422(): void
    {
        $this->postJson('/api/quotes/convert-many', ['company_id' => $this->company->id, 'quote_ids' => []])->assertStatus(422);

        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);
        $this->postJson('/api/quotes/convert-many', ['company_id' => $this->company->id, 'quote_ids' => [$q1->id, $q1->id], 'forma_pago' => 'credito'])->assertStatus(422);
        $this->assertNadaCreado();
    }

    // ------------------------------------------------------------ vista previa y conversión de una sola

    public function test_la_vista_previa_trae_las_lineas_unidas_el_total_y_el_cliente_por_defecto(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2), $this->linea('DIE', 5, 3)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2)]);

        $r = $this->postJson('/api/quotes/merge-preview', ['company_id' => $this->company->id, 'quote_ids' => [$q1->id, $q2->id]])->assertOk();

        $this->assertSame($this->hospital->id, $r->json('contact_id'), 'Todas del mismo cliente: es el cliente por defecto');
        $this->assertCount(2, $r->json('items'));
        $this->assertEquals(30.0, (float) collect($r->json('items'))->firstWhere('codigo_principal', 'GAS')['cantidad']);
        $this->assertEquals(75.00, (float) $r->json('total_sin_impuestos'));
        $this->assertEquals(86.25, (float) $r->json('importe_total'));
        $this->assertSame(0, Invoice::count(), 'La vista previa no crea nada');

        $q3 = $this->cotizacion($this->otroCliente, [$this->linea('GAS', 1, 2)]);
        $r = $this->postJson('/api/quotes/merge-preview', ['company_id' => $this->company->id, 'quote_ids' => [$q1->id, $q3->id]])->assertOk();
        $this->assertNull($r->json('contact_id'), 'Clientes distintos: no hay cliente por defecto');
    }

    public function test_convertir_una_cotizacion_dos_veces_ya_no_emite_dos_facturas(): void
    {
        $q = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2)]);

        $this->postJson("/api/quotes/{$q->id}/convert", ['forma_pago' => 'credito'])->assertOk()->assertJsonPath('invoice.contact_id', $this->hospital->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(Invoice::first()->id, $q->fresh()->invoice_id);

        $this->postJson("/api/quotes/{$q->id}/convert", ['forma_pago' => 'credito'])->assertStatus(422);
        $this->assertSame(1, Invoice::count(), 'La segunda vez no emite otra factura ni vuelve a sacar inventario');
        $this->assertEquals(90.0, (float) $this->gas->fresh()->stock);
    }

    // ------------------------------------------------------------ el chequeo contable sigue en verde

    public function test_el_chequeo_contable_estricto_queda_en_cero_despues_de_unir_cotizaciones(): void
    {
        $q1 = $this->cotizacion($this->hospital, [$this->linea('GAS', 10, 2), $this->linea('DIE', 5, 3)]);
        $q2 = $this->cotizacion($this->hospital, [$this->linea('GAS', 20, 2), $this->linea('SRV', 1, 50)]);
        $this->convertir([$q1, $q2], ['contact_id' => $this->otroCliente->id])->assertCreated();

        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
    }
}
