<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Withholding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.1: retención al registrar la compra. Una retención lleva varias líneas (renta / IVA, código,
 * base, porcentaje), baja el saldo de la compra y asienta Debe 2.1.01 / Haber 2.1.09 y 2.1.10.
 * El comprobante electrónico (codDoc 07) se genera con el mismo mecanismo de los demás documentos.
 */
class RetencionCompraTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Retenciones Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test', 'email' => 'prov@test.com',
        ]);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_retencion_de_renta_e_iva_asienta_baja_el_saldo_y_deja_el_chequeo_estricto_en_verde(): void
    {
        $compra = $this->comprar(); // 100 + IVA 15 = 115

        $resp = $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [
                ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100],
                ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15],
            ],
        ])->assertCreated();

        $this->assertSame('001-001-000000001', $resp->json('numero'));
        $this->assertEquals(25.0, $resp->json('total_retenido'));
        $this->assertEquals(90.0, $resp->json('saldo_pendiente'));
        $this->assertCount(2, $resp->json('retenciones'));

        // Una fila por línea, ligadas a la compra y al proveedor, con el código y la base
        $filas = Withholding::where('company_id', $this->company->id)->orderBy('id')->get();
        $this->assertCount(2, $filas);
        [$renta, $iva] = $filas->all();
        $this->assertSame('emitida', $renta->tipo);
        $this->assertSame($compra->id, $renta->purchase_id);
        $this->assertSame($this->proveedor->id, $renta->contact_id);
        $this->assertSame('303', $renta->codigo_retencion);
        $this->assertEquals(100.0, (float) $renta->base_imponible);
        $this->assertEquals(10.0, (float) $renta->porcentaje);
        $this->assertEquals(10.0, (float) $renta->total_retenido);
        $this->assertEquals(15.0, (float) $iva->base_imponible);
        $this->assertEquals(100.0, (float) $iva->porcentaje);
        $this->assertEquals(15.0, (float) $iva->total_retenido);
        // La retención de IVA no lleva código de renta: así no entra al formulario 103
        $this->assertNull($iva->codigo_retencion);

        // Un solo asiento: Debe CxP por el total; Haber retención IVA por pagar y retención renta por pagar
        $asientos = JournalEntry::where('origen_type', $renta->getMorphClass())->whereIn('origen_id', $filas->pluck('id'))->get();
        $this->assertCount(1, $asientos);
        $this->assertLineas($asientos->first(), [['2.1.01', 25, 0], ['2.1.09', 0, 15], ['2.1.10', 0, 10]]);

        $this->assertEquals(90.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(2, (int) $this->company->fresh()->secuencial, 'Una retención gasta un solo secuencial');

        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('Cuentas por pagar (2.1.01): compras pendientes $90.00 = mayor $90.00')
            ->assertExitCode(0);
    }

    public function test_genera_el_comprobante_electronico_de_retencion_en_estado_generado_sin_certificado(): void
    {
        $compra = $this->comprar();

        $resp = $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [
                ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100],
                ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15],
            ],
        ])->assertCreated();

        $principal = Withholding::where('purchase_id', $compra->id)->orderBy('id')->firstOrFail();
        $doc = SriDocument::where('documentable_type', $principal->getMorphClass())->where('documentable_id', $principal->id)->firstOrFail();
        $this->assertSame('comprobanteRetencion', $doc->tipo_comprobante);
        $this->assertSame('generado', $doc->estado);
        $this->assertSame(1, SriDocument::count(), 'Un comprobante por retención, no uno por línea');
        $this->assertSame($doc->clave_acceso, $resp->json('sri_document.clave_acceso'));
        $this->assertSame('generado', $resp->json('sri_document.estado'));
        $this->assertSame($doc->clave_acceso, $principal->fresh()->clave_acceso);

        $xml = simplexml_load_string($doc->xml);
        $this->assertSame('comprobanteRetencion', $xml->getName());
        $this->assertSame('07', (string) $xml->infoTributaria->codDoc);
        $this->assertSame('000000001', (string) $xml->infoTributaria->secuencial);
        $this->assertSame('1790011223001', (string) $xml->infoCompRetencion->identificacionSujetoRetenido);
        $this->assertSame('04', (string) $xml->infoCompRetencion->tipoIdentificacionSujetoRetenido);
        $this->assertSame(now()->format('m/Y'), (string) $xml->infoCompRetencion->periodoFiscal);

        $impuestos = $xml->impuestos->impuesto;
        $this->assertCount(2, $impuestos);
        // Renta: código 1 del SRI, el código de retención del catálogo
        $this->assertSame(['1', '303', '100.00', '10', '10.00', '01', '001001000000099'],
            [(string) $impuestos[0]->codigo, (string) $impuestos[0]->codigoRetencion, (string) $impuestos[0]->baseImponible,
                (string) $impuestos[0]->porcentajeRetener, (string) $impuestos[0]->valorRetenido,
                (string) $impuestos[0]->codDocSustento, (string) $impuestos[0]->numDocSustento]);
        // IVA: código 2 del SRI; el 725 del formulario 104 viaja como código de retención 3 (100%)
        $this->assertSame(['2', '3', '15.00', '100', '15.00'],
            [(string) $impuestos[1]->codigo, (string) $impuestos[1]->codigoRetencion, (string) $impuestos[1]->baseImponible,
                (string) $impuestos[1]->porcentajeRetener, (string) $impuestos[1]->valorRetenido]);
    }

    public function test_la_retencion_mayor_al_saldo_de_la_compra_devuelve_422_y_no_deja_nada(): void
    {
        $compra = $this->comprar();
        $compra->update(['saldo_pendiente' => 20]); // ya se pagó casi todo
        $asientosAntes = JournalEntry::count();

        $resp = $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [
                ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100],
                ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['lineas']);

        $this->assertSame('La retención ($25.00) supera el saldo pendiente de la compra ($20.00).', $resp->json('errors.lineas.0'));
        $this->assertSame(0, Withholding::count());
        $this->assertSame(0, SriDocument::count());
        $this->assertSame($asientosAntes, JournalEntry::count());
        $this->assertEquals(20.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(1, (int) $this->company->fresh()->secuencial, 'No se gasta secuencial');
    }

    public function test_varias_retenciones_a_la_misma_compra_se_acumulan_hasta_agotar_el_saldo(): void
    {
        $compra = $this->comprar(); // saldo 115

        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100]])->assertCreated(); // 10
        $this->retener($compra, [['tipo' => 'iva', 'codigo' => '721', 'base_imponible' => 15]])->assertCreated();    // 4.50
        $this->assertEquals(100.5, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(2, SriDocument::count());
        $this->assertSame(['001-001-000000001', '001-001-000000002'], Withholding::orderBy('id')->pluck('numero')->all());

        // Una tercera que ya no cabe en el saldo
        $compra->update(['saldo_pendiente' => 5]);
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100]])->assertStatus(422);
        $this->assertSame(2, Withholding::count());
    }

    public function test_un_codigo_que_no_existe_o_no_corresponde_al_tipo_devuelve_422_en_espanol(): void
    {
        $compra = $this->comprar();

        // No existe en el catálogo de renta
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '999', 'base_imponible' => 100]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.codigo']);
        // El 725 es de IVA, no de renta; y el 303 es de renta, no de IVA
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '725', 'base_imponible' => 100]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.codigo']);
        $resp = $this->retener($compra, [['tipo' => 'iva', 'codigo' => '303', 'base_imponible' => 15]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.codigo']);
        $this->assertSame('El código 303 no es un código de retención de IVA válido.', $resp->json('errors')['lineas.0.codigo'][0]);

        // Sin código, tipo desconocido, base en cero
        $this->retener($compra, [['tipo' => 'renta', 'base_imponible' => 100]])->assertStatus(422)->assertJsonValidationErrors(['lineas.0.codigo']);
        $this->retener($compra, [['tipo' => 'isd', 'codigo' => '303', 'base_imponible' => 100]])->assertStatus(422)->assertJsonValidationErrors(['lineas.0.tipo']);
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 0]])->assertStatus(422)->assertJsonValidationErrors(['lineas.0.base_imponible']);

        $this->assertSame(0, Withholding::count());
        $this->assertEquals(115.0, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_el_porcentaje_sale_del_codigo_y_solo_los_codigos_variables_lo_piden(): void
    {
        $compra = $this->comprar();

        // El porcentaje que mande la pantalla no pisa el del catálogo (303 = 10%)
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100, 'porcentaje' => 1]])->assertCreated();
        $this->assertEquals(10.0, (float) Withholding::firstOrFail()->total_retenido);

        // 340 es "otros porcentajes": exige el porcentaje
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '340', 'base_imponible' => 50]])
            ->assertStatus(422)->assertJsonValidationErrors(['lineas.0.porcentaje']);
        $this->retener($compra, [['tipo' => 'renta', 'codigo' => '340', 'base_imponible' => 50, 'porcentaje' => 4]])->assertCreated();
        $this->assertEquals(2.0, (float) Withholding::orderByDesc('id')->firstOrFail()->total_retenido);
    }

    public function test_la_forma_anterior_de_una_sola_linea_sigue_funcionando(): void
    {
        $compra = $this->comprar();

        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'tipo' => 'iva', 'porcentaje' => 30, 'base_imponible' => 15,
        ])->assertCreated();

        $w = Withholding::firstOrFail();
        $this->assertEquals(4.5, (float) $w->total_retenido);
        $this->assertEquals(110.5, (float) $compra->fresh()->saldo_pendiente);
        $this->assertLineas(JournalEntry::where('origen_type', $w->getMorphClass())->where('origen_id', $w->id)->firstOrFail(), [['2.1.01', 4.5, 0], ['2.1.09', 0, 4.5]]);
    }

    public function test_si_falla_el_comprobante_electronico_la_retencion_queda_registrada_y_se_puede_reintentar(): void
    {
        $compra = $this->comprar();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->once()->andThrow(new \RuntimeException('El SRI no responde'));

        $resp = $this->retener($compra, [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100]])->assertCreated();

        $this->assertNull($resp->json('sri_document'));
        $this->assertSame('error', $resp->json('emision.estado'));
        $this->assertStringContainsString('El SRI no responde', $resp->json('emision.mensaje'));
        // La contabilidad no se pierde por un problema del comprobante electrónico
        $this->assertEquals(105.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(1, Withholding::count());

        // Reintento: ahora sí se genera, con el mismo número y sin tocar saldos
        $this->app->instance(EmitirSriDocument::class, new EmitirSriDocument());
        $w = Withholding::firstOrFail();
        $this->postJson("/api/withholdings-emitted/{$w->id}/emit", ['company_id' => $this->company->id])
            ->assertOk()->assertJsonPath('sri_document.estado', 'generado');
        $doc = SriDocument::firstOrFail();
        $this->assertSame('000000001', (string) simplexml_load_string($doc->xml)->infoTributaria->secuencial);
        $this->assertEquals(105.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertSame(2, (int) $this->company->fresh()->secuencial);

        // Una segunda vez no duplica el comprobante
        $this->postJson("/api/withholdings-emitted/{$w->id}/emit", ['company_id' => $this->company->id])->assertOk();
        $this->assertSame(1, SriDocument::count());
    }

    public function test_lista_las_retenciones_de_una_compra_agrupadas_por_comprobante(): void
    {
        $compra = $this->comprar();
        $this->retener($compra, [
            ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100],
            ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15],
        ])->assertCreated();

        $r = $this->getJson("/api/purchases/{$compra->id}/withholdings?company_id={$this->company->id}")->assertOk()->json();

        $this->assertCount(1, $r['comprobantes']);
        $c = $r['comprobantes'][0];
        $this->assertSame('001-001-000000001', $c['numero']);
        $this->assertEquals(25.0, $c['total_retenido']);
        $this->assertSame('generado', $c['estado_sri']);
        $this->assertSame(
            [['renta', '303', 100.0, 10.0, 10.0], ['iva', '725', 15.0, 100.0, 15.0]],
            array_map(fn ($l) => [$l['tipo'], $l['codigo'], (float) $l['base_imponible'], (float) $l['porcentaje'], (float) $l['valor_retenido']], $c['lineas']),
        );
        $this->assertEquals(25.0, $r['total_retenido']);
        $this->assertEquals(90.0, $r['saldo_pendiente']);
        $this->assertEquals(115.0, $r['importe_total']);
    }

    public function test_el_formulario_103_toma_la_retencion_de_renta_por_su_codigo_y_deja_la_de_iva_para_el_104(): void
    {
        $compra = $this->comprar();
        $this->retener($compra, [
            ['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100],
            ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15],
        ])->assertCreated();

        $f = $this->getJson('/api/tax/103?company_id='.$this->company->id.'&anio='.now()->year.'&mes='.now()->month)->assertOk()->json();

        $this->assertCount(1, $f['detalle']);
        $this->assertSame('303', $f['detalle'][0]['codigo']);
        $this->assertEquals(100.0, $f['detalle'][0]['base_imponible']);
        $this->assertEquals(10.0, $f['detalle'][0]['valor_retenido']);

        // Y las emitidas no aparecen en la pantalla de retenciones recibidas
        $this->getJson('/api/withholdings?company_id='.$this->company->id)->assertOk()->assertJsonCount(0);
    }

    public function test_el_catalogo_de_retenciones_trae_renta_e_iva_con_su_porcentaje(): void
    {
        $c = $this->getJson('/api/catalogos/retenciones')->assertOk()->json();

        $renta = collect($c['renta'])->keyBy('codigo');
        $iva = collect($c['iva'])->keyBy('codigo');
        $this->assertEquals(10, $renta['303']['porcentaje']);
        $this->assertSame('Honorarios profesionales', $renta['303']['nombre']);
        $this->assertEquals(100, $iva['725']['porcentaje']);
        $this->assertEquals(30, $iva['721']['porcentaje']);
    }

    public function test_no_se_retiene_una_compra_de_otra_empresa(): void
    {
        $otra = Company::create(['ruc' => '1791234567999', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. Otra', 'plan' => 'completo']);
        $compra = $this->comprar();

        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $otra->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors(['purchase_id']);

        $this->assertSame(0, Withholding::count());
    }

    // --------------------------------------------------------------- helpers

    private function comprar(): Purchase
    {
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id,
            'numero' => '001-001-000000099', 'fecha_emision' => '2026-10-05',
            'items' => [['codigo_principal' => 'RET-BIEN', 'descripcion' => 'Bien', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->firstOrFail();
    }

    private function retener(Purchase $compra, array $lineas)
    {
        return $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id, 'lineas' => $lineas,
        ]);
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

        $debe = round(array_sum(array_column($real, 1)), 2);
        $haber = round(array_sum(array_column($real, 2)), 2);
        $this->assertSame($debe, $haber, 'El asiento no cuadra');
        $this->assertEquals($debe, $entry->total_debe);
        $this->assertEquals($haber, $entry->total_haber);
    }
}
