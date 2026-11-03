<?php

namespace Tests\Feature\Reuniones;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PendingImport;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Withholding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reunión del 10 de julio: el consultor tributario le muestra a Javier Soliz cómo trabajan otros sistemas.
 * Compras del SRI, contactos, sustentos, retenciones, pagos, estado de cuenta, documentos pendientes, asientos y períodos.
 */
#[Group('reuniones')]
class Reunion10JulTest extends ReunionesTestCase
{
    // ============================================================ R1 · importar compras del SRI

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Bajo el TXT del mes del portal del SRI y que me deje solo las facturas; las retenciones y las notas de crédito no son compras.»
     * Se prueba: el TXT mezclado deja 1 factura pendiente y omite la retención y la nota de crédito, con su motivo.
     */
    #[Group('reuniones')]
    #[Group('R1')]
    public function test_R1_el_txt_del_mes_deja_pendientes_las_facturas_y_omite_retenciones_y_notas_de_credito(): void
    {
        // Arrange: el TXT del mes trae una factura, una retención y una nota de crédito
        $factura = $this->claveSri('01', '1790011223001', '000000130');
        $retencion = $this->claveSri('07', '1790011224001', '000000007', '11102026');
        $nota = $this->claveSri('04', '1790011225001', '000000008', '12102026');
        $txt = implode("\r\n", array_map(fn ($f) => implode("\t", $f), [
            ['RUC_EMISOR', 'RAZON_SOCIAL_EMISOR', 'TIPO_COMPROBANTE', 'SERIE_COMPROBANTE', 'CLAVE_ACCESO', 'FECHA_EMISION'],
            ['1790011223001', 'PROVEEDOR FACTURA S.A.', 'Factura', '001-001-000000130', $factura, '10/10/2026'],
            ['1790011224001', 'CLIENTE QUE RETIENE S.A.', 'Comprobante de Retención', '001-001-000000007', $retencion, '11/10/2026'],
            ['1790011225001', 'PROVEEDOR NOTA S.A.', 'Notas de Crédito', '001-001-000000008', $nota, '12/10/2026'],
        ]))."\r\n";

        // Act: se sube el archivo del mes
        $r = $this->post('/api/pending-imports/upload-txt', [
            'company_id' => $this->company->id,
            'txt' => UploadedFile::fake()->createWithContent('comprobantes-octubre.txt', $txt),
        ], ['Accept' => 'application/json'])->assertOk();

        // Assert: una sola factura queda por procesar; las otras dos se omiten diciendo por qué
        $this->assertSame(1, $r->json('insertadas'));
        $this->assertSame(2, $r->json('omitidas'));
        $filas = PendingImport::orderBy('id')->get()->keyBy('clave_acceso');
        $this->assertSame('pendiente', $filas[$factura]->estado);
        $this->assertSame('PROVEEDOR FACTURA S.A.', $filas[$factura]->razon_social);
        $this->assertSame('omitido', $filas[$retencion]->estado);
        $this->assertStringContainsString('comprobante de retención', $filas[$retencion]->error);
        $this->assertSame('omitido', $filas[$nota]->estado);
        $this->assertStringContainsString('nota de crédito', $filas[$nota]->error);
        $this->assertSame(0, PendingImport::where('estado', 'error')->count(), 'Lo omitido no es un error');
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Y si no quiero esperar el TXT, subo el XML de la factura, uno por uno.»
     * Se prueba: el XML de una factura de compra se importa con sus totales, sube el inventario y no se puede importar dos veces.
     */
    #[Group('reuniones')]
    #[Group('R1')]
    public function test_R1_el_xml_de_una_factura_de_compra_se_importa_uno_por_uno_y_no_se_repite(): void
    {
        // Act: se sube el XML de una compra de 10 unidades a $5 (más IVA 15 %)
        $r = $this->subirXmlCompra('000000201', [['MERC-XML', 10, 5]])->assertCreated();

        // Assert: la compra queda registrada con los valores del XML y el inventario sube
        $compra = Purchase::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('001-001-000000201', $compra->numero);
        $this->assertEquals(50.0, (float) $compra->total_sin_impuestos);
        $this->assertEquals(7.5, (float) $compra->total_impuesto);
        $this->assertEquals(57.5, (float) $compra->importe_total);
        $this->assertEquals(57.5, (float) $compra->saldo_pendiente, 'Queda por pagar al proveedor');
        $this->assertEquals(10.0, (float) Product::where('codigo', 'MERC-XML')->value('stock'));
        $this->assertContains('001-001-000000201', array_column($this->getJson('/api/purchases?company_id='.$this->company->id)->assertOk()->json(), 'numero'));

        // Assert: el mismo XML otra vez se rechaza (no duplica la compra ni el inventario)
        $this->subirXmlCompra('000000201', [['MERC-XML', 10, 5]])->assertStatus(422)->assertJsonPath('errors.xml.0', 'Esta factura de compra ya fue importada.');
        $this->assertSame(1, Purchase::count());
        $this->assertEquals(10.0, (float) Product::where('codigo', 'MERC-XML')->value('stock'));

        // Assert: otro XML distinto entra como una segunda compra (uno por uno)
        $this->subirXmlCompra('000000202', [['MERC-XML', 2, 5]])->assertCreated();
        $this->assertSame(2, Purchase::count());
        $this->assertEquals(12.0, (float) Product::where('codigo', 'MERC-XML')->value('stock'));
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R2 · clientes y proveedores

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Pongo la cédula o el RUC y el sistema me trae el nombre desde el SRI; no quiero escribirlo.»
     * Se prueba: la consulta por RUC trae razón social, estado y la clase de contribuyente sugerida, y la cédula inválida da 422.
     */
    #[Group('reuniones')]
    #[Group('R2')]
    public function test_R2_el_cliente_se_autocompleta_con_la_cedula_o_el_ruc_desde_el_sri(): void
    {
        // Arrange: el SRI (simulado) responde con los datos del RUC
        Http::fake(fn () => Http::response([[
            'razonSocial' => 'DISTRIBUIDORA ANDINA S.A.', 'nombreComercial' => 'ANDINA', 'estadoContribuyenteRuc' => 'ACTIVO',
            'obligadoLlevarContabilidad' => 'SI', 'regimen' => 'RIMPE - NEGOCIO POPULAR', 'tipoContribuyente' => 'SOCIEDAD',
        ]], 200));

        // Act: se escribe solo el RUC
        $r = $this->getJson('/api/sri/consulta?identificacion=1790011223001')->assertOk();

        // Assert: la pantalla recibe el nombre y los datos tributarios para llenar el formulario
        $this->assertTrue($r->json('encontrado'));
        $this->assertSame('DISTRIBUIDORA ANDINA S.A.', $r->json('razon_social'));
        $this->assertSame('04', $r->json('tipo_identificacion'), 'Un RUC es tipo 04');
        $this->assertSame('ACTIVO', $r->json('estado'));
        $this->assertTrue($r->json('obligado_contabilidad'));
        $this->assertSame('negocio_popular', $r->json('clase_contribuyente_sugerida'));

        // Assert: con una cédula de 10 dígitos consulta el RUC de la persona (cédula + 001)
        $this->getJson('/api/sri/consulta?identificacion=1700000001')->assertOk()->assertJsonPath('tipo_identificacion', '05');
        // Assert: un número que no es cédula ni RUC se rechaza con un mensaje claro
        $this->getJson('/api/sri/consulta?identificacion=12345')->assertStatus(422)
            ->assertJsonPath('error', 'La identificación debe tener 10 dígitos (cédula) o 13 (RUC).');
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Si el cliente es parte relacionada, sus facturas tienen que ir a cuentas por cobrar relacionadas, y lo mismo del lado del proveedor.»
     * Se prueba: factura a un relacionado debita 1.1.09, compra a un proveedor relacionado acredita 2.1.11 y la clase de contribuyente se guarda.
     */
    #[Group('reuniones')]
    #[Group('R2')]
    public function test_R2_la_parte_relacionada_va_a_cuentas_relacionadas_y_la_clase_de_contribuyente_se_guarda(): void
    {
        // Arrange: un cliente relacionado, un proveedor relacionado y un cliente normal, creados por la API como en la pantalla
        $relacionado = $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002', 'razon_social' => 'Socio Relacionado',
            'es_cliente' => true, 'parte_relacionada' => true, 'clase_contribuyente' => 'emprendedor',
        ])->assertCreated();
        $this->assertSame('emprendedor', $relacionado->json('clase_contribuyente'));
        $this->assertTrue($relacionado->json('parte_relacionada'));
        $proveedorRel = $this->contacto('1790011224001', 'Proveedor Relacionado SA', ['es_proveedor' => true, 'es_cliente' => false, 'parte_relacionada' => true]);

        // Act
        $facturaRel = $this->facturar(Contact::find($relacionado->json('id')));
        $facturaNormal = $this->facturar($this->cliente);
        $compraRel = $this->comprar($proveedorRel, [['SERV-1', 1, 100]]);
        $compraNormal = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);

        // Assert: cada documento usa la cuenta que corresponde
        $this->assertLineas($this->asientoDe($facturaRel), [['1.1.09', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);
        $this->assertLineas($this->asientoDe($facturaNormal), [['1.1.03', 115, 0], ['4.1.01', 0, 100], ['2.1.02', 0, 15]]);
        $this->assertLineas($this->asientoDe($compraRel), [['5.1.01', 100, 0], ['1.1.04', 15, 0], ['2.1.11', 0, 115]]);
        $this->assertLineas($this->asientoDe($compraNormal), [['5.1.01', 100, 0], ['1.1.04', 15, 0], ['2.1.01', 0, 115]]);
        $this->assertSame('Cuentas por cobrar relacionadas', Account::where('company_id', $this->company->id)->where('codigo', '1.1.09')->value('nombre'));
        $this->assertSame('Cuentas por pagar relacionadas', Account::where('company_id', $this->company->id)->where('codigo', '2.1.11')->value('nombre'));

        // Assert: una clase de contribuyente que no existe se rechaza en español
        $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000077', 'razon_social' => 'X', 'clase_contribuyente' => 'gigante',
        ])->assertStatus(422)->assertJsonValidationErrors('clase_contribuyente');
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Cada proveedor debería tener su cuenta contable por defecto: la luz va a servicios básicos, no a compras.»
     * Se prueba: los servicios de la compra van a la cuenta del proveedor y el bien sigue en Inventario.
     */
    #[Group('reuniones')]
    #[Group('R2')]
    public function test_R2_la_cuenta_contable_por_defecto_del_proveedor_recibe_sus_servicios(): void
    {
        // Arrange: el proveedor de luz con su cuenta «Servicios básicos»
        $cuenta = Account::create(['company_id' => $this->company->id, 'codigo' => '5.1.20', 'nombre' => 'Servicios básicos', 'tipo' => 'gasto']);
        $this->putJson("/api/contacts/{$this->proveedor->id}", ['cuenta_contable_id' => $cuenta->id])->assertOk();

        // Act: compra con un bien y un servicio
        $compra = $this->comprar($this->proveedor, [['MERC-1', 10, 5], ['SERV-1', 1, 40]]);

        // Assert: el servicio va a su cuenta; el bien, a Inventario; nada va a Compras
        $this->assertLineas($this->asientoDe($compra), [['1.1.05', 50, 0], ['5.1.20', 40, 0], ['1.1.04', 13.5, 0], ['2.1.01', 0, 103.5]]);
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R3 · sustento tributario

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «El sustento tributario va del 01 al 13, y cuando importo el XML tiene que respetar el sustento que yo elegí.»
     * Se prueba: el catálogo trae del 01 al 13, el XML guarda el sustento elegido y uno que no existe da 422.
     */
    #[Group('reuniones')]
    #[Group('R3')]
    public function test_R3_los_sustentos_van_del_01_al_13_y_el_xml_importado_respeta_el_elegido(): void
    {
        // Assert: el catálogo tiene los trece sustentos
        $codigos = array_column($this->getJson('/api/catalogos/sustentos')->assertOk()->json(), 'value');
        foreach (range(1, 13) as $n) {
            $this->assertContains(sprintf('%02d', $n), $codigos, 'Falta el sustento '.sprintf('%02d', $n));
        }

        // Act + Assert: el XML con el sustento 07 queda con el 07
        $this->subirXmlCompra('000000301', [['MERC-1', 2, 10]], ['sustento_tributario' => '07'])->assertCreated();
        $this->assertSame('07', Purchase::where('numero', '001-001-000000301')->value('sustento_tributario'));

        // Act + Assert: sin elegir, una compra de bienes con inventario se deduce como 06
        $this->subirXmlCompra('000000302', [['MERC-1', 2, 10]])->assertCreated();
        $this->assertSame('06', Purchase::where('numero', '001-001-000000302')->value('sustento_tributario'));

        // Assert: un sustento que no existe no importa nada
        $this->subirXmlCompra('000000303', [['MERC-1', 2, 10]], ['sustento_tributario' => '99'])
            ->assertStatus(422)->assertJsonValidationErrors('sustento_tributario');
        $this->assertSame(2, Purchase::count());
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Necesito un reporte de las compras por sustento: cuánto compré con cada sustento, con subtotal, IVA y total.»
     * Se prueba: el reporte agrupa por sustento con la cantidad de comprobantes y los valores, y el total general coincide.
     */
    #[Group('reuniones')]
    #[Group('R3')]
    public function test_R3_el_reporte_de_compras_por_sustento_agrupa_y_suma(): void
    {
        // Arrange: una compra de servicios (sustento 01) y dos de inventario (sustento 06)
        $this->comprar($this->proveedor, [['SERV-1', 1, 80]], '2026-10-05', ['sustento_tributario' => '01']);
        $this->comprar($this->proveedor, [['MERC-A', 10, 10]], '2026-10-06', ['sustento_tributario' => '06']);
        $this->comprar($this->proveedor, [['MERC-B', 2, 50]], '2026-10-07', ['sustento_tributario' => '06']);

        // Act
        $r = $this->getJson('/api/reportes/compras-sustento?'.http_build_query(['company_id' => $this->company->id, 'desde' => '2026-10-01', 'hasta' => '2026-10-31']))->assertOk();

        // Assert: dos grupos, con la cantidad de comprobantes y el total de cada uno
        $porSustento = collect($r->json('por_sustento'))->keyBy('codigo');
        $this->assertSame(['01', '06'], $porSustento->keys()->all());
        $this->assertSame(1, $porSustento['01']['comprobantes']);
        $this->assertEquals(92.0, $porSustento['01']['total']);        // 80 + 12 de IVA
        $this->assertSame(2, $porSustento['06']['comprobantes']);
        $this->assertEquals(230.0, $porSustento['06']['total']);       // (100 + 100) + 30 de IVA
        $this->assertEquals(30.0, $porSustento['06']['iva']);
        $this->assertSame(3, $r->json('totales.comprobantes'));
        $this->assertEquals(322.0, $r->json('totales.total'));
    }

    // ============================================================ R4 · retención al registrar la compra

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Al registrar la compra quiero retener renta e IVA ahí mismo, con su asiento, y que la compra me muestre el saldo ya sin lo retenido.»
     * Se prueba: compra de $100 + IVA, retención de renta 10 % ($10) e IVA 100 % ($15): asiento Debe 2.1.01 25 / Haber 2.1.10 10 y 2.1.09 15, saldo $90.
     */
    #[Group('reuniones')]
    #[Group('R4')]
    public function test_R4_la_retencion_de_renta_e_iva_deja_su_asiento_y_baja_el_saldo_de_la_compra(): void
    {
        // Arrange: compra de $100 + IVA 15 = $115
        $compra = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $this->assertEquals(115.0, (float) $compra->saldo_pendiente);

        // Act: se retiene renta (código 303 sobre la base 100) e IVA (código 725 sobre el IVA 15)
        $r = $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100], ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15]],
        ])->assertCreated();

        // Assert: total retenido y saldo de la compra
        $this->assertEquals(25.0, $r->json('total_retenido'));
        $this->assertEquals(90.0, $r->json('saldo_pendiente'));
        $this->assertEquals(90.0, (float) $compra->fresh()->saldo_pendiente);
        // Assert: un solo asiento que debita al proveedor y acredita las retenciones por pagar
        $fila = Withholding::where('purchase_id', $compra->id)->orderBy('id')->firstOrFail();
        $asiento = JournalEntry::where('origen_type', $fila->getMorphClass())->whereIn('origen_id', Withholding::where('purchase_id', $compra->id)->pluck('id'))->get();
        $this->assertCount(1, $asiento);
        $this->assertLineas($asiento->first(), [['2.1.01', 25, 0], ['2.1.09', 0, 15], ['2.1.10', 0, 10]]);
        // Assert: la retención de más de lo que se debe no pasa
        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas');
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R5 · pagos

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Pago con cheque de un banco, y que el cheque me aparezca como movimiento de ese banco para conciliarlo.»
     * Se prueba: pagar una compra con cheque del banco deja saldo cero, el asiento contra Bancos y un movimiento de débito con el número del cheque.
     */
    #[Group('reuniones')]
    #[Group('R5')]
    public function test_R5_el_pago_con_cheque_de_un_banco_deja_el_movimiento_del_banco(): void
    {
        // Arrange
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco del Pacífico']);
        $compra = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);   // 115

        // Act
        $this->postJson("/api/payables/{$compra->id}/pay", [
            'pagos' => [['tipo' => 'cheque_banco', 'valor' => 115, 'bank_id' => $banco->id, 'documento' => '000451']],
        ])->assertOk();

        // Assert
        $this->assertEquals(0.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertLineas($this->asientoDe($compra, 'Pago compra'), [['2.1.01', 115, 0], ['1.1.02', 0, 115]]);
        $movimiento = BankMovement::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('debito', $movimiento->tipo);
        $this->assertEquals(115.0, (float) $movimiento->monto);
        $this->assertSame('000451', $movimiento->documento);
        $this->assertSame($banco->id, (int) $movimiento->bank_id);
        $this->assertFalse((bool) $movimiento->conciliado, 'Queda pendiente de conciliar');
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Quiero pagar a varios proveedores en un solo pago, con una parte en efectivo y el resto con cheque.»
     * Se prueba: un pago de $345 a dos proveedores (uno relacionado) con efectivo y cheque deja un solo asiento y cada compra en cero.
     */
    #[Group('reuniones')]
    #[Group('R5')]
    public function test_R5_un_solo_pago_cancela_las_compras_de_varios_proveedores(): void
    {
        // Arrange: dos compras de dos proveedores, una a un relacionado
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Pichincha']);
        $relacionado = $this->contacto('1790011224001', 'Proveedor B Relacionado', ['es_proveedor' => true, 'es_cliente' => false, 'parte_relacionada' => true]);
        $a = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);     // 115
        $b = $this->comprar($relacionado, [['SERV-1', 1, 200]]);         // 230

        // Act: efectivo 200 + cheque 145 = 345
        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id,
            'pagos' => [['purchase_id' => $a->id, 'monto' => 115], ['purchase_id' => $b->id, 'monto' => 230]],
            'formas' => [['tipo' => 'efectivo', 'valor' => 200], ['tipo' => 'cheque_banco', 'valor' => 145, 'bank_id' => $banco->id, 'documento' => '009001']],
        ])->assertOk()->assertJsonPath('facturas', 2);

        // Assert: ambas compras en cero y UN solo asiento con cada cuenta por pagar
        $this->assertEquals(0.0, (float) $a->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) $b->fresh()->saldo_pendiente);
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Pago múltiple%')->get();
        $this->assertCount(1, $asientos);
        $this->assertLineas($asientos->first(), [['2.1.01', 115, 0], ['2.1.11', 230, 0], ['1.1.01', 0, 200], ['1.1.02', 0, 145]]);
        // Assert: y un pago que no suma lo que se debe se rechaza sin tocar nada
        $c = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);
        $this->postJson('/api/payables/pay-multiple', [
            'company_id' => $this->company->id, 'pagos' => [['purchase_id' => $c->id, 'monto' => 115]], 'formas' => [['tipo' => 'efectivo', 'valor' => 100]],
        ])->assertStatus(422);
        $this->assertEquals(115.0, (float) $c->fresh()->saldo_pendiente);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Le di un anticipo al proveedor; cuando llegue la factura quiero aplicarlo y que baje lo que le debo.»
     * Se prueba: anticipo de $80 (Debe 1.1.10 / Haber Caja) aplicado $50 a una compra de $115 deja la compra en $65 y el anticipo en $30.
     */
    #[Group('reuniones')]
    #[Group('R5')]
    public function test_R5_el_anticipo_a_un_proveedor_se_aplica_a_su_compra(): void
    {
        // Arrange: anticipo en efectivo de $80
        $anticipo = $this->postJson('/api/advances', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'tipo' => 'proveedor',
            'monto' => 80, 'forma_pago' => 'efectivo', 'nota' => 'Anticipo de obra',
        ])->assertCreated()->assertJsonPath('tipo', 'proveedor');
        $compra = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);   // 115

        // Act: se aplican $50 del anticipo a la compra
        $r = $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->json('id'), 'monto' => 50])->assertOk();

        // Assert
        $this->assertEquals(65.0, $r->json('saldo_compra'));
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertEquals(30.0, (float) \App\Models\Advance::findOrFail($anticipo->json('id'))->saldo);
        $this->assertLineas($this->asientoDe($compra, 'Uso de anticipo'), [['2.1.01', 50, 0], ['1.1.10', 0, 50]]);
        // Assert: no se aplica más de lo que queda del anticipo
        $this->postJson("/api/credits/apply-purchase/{$compra->id}", ['id' => $anticipo->json('id'), 'monto' => 40])->assertStatus(422);
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Si un contacto es cliente y proveedor a la vez, quiero cruzar saldos: lo que me debe contra lo que le debo, sin mover caja.»
     * Se prueba: cobro con cruce de $50 baja la factura y la compra a $65 cada una, con asiento Debe 2.1.01 / Haber 1.1.03 y sin tocar caja ni bancos.
     */
    #[Group('reuniones')]
    #[Group('R5')]
    public function test_R5_el_cruce_de_saldos_cancela_la_factura_contra_la_compra_del_mismo_contacto(): void
    {
        // Arrange: el mismo contacto me debe una factura de $115 y yo le debo una compra de $115
        $ambos = $this->clienteYProveedor();
        $factura = $this->facturar($ambos);
        $compra = $this->comprar($ambos, [['SERV-1', 1, 100]]);

        // Act: cobro de $50 por cruce de saldos
        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 50, 'documento_cruce' => $compra->id]],
        ])->assertOk()->assertJsonPath('saldo', 65);

        // Assert: bajan las dos puntas y el asiento no toca caja ni bancos
        $this->assertEquals(65.0, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $cruce = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Cruce de saldos%')->sole();
        $this->assertLineas($cruce, [['2.1.01', 50, 0], ['1.1.03', 0, 50]]);
        // Assert: no se cruza más de lo que se debe del otro lado
        $this->postJson("/api/receivables/{$factura->id}/pay", [
            'pagos' => [['tipo' => 'cruce_saldos', 'valor' => 100, 'documento_cruce' => $compra->id]],
        ])->assertStatus(422);
        $this->assertEquals(65.0, (float) $compra->fresh()->saldo_pendiente);
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R6 · estado de cuenta

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «El estado de cuenta, documento por documento y por cliente: una factura de 100, una nota de crédito de 10 y una retención de 1, el cliente debe 89.»
     * Se prueba: el documento, el resumen por cliente y el libro mayor dicen $89; los movimientos son 100, 90 y 89.
     */
    #[Group('reuniones')]
    #[Group('R6')]
    public function test_R6_el_estado_de_cuenta_muestra_100_menos_10_menos_1_igual_89(): void
    {
        // Arrange: factura de $100 sin IVA, nota de crédito interna de $10 y retención recibida de $1
        $factura = $this->facturar($this->cliente, 100, 0);
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id, 'tipo' => 'interna', 'motivo' => 'Descuento acordado',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 0]],
        ])->assertCreated();
        $this->subirRetencionRecibida($factura->numero, 1.0)->assertCreated();

        // Act
        $detalle = $this->getJson("/api/estado-cuenta/clientes/{$this->cliente->id}?company_id={$this->company->id}")->assertOk();
        $resumen = $this->getJson("/api/estado-cuenta/clientes?company_id={$this->company->id}")->assertOk();

        // Assert 1: el documento
        $doc = $detalle->json('documentos.0');
        $this->assertSame($factura->numero, $doc['numero']);
        $this->assertEquals(100.0, $doc['total']);
        $this->assertSame('NCI-000001', $doc['notas_credito'][0]['numero']);
        $this->assertEquals(10.0, $doc['notas_credito'][0]['aplicado']);
        $this->assertEquals(1.0, $doc['retenciones'][0]['valor']);
        $this->assertEquals(89.0, $doc['saldo']);
        // Assert 2: el resumen por cliente
        $fila = $resumen->json('clientes.0');
        $this->assertEquals([100.0, 10.0, 1.0, 89.0], [$fila['facturado'], $fila['notas_credito'], $fila['retenciones'], $fila['saldo']]);
        $this->assertEquals(89.0, $resumen->json('totales.saldo'));
        // Assert 3: los movimientos en orden y el libro mayor
        $this->assertSame(['factura', 'nota_credito', 'retencion'], array_column($detalle->json('movimientos'), 'tipo'));
        $this->assertEquals([100.0, 90.0, 89.0], array_column($detalle->json('movimientos'), 'saldo'));
        $this->assertEquals(89.0, $this->saldoMayor(['1.1.03', '1.1.09']));
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Lo mismo del lado de los proveedores: por cada compra, lo retenido, lo pagado y cuánto les debo.»
     * Se prueba: compra de $115 con retención de $25 y pago de $40 muestra saldo $50 en el documento, el resumen y el libro.
     */
    #[Group('reuniones')]
    #[Group('R6')]
    public function test_R6_el_estado_de_cuenta_de_proveedores_muestra_lo_retenido_lo_pagado_y_lo_que_se_debe(): void
    {
        // Arrange
        $compra = $this->comprar($this->proveedor, [['SERV-1', 1, 100]]);   // 115
        $this->postJson('/api/withholdings-emitted', [
            'company_id' => $this->company->id, 'purchase_id' => $compra->id,
            'lineas' => [['tipo' => 'renta', 'codigo' => '303', 'base_imponible' => 100], ['tipo' => 'iva', 'codigo' => '725', 'base_imponible' => 15]],
        ])->assertCreated();
        $this->postJson("/api/payables/{$compra->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 40]]])->assertOk();

        // Act
        $detalle = $this->getJson("/api/estado-cuenta/proveedores/{$this->proveedor->id}?company_id={$this->company->id}")->assertOk();
        $resumen = $this->getJson("/api/estado-cuenta/proveedores?company_id={$this->company->id}")->assertOk();

        // Assert: 115 − 25 de retención − 40 de pago = 50
        $doc = $detalle->json('documentos.0');
        $this->assertSame($compra->numero, $doc['numero']);
        $this->assertEquals([115.0, 25.0, 40.0, 50.0], [$doc['total'], $doc['retenciones_total'], $doc['pagos_total'], $doc['saldo']]);
        $this->assertEquals(50.0, $resumen->json('proveedores.0.saldo'));
        $this->assertEquals(50.0, $resumen->json('totales.saldo'));
        $this->assertEquals(50.0, $this->saldoMayor(['2.1.01', '2.1.11'], -1), 'El libro mayor dice lo mismo');
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R10 · documentos pendientes

    /**
     * Pedido por: CONS-10jul
     * Pedido: «En documentos pendientes, un botón de autorizar todo que reenvíe los que quedaron firmados y me diga cómo le fue a cada uno.»
     * Se prueba: con certificado, «autorizar todo» firma, envía y autoriza los pendientes y reporta el resultado de cada documento.
     */
    #[Group('reuniones')]
    #[Group('R10')]
    public function test_R10_autorizar_todo_reenvia_los_firmados_y_reporta_por_documento(): void
    {
        // Arrange: dos facturas ya emitidas (generadas) y la empresa recién subió su certificado; el SRI (simulado) autoriza
        $this->conComprobanteReal();
        $a = $this->facturar($this->cliente);
        $b = $this->facturar($this->cliente);
        $this->assertSame(2, count($this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->assertOk()->json()));
        $this->conCertificado();
        $this->sriFalso(true);

        // Act
        $r = $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk();

        // Assert: el resumen y una fila por documento
        $this->assertSame(2, $r->json('procesados'));
        $this->assertSame(2, $r->json('autorizados'));
        $this->assertSame(0, $r->json('fallidos'));
        $this->assertEqualsCanonicalizing([$a->numero, $b->numero], array_column($r->json('resultados'), 'numero'));
        foreach ($r->json('resultados') as $fila) {
            $this->assertSame('autorizado', $fila['resultado']);
            $this->assertSame('AUTORIZADO', $fila['estado']);
        }
        $this->assertSame('AUTORIZADO', $a->sriDocument()->first()->estado);
        $this->assertSame([], $this->getJson('/api/sri-documents/pending?company_id='.$this->company->id)->json(), 'Ya no quedan pendientes');
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «Si la empresa todavía no tiene el certificado, que me avise, pero que no se rompa nada.»
     * Se prueba: sin certificado «autorizar todo» responde 200 con un mensaje claro y el comprobante sigue generado, sin inventar un estado.
     */
    #[Group('reuniones')]
    #[Group('R10')]
    public function test_R10_sin_certificado_avisa_sin_romper(): void
    {
        // Arrange: factura emitida sin certificado
        $this->conComprobanteReal();
        $factura = $this->facturar($this->cliente);
        $sri = $this->sriFalso(true);

        // Act
        $r = $this->postJson('/api/sri-documents/authorize-batch', ['company_id' => $this->company->id])->assertOk();

        // Assert: avisa del certificado, no llama al SRI y el comprobante no cambia
        $this->assertStringContainsString('certificado', $r->json('mensaje'));
        $this->assertSame(1, $r->json('sin_firma'));
        $this->assertSame(0, $r->json('autorizados'));
        $this->assertSame('generado', $factura->sriDocument()->first()->estado);
        $this->assertNull($factura->sriDocument()->first()->numero_autorizacion);
        $this->assertSame([], $sri->firmados, 'No se firmó nada');
        $this->assertSame('emitida', $factura->fresh()->estado, 'La factura sigue emitida');
    }

    // ============================================================ R13 · asientos manuales y correcciones

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «Quiero hacer asientos manuales, y que no me deje guardar uno que no cuadra.»
     * Se prueba: el asiento cuadrado se crea numerado y marcado como manual; el descuadrado da 422 con los valores y no guarda nada.
     */
    #[Group('reuniones')]
    #[Group('R13')]
    public function test_R13_un_asiento_manual_cuadrado_se_crea_y_uno_descuadrado_se_rechaza(): void
    {
        // Act 1: aporte de capital de $500
        $this->postJson('/api/journal-entries', $this->cuerpoAsiento('Aporte de capital', [['1.1.01', 500, 0], ['3.1.01', 0, 500]]))
            ->assertCreated()->assertJsonPath('numero', 'AS-000001')->assertJsonPath('manual', true)->assertJsonPath('estado', 'pendiente');
        $this->assertEquals(500.0, JournalEntry::firstOrFail()->total_debe);

        // Act 2: un asiento con un centavo de diferencia
        $r = $this->postJson('/api/journal-entries', $this->cuerpoAsiento('Descuadrado', [['1.1.01', 100.01, 0], ['3.1.01', 0, 100]]))->assertStatus(422);

        // Assert
        $this->assertSame('El asiento no cuadra: debe 100.01, haber 100.00', $r->json('message'));
        $this->assertSame(1, JournalEntry::count(), 'El descuadrado no se guardó');
    }

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «Si un asiento quedó mal parametrizado, quiero poder corregirlo: desmayorizar, cambiar la cuenta y volver a guardar.»
     * Se prueba: un asiento generado y mayorizado no se edita; al desmayorizarlo se cambia la cuenta (1.1.03 a 1.1.09), conserva su documento y queda auditado.
     */
    #[Group('reuniones')]
    #[Group('R13')]
    public function test_R13_se_corrige_un_asiento_mal_parametrizado_desmayorizando_y_editando(): void
    {
        // Arrange: una factura de $100 sin IVA cuyo asiento se mayorizó con la cuenta equivocada (1.1.03)
        $factura = $this->facturar($this->cliente, 100, 0);
        $asiento = $this->asientoDe($factura);
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id])->assertOk();
        $cuerpo = [
            'company_id' => $this->company->id, 'fecha' => $asiento->fecha->toDateString(), 'concepto' => $asiento->concepto.' (corregido)',
            'lines' => $this->lineasAsiento([['1.1.09', 100, 0, 'cuenta corregida'], ['4.1.01', 0, 100]]),
        ];

        // Act 1: mayorizado no se puede editar
        $this->putJson("/api/journal-entries/{$asiento->id}", $cuerpo)->assertStatus(422)
            ->assertJsonPath('message', 'Desmayoriza el asiento para poder editarlo.');

        // Act 2: se desmayoriza y se corrige
        $this->postJson("/api/journal-entries/{$asiento->id}/desmayorizar", ['company_id' => $this->company->id])->assertOk();
        $this->putJson("/api/journal-entries/{$asiento->id}", $cuerpo)->assertOk()->assertJsonPath('editado_manualmente', true);

        // Assert: cambió la cuenta, sigue ligado a la factura y quedó en la auditoría
        $asiento->refresh();
        $this->assertLineas($asiento, [['1.1.09', 100, 0], ['4.1.01', 0, 100]]);
        $this->assertSame($factura->id, (int) $asiento->origen_id);
        $this->assertTrue($asiento->editado_manualmente);
        $log = AuditLog::where('accion', 'edito_asiento')->where('modelo_id', $asiento->id)->firstOrFail();
        $this->assertSame('1.1.03 Cuentas por cobrar clientes', $log->cambios['antes']['lineas'][0]['cuenta']);
        $this->assertSame('1.1.09 Cuentas por cobrar relacionadas', $log->cambios['despues']['lineas'][0]['cuenta']);
    }

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «Contabilizar un mes: que mayorice solo lo que está cuadrado y me diga qué dejó afuera y por qué.»
     * Se prueba: contabilizar enero mayoriza los 2 cuadrados, deja el borrador y el descuadrado en «omitidos» con su motivo y no toca febrero.
     */
    #[Group('reuniones')]
    #[Group('R13')]
    public function test_R13_contabilizar_un_mes_mayoriza_solo_lo_cuadrado_y_el_resto_sale_en_omitidos(): void
    {
        // Arrange: dos asientos de enero cuadrados, uno descuadrado (viejo), un borrador y uno de febrero
        $enero1 = $this->crearAsiento('Enero 1', '2026-01-10');
        $enero2 = $this->crearAsiento('Enero 2', '2026-01-31');
        $descuadrado = $this->crearAsiento('Enero descuadrado', '2026-01-20');
        $borrador = $this->crearAsiento('Enero borrador', '2026-01-25', 'borrador');
        $febrero = $this->crearAsiento('Febrero', '2026-02-01');
        JournalEntry::whereKey($descuadrado)->update(['total_debe' => 99]);

        // Act
        $r = $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'mes' => 1, 'anio' => 2026])
            ->assertOk()->assertJsonPath('mayorizados', 2);

        // Assert
        $this->assertSame('mayorizado', JournalEntry::find($enero1)->estado);
        $this->assertSame('mayorizado', JournalEntry::find($enero2)->estado);
        $this->assertSame('pendiente', JournalEntry::find($febrero)->estado, 'Febrero está fuera del mes');
        $omitidos = collect($r->json('omitidos'))->keyBy('numero');
        $this->assertCount(2, $omitidos);
        $this->assertStringStartsWith('Descuadrado: debe', $omitidos[JournalEntry::find($descuadrado)->numero]['motivo']);
        $this->assertSame('Es un borrador: contabilízalo primero.', $omitidos[JournalEntry::find($borrador)->numero]['motivo']);
    }

    // ============================================================ R14 · períodos y estados financieros

    /**
     * Pedido por: CONS-10jul
     * Pedido: «Cuando cierro un mes, nadie debería poder facturar ni comprar en ese mes.»
     * Se prueba: con el mes cerrado, facturar y comprar dan 422 «El período … está cerrado» y no dejan nada; con otro mes abierto sí se puede.
     */
    #[Group('reuniones')]
    #[Group('R14')]
    public function test_R14_un_mes_cerrado_no_admite_facturar_ni_comprar(): void
    {
        // Arrange: se cierra el mes de hoy
        $hoy = now();
        $periodo = $hoy->format('Y-m');
        $this->postJson('/api/accounting/periodos/cerrar', ['company_id' => $this->company->id, 'anio' => (int) $hoy->format('Y'), 'mes' => (int) $hoy->format('n')])->assertOk();
        $mensaje = "El período {$periodo} está cerrado. Reábrelo para registrar o modificar movimientos de esa fecha.";
        $facturas = Invoice::count();
        $compras = Purchase::count();

        // Act + Assert: facturar hoy se rechaza
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'credito',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertStatus(422)->assertJsonPath('message', $mensaje);
        $this->assertSame($facturas, Invoice::count());

        // Act + Assert: comprar con fecha de hoy se rechaza
        $this->postJson('/api/purchases', [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => '001-001-000000900', 'fecha_emision' => $hoy->toDateString(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
        ])->assertStatus(422)->assertJsonPath('message', $mensaje);
        $this->assertSame($compras, Purchase::count());

        // Assert: una compra de un mes anterior (abierto) sí entra
        $this->comprar($this->proveedor, [['SERV-1', 1, 100]], $hoy->copy()->subMonthsNoOverflow(2)->startOfMonth()->toDateString());
        $this->assertSame($compras + 1, Purchase::count());
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «El contador puede cerrar el mes, pero reabrirlo solo el administrador, y diciendo el motivo.»
     * Se prueba: el contador cierra y no puede reabrir (403); el administrador reabre con motivo, queda en la auditoría y ya se puede facturar.
     */
    #[Group('reuniones')]
    #[Group('R14')]
    public function test_R14_solo_el_administrador_reabre_un_mes_cerrado(): void
    {
        $hoy = now();
        $mes = ['company_id' => $this->company->id, 'anio' => (int) $hoy->format('Y'), 'mes' => (int) $hoy->format('n')];

        // Act 1: el contador cierra el mes pero no lo reabre
        $this->como('contador');
        $this->postJson('/api/accounting/periodos/cerrar', $mes)->assertOk();
        $this->postJson('/api/accounting/periodos/reabrir', $mes + ['motivo' => 'Falta una factura'])->assertStatus(403);

        // Act 2: el administrador no puede reabrir sin motivo, y con motivo sí
        $this->como('admin');
        $this->postJson('/api/accounting/periodos/reabrir', $mes)->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->postJson('/api/accounting/periodos/reabrir', $mes + ['motivo' => 'Falta una factura'])->assertOk();

        // Assert: queda la auditoría y se puede facturar otra vez
        $this->assertSame('Falta una factura', AuditLog::where('accion', 'reabrio_periodo')->firstOrFail()->cambios['motivo']);
        $this->facturar($this->cliente);
        $this->assertSame(1, Invoice::count());
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «Los estados financieros por período, con el detalle de cada cuenta, y que me diga si el balance cuadra.»
     * Se prueba: estado de resultados de 2025 (ingresos 1000, gastos 350, resultado 650), de un solo mes (abril −150) y balance que «Cuadra».
     */
    #[Group('reuniones')]
    #[Group('R14')]
    public function test_R14_los_estados_financieros_por_periodo_traen_detalle_y_el_balance_cuadra(): void
    {
        // Arrange: el ejercicio 2025 del consultor
        $this->crearAsiento('Aporte de capital', '2025-01-10', null, [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->crearAsiento('Venta de contado', '2025-02-05', null, [['1.1.01', 600, 0], ['4.1.01', 0, 600]]);
        $this->crearAsiento('Venta a crédito', '2025-03-10', null, [['1.1.03', 400, 0], ['4.1.01', 0, 400]]);
        $this->crearAsiento('Gasto general', '2025-04-15', null, [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->crearAsiento('Compra a crédito', '2025-05-20', null, [['5.1.01', 200, 0], ['2.1.01', 0, 200]]);

        // Act + Assert: el año completo, con el detalle de cuentas
        $anio = $this->getJson("/api/accounting/estado-resultados?company_id={$this->company->id}&desde=2025-01-01&hasta=2025-12-31")->assertOk();
        $this->assertEquals([1000, 350, 650], [$anio->json('totales.ingresos'), $anio->json('totales.gastos'), $anio->json('totales.resultado')]);
        $gastos = collect($anio->json('secciones'))->firstWhere('tipo', 'gasto');
        $this->assertSame(['5.1.01', '5.1.02'], array_column($gastos['cuentas'][0]['hijos'], 'codigo'), 'Trae el detalle por cuenta');
        $this->assertEquals([200, 150], array_column($gastos['cuentas'][0]['hijos'], 'saldo'));

        // Act + Assert: un solo mes
        $abril = $this->getJson("/api/accounting/estado-resultados?company_id={$this->company->id}&desde=2025-04-01&hasta=2025-04-30")->assertOk();
        $this->assertEquals([0, 150, -150], [$abril->json('totales.ingresos'), $abril->json('totales.gastos'), $abril->json('totales.resultado')]);

        // Act + Assert: el balance al 31 de diciembre cuadra
        $balance = $this->getJson("/api/accounting/balance-general?company_id={$this->company->id}&hasta=2025-12-31")->assertOk();
        $this->assertEquals([1850, 200, 1000, 650], [$balance->json('totales.activo'), $balance->json('totales.pasivo'), $balance->json('totales.patrimonio'), $balance->json('totales.resultado_periodo')]);
        $this->assertTrue($balance->json('totales.cuadra'));
        $this->assertEquals(0, $balance->json('totales.diferencia'));
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «En el plan de cuentas, que el sistema me sugiera el código siguiente, para no equivocarme.»
     * Se prueba: bajo 1.1 el siguiente es 1.1.12; al crear esa cuenta el siguiente avanza a 1.1.13; un código repetido se rechaza.
     */
    #[Group('reuniones')]
    #[Group('R14')]
    public function test_R14_el_plan_de_cuentas_sugiere_el_codigo_siguiente(): void
    {
        // Act + Assert 1: la sugerencia bajo «1.1 ACTIVO CORRIENTE»
        $this->getJson('/api/accounts/siguiente-codigo?company_id='.$this->company->id.'&padre=1.1')->assertOk()
            ->assertJsonPath('codigo', '1.1.12')->assertJsonPath('padre_nombre', 'ACTIVO CORRIENTE');

        // Act: se crea la cuenta con ese código
        $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => '1.1.12', 'nombre' => 'Anticipos varios', 'tipo' => 'activo'])->assertCreated();

        // Assert: la sugerencia avanza y el mismo código ya no se acepta
        $this->getJson('/api/accounts/siguiente-codigo?company_id='.$this->company->id.'&padre=1.1')->assertOk()->assertJsonPath('codigo', '1.1.13');
        $this->postJson('/api/accounts', ['company_id' => $this->company->id, 'codigo' => '1.1.12', 'nombre' => 'Repetida', 'tipo' => 'activo'])->assertStatus(422);
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «Al terminar el año, el cierre del ejercicio: que pase los ingresos y gastos a resultados en un solo asiento.»
     * Se prueba: el cierre se rechaza con meses abiertos; con los doce cerrados deja un asiento al 31 de diciembre y el resultado de $650 en patrimonio.
     */
    #[Group('reuniones')]
    #[Group('R14')]
    public function test_R14_el_cierre_del_ejercicio_deja_un_solo_asiento_con_el_resultado(): void
    {
        // Arrange: ejercicio 2025 con ingresos 1000, gastos 350
        $this->crearAsiento('Aporte', '2025-01-10', null, [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->crearAsiento('Venta', '2025-02-05', null, [['1.1.01', 600, 0], ['4.1.01', 0, 600]]);
        $this->crearAsiento('Venta a crédito', '2025-03-10', null, [['1.1.03', 400, 0], ['4.1.01', 0, 400]]);
        $this->crearAsiento('Gasto', '2025-04-15', null, [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->crearAsiento('Compra', '2025-05-20', null, [['5.1.01', 200, 0], ['2.1.01', 0, 200]]);
        foreach (range(1, 10) as $mes) {
            $this->postJson('/api/accounting/periodos/cerrar', ['company_id' => $this->company->id, 'anio' => 2025, 'mes' => $mes])->assertOk();
        }

        // Act 1: con dos meses abiertos el ejercicio no se cierra y dice cuáles faltan
        $r = $this->postJson('/api/accounting/cierre-ejercicio', ['company_id' => $this->company->id, 'anio' => 2025])->assertStatus(422);
        $this->assertSame([11, 12], $r->json('meses_abiertos'));

        // Act 2: se cierran los dos meses y el ejercicio
        foreach ([11, 12] as $mes) {
            $this->postJson('/api/accounting/periodos/cerrar', ['company_id' => $this->company->id, 'anio' => 2025, 'mes' => $mes])->assertOk();
        }
        $cierre = $this->postJson('/api/accounting/cierre-ejercicio', ['company_id' => $this->company->id, 'anio' => 2025])->assertOk();

        // Assert: un solo asiento de cierre al 31 de diciembre y el resultado en patrimonio
        $this->assertEquals(650, $cierre->json('resultado'));
        $asientos = JournalEntry::where('company_id', $this->company->id)->where('es_cierre', true)->get();
        $this->assertCount(1, $asientos);
        $this->assertSame('2025-12-31', $asientos->first()->fecha->toDateString());
        $this->assertLineas($asientos->first(), [['3.1.02', 0, 650], ['4.1.01', 1000, 0], ['5.1.01', 0, 200], ['5.1.02', 0, 150]]);
        $this->postJson('/api/accounting/cierre-ejercicio', ['company_id' => $this->company->id, 'anio' => 2025])->assertStatus(422);
    }

    // ============================================================ ayudas de esta reunión

    /** Retención que un cliente le hizo a la empresa (XML del SRI), ligada a la factura por su número. */
    private function subirRetencionRecibida(string $numeroFactura, float $valor)
    {
        $sustento = str_replace('-', '', $numeroFactura);
        $clave = $this->claveSri('07', '1790011223001', '000000001', now()->format('dmY'));
        $v = number_format($valor, 2, '.', '');
        $fecha = now()->format('d/m/Y');
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<comprobanteRetencion id="comprobante" version="1.0.0">
  <infoTributaria>
    <ambiente>1</ambiente><tipoEmision>1</tipoEmision>
    <razonSocial>Cliente que retiene</razonSocial><ruc>1790011223001</ruc>
    <claveAcceso>$clave</claveAcceso><codDoc>07</codDoc>
    <estab>001</estab><ptoEmi>001</ptoEmi><secuencial>000000001</secuencial>
  </infoTributaria>
  <infoCompRetencion>
    <fechaEmision>$fecha</fechaEmision>
    <identificacionSujetoRetenido>{$this->company->ruc}</identificacionSujetoRetenido>
  </infoCompRetencion>
  <impuestos>
    <impuesto>
      <codigo>1</codigo><codigoRetencion>303</codigoRetencion>
      <baseImponible>100.00</baseImponible><porcentajeRetener>1</porcentajeRetener>
      <valorRetenido>$v</valorRetenido>
      <codDocSustento>01</codDocSustento><numDocSustento>$sustento</numDocSustento>
    </impuesto>
  </impuestos>
</comprobanteRetencion>
XML;

        return $this->post('/api/withholdings/import', [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('retencion.xml', $xml),
        ], ['Accept' => 'application/json']);
    }

    /** @param array<int,array{0:string,1:float|int,2:float|int,3?:?string}> $filas [código de cuenta, debe, haber, referencia] */
    private function lineasAsiento(array $filas): array
    {
        return array_map(function ($f) {
            $linea = [
                'account_id' => Account::where('company_id', $this->company->id)->where('codigo', $f[0])->firstOrFail()->id,
                'debe' => $f[1], 'haber' => $f[2],
            ];
            if (isset($f[3])) {
                $linea['referencia'] = $f[3];
            }

            return $linea;
        }, $filas);
    }

    private function cuerpoAsiento(string $concepto, array $filas, string $fecha = '2026-03-05', ?string $estado = null): array
    {
        $cuerpo = ['company_id' => $this->company->id, 'fecha' => $fecha, 'concepto' => $concepto, 'lines' => $this->lineasAsiento($filas)];
        if ($estado) {
            $cuerpo['estado'] = $estado;
        }

        return $cuerpo;
    }

    /** Crea un asiento manual (por defecto Caja / Capital por $10) y devuelve su id. */
    private function crearAsiento(string $concepto, string $fecha, ?string $estado = null, array $filas = [['1.1.01', 10, 0], ['3.1.01', 0, 10]]): int
    {
        return $this->postJson('/api/journal-entries', $this->cuerpoAsiento($concepto, $filas, $fecha, $estado))->assertCreated()->json('id');
    }
}
