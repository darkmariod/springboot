<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StorePurchaseFromXml;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 / T1.5: la mercadería no es gasto.
 *   Compra de bienes  -> Debe 1.1.05 Inventario (servicios y gastos siguen en 5.1.01).
 *   Venta de bienes   -> Debe Costo de ventas / Haber Inventario, en el MISMO asiento de la factura.
 *   Devolución (NC)   -> Debe Inventario / Haber Costo de ventas, con el costo al que salió.
 */
class InventarioContableTest extends TestCase
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
            'ruc' => '1791234567001',
            'razon_social' => 'Empresa Inventario Test SA',
            'dir_matriz' => 'Av. Test 123',
            'estab' => '001',
            'pto_emi' => '001',
            'plan' => 'completo',
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

    // ------------------------------------------------------------ compras

    public function test_compra_de_un_bien_va_a_inventario_y_no_a_compras(): void
    {
        $compra = $this->comprar([['MERC-1', 10, 5]]);

        $this->assertAsiento($compra, [
            ['1.1.05', 50, 0],
            ['1.1.04', 7.5, 0],
            ['2.1.01', 0, 57.5],
        ]);
        $this->assertSame(0.0, $this->saldo('5.1.01'));
        $this->assertSame(10.0, (float) Product::where('codigo', 'MERC-1')->value('stock'));
    }

    public function test_compra_mixta_parte_la_base_entre_inventario_y_compras_por_linea(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio de flete', 'tipo' => 'servicio']);

        $compra = $this->comprar([['MERC-1', 10, 5], ['SERV-1', 1, 40]]);

        $this->assertAsiento($compra, [
            ['1.1.05', 50, 0],   // el bien
            ['5.1.01', 40, 0],   // el servicio sigue siendo gasto
            ['1.1.04', 13.5, 0],
            ['2.1.01', 0, 103.5],
        ]);
        // El servicio no lleva stock
        $this->assertSame(0, InventoryMovement::where('product_id', Product::where('codigo', 'SERV-1')->value('id'))->count());
    }

    public function test_compra_solo_de_servicios_va_toda_a_compras(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio de flete', 'tipo' => 'servicio']);

        $compra = $this->comprar([['SERV-1', 2, 25]]);

        $this->assertAsiento($compra, [
            ['5.1.01', 50, 0],
            ['1.1.04', 7.5, 0],
            ['2.1.01', 0, 57.5],
        ]);
        $this->assertSame(0.0, $this->saldo('1.1.05'));
    }

    public function test_compra_con_descuento_lleva_al_inventario_el_costo_neto(): void
    {
        $compra = $this->comprar([['MERC-1', 10, 5, 5]]);   // 50 - 5 de descuento = 45

        $this->assertAsiento($compra, [
            ['1.1.05', 45, 0],
            ['1.1.04', 6.75, 0],
            ['2.1.01', 0, 51.75],
        ]);
        $mov = InventoryMovement::where('purchase_id', $compra->id)->firstOrFail();
        $this->assertEqualsWithDelta(4.5, (float) $mov->costo_unitario, 0.0001);
        $this->assertEqualsWithDelta(45.0, (float) $mov->saldo_valor, 0.001);
    }

    public function test_compra_importada_por_xml_separa_inventario_y_compras(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'XML-SERV', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);

        $xml = $this->xmlCompra('000000123', [['XML-BIEN', 4, 25], ['XML-SERV', 1, 30]]);
        $this->post('/api/purchases/import', [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $xml),
        ], ['Accept' => 'application/json'])->assertCreated();
        $compra = Purchase::firstOrFail();

        $this->assertAsiento($compra, [
            ['1.1.05', 100, 0],
            ['5.1.01', 30, 0],
            ['1.1.04', 19.5, 0],
            ['2.1.01', 0, 149.5],
        ]);
        // El ingreso al kárdex queda ligado a la compra (el chequeo contable lo necesita)
        $this->assertSame($compra->id, InventoryMovement::firstOrFail()->purchase_id);
    }

    public function test_compra_guardada_desde_xml_con_el_servicio_tambien_separa_inventario_y_compras(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'XML-SERV', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);

        $xml = $this->xmlCompra('000000124', [['XML-BIEN', 2, 50], ['XML-SERV', 1, 10]]);
        $compra = app(StorePurchaseFromXml::class)->handle($this->company, $xml);

        $this->assertAsiento($compra, [
            ['1.1.05', 100, 0],
            ['5.1.01', 10, 0],
            ['1.1.04', 16.5, 0],
            ['2.1.01', 0, 126.5],
        ]);
        $this->assertSame($compra->id, InventoryMovement::firstOrFail()->purchase_id);
    }

    // ------------------------------------------------------------- ventas

    public function test_venta_de_un_bien_asienta_costo_de_ventas_contra_inventario_en_el_mismo_asiento(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);

        $factura = $this->facturar([['MERC-1', 4, 10]]);

        // Un solo asiento: venta (cobro en caja) + costo (4 x 5 = 20)
        $this->assertAsiento($factura, [
            ['1.1.01', 46, 0],
            ['4.1.01', 0, 40],
            ['2.1.02', 0, 6],
            ['5.1.04', 20, 0],
            ['1.1.05', 0, 20],
        ]);
        $this->assertSame('Costo de ventas', Account::where('company_id', $this->company->id)->where('codigo', '5.1.04')->value('nombre'));
    }

    public function test_factura_de_solo_servicios_no_tiene_lineas_de_costo(): void
    {
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Consultoría', 'tipo' => 'servicio']);

        $factura = $this->facturar([['SERV-1', 2, 50]]);

        $this->assertAsiento($factura, [
            ['1.1.01', 115, 0],
            ['4.1.01', 0, 100],
            ['2.1.02', 0, 15],
        ]);
        $codigos = JournalEntryLine::with('account')->get()->pluck('account.codigo')->all();
        $this->assertNotContains('5.1.04', $codigos);
        $this->assertNotContains('1.1.05', $codigos);
    }

    public function test_factura_mixta_solo_costea_el_bien(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Instalación', 'tipo' => 'servicio']);

        $factura = $this->facturar([['MERC-1', 2, 10], ['SERV-1', 1, 30]]);

        $this->assertAsiento($factura, [
            ['1.1.01', 57.5, 0],
            ['4.1.01', 0, 50],
            ['2.1.02', 0, 7.5],
            ['5.1.04', 10, 0],   // solo 2 x 5 del bien
            ['1.1.05', 0, 10],
        ]);
    }

    public function test_el_costo_de_ventas_usa_el_costo_promedio_del_kardex(): void
    {
        $this->comprar([['MERC-1', 10, 5]], 'A-1');
        $this->comprar([['MERC-1', 10, 7]], 'A-2');   // promedio 6

        $factura = $this->facturar([['MERC-1', 5, 10]]);

        $this->assertAsiento($factura, [
            ['1.1.01', 57.5, 0],
            ['4.1.01', 0, 50],
            ['2.1.02', 0, 7.5],
            ['5.1.04', 30, 0],
            ['1.1.05', 0, 30],
        ]);
    }

    public function test_venta_a_credito_deja_cxc_y_venta_en_efectivo_no(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);

        $credito = $this->facturar([['MERC-1', 1, 10]], 'credito');
        $this->assertAsiento($credito, [
            ['1.1.03', 11.5, 0],
            ['4.1.01', 0, 10],
            ['2.1.02', 0, 1.5],
            ['5.1.04', 5, 0],
            ['1.1.05', 0, 5],
        ]);
        $this->assertSame(11.5, (float) $credito->saldo_pendiente);

        $this->assertSame(11.5, $this->saldo('1.1.03'));
    }

    public function test_ventas_cobradas_al_instante_entran_a_caja_banco_o_tarjetas_y_no_dejan_cxc_abierta(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);

        foreach (['efectivo' => '1.1.01', 'transferencia' => '1.1.02', 'tarjeta' => '1.1.08'] as $forma => $cuenta) {
            $factura = $this->facturar([['MERC-1', 1, 10]], $forma);

            $this->assertAsiento($factura, [
                [$cuenta, 11.5, 0],
                ['4.1.01', 0, 10],
                ['2.1.02', 0, 1.5],
                ['5.1.04', 5, 0],
                ['1.1.05', 0, 5],
            ]);
            $this->assertSame(0.0, (float) $factura->saldo_pendiente);
        }
        // CxC del libro mayor = suma de saldos pendientes de las facturas (cero)
        $this->assertSame(0.0, $this->saldo('1.1.03'));
    }

    // ---------------------------------------- nota de crédito con devolución

    public function test_compra_venta_y_nota_de_credito_parcial_dejan_inventario_costo_y_compras_cuadrados(): void
    {
        $this->comprar([['MERC-1', 10, 5]], 'A-1');
        $this->comprar([['MERC-1', 10, 7]], 'A-2');   // 20 u., costo promedio 6, inventario 120
        $factura = $this->facturar([['MERC-1', 5, 10]]);   // sale a 6: costo 30

        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id,
            'contact_id' => $this->cliente->id,
            'invoice_id' => $factura->id,
            'tipo' => 'sri',
            'motivo' => 'Devolución parcial',
            'items' => [[
                'codigo_principal' => 'MERC-1', 'descripcion' => 'Mercadería', 'cantidad' => 2,
                'precio_unitario' => 10, 'tarifa' => 15,
            ]],
        ])->assertCreated();
        $nota = CreditNote::firstOrFail();

        // La NC acredita la venta (Debe devoluciones por el subtotal + Debe IVA por pagar / Haber CxC por el total)
        // y devuelve el costo: 2 x 6 = 12
        $this->assertAsiento($nota, [
            ['4.1.02', 20, 0],
            ['2.1.02', 3, 0],
            ['1.1.03', 0, 23],
            ['1.1.05', 12, 0],
            ['5.1.04', 0, 12],
        ]);

        // Saldos finales por cuenta
        $this->assertSame(102.0, $this->saldo('1.1.05'));   // 120 - 30 + 12
        $this->assertSame(18.0, $this->saldo('5.1.04'));    // 30 - 12
        $this->assertSame(0.0, $this->saldo('5.1.01'));     // nada de mercadería fue a gasto
        // y el kárdex coincide con el libro: 17 u. x $6 = 102
        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $this->assertSame(17.0, (float) $producto->stock);
        $this->assertEqualsWithDelta(102.0, (float) $producto->stock * (float) $producto->costo_promedio, 0.01);
    }

    public function test_la_devolucion_usa_el_costo_de_la_venta_aunque_el_promedio_haya_cambiado(): void
    {
        $this->comprar([['MERC-1', 10, 5]], 'A-1');
        $factura = $this->facturar([['MERC-1', 4, 10]]);   // sale a 5: costo 20
        $this->comprar([['MERC-1', 10, 9]], 'A-2', '2099-01-01');   // compra posterior: el promedio sube

        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'sri', 'motivo' => 'Devolución',
            'items' => [['codigo_principal' => 'MERC-1', 'descripcion' => 'Mercadería', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
        ])->assertCreated();

        // Vuelve al costo de la venta (5), no al promedio de hoy
        $this->assertAsiento(CreditNote::firstOrFail(), [
            ['4.1.02', 10, 0],
            ['2.1.02', 1.5, 0],
            ['1.1.03', 0, 11.5],
            ['1.1.05', 5, 0],
            ['5.1.04', 0, 5],
        ]);
    }

    public function test_nota_de_credito_sin_devolucion_de_stock_no_toca_inventario_ni_costo(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);
        $factura = $this->facturar([['MERC-1', 2, 10]]);

        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Descuento comercial', 'importe_total' => 5,
        ])->assertCreated();

        $this->assertAsiento(CreditNote::firstOrFail(), [
            ['4.1.02', 5, 0],
            ['1.1.03', 0, 5],
        ]);
    }

    public function test_anular_una_factura_revierte_tambien_el_costo_de_ventas_y_devuelve_el_inventario(): void
    {
        $this->comprar([['MERC-1', 10, 5]]);
        $factura = $this->facturar([['MERC-1', 4, 10]]);
        $this->assertSame(20.0, $this->saldo('5.1.04'));
        $this->assertSame(30.0, $this->saldo('1.1.05'));

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        // El contra-asiento invierte todas las líneas del original, costo incluido
        $this->assertSame(0.0, $this->saldo('5.1.04'));
        $this->assertSame(50.0, $this->saldo('1.1.05'));
        $this->assertSame(0.0, $this->saldo('4.1.01'));
        $this->assertSame(0.0, $this->saldo('1.1.01'));
        $this->assertSame(10.0, (float) Product::where('codigo', 'MERC-1')->value('stock'));
    }

    public function test_anular_una_factura_devuelve_al_costo_de_la_venta_y_el_inventario_coincide_con_el_kardex(): void
    {
        $this->comprar([['MERC-1', 10, 5]], 'A-1');
        $factura = $this->facturar([['MERC-1', 4, 10]]);                 // sale a 5: costo 20
        $this->comprar([['MERC-1', 10, 9]], 'A-2', '2099-01-01');         // compra posterior: el promedio sube

        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $this->assertSame(20.0, (float) $producto->stock);
        $this->assertSame(140.0, $this->saldo('1.1.05'));   // 50 + 90: la venta anulada ya no cuenta
        $this->assertSame(0.0, $this->saldo('5.1.04'));
        $this->assertEqualsWithDelta(140.0, (float) $producto->stock * (float) $producto->costo_promedio, 0.01);
    }

    // ------------------------------------------------------------ guardas

    public function test_los_generadores_de_asientos_no_escriben_codigos_de_cuenta_a_mano(): void
    {
        $archivos = array_merge(
            glob(app_path('Services/Generate*.php')),
            glob(app_path('Support/*.php')),
        );
        $this->assertNotEmpty($archivos);

        $encontrados = [];
        foreach ($archivos as $archivo) {
            if (basename($archivo) === 'Cuentas.php') {
                continue;
            }
            if (preg_match_all('/[\'"][1-5]\.\d{1,2}\.\d{2}[\'"]/', file_get_contents($archivo), $m)) {
                $encontrados[basename($archivo)] = array_unique($m[0]);
            }
        }
        $this->assertSame([], $encontrados, 'Usa config/cuentas.php (App\\Support\\Cuentas) en lugar de códigos escritos a mano.');
    }

    public function test_costo_de_ventas_es_una_cuenta_de_gasto_del_plan_central_con_codigo_propio(): void
    {
        $cuenta = config('cuentas.cuentas.costo_ventas');
        $this->assertSame('5.1.04', $cuenta['codigo']);
        $this->assertSame('Costo de ventas', $cuenta['nombre']);
        $this->assertSame('gasto', $cuenta['tipo']);
        $this->assertSame(1, count(array_filter(config('cuentas.cuentas'), fn ($c) => $c['codigo'] === '5.1.04')));
    }

    public function test_el_plan_sembrado_incluye_costo_de_ventas(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = Company::where('ruc', '1790000000001')->firstOrFail();

        $cuenta = Account::where('company_id', $demo->id)->where('codigo', '5.1.04')->first();
        $this->assertNotNull($cuenta, 'El seeder no siembra Costo de ventas');
        $this->assertSame('Costo de ventas', $cuenta->nombre);
        $this->assertSame('gasto', $cuenta->tipo);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Compra manual por la API. $lineas: [codigo, cantidad, precio_unitario, descuento?]; IVA 15 %.
     */
    private function comprar(array $lineas, string $numero = 'A-1', string $fecha = '2026-01-10'): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id,
            'contact_id' => $this->proveedor->id,
            'numero' => '001-001-'.str_pad(preg_replace('/\D/', '', $numero) ?: '1', 9, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1],
                'precio_unitario' => $l[2], 'descuento' => $l[3] ?? 0, 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Factura por la API. $lineas: [codigo, cantidad, precio_unitario]; IVA 15 %. */
    private function facturar(array $lineas, string $formaPago = 'efectivo'): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id,
            'contact_id' => $this->cliente->id,
            'forma_pago' => $formaPago,
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1],
                'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function xmlCompra(string $secuencial, array $lineas): string
    {
        $detalles = '';
        $base = 0;
        foreach ($lineas as [$codigo, $cant, $precio]) {
            $sub = $cant * $precio;
            $base += $sub;
            $detalles .= "<detalle><codigoPrincipal>{$codigo}</codigoPrincipal><descripcion>{$codigo}</descripcion>"
                ."<cantidad>{$cant}</cantidad><precioUnitario>{$precio}</precioUnitario>"
                ."<impuestos><impuesto><codigo>2</codigo><tarifa>15</tarifa><baseImponible>{$sub}</baseImponible><valor>".($sub * 0.15).'</valor></impuesto></impuestos></detalle>';
        }
        $iva = $base * 0.15;
        $total = $base + $iva;
        $clave = str_pad($secuencial, 49, '1', STR_PAD_LEFT);

        return "<factura><infoTributaria><ruc>1790011223001</ruc><razonSocial>Proveedor Test</razonSocial>"
            ."<estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$secuencial}</secuencial><claveAcceso>{$clave}</claveAcceso></infoTributaria>"
            ."<infoFactura><fechaEmision>05/10/2026</fechaEmision><totalSinImpuestos>{$base}</totalSinImpuestos>"
            ."<totalConImpuestos><totalImpuesto><codigo>2</codigo><valor>{$iva}</valor></totalImpuesto></totalConImpuestos>"
            ."<importeTotal>{$total}</importeTotal></infoFactura><detalles>{$detalles}</detalles></factura>";
    }

    /** Saldo del libro mayor de una cuenta (Debe - Haber; en las de gasto/activo es el saldo natural). */
    private function saldo(string $codigo): float
    {
        $cuenta = Account::where('company_id', $this->company->id)->where('codigo', $codigo)->first();
        if (! $cuenta) {
            return 0.0;
        }
        $l = JournalEntryLine::where('account_id', $cuenta->id);

        return round((float) $l->sum('debe') - (float) $l->sum('haber'), 2);
    }

    private function assertAsiento(Model $origen, array $esperado): void
    {
        $asientos = JournalEntry::where('origen_type', $origen->getMorphClass())
            ->where('origen_id', $origen->getKey())->get();
        $this->assertCount(1, $asientos, 'Se esperaba exactamente un asiento para '.class_basename($origen).' #'.$origen->getKey());
        $entry = $asientos->first();

        $real = $entry->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->codigo, round((float) $l->debe, 2), round((float) $l->haber, 2)])->all();
        $ordenar = function (array $filas): array {
            $filas = array_map(fn ($f) => [(string) $f[0], round((float) $f[1], 2), round((float) $f[2], 2)], $filas);
            usort($filas, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            return $filas;
        };
        $this->assertEquals($ordenar($esperado), $ordenar($real), 'Líneas del asiento '.$entry->concepto);

        $debe = round(array_sum(array_column($real, 1)), 2);
        $haber = round(array_sum(array_column($real, 2)), 2);
        $this->assertSame($debe, $haber, 'El asiento no cuadra');
        $this->assertEquals($debe, $entry->total_debe);
        $this->assertEquals($haber, $entry->total_haber);
    }
}
