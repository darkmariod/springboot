<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * T7.2 · A2: una compra manual con sustento 03 o 04 (activo fijo) se capitaliza: la base va a 1.2.01 Activos fijos y no entra al
 * inventario ni al gasto. La ficha del activo se crea aparte, ligada a la compra, y no vuelve a asentar el costo. La importación
 * del XML conserva su comportamiento de siempre y solo avisa.
 */
class CompraActivoFijoTest extends ActivosFijosTestCase
{
    private int $n = 0;

    private function comprar(string $sustento, float $precio = 1000, string $fecha = '2026-09-05'): TestResponse
    {
        return $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => '001-001-'.str_pad((string) ++$this->n, 9, '0', STR_PAD_LEFT), 'fecha_emision' => $fecha,
            'sustento_tributario' => $sustento,
            'items' => [['codigo_principal' => 'LAPTOP-01', 'descripcion' => 'Laptop', 'cantidad' => 1, 'precio_unitario' => $precio, 'tarifa' => 15]],
        ]);
    }

    public function test_una_compra_con_sustento_de_activo_fijo_se_capitaliza_en_lugar_de_ir_a_inventario_o_gasto(): void
    {
        foreach (['03', '04'] as $sustento) {
            $r = $this->comprar($sustento)->assertCreated();
            $r->assertJsonPath('capitalizada', true)->assertJsonPath('sustento_tributario', $sustento);

            $compra = Purchase::findOrFail($r->json('id'));
            $asiento = $this->asientoDe($compra);
            // Debe Activos fijos (base) + Crédito tributario IVA / Haber Cuentas por pagar (total)
            $this->assertLineas($asiento, [['1.2.01', 1000, 0], ['1.1.04', 150, 0], ['2.1.01', 0, 1150]]);
            $this->assertSame('2026-09-05', $asiento->fecha->toDateString());
        }

        // No entra al kárdex ni crea productos: un activo no es mercadería
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, Product::count());
        $this->assertEquals(2000, $this->saldo('activos_fijos'));
        $this->assertEquals(0, $this->saldo('inventario'));
        $this->assertEquals(0, $this->saldo('compras'));

        $this->chequeoEstricto()->assertExitCode(0);
        $this->artisan('inventario:auditar', ['--empresa' => $this->company->id])->assertExitCode(0);
    }

    public function test_una_compra_con_otro_sustento_sigue_igual(): void
    {
        $r = $this->comprar('01')->assertCreated();

        $r->assertJsonPath('capitalizada', false);
        $asiento = $this->asientoDe(Purchase::findOrFail($r->json('id')));
        $this->assertSame(0, $asiento->lines()->whereHas('account', fn ($q) => $q->where('codigo', '1.2.01'))->count());
        $this->assertEquals(0, $this->saldo('activos_fijos'));
        $this->assertSame(1, InventoryMovement::count());   // el producto sí entra al inventario, como siempre
    }

    public function test_la_importacion_del_xml_no_cambia_y_solo_avisa_si_el_sustento_es_de_activo_fijo(): void
    {
        $r = $this->post('/api/purchases/import', [
            'company_id' => $this->company->id, 'sustento_tributario' => '03',
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $this->xmlCompra('000000301')),
        ], ['Accept' => 'application/json'])->assertCreated();

        $r->assertJsonPath('sustento_tributario', '03');
        $this->assertNotEmpty($r->json('aviso_activo_fijo'));
        $this->assertStringContainsString('ficha de activo fijo', $r->json('aviso_activo_fijo'));
        $asiento = $this->asientoDe(Purchase::findOrFail($r->json('id')));
        $this->assertSame(0, $asiento->lines()->whereHas('account', fn ($q) => $q->where('codigo', '1.2.01'))->count());   // sigue a inventario o gasto
        $this->assertEquals(0, $this->saldo('activos_fijos'));

        $r = $this->post('/api/purchases/import', [
            'company_id' => $this->company->id, 'sustento_tributario' => '01',
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $this->xmlCompra('000000302')),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertNull($r->json('aviso_activo_fijo'));
    }

    // ------------------------------------------------------------- ficha ligada a la compra

    public function test_las_compras_de_activo_fijo_sin_ficha_se_ofrecen_para_ligarlas(): void
    {
        $capitalizada = Purchase::findOrFail($this->comprar('03')->json('id'));
        $this->comprar('01')->assertCreated();   // otra clase de compra: no es candidata

        $r = $this->getJson("/api/fixed-assets/compras?company_id={$this->company->id}")->assertOk();

        $this->assertCount(1, $r->json());
        $this->assertSame($capitalizada->id, $r->json('0.id'));
        $this->assertSame($capitalizada->numero, $r->json('0.numero'));
        $this->assertSame('Proveedor de Equipos', $r->json('0.proveedor'));
        $this->assertTrue($r->json('0.capitalizada'));
        $this->assertEquals(1000, $r->json('0.base'));
        $this->assertEquals(1000, $r->json('0.disponible'));
        $this->assertSame('2026-09-05', $r->json('0.fecha_emision'));
    }

    public function test_la_ficha_ligada_a_una_compra_capitalizada_no_vuelve_a_asentar_el_costo(): void
    {
        $compra = Purchase::findOrFail($this->comprar('03')->json('id'));
        $asientos = JournalEntry::count();

        $datos = $this->datosActivo(['purchase_id' => $compra->id, 'costo' => 1000, 'fecha_adquisicion' => '2026-09-05', 'fecha_inicio_depreciacion' => '2026-09-01']);
        unset($datos['cuenta_contrapartida_id']);
        $r = $this->postJson('/api/fixed-assets', $datos)->assertCreated();

        $r->assertJsonPath('purchase_id', $compra->id)->assertJsonPath('compra_numero', $compra->numero);
        $this->assertSame($asientos, JournalEntry::count());          // el costo ya estaba asentado por la compra
        $this->assertNull(FixedAsset::findOrFail($r->json('id'))->journal_entry_alta_id);
        $this->assertEquals(1000, $this->saldo('activos_fijos'));

        // La compra ya no tiene saldo por capitalizar: deja de ofrecerse
        $this->assertSame([], $this->getJson("/api/fixed-assets/compras?company_id={$this->company->id}")->json());
        // Y no se puede crear otra ficha por más de lo que la compra capitalizó
        $this->crear(['purchase_id' => $compra->id, 'costo' => 1, 'fecha_adquisicion' => '2026-09-05', 'fecha_inicio_depreciacion' => '2026-09-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['costo']);
        $this->assertSame(1, FixedAsset::count());

        // La depreciación sigue su camino normal y el chequeo cuadra
        $this->mes('2026-09')->assertCreated();
        $this->chequeoEstricto()->assertExitCode(0);
    }

    public function test_una_compra_con_varias_fichas_reparte_lo_capitalizado(): void
    {
        $compra = Purchase::findOrFail($this->comprar('04')->json('id'));
        $base = ['purchase_id' => $compra->id, 'fecha_adquisicion' => '2026-09-05', 'fecha_inicio_depreciacion' => '2026-09-01'];
        $sin = fn (array $d) => array_diff_key($d, ['cuenta_contrapartida_id' => 1]);

        $this->postJson('/api/fixed-assets', $sin($this->datosActivo($base + ['nombre' => 'Monitor', 'costo' => 400])))->assertCreated();
        $lista = $this->getJson("/api/fixed-assets/compras?company_id={$this->company->id}")->assertOk();
        $this->assertEquals(600, $lista->json('0.disponible'));

        $this->postJson('/api/fixed-assets', $sin($this->datosActivo($base + ['nombre' => 'Torre', 'costo' => 700])))
            ->assertStatus(422)->assertJsonValidationErrors(['costo']);
        $this->postJson('/api/fixed-assets', $sin($this->datosActivo($base + ['nombre' => 'Torre', 'costo' => 600])))->assertCreated();
        $this->assertSame([], $this->getJson("/api/fixed-assets/compras?company_id={$this->company->id}")->json());
    }

    public function test_una_compra_que_no_se_capitalizo_pide_la_cuenta_donde_se_asento(): void
    {
        // Una compra con sustento 01: su base fue a Inventario o a gasto, no a Activos fijos
        $compra = Purchase::findOrFail($this->comprar('01', 800)->json('id'));
        $datos = $this->datosActivo(['purchase_id' => $compra->id, 'costo' => 800, 'cuenta_contrapartida_id' => null]);

        $this->postJson('/api/fixed-assets', $datos)->assertStatus(422)->assertJsonValidationErrors(['cuenta_contrapartida_id']);
        $this->assertSame(0, FixedAsset::count());

        // Con la cuenta de inventario como contrapartida se reclasifica: Debe Activos fijos / Haber Inventario
        $this->postJson('/api/fixed-assets', array_merge($datos, ['cuenta_contrapartida_id' => $this->cuentaId('inventario')]))->assertCreated();
        $alta = $this->asientoDe(FixedAsset::sole());
        $this->assertLineas($alta, [['1.2.01', 800, 0], ['1.1.05', 0, 800]]);
    }

    public function test_una_compra_de_otra_empresa_no_se_puede_ligar(): void
    {
        $ajena = Purchase::create([
            'company_id' => $this->otra->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000999', 'fecha_emision' => '2026-09-05',
            'sustento_tributario' => '03', 'items' => [], 'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'saldo_pendiente' => 115,
        ]);

        $this->crear(['purchase_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors(['purchase_id']);
        $this->assertSame(0, FixedAsset::count());
    }

    public function test_no_se_elimina_una_compra_que_ya_tiene_ficha_de_activo(): void
    {
        $compra = Purchase::findOrFail($this->comprar('03')->json('id'));
        $datos = $this->datosActivo(['purchase_id' => $compra->id, 'costo' => 1000, 'fecha_adquisicion' => '2026-09-05', 'fecha_inicio_depreciacion' => '2026-09-01']);
        unset($datos['cuenta_contrapartida_id']);
        $this->postJson('/api/fixed-assets', $datos)->assertCreated();

        $this->deleteJson("/api/purchases/{$compra->id}")->assertStatus(422);
        $this->assertTrue(Purchase::whereKey($compra->id)->exists());
        $this->assertEquals(1000, $this->saldo('activos_fijos'));
    }

    // ------------------------------------------------------------- helpers

    private function xmlCompra(string $secuencial): string
    {
        $base = 100;
        $clave = str_pad($secuencial, 49, '1', STR_PAD_LEFT);

        return '<factura><infoTributaria><ruc>1790011223001</ruc><razonSocial>Proveedor Test</razonSocial>'
            ."<estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$secuencial}</secuencial><claveAcceso>{$clave}</claveAcceso></infoTributaria>"
            ."<infoFactura><fechaEmision>05/10/2026</fechaEmision><totalSinImpuestos>{$base}</totalSinImpuestos>"
            .'<totalConImpuestos><totalImpuesto><codigo>2</codigo><valor>15</valor></totalImpuesto></totalConImpuestos>'
            .'<importeTotal>115</importeTotal></infoFactura><detalles>'
            ."<detalle><codigoPrincipal>XML-1</codigoPrincipal><descripcion>XML-1</descripcion><cantidad>1</cantidad><precioUnitario>{$base}</precioUnitario>"
            ."<impuestos><impuesto><codigo>2</codigo><tarifa>15</tarifa><baseImponible>{$base}</baseImponible><valor>15</valor></impuesto></impuestos></detalle>"
            .'</detalles></factura>';
    }
}
