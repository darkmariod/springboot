<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DocumentCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T3.9: el comprobante que sale al SRI dice lo mismo que la factura.
 *   (a) El código de porcentaje del IVA sale de la tarifa de la línea (0% → '0', 15% → '4'...). Antes todo llevaba '4'
 *       aunque la tarifa fuera 0, y el XML mandaba "15%" con valor cero. 'No objeto' ('6') y 'Exento' ('7') solo si se piden.
 *   (b) El descuento de la línea y el código de porcentaje pasan la validación de /api/invoices (antes se descartaban)
 *       y llegan al documento, a los totales y al asiento.
 * Aquí NO se simula el SRI: se genera el XML real (sin certificado queda "generado", nada sale a la red).
 */
class CorrectitudComprobanteSriTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Contact $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Comprobante Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo', 'ambiente' => 1,
        ]);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Normal',
        ]);
        Product::create(['company_id' => $this->company->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function linea(float $cantidad, float $precio, array $extra = []): array
    {
        return $extra + ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => $cantidad, 'precio_unitario' => $precio];
    }

    private function emitir(array $items, string $forma = 'credito')
    {
        return $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => $forma, 'items' => $items,
        ]);
    }

    private function xmlDe(Invoice $factura): \SimpleXMLElement
    {
        $doc = SriDocument::where('documentable_type', $factura->getMorphClass())->where('documentable_id', $factura->id)->firstOrFail();

        return new \SimpleXMLElement($doc->xml);
    }

    /** Debe y Haber de la cuenta (por código) en el asiento de la factura. */
    private function asiento(Invoice $factura): array
    {
        $asiento = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->orderBy('id')->firstOrFail();
        $porCuenta = [];
        foreach ($asiento->lines as $l) {
            $c = $l->account->codigo;
            $porCuenta[$c]['debe'] = round(($porCuenta[$c]['debe'] ?? 0) + (float) $l->debe, 2);
            $porCuenta[$c]['haber'] = round(($porCuenta[$c]['haber'] ?? 0) + (float) $l->haber, 2);
        }

        return [$asiento, $porCuenta];
    }

    // ------------------------------------------------------------ (a) código de porcentaje según la tarifa

    public function test_factura_con_linea_al_15_y_linea_al_0_manda_los_codigos_4_y_0_y_los_totales_correctos(): void
    {
        $this->emitir([
            $this->linea(1, 100, ['tarifa' => 15]),
            $this->linea(2, 50, ['tarifa' => 0, 'descripcion' => 'Servicio sin IVA']),
        ])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertEquals(200.00, (float) $f->total_sin_impuestos);
        $this->assertEquals(15.00, (float) $f->total_impuesto, 'Solo la línea al 15% paga IVA');
        $this->assertEquals(215.00, (float) $f->importe_total);

        $xml = $this->xmlDe($f);
        $totales = [];
        foreach ($xml->infoFactura->totalConImpuestos->totalImpuesto as $t) {
            $totales[(string) $t->codigoPorcentaje] = ['base' => (string) $t->baseImponible, 'valor' => (string) $t->valor, 'codigo' => (string) $t->codigo];
        }
        $this->assertEqualsCanonicalizing(['0', '4'], array_keys($totales), 'El XML trae un total por código: 0% y 15%');
        $this->assertSame(['base' => '100.00', 'valor' => '15.00', 'codigo' => '2'], $totales['4']);
        $this->assertSame(['base' => '100.00', 'valor' => '0.00', 'codigo' => '2'], $totales['0']);

        $detalles = $xml->detalles->detalle;
        $this->assertSame('4', (string) $detalles[0]->impuestos->impuesto->codigoPorcentaje);
        $this->assertSame('15', (string) $detalles[0]->impuestos->impuesto->tarifa);
        $this->assertSame('0', (string) $detalles[1]->impuestos->impuesto->codigoPorcentaje, 'La línea al 0% ya no sale con el código del 15%');
        $this->assertSame('0', (string) $detalles[1]->impuestos->impuesto->tarifa);
        $this->assertSame('0.00', (string) $detalles[1]->impuestos->impuesto->valor);
        $this->assertSame('100.00', (string) $detalles[1]->impuestos->impuesto->baseImponible);
        $this->assertSame('215.00', (string) $xml->infoFactura->importeTotal);

        // El asiento sigue los totales de la factura: ventas 200, IVA 15, CxC 215
        [$asiento, $c] = $this->asiento($f);
        $this->assertEquals(215.00, $c['1.1.03']['debe']);
        $this->assertEquals(200.00, $c['4.1.01']['haber']);
        $this->assertEquals(15.00, $c['2.1.02']['haber']);
        $this->assertEquals((float) $asiento->total_debe, (float) $asiento->total_haber);
    }

    public function test_una_factura_toda_al_0_manda_solo_el_codigo_0_y_ningun_iva(): void
    {
        $this->emitir([$this->linea(3, 20, ['tarifa' => 0])])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertEquals(60.00, (float) $f->importe_total);
        $this->assertEquals(0.00, (float) $f->total_impuesto);
        $totales = $this->xmlDe($f)->infoFactura->totalConImpuestos->totalImpuesto;
        $this->assertCount(1, $totales);
        $this->assertSame('0', (string) $totales[0]->codigoPorcentaje);
        $this->assertSame('60.00', (string) $totales[0]->baseImponible);
    }

    public function test_no_objeto_y_exento_solo_van_cuando_se_piden_y_no_cobran_iva(): void
    {
        $this->emitir([
            $this->linea(1, 100, ['tarifa' => 15]),
            $this->linea(1, 30, ['tarifa' => 0, 'codigo_porcentaje' => '6']),
            $this->linea(1, 20, ['tarifa' => 0, 'codigo_porcentaje' => '7']),
            $this->linea(1, 10, ['tarifa' => 0]),
        ])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertEquals(160.00, (float) $f->total_sin_impuestos);
        $this->assertEquals(15.00, (float) $f->total_impuesto);
        $totales = [];
        foreach ($this->xmlDe($f)->infoFactura->totalConImpuestos->totalImpuesto as $t) {
            $totales[(string) $t->codigoPorcentaje] = (string) $t->baseImponible;
        }
        $this->assertEquals(['4' => '100.00', '6' => '30.00', '7' => '20.00', '0' => '10.00'], $totales);
    }

    public function test_un_codigo_que_el_sri_no_conoce_da_422_y_no_se_crea_nada(): void
    {
        $r = $this->emitir([$this->linea(1, 100, ['tarifa' => 15, 'codigo_porcentaje' => '99'])])->assertStatus(422);
        $this->assertArrayHasKey('items.0.codigo_porcentaje', $r->json('errors'));
        $this->assertSame(0, Invoice::count());
    }

    public function test_una_tarifa_sin_codigo_del_sri_da_422_en_espanol_y_no_se_crea_nada(): void
    {
        $r = $this->emitir([$this->linea(1, 100, ['tarifa' => 7])])->assertStatus(422);
        $this->assertStringContainsString('7%', $r->json('message'));
        $this->assertStringContainsString('IVA', $r->json('message'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, SriDocument::count());
        $this->assertSame(0, JournalEntry::count());
    }

    // ------------------------------------------------------------ (b) el descuento de la línea llega al documento

    public function test_el_descuento_de_la_linea_llega_al_total_a_la_base_del_iva_y_al_asiento(): void
    {
        // 2 x 50 = 100, descuento 10 → base 90, IVA 15% = 13.50, total 103.50. La segunda línea al 0%: 40, descuento 5 → 35.
        $this->emitir([
            $this->linea(2, 50, ['tarifa' => 15, 'descuento' => 10]),
            $this->linea(1, 40, ['tarifa' => 0, 'descuento' => 5]),
        ])->assertCreated();

        $f = Invoice::firstOrFail();
        $this->assertEquals(125.00, (float) $f->total_sin_impuestos, '90 + 35');
        $this->assertEquals(13.50, (float) $f->total_impuesto, 'El IVA se calcula sobre la base ya con descuento');
        $this->assertEquals(138.50, (float) $f->importe_total);
        $this->assertEquals(10.0, (float) $f->items[0]['descuento'], 'La línea guardada conserva su descuento');

        $xml = $this->xmlDe($f);
        $this->assertSame('15.00', (string) $xml->infoFactura->totalDescuento);
        $this->assertSame('125.00', (string) $xml->infoFactura->totalSinImpuestos);
        $this->assertSame('138.50', (string) $xml->infoFactura->importeTotal);
        $d = $xml->detalles->detalle;
        $this->assertSame('10.00', (string) $d[0]->descuento);
        $this->assertSame('90.00', (string) $d[0]->precioTotalSinImpuesto);
        $this->assertSame('90.00', (string) $d[0]->impuestos->impuesto->baseImponible);
        $this->assertSame('13.50', (string) $d[0]->impuestos->impuesto->valor);
        $this->assertSame('5.00', (string) $d[1]->descuento);
        $this->assertSame('35.00', (string) $d[1]->precioTotalSinImpuesto);

        [$asiento, $c] = $this->asiento($f);
        $this->assertEquals(138.50, $c['1.1.03']['debe']);
        $this->assertEquals(125.00, $c['4.1.01']['haber'], 'Ventas netas del descuento');
        $this->assertEquals(13.50, $c['2.1.02']['haber']);
        $this->assertEquals(138.50, (float) $asiento->total_debe);
        $this->assertEquals(138.50, (float) $asiento->total_haber);
    }

    public function test_un_descuento_mayor_que_el_valor_de_la_linea_da_422_y_no_se_crea_nada(): void
    {
        $this->emitir([$this->linea(1, 50, ['tarifa' => 15, 'descuento' => 60])])->assertStatus(422);
        $this->assertSame(0, Invoice::count());

        $r = $this->emitir([$this->linea(1, 50, ['tarifa' => 15, 'descuento' => -1])])->assertStatus(422);
        $this->assertArrayHasKey('items.0.descuento', $r->json('errors'));
    }

    // ------------------------------------------------------------ la calculadora, sola

    /** @return array<string, array{0: float, 1: string}> */
    public static function tarifasConSuCodigo(): array
    {
        return [
            '0%' => [0, '0'], '5%' => [5, '5'], '8%' => [8, '8'], '12%' => [12, '2'],
            '13%' => [13, '10'], '14%' => [14, '3'], '15%' => [15, '4'],
        ];
    }

    /** @dataProvider tarifasConSuCodigo */
    #[\PHPUnit\Framework\Attributes\DataProvider('tarifasConSuCodigo')]
    public function test_la_calculadora_deduce_el_codigo_de_la_tarifa(float $tarifa, string $codigo): void
    {
        $r = (new DocumentCalculator)->fromItems([['codigo_principal' => 'X', 'descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => $tarifa]]);

        $this->assertSame($codigo, $r['detalle'][0]['impuesto']['codigoPorcentaje']);
        $this->assertSame($codigo, $r['impuestos'][0]['codigoPorcentaje']);
        $this->assertEquals(round(100 * $tarifa / 100, 2), $r['total_impuesto']);
    }

    public function test_la_calculadora_respeta_el_codigo_explicito_y_sin_tarifa_ni_codigo_cuenta_15(): void
    {
        $calc = new DocumentCalculator;
        $item = ['codigo_principal' => 'X', 'descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 100];

        // Sin tarifa ni código: 15% (así lo calcula el punto de venta)
        $r = $calc->fromItems([$item]);
        $this->assertSame('4', $r['detalle'][0]['impuesto']['codigoPorcentaje']);
        $this->assertEquals(15.00, $r['total_impuesto']);

        // Solo el código: la tarifa sale del código (antes un '0' sin tarifa cobraba 15%)
        $r = $calc->fromItems([$item + ['codigo_porcentaje' => '0']]);
        $this->assertSame('0', $r['detalle'][0]['impuesto']['codigoPorcentaje']);
        $this->assertEquals(0.00, $r['total_impuesto']);
        $r = $calc->fromItems([$item + ['codigo_porcentaje' => '2']]);
        $this->assertEquals(12.00, $r['total_impuesto']);

        // No objeto y exento: el código se respeta y no cobran IVA aunque llegue una tarifa
        foreach (['6', '7'] as $cod) {
            $r = $calc->fromItems([$item + ['codigo_porcentaje' => $cod, 'tarifa' => 15]]);
            $this->assertSame($cod, $r['detalle'][0]['impuesto']['codigoPorcentaje']);
            $this->assertSame('0', $r['detalle'][0]['impuesto']['tarifa']);
            $this->assertEquals(0.00, $r['total_impuesto']);
            $this->assertEquals(100.00, $r['importe_total']);
        }

        // Si el código contradice la tarifa gana la tarifa (el IVA se calculó con ella): el comprobante no puede decir otra cosa
        $r = $calc->fromItems([$item + ['codigo_porcentaje' => '4', 'tarifa' => 0]]);
        $this->assertSame('0', $r['detalle'][0]['impuesto']['codigoPorcentaje']);
        $this->assertEquals(0.00, $r['total_impuesto']);
    }

    public function test_la_calculadora_rechaza_una_tarifa_que_el_sri_no_tiene(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('7%');
        (new DocumentCalculator)->fromItems([['codigo_principal' => 'X', 'descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 7]]);
    }
}
