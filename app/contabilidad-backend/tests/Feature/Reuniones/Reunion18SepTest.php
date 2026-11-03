<?php

namespace Tests\Feature\Reuniones;

use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\CardSettlement;
use App\Models\CardTransaction;
use App\Models\CashMovement;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SriDocument;
use App\Services\RideBuilder;
use App\Support\Cuentas;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reunión del 18 de septiembre: segunda demostración de HasReset al consultor y a Javier, con la lista de pendientes ya hecha.
 * Notas de crédito y débito, RIDE, anulaciones, cotizaciones, facturación masiva y todo el movimiento de caja y bancos.
 */
#[Group('reuniones')]
class Reunion18SepTest extends ReunionesTestCase
{
    // ============================================================ R7 · notas de crédito y de débito

    private function emitirNotaCredito(Invoice $factura, string $tipo, float $valor, float $tarifa = 15)
    {
        return $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $factura->contact_id, 'invoice_id' => $factura->id, 'tipo' => $tipo, 'motivo' => 'Devolución parcial',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $valor, 'tarifa' => $tarifa]],
        ]);
    }

    private function emitirNotaDebito(Invoice $factura, string $tipo, float $valor)
    {
        return $this->postJson('/api/notas-debito', [
            'company_id' => $this->company->id, 'invoice_id' => $factura->id, 'tipo' => $tipo, 'forma_pago' => '01',
            'motivos' => [['razon' => 'Interés por mora', 'valor' => $valor, 'tarifa' => 0]],
        ]);
    }

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «La nota de crédito tiene que obligarme a elegir la factura que corrige; no puede haber una nota suelta.»
     * Se prueba: la nota de crédito, SRI o interna, sin factura responde 422 «Selecciona la factura que corriges» y no deja asiento ni nota.
     */
    #[Group('reuniones')]
    #[Group('R7')]
    public function test_R7_la_nota_de_credito_obliga_a_elegir_la_factura_y_sin_ella_da_422(): void
    {
        foreach (['interna', 'sri'] as $tipo) {
            // Act: se intenta crear la nota sin elegir la factura
            $r = $this->postJson('/api/credit-notes', [
                'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'tipo' => $tipo, 'motivo' => 'Sin factura', 'importe_total' => 10,
            ])->assertStatus(422);

            // Assert
            $this->assertStringContainsString('Selecciona la factura que corriges', $r->json('errors.invoice_id.0'), "tipo $tipo");
        }
        $this->assertSame(0, CreditNote::count());
        $this->assertSame(0, JournalEntry::where('concepto', 'like', 'Nota de crédito%')->count());

        // Assert: con una factura de otro cliente tampoco
        $factura = $this->facturar($this->cliente);
        $otro = $this->contacto('1700000009', 'Otro Cliente');
        $this->postJson('/api/credit-notes', [
            'company_id' => $this->company->id, 'contact_id' => $otro->id, 'invoice_id' => $factura->id, 'tipo' => 'interna', 'motivo' => 'Otro cliente', 'importe_total' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->assertSame(0, CreditNote::count());
    }

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «Hay notas del SRI y notas internas: las internas no van al SRI y llevan su propio número NCI.»
     * Se prueba: la interna sale como NCI-000001, sin documento SRI y sin gastar el secuencial; la SRI lleva su número y solo se emite al pedirlo.
     */
    #[Group('reuniones')]
    #[Group('R7')]
    public function test_R7_existen_notas_del_SRI_e_internas_la_interna_no_tiene_documento_SRI(): void
    {
        // Arrange
        $factura = $this->facturar($this->cliente);                     // $115
        $secuencial = (int) $this->company->fresh()->secuencial;

        // Act 1: nota interna de $10 + IVA
        $interna = $this->emitirNotaCredito($factura, 'interna', 10)->assertCreated();
        $nota = CreditNote::findOrFail($interna->json('id'));

        // Assert 1
        $this->assertSame('NCI-000001', $nota->numero);
        $this->assertSame(0, SriDocument::where('documentable_type', $nota->getMorphClass())->count(), 'La interna no tiene documento del SRI');
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial, 'La interna no gasta el secuencial de la empresa');
        $this->assertEquals(103.5, (float) $factura->fresh()->saldo_pendiente, '115 − 11.50');
        $this->postJson("/api/credit-notes/{$nota->id}/emit", ['company_id' => $this->company->id])->assertStatus(422);

        // Act 2: nota del SRI de $10 + IVA
        $sri = $this->emitirNotaCredito($factura, 'sri', 10)->assertCreated();
        $notaSri = CreditNote::findOrFail($sri->json('id'));

        // Assert 2: no lleva el prefijo interno y se emite solo cuando se pide
        $this->assertSame('sri', $notaSri->tipo);
        $this->assertStringStartsNotWith('NCI-', (string) $notaSri->numero);
        $this->assertEquals(92.0, (float) $factura->fresh()->saldo_pendiente);
        $this->postJson("/api/credit-notes/{$notaSri->id}/emit", ['company_id' => $this->company->id])->assertOk();
        // Assert 3: la lista las distingue
        $lista = collect($this->getJson('/api/credit-notes?company_id='.$this->company->id)->assertOk()->json());
        $this->assertTrue($lista->firstWhere('numero', 'NCI-000001')['interna']);
        $this->assertFalse($lista->firstWhere('id', $notaSri->id)['interna']);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-18sep, CONS-10jul
     * Pedido: «La nota de débito sube el saldo de la factura, y las de crédito y de débito las quiero ver juntas.»
     * Se prueba: factura $115, nota de crédito $11.50 y nota de débito $10 dejan $113.50 en la factura, en el estado de cuenta y en el mayor.
     */
    #[Group('reuniones')]
    #[Group('R7')]
    public function test_R7_la_nota_de_debito_sube_el_saldo_y_las_notas_se_listan_juntas(): void
    {
        // Arrange
        $factura = $this->facturar($this->cliente);                     // $115
        $this->emitirNotaCredito($factura, 'interna', 10)->assertCreated();    // baja 11.50
        $nd = $this->emitirNotaDebito($factura, 'interna', 10)->assertCreated()
            ->assertJsonPath('nota_debito.numero', 'NDI-000001')->assertJsonPath('nota_debito.tipo_comprobante', 'nota_debito_interna');

        // Assert: la deuda vive en la factura: 115 − 11.50 + 10
        $this->assertEquals(113.5, (float) $factura->fresh()->saldo_pendiente);
        $this->assertEquals(0.0, (float) Invoice::findOrFail($nd->json('nota_debito.id'))->saldo_pendiente, 'La nota de débito no es una cuenta por cobrar aparte');
        $this->assertLineas($this->asientoDe(Invoice::findOrFail($nd->json('nota_debito.id'))), [['1.1.03', 10, 0], ['4.1.03', 0, 10]]);

        // Assert: las dos listas traen sus notas y la factura no las mezcla
        $creditos = $this->getJson('/api/credit-notes?company_id='.$this->company->id)->assertOk()->json();
        $debitos = $this->getJson('/api/notas-debito?company_id='.$this->company->id)->assertOk()->json();
        $this->assertSame(['NCI-000001'], array_column($creditos, 'numero'));
        $this->assertSame(['NDI-000001'], array_column($debitos, 'numero'));
        $this->assertSame([$factura->id], array_column($this->getJson('/api/invoices?company_id='.$this->company->id)->assertOk()->json(), 'id'));

        // Assert: el estado de cuenta junta las dos notas en el mismo documento y cierra en 113.50
        $doc = $this->getJson("/api/estado-cuenta/clientes/{$this->cliente->id}?company_id={$this->company->id}")->assertOk()->json('documentos.0');
        $this->assertEquals([115.0, 10.0, 11.5, 113.5], [$doc['total'], $doc['notas_debito_total'], $doc['notas_credito_total'], $doc['saldo']]);
        $this->assertEquals(113.5, $this->saldoMayor(['1.1.03', '1.1.09']));
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R8 · RIDE

    /** Texto del PDF si pdftotext está instalado (si no, vacío: las pruebas no dependen de él para pasar). */
    private function textoDelPdf(string $pdf): string
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            return '';
        }
        $ruta = tempnam(sys_get_temp_dir(), 'ride').'.pdf';
        file_put_contents($ruta, $pdf);
        try {
            return (string) shell_exec(escapeshellarg($pdftotext).' -layout '.escapeshellarg($ruta).' - 2>/dev/null');
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «El RIDE tiene que traer los datos del emisor y, si el documento está autorizado, el número y la fecha de autorización.»
     * Se prueba: el PDF de una factura AUTORIZADA trae el emisor, el número y la fecha que devolvió el SRI y no la leyenda de pendiente.
     */
    #[Group('reuniones')]
    #[Group('R8')]
    public function test_R8_el_ride_de_un_documento_autorizado_trae_el_emisor_el_numero_y_la_fecha_de_autorizacion(): void
    {
        // Arrange: factura emitida y autorizada por el SRI
        $this->conComprobanteReal();
        $factura = $this->facturar($this->cliente);
        $doc = $this->marcarSri($factura, 'AUTORIZADO');

        // Act
        $respuesta = $this->get("/api/invoices/{$factura->id}/ride?company_id={$this->company->id}")->assertOk();
        $ride = app(RideBuilder::class)->paraFactura($factura->fresh());

        // Assert: es un PDF y los datos son los del emisor y los de la autorización
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
        $this->assertSame('Distribuidora Andina SA', $ride['emisor']['razon_social']);
        $this->assertSame($this->company->ruc, $ride['emisor']['ruc']);
        $this->assertTrue($ride['autorizacion']['autorizado']);
        $this->assertSame($doc->clave_acceso, $ride['autorizacion']['numero']);
        $this->assertSame('07/10/2026 10:15:30', $ride['autorizacion']['fecha_hora'], 'La fecha y hora tal como las devolvió el SRI');
        $this->assertNull($ride['leyenda']);
        $texto = $this->textoDelPdf($respuesta->getContent());
        if ($texto !== '') {
            foreach (['Distribuidora Andina SA', $this->company->ruc, $doc->clave_acceso, '07/10/2026 10:15:30'] as $esperado) {
                $this->assertStringContainsString($esperado, $texto, "El PDF debe mostrar «{$esperado}»");
            }
            $this->assertStringNotContainsString('pendiente de autorización', $texto);
        }
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «Si el documento no está autorizado, que lo diga con una leyenda; jamás me inventen un número de autorización.»
     * Se prueba: generado, firmado, enviado o rechazado muestran su leyenda y número y fecha de autorización vacíos, aunque el comprobante guarde un número.
     */
    #[Group('reuniones')]
    #[Group('R8')]
    public function test_R8_el_ride_de_un_documento_no_autorizado_lleva_la_leyenda_y_ningun_numero_inventado(): void
    {
        // Arrange: factura sin certificado, el comprobante queda «generado»
        $this->conComprobanteReal();
        $factura = $this->facturar($this->cliente);
        $this->assertSame('generado', $factura->sriDocument->estado);

        foreach (['generado', 'firmado', 'enviado'] as $estado) {
            // Act: el comprobante en cada estado sin autorizar, incluso con un número guardado por error
            $factura->sriDocument()->update(['estado' => $estado, 'numero_autorizacion' => '0710202601179123456700110010010000000011234567810']);
            $ride = app(RideBuilder::class)->paraFactura($factura->fresh());

            // Assert
            $this->assertFalse($ride['autorizacion']['autorizado'], $estado);
            $this->assertNull($ride['autorizacion']['numero'], "En «{$estado}» no se imprime ningún número de autorización");
            $this->assertNull($ride['autorizacion']['fecha_hora'], $estado);
            $this->assertSame(RideBuilder::LEYENDA_PENDIENTE, $ride['leyenda'], $estado);
        }
        $factura->sriDocument()->update(['estado' => 'NO AUTORIZADO', 'numero_autorizacion' => null]);
        $ride = app(RideBuilder::class)->paraFactura($factura->fresh());
        $this->assertSame(RideBuilder::LEYENDA_RECHAZADO, $ride['leyenda']);
        $this->assertNull($ride['autorizacion']['numero']);

        // Assert: el PDF real lleva la leyenda de pendiente
        $factura->sriDocument()->update(['estado' => 'generado', 'numero_autorizacion' => null]);
        $respuesta = $this->get("/api/invoices/{$factura->id}/ride?company_id={$this->company->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
        $texto = $this->textoDelPdf($respuesta->getContent());
        if ($texto !== '') {
            $this->assertStringContainsString('Documento pendiente de autorización del SRI', $texto);
        }
    }

    // ============================================================ R9 · anulación

    /**
     * Pedido por: CONS-18sep
     * Pedido: «Cuando anulo una factura que ya estaba autorizada, que me avise que también hay que anularla en el portal del SRI.»
     * Se prueba: la factura autorizada queda anulada, conserva su número y la secuencia sigue, y la respuesta pide anular también en el portal del SRI.
     */
    #[Group('reuniones')]
    #[Group('R9')]
    public function test_R9_anular_una_factura_autorizada_conserva_la_secuencia_y_avisa_del_portal_del_SRI(): void
    {
        // Arrange
        $this->conComprobanteReal();
        $factura = $this->facturar($this->cliente);
        $this->marcarSri($factura, 'AUTORIZADO');
        $secuencial = (int) $this->company->fresh()->secuencial;
        $asientos = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->count();

        // Act
        $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        // Assert: aviso del portal
        $this->assertTrue($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString($factura->numero, $r->json('aviso_sri'));
        $this->assertStringContainsString('portal SRI en línea', $r->json('aviso_sri'));
        // Assert: la factura se conserva anulada, con su número, y el SRI sigue considerándola autorizada
        $this->assertSame('anulado', $factura->fresh()->estado);
        $this->assertNotNull(Invoice::find($factura->id));
        $this->assertSame('AUTORIZADO', $factura->sriDocument()->first()->estado);
        // Assert: la secuencia no se reutiliza y el asiento queda con su contra-asiento
        $this->assertSame($secuencial, (int) $this->company->fresh()->secuencial);
        $siguiente = $this->facturar($this->cliente);
        $this->assertSame((int) substr($factura->numero, -9) + 1, (int) substr($siguiente->numero, -9));
        $this->assertSame($asientos + 1, JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->count());
        // Assert: anular dos veces da 422
        $this->postJson("/api/invoices/{$factura->id}/anular")->assertStatus(422);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «Si la factura nunca llegó a autorizarse, que me diga claro que no hace falta hacer nada en el SRI.»
     * Se prueba: factura generada, firmada o rechazada se anula con «no hace falta ninguna acción en el SRI»; la enviada avisa del portal por si se autoriza después.
     */
    #[Group('reuniones')]
    #[Group('R9')]
    public function test_R9_anular_una_factura_que_no_se_autorizo_dice_que_no_hace_falta_accion_en_el_SRI(): void
    {
        $this->conComprobanteReal();
        foreach (['generado', 'firmado', 'NO AUTORIZADO'] as $estado) {
            $factura = $this->facturar($this->cliente);
            $this->marcarSri($factura, $estado);

            $r = $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

            $this->assertFalse($r->json('requiere_anulacion_sri'), $estado);
            $this->assertStringContainsString('no hace falta ninguna acción en el SRI', $r->json('aviso_sri'), $estado);
            $this->assertSame('anulado', $factura->fresh()->estado);
        }

        // Enviada pero sin respuesta: todavía no es autorizada, pero se avisa del portal por si el SRI la autoriza después
        $enviada = $this->facturar($this->cliente);
        $this->marcarSri($enviada, 'enviado');
        $r = $this->postJson("/api/invoices/{$enviada->id}/anular")->assertOk();
        $this->assertFalse($r->json('requiere_anulacion_sri'));
        $this->assertStringContainsString('portal SRI en línea', $r->json('aviso_sri'));
    }

    // ============================================================ R11 · cotizaciones y facturación masiva

    private function cotizacion($cliente, array $items): Quote
    {
        $r = $this->postJson('/api/quotes', ['company_id' => $this->company->id, 'contact_id' => $cliente->id, 'items' => $items])->assertCreated();

        return Quote::findOrFail($r->json('id'));
    }

    private function lineaCot(string $codigo, float $cantidad, float $precio): array
    {
        return ['codigo_principal' => $codigo, 'descripcion' => $codigo, 'cantidad' => $cantidad, 'precio_unitario' => $precio, 'tarifa' => 15];
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «Varias cotizaciones del mismo cliente tienen que poder convertirse en una sola factura, sumando lo repetido.»
     * Se prueba: dos cotizaciones del hospital se unen en UNA factura de $126.50, el producto repetido suma cantidad, baja el stock una vez y ambas quedan facturadas.
     */
    #[Group('reuniones')]
    #[Group('R11')]
    public function test_R11_varias_cotizaciones_se_convierten_en_una_sola_factura(): void
    {
        // Arrange: 100 galones en bodega y dos cotizaciones
        $this->comprar($this->proveedor, [['GAS', 100, 1]]);
        $hospital = $this->contacto('1790000111001', 'Hospital Central', ['es_cliente' => true]);
        $q1 = $this->cotizacion($hospital, [$this->lineaCot('GAS', 10, 2), $this->lineaCot('SERV-1', 1, 50)]);
        $q2 = $this->cotizacion($hospital, [$this->lineaCot('GAS', 20, 2)]);

        // Act
        $r = $this->postJson('/api/quotes/convert-many', [
            'company_id' => $this->company->id, 'quote_ids' => [$q1->id, $q2->id], 'forma_pago' => 'credito',
        ])->assertCreated();

        // Assert: una sola factura con el producto repetido sumado
        $this->assertSame(1, Invoice::count());
        $factura = Invoice::firstOrFail();
        $this->assertSame($factura->id, $r->json('invoice.id'));
        $this->assertSame($hospital->id, $factura->contact_id);
        $porCodigo = collect($factura->items)->keyBy('codigo_principal');
        $this->assertCount(2, $factura->items);
        $this->assertEquals(30.0, (float) $porCodigo['GAS']['cantidad']);
        $this->assertEquals(110.0, (float) $factura->total_sin_impuestos);     // 60 + 50
        $this->assertEquals(126.5, (float) $factura->importe_total);
        $this->assertEquals(126.5, (float) $factura->saldo_pendiente);
        $this->assertEquals(70.0, (float) Product::where('codigo', 'GAS')->value('stock'), 'El stock baja una sola vez, con la cantidad unida');
        foreach ([$q1, $q2] as $q) {
            $this->assertSame('facturada', $q->fresh()->estado);
            $this->assertSame($factura->id, $q->fresh()->invoice_id);
        }
        // Assert: una cotización ya facturada no se factura otra vez
        $this->postJson('/api/quotes/convert-many', ['company_id' => $this->company->id, 'quote_ids' => [$q1->id], 'forma_pago' => 'credito'])->assertStatus(422);
        $this->assertSame(1, Invoice::count());
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «Y poder facturar esas cotizaciones a otro cliente; y la facturación masiva me tiene que responder cómo le fue a cada factura.»
     * Se prueba: dos cotizaciones de un cliente se facturan al otro (la deuda es del segundo); el lote masivo de 2 facturas responde éxito por cada una.
     */
    #[Group('reuniones')]
    #[Group('R11')]
    public function test_R11_las_cotizaciones_se_facturan_a_otro_cliente_y_la_facturacion_masiva_responde(): void
    {
        // Arrange
        $a = $this->contacto('1790000111001', 'Hospital Central', ['es_cliente' => true]);
        $b = $this->contacto('1790000222001', 'Constructora Norte', ['es_cliente' => true]);
        $q1 = $this->cotizacion($a, [$this->lineaCot('SERV-1', 1, 100)]);
        $q2 = $this->cotizacion($a, [$this->lineaCot('SERV-1', 2, 50)]);

        // Act 1: las dos cotizaciones del hospital se facturan a la constructora
        $this->postJson('/api/quotes/convert-many', [
            'company_id' => $this->company->id, 'quote_ids' => [$q1->id, $q2->id], 'forma_pago' => 'credito', 'contact_id' => $b->id,
        ])->assertCreated();

        // Assert 1: la deuda es del cliente al que se facturó
        $factura = Invoice::firstOrFail();
        $this->assertSame($b->id, $factura->contact_id);
        $this->assertEquals(230.0, (float) $factura->importe_total);
        $this->assertSame(0, Invoice::where('contact_id', $a->id)->count());

        // Act 2: facturación masiva de dos facturas a dos clientes
        $lote = $this->postJson('/api/invoices/masiva', [
            'company_id' => $this->company->id,
            'invoices' => [
                ['contact_id' => $a->id, 'forma_pago' => 'credito', 'items' => [$this->lineaCot('SERV-1', 1, 10)]],
                ['contact_id' => $b->id, 'forma_pago' => 'credito', 'items' => [$this->lineaCot('SERV-1', 1, 20)]],
            ],
        ])->assertOk();

        // Assert 2: responde el total, los éxitos y una fila por factura con su número
        $this->assertSame(2, $lote->json('total'));
        $this->assertSame(2, $lote->json('exitos'));
        $this->assertSame(0, $lote->json('fallas'));
        $this->assertSame([true, true], array_column($lote->json('results'), 'success'));
        $this->assertEquals([11.5, 23.0], array_column($lote->json('results'), 'total'));
        $this->assertSame(3, Invoice::count());
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R17 · caja, bancos y tarjetas

    private function abrirCaja(float $inicial = 0)
    {
        $this->postJson('/api/cash/open', ['company_id' => $this->company->id, 'saldo_inicial' => $inicial])->assertSuccessful();

        return \App\Models\CashSession::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    private function movimientoCaja($sesion, string $tipo, float $monto, string $concepto, string $cuenta)
    {
        return $this->postJson("/api/cash/{$sesion->id}/movements", [
            'tipo' => $tipo, 'monto' => $monto, 'concepto' => $concepto, 'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, $cuenta)->id,
        ]);
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Cada ingreso y egreso de caja tiene que salir con su comprobante numerado y su asiento contable.»
     * Se prueba: un ingreso (CI-000001) y un egreso (CE-000001) de caja con sus asientos, números separados, comprobante en PDF.
     */
    #[Group('reuniones')]
    #[Group('R17')]
    public function test_R17_cada_movimiento_de_caja_sale_con_comprobante_numerado_y_asiento(): void
    {
        // Arrange
        $sesion = $this->abrirCaja(100);

        // Act
        $this->movimientoCaja($sesion, 'ingreso', 120.50, 'Aporte del socio', 'capital')->assertOk();
        $this->movimientoCaja($sesion, 'egreso', 45.25, 'Papelería', 'gastos_generales')->assertOk();

        // Assert: números separados por clase y un asiento por comprobante
        $ingreso = CashMovement::where('tipo', 'ingreso')->firstOrFail();
        $egreso = CashMovement::where('tipo', 'egreso')->firstOrFail();
        $this->assertSame('CI-000001', $ingreso->numero);
        $this->assertSame('CE-000001', $egreso->numero);
        $this->assertLineas($this->asientoDe($ingreso), [['1.1.01', 120.50, 0], ['3.1.01', 0, 120.50]]);
        $this->assertLineas($this->asientoDe($egreso), [['5.1.02', 45.25, 0], ['1.1.01', 0, 45.25]]);
        // Assert: el comprobante se imprime en PDF
        $pdf = $this->get("/api/cash-movements/{$ingreso->id}/comprobante?company_id={$this->company->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // Assert: sin cuenta contrapartida no se registra nada
        $this->postJson("/api/cash/{$sesion->id}/movements", ['tipo' => 'ingreso', 'monto' => 10, 'concepto' => 'Sin cuenta'])->assertStatus(422)->assertJsonValidationErrors('cuenta_contrapartida_id');
        $this->assertSame(2, CashMovement::count());
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «El depósito a banco no puede ser mayor que lo que hay en caja; pero depositar exactamente lo que hay sí se permite.»
     * Se prueba: con $250 en caja, $250.01 se rechaza con el disponible; $250.00 pasa con asiento Debe Bancos / Haber Caja y deja el disponible en cero.
     */
    #[Group('reuniones')]
    #[Group('R17')]
    public function test_R17_el_deposito_a_banco_se_rechaza_si_pasa_de_lo_que_hay_en_caja_y_el_limite_exacto_se_permite(): void
    {
        // Arrange: $250 en caja y un banco
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        $sesion = $this->abrirCaja(0);
        $this->movimientoCaja($sesion, 'ingreso', 250, 'Efectivo cobrado', 'capital')->assertOk();
        $depositar = fn (float $monto, string $papeleta) => $this->postJson('/api/deposits', [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'fecha' => now()->toDateString(), 'monto' => $monto, 'documento' => $papeleta,
        ]);

        // Act + Assert 1: un centavo de más
        $depositar(250.01, 'PAP-1')->assertStatus(422)
            ->assertJsonPath('errors.monto.0', 'No puedes depositar más de lo que hay en caja: disponible $250.00');
        $this->assertSame(0, BankMovement::count());

        // Act + Assert 2: el límite exacto
        $depositar(250.00, 'PAP-2')->assertCreated()->assertJsonPath('numero', 'DP-000001')->assertJsonPath('disponible', 0);
        $this->assertLineas(JournalEntry::where('concepto', 'like', 'Depósito a banco%')->firstOrFail(), [['1.1.02', 250, 0], ['1.1.01', 0, 250]]);
        $movimiento = BankMovement::firstOrFail();
        $this->assertSame('PAP-2', $movimiento->documento);
        $this->assertEquals(250.0, (float) $movimiento->monto);
        // Assert 3: ya no hay nada más que depositar
        $depositar(0.01, 'PAP-3')->assertStatus(422);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Quiero registrar un cheque posfechado: que se asiente hoy, pero que no cuente en el banco hasta su fecha de cobro.»
     * Se prueba: el cheque a 10 días se asienta hoy, no entra en el saldo ni se concilia; a su fecha de cobro cuenta y se concilia.
     */
    #[Group('reuniones')]
    #[Group('R17')]
    public function test_R17_un_cheque_posfechado_se_asienta_hoy_pero_no_cuenta_en_el_banco_hasta_su_fecha(): void
    {
        // Arrange: el banco con $1000 de fondeo
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        $hoy = now()->toDateString();
        $cobro = now()->addDays(10)->toDateString();
        $mover = fn (array $datos, string $cuenta) => $this->postJson('/api/bank-movements', $datos + [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'fecha' => $hoy,
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, $cuenta)->id,
        ]);
        $mover(['tipo' => 'credito', 'monto' => 1000, 'concepto' => 'Fondeo'], 'capital')->assertCreated();

        // Act: cheque de $300 al proveedor, cobrable en 10 días
        $mover(['tipo' => 'debito', 'monto' => 300, 'concepto' => 'Cheque a proveedor', 'forma' => 'cheque', 'documento' => '000451', 'fecha_cobro' => $cobro], 'compras')->assertCreated();

        // Assert 1: el asiento es de hoy (el gasto y la salida de Bancos ya están en los libros)
        $cheque = BankMovement::where('documento', '000451')->firstOrFail();
        $this->assertTrue($cheque->esPosfechado());
        $this->assertSame($hoy, $this->asientoDe($cheque)->fecha->toDateString());
        $this->assertLineas($this->asientoDe($cheque), [['5.1.01', 300, 0], ['1.1.02', 0, 300]]);
        // Assert 2: hoy no cuenta en el saldo del banco y no se puede conciliar
        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$banco->id)->assertOk();
        $this->assertEquals(1000.0, $lista->json('saldo_sistema'));
        $this->assertEquals(-300.0, $lista->json('saldo_posfechado'));
        $this->assertSame(1, $lista->json('posfechados'));
        $this->postJson("/api/bank-movements/{$cheque->id}/toggle")->assertStatus(422);

        // Act + Assert 3: llega la fecha de cobro
        $this->travelTo(now()->addDays(10));
        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$banco->id)->assertOk();
        $this->assertEquals(700.0, $lista->json('saldo_sistema'));
        $this->assertSame(0, $lista->json('posfechados'));
        $this->postJson("/api/bank-movements/{$cheque->id}/toggle")->assertOk();
        $this->assertTrue((bool) $cheque->fresh()->conciliado);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Para conciliar, subo el archivo que me da el banco y el sistema me propone las parejas; yo acepto.»
     * Se prueba: el CSV del banco empareja el cheque (por su número) y el depósito (por fecha y monto), deja una línea del banco sin pareja y al aceptar solo marca conciliado, sin asientos nuevos.
     */
    #[Group('reuniones')]
    #[Group('R17')]
    public function test_R17_la_conciliacion_con_el_archivo_del_banco_propone_parejas_y_al_aceptar_solo_marca_conciliado(): void
    {
        // Arrange: el cheque y un depósito en el sistema
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        $hoy = now();
        $mover = fn (array $datos, string $cuenta) => $this->postJson('/api/bank-movements', $datos + [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'fecha' => $hoy->toDateString(),
            'cuenta_contrapartida_id' => Cuentas::cuenta($this->company->id, $cuenta)->id,
        ])->assertCreated();
        $mover(['tipo' => 'credito', 'monto' => 500, 'concepto' => 'Depósito de caja'], 'capital');
        $mover(['tipo' => 'debito', 'monto' => 115, 'concepto' => 'Pago a proveedor', 'forma' => 'cheque', 'documento' => '004512'], 'compras');
        $deposito = BankMovement::where('tipo', 'credito')->firstOrFail();
        $cheque = BankMovement::where('documento', '004512')->firstOrFail();
        $asientos = JournalEntry::count();
        $fecha = $hoy->format('d/m/Y');
        $csv = "Fecha;Descripción;Documento;Débito;Crédito;Saldo\n"
            ."{$fecha};PAGO CHEQUE;0004512;115,00;;385,00\n"
            ."{$fecha};DEPOSITO EFECTIVO;778;;500,00;885,00\n"
            ."{$fecha};NOTA DE CRÉDITO INTERÉS;;;12,34;897,34\n";

        // Act 1: se sube el archivo
        $r = $this->post('/api/bank-movements/importar', [
            'company_id' => $this->company->id, 'bank_id' => $banco->id,
            'csv' => UploadedFile::fake()->createWithContent('estado.csv', mb_convert_encoding($csv, 'ISO-8859-1', 'UTF-8')),
        ], ['Accept' => 'application/json'])->assertOk();

        // Assert 1: dos parejas y una línea del banco que el sistema no tiene
        $this->assertSame('columnas débito y crédito', $r->json('formato'));
        $this->assertSame(3, $r->json('resumen.lineas'));
        $this->assertSame(2, $r->json('resumen.con_pareja'));
        $lineas = collect($r->json('lineas'))->keyBy('descripcion');
        $this->assertSame($cheque->id, $lineas['PAGO CHEQUE']['movimiento']['id']);
        $this->assertSame('documento', $lineas['PAGO CHEQUE']['criterio']);
        $this->assertSame($deposito->id, $lineas['DEPOSITO EFECTIVO']['movimiento']['id']);
        $this->assertNull($lineas['NOTA DE CRÉDITO INTERÉS']['movimiento']);
        $this->assertSame(0, BankMovement::where('conciliado', true)->count(), 'Proponer no concilia nada');

        // Act 2: se aceptan las dos parejas
        $this->postJson('/api/bank-movements/importar/aplicar', [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'ids' => [$cheque->id, $deposito->id],
        ])->assertOk()->assertJsonPath('conciliados', 2);

        // Assert 2: conciliados, saldo conciliado y ningún asiento nuevo
        $this->assertTrue((bool) $cheque->fresh()->conciliado);
        $this->assertTrue((bool) $deposito->fresh()->conciliado);
        $this->assertSame($asientos, JournalEntry::count(), 'Conciliar no asienta nada');
        $this->assertEquals(385.0, $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$banco->id)->json('saldo_conciliado'));
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-10jul, CONS-18sep
     * Pedido: «Las ventas con tarjeta tienen que quedar en una cuenta por liquidar, y cuando el procesador deposita, esa cuenta debe quedar en cero.»
     * Se prueba: dos ventas con tarjeta ($230) suben 1.1.08; la liquidación con comisión y retenciones reparte el bruto y 1.1.08 vuelve a $0.
     */
    #[Group('reuniones')]
    #[Group('R17')]
    public function test_R17_la_liquidacion_de_tarjetas_deja_la_cuenta_por_liquidar_en_cero(): void
    {
        // Arrange: dos ventas con tarjeta de $115 y un banco
        $banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        $this->facturar($this->cliente, 100, 15, 'tarjeta');
        $this->facturar($this->cliente, 100, 15, 'tarjeta');
        $this->assertEquals(230.0, $this->saldoMayor(['1.1.08']));

        // Act: el procesador deposita 224.80 por 230 de ventas (comisión 4.60 y retenciones 0.55 + 0.05)
        $r = $this->postJson('/api/card/settlements', [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'fecha' => now()->toDateString(), 'procesador' => 'Datafast',
            'monto_bruto' => 230, 'comision' => 4.60, 'retencion_iva' => 0.55, 'retencion_renta' => 0.05, 'monto_neto' => 224.80, 'lote' => 'L-778',
        ])->assertCreated();
        $this->postJson('/api/card/settlements/'.$r->json('id').'/asignar', ['transaction_ids' => CardTransaction::pluck('id')->all()])->assertOk();

        // Assert: el asiento reparte el bruto, el depósito queda en el banco y 1.1.08 vuelve a cero
        $liquidacion = CardSettlement::firstOrFail();
        $this->assertSame('LQ-000001', $liquidacion->numero);
        $this->assertLineas($this->asientoDe($liquidacion), [['1.1.02', 224.80, 0], ['5.3.01', 4.60, 0], ['1.1.06', 0.55, 0], ['1.1.06', 0.05, 0], ['1.1.08', 0, 230]]);
        $this->assertEquals(224.80, (float) BankMovement::firstOrFail()->monto);
        $this->assertEquals(0.0, $this->saldoMayor(['1.1.08']), 'Tarjetas por liquidar vuelve a cero');
        $this->assertSame(0, CardTransaction::pendientes()->count());
        // Assert: un centavo de diferencia entre el bruto y el neto más descuentos se rechaza
        $this->postJson('/api/card/settlements', [
            'company_id' => $this->company->id, 'bank_id' => $banco->id, 'fecha' => now()->toDateString(), 'procesador' => 'Datafast',
            'monto_bruto' => 100, 'comision' => 1, 'monto_neto' => 98.99,
        ])->assertStatus(422);
        $this->assertSame(1, CardSettlement::count());
        $this->assertContabilidadCuadra();
    }
}
