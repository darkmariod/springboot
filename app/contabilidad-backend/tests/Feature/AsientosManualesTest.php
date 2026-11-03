<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GenerateInvoiceJournalEntry;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T4.1 y T4.2: asientos manuales (borrador o pendiente), editar y eliminar, y mayorizar por mes.
 * Cada prueba revisa las líneas reales y los valores, no solo el código HTTP.
 */
class AsientosManualesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otra;
    private Contact $cliente;
    private Contact $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Asientos SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->otra = Company::create([
            'ruc' => '1790000000001', 'razon_social' => 'Otra Empresa SA', 'dir_matriz' => 'Av. 1',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Cuentas::sembrar($this->company->id);
        Cuentas::sembrar($this->otra->id);
        $this->cliente = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '05',
            'identificacion' => '1700000001', 'razon_social' => 'Cliente Test',
        ]);
        $this->proveedor = Contact::create([
            'company_id' => $this->company->id, 'tipo_identificacion' => '04',
            'identificacion' => '1790011223001', 'razon_social' => 'Proveedor Test',
        ]);
        Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));
    }

    // ------------------------------------------------------------- T4.1 crear

    public function test_asiento_manual_cuadrado_se_crea_numerado_y_marcado_como_manual(): void
    {
        $this->postJson('/api/journal-entries', $this->manual('Aporte de capital', [
            ['1.1.01', 500, 0], ['3.1.01', 0, 500],
        ]))->assertCreated()
            ->assertJsonPath('numero', 'AS-000001')
            ->assertJsonPath('manual', true)
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('puede_editar', true)
            ->assertJsonPath('puede_eliminar', true);

        $e = JournalEntry::firstOrFail();
        $this->assertTrue($e->manual);
        $this->assertNull($e->origen_type);
        $this->assertNull($e->origen_id);
        $this->assertSame('2026-03-05', $e->fecha->toDateString());
        $this->assertEquals(500, $e->total_debe);
        $this->assertEquals(500, $e->total_haber);
        $this->assertSame([['1.1.01', 500.0, 0.0, 'Aporte inicial'], ['3.1.01', 0.0, 500.0, null]], $this->lineas($e));
    }

    public function test_asiento_descuadrado_da_422_con_los_valores_y_no_guarda_nada(): void
    {
        $res = $this->postJson('/api/journal-entries', $this->manual('Descuadrado', [
            ['1.1.01', 100, 0], ['3.1.01', 0, 90],
        ]))->assertStatus(422);

        $this->assertSame('El asiento no cuadra: debe 100.00, haber 90.00', $res->json('message'));
        $this->assertSame(0, JournalEntry::count());

        // Un centavo de diferencia también descuadra
        $res = $this->postJson('/api/journal-entries', $this->manual('Un centavo', [
            ['1.1.01', 100.01, 0], ['3.1.01', 0, 100],
        ]))->assertStatus(422);
        $this->assertSame('El asiento no cuadra: debe 100.01, haber 100.00', $res->json('message'));
    }

    public function test_reglas_de_las_lineas(): void
    {
        // Menos de dos líneas
        $this->postJson('/api/journal-entries', $this->manual('Una sola', [['1.1.01', 10, 0]]))->assertStatus(422);
        // Valor en Debe y en Haber a la vez
        $this->postJson('/api/journal-entries', $this->manual('Ambos', [['1.1.01', 10, 5], ['3.1.01', 0, 5]]))
            ->assertStatus(422)->assertJsonPath('message', 'Línea 1: escribe el valor en Debe o en Haber, no en los dos.');
        // Línea sin valor
        $this->postJson('/api/journal-entries', $this->manual('Cero', [['1.1.01', 10, 0], ['3.1.01', 0, 10], ['1.1.02', 0, 0]]))
            ->assertStatus(422)->assertJsonPath('message', 'Línea 3: escribe un valor mayor que cero en Debe o en Haber.');
        // Más de dos decimales
        $this->postJson('/api/journal-entries', $this->manual('Decimales', [['1.1.01', 10.005, 0], ['3.1.01', 0, 10.005]]))->assertStatus(422);
        // Sin concepto o sin fecha
        $this->postJson('/api/journal-entries', ['company_id' => $this->company->id, 'fecha' => '2026-03-05',
            'lines' => $this->payload([['1.1.01', 10, 0], ['3.1.01', 0, 10]])])->assertStatus(422)->assertJsonValidationErrors('concepto');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_cuenta_de_otra_empresa_o_encabezado_del_plan_da_422(): void
    {
        $ajena = Account::where('company_id', $this->otra->id)->where('codigo', '3.1.01')->firstOrFail();
        $payload = $this->manual('Cuenta ajena', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]);
        $payload['lines'][1]['account_id'] = $ajena->id;
        $this->postJson('/api/journal-entries', $payload)->assertStatus(422)
            ->assertJsonPath('message', 'Una de las cuentas elegidas no pertenece a esta empresa.');

        // "1.1 ACTIVO CORRIENTE" tiene subcuentas (1.1.01…): no recibe movimientos
        $encabezado = Account::where('company_id', $this->company->id)->where('codigo', '1.1')->firstOrFail();
        $payload = $this->manual('Encabezado', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]);
        $payload['lines'][0]['account_id'] = $encabezado->id;
        $this->postJson('/api/journal-entries', $payload)->assertStatus(422);

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_el_borrador_no_cuenta_en_mayor_ni_estados_hasta_contabilizarlo(): void
    {
        $id = $this->postJson('/api/journal-entries', $this->manual('Aporte', [['1.1.01', 500, 0], ['3.1.01', 0, 500]], 'borrador'))
            ->assertCreated()->assertJsonPath('estado', 'borrador')->json('id');

        $this->assertSame([], $this->getJson("/api/ledger?company_id={$this->company->id}")->assertOk()->json());
        $this->getJson("/api/balance-sheet?company_id={$this->company->id}")->assertOk()
            ->assertJsonPath('activos', 0)->assertJsonPath('patrimonio', 0);
        // El libro diario sí lo muestra, con su estado
        $this->getJson("/api/journal?company_id={$this->company->id}")->assertOk()
            ->assertJsonPath('0.estado', 'borrador')->assertJsonPath('0.manual', true);
        // Mayorizar no lo toca y dice por qué
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id])->assertOk()
            ->assertJsonPath('mayorizados', 0)->assertJsonPath('omitidos.0.numero', 'AS-000001')
            ->assertJsonPath('omitidos.0.motivo', 'Es un borrador: contabilízalo primero.');

        $this->postJson("/api/journal-entries/{$id}/contabilizar", ['company_id' => $this->company->id])
            ->assertOk()->assertJsonPath('estado', 'pendiente');

        $ledger = collect($this->getJson("/api/ledger?company_id={$this->company->id}")->assertOk()->json())->keyBy('codigo');
        $this->assertEquals(500, $ledger['1.1.01']['debe']);
        $this->assertEquals(500, $ledger['3.1.01']['haber']);
        $this->getJson("/api/balance-sheet?company_id={$this->company->id}")->assertJsonPath('activos', 500)->assertJsonPath('patrimonio', 500)->assertJsonPath('cuadrado', true);

        // Contabilizar dos veces: 422
        $this->postJson("/api/journal-entries/{$id}/contabilizar")->assertStatus(422);
    }

    public function test_numero_sin_colision_tras_eliminar_asientos(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[$n] = $this->postJson('/api/journal-entries', $this->manual("Manual $n", [['1.1.01', 10, 0], ['3.1.01', 0, 10]]))
                ->assertCreated()->json('id');
        }
        $this->assertSame(['AS-000001', 'AS-000002', 'AS-000003'], JournalEntry::orderBy('id')->pluck('numero')->all());

        // Se elimina el último: el siguiente retoma ese número, sin repetir ninguno
        $this->deleteJson("/api/journal-entries/{$ids[3]}")->assertOk();
        $this->postJson('/api/journal-entries', $this->manual('Manual 4', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]))
            ->assertCreated()->assertJsonPath('numero', 'AS-000003');

        // Se elimina uno del medio: "cuántos hay + 1" repetiría AS-000003. Un documento que numera así no choca
        $this->deleteJson("/api/journal-entries/{$ids[2]}")->assertOk();
        $factura = $this->facturaCredito(100);
        $generado = app(GenerateInvoiceJournalEntry::class)->handle($factura);
        $manual = $this->postJson('/api/journal-entries', $this->manual('Manual 5', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]))->assertCreated()->json('numero');

        $numeros = JournalEntry::pluck('numero')->all();
        $this->assertSame($numeros, array_values(array_unique($numeros)), 'Hay números de asiento repetidos: '.implode(', ', $numeros));
        $this->assertCount(4, $numeros);
        $this->assertNotSame($generado->numero, $manual);
    }

    // ------------------------------------------------------------- T4.1 editar

    public function test_editar_un_asiento_generado_cambia_la_cuenta_conserva_el_documento_y_audita(): void
    {
        $factura = $this->facturaCredito(100);
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($factura);
        $this->assertSame([['1.1.03', 100.0, 0.0, '001-001-000000010'], ['4.1.01', 0.0, 100.0, '001-001-000000010'], ['2.1.02', 0.0, 0.0, '001-001-000000010']],
            $this->lineas($entry));

        // La contadora se equivocó de cuenta: el cobro iba a Cuentas por cobrar relacionadas (1.1.09), no a 1.1.03.
        // La línea de IVA en cero que trae el asiento generado viaja igual y se descarta
        $res = $this->putJson("/api/journal-entries/{$entry->id}", [
            'company_id' => $this->company->id, 'fecha' => '2026-03-06', 'concepto' => 'Venta factura 001-001-000000010 (corregida)',
            'lines' => $this->payload([['1.1.09', 100, 0, 'cuenta corregida'], ['4.1.01', 0, 100], ['2.1.02', 0, 0]]),
        ])->assertOk()->assertJsonPath('editado_manualmente', true)->assertJsonPath('estado', 'pendiente');

        $this->assertEquals(100, $res->json('total_debe'));
        $this->assertEquals(100, $res->json('total_haber'));
        $e = $entry->fresh();
        $this->assertSame($factura->getMorphClass(), $e->origen_type);
        $this->assertSame($factura->id, (int) $e->origen_id);
        $this->assertSame('2026-03-06', $e->fecha->toDateString());
        $this->assertSame('Venta factura 001-001-000000010 (corregida)', $e->concepto);
        $this->assertFalse($e->manual);
        $this->assertTrue($e->editado_manualmente);
        $this->assertNotNull($e->editado_at);
        $this->assertSame([['1.1.09', 100.0, 0.0, 'cuenta corregida'], ['4.1.01', 0.0, 100.0, null]], $this->lineas($e));
        $this->assertSame(2, $e->lines()->count());

        // Auditoría: quién, y antes / después
        $log = AuditLog::where('accion', 'edito_asiento')->where('modelo_id', $entry->id)->firstOrFail();
        $this->assertSame('JournalEntry', $log->modelo);
        $this->assertSame($entry->numero, $log->descripcion);
        $this->assertSame((int) auth('sanctum')->id(), (int) $log->user_id);
        $this->assertSame($this->company->id, (int) $log->company_id);
        $this->assertSame('1.1.03 Cuentas por cobrar clientes', $log->cambios['antes']['lineas'][0]['cuenta']);
        $this->assertSame('1.1.09 Cuentas por cobrar relacionadas', $log->cambios['despues']['lineas'][0]['cuenta']);
        $this->assertSame('2026-03-06', $log->cambios['despues']['fecha']);
        $this->assertSame(1, AuditLog::where('accion', 'edito_asiento')->count());
    }

    public function test_editar_sin_cambios_no_marca_ni_audita(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $fecha = $entry->fecha->toDateString();

        $this->putJson("/api/journal-entries/{$entry->id}", [
            'fecha' => $fecha, 'concepto' => $entry->concepto,
            'lines' => $this->payload([['1.1.03', 100, 0, '001-001-000000010'], ['4.1.01', 0, 100, '001-001-000000010']]),
        ])->assertOk();

        $this->assertSame(0, AuditLog::where('accion', 'edito_asiento')->count());
    }

    public function test_un_asiento_mayorizado_no_se_edita_hasta_desmayorizarlo(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('mayorizados', 1);
        $cuerpo = ['fecha' => '2026-03-06', 'concepto' => 'Cambio', 'lines' => $this->payload([['1.1.09', 100, 0], ['4.1.01', 0, 100]])];

        $this->putJson("/api/journal-entries/{$entry->id}", $cuerpo)->assertStatus(422)
            ->assertJsonPath('message', 'Desmayoriza el asiento para poder editarlo.');
        $this->assertSame('1.1.03', $this->lineas($entry->fresh())[0][0]);

        $this->postJson("/api/journal/{$entry->id}/desmayorizar")->assertOk();
        $this->assertSame('pendiente', $entry->fresh()->estado);
        $this->putJson("/api/journal-entries/{$entry->id}", $cuerpo)->assertOk();
        $this->assertSame('1.1.09', $this->lineas($entry->fresh())[0][0]);
    }

    public function test_un_asiento_anulado_y_su_contra_asiento_no_se_editan(): void
    {
        $this->comprar();
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');
        $this->postJson("/api/invoices/{$factura->id}/anular")->assertOk();

        $entradas = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->orderBy('id')->get();
        $this->assertCount(2, $entradas);
        [$original, $reverso] = $entradas;
        $this->assertStringStartsWith('Reversión', $reverso->concepto);

        $cuerpo = fn (JournalEntry $e) => ['fecha' => '2026-03-06', 'concepto' => 'Cambio', 'lines' => $this->payload($e->lines->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->all())];
        $this->putJson("/api/journal-entries/{$reverso->id}", $cuerpo($reverso->load('lines.account')))->assertStatus(422)
            ->assertJsonPath('message', 'Un contra-asiento no se edita: es el reverso de una anulación.');
        $this->putJson("/api/journal-entries/{$original->id}", $cuerpo($original->load('lines.account')))->assertStatus(422)
            ->assertJsonPath('message', 'Este asiento ya fue anulado con su contra-asiento y no se puede editar.');

        // El libro diario ya no ofrece Editar en ninguno de los dos
        $diario = collect($this->getJson("/api/journal?company_id={$this->company->id}")->assertOk()->json())->keyBy('id');
        $this->assertTrue($diario[$original->id]['anulado']);
        $this->assertFalse($diario[$original->id]['puede_editar']);
        $this->assertTrue($diario[$reverso->id]['es_contra']);
        $this->assertFalse($diario[$reverso->id]['puede_editar']);
    }

    public function test_no_se_puede_editar_una_linea_hacia_una_cuenta_de_otra_empresa(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $ajena = Account::where('company_id', $this->otra->id)->where('codigo', '1.1.09')->firstOrFail();
        $lineas = $this->payload([['1.1.03', 100, 0], ['4.1.01', 0, 100]]);
        $lineas[0]['account_id'] = $ajena->id;

        $this->putJson("/api/journal-entries/{$entry->id}", ['fecha' => '2026-03-06', 'concepto' => 'x', 'lines' => $lineas])
            ->assertStatus(422)->assertJsonPath('message', 'Una de las cuentas elegidas no pertenece a esta empresa.');
        $this->assertSame('1.1.03', $this->lineas($entry->fresh())[0][0]);
        $this->assertFalse($entry->fresh()->editado_manualmente);
    }

    public function test_el_asiento_editado_tiene_que_seguir_cuadrando(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));

        $this->putJson("/api/journal-entries/{$entry->id}", ['fecha' => '2026-03-06', 'concepto' => 'x',
            'lines' => $this->payload([['1.1.09', 100, 0], ['4.1.01', 0, 95]])])
            ->assertStatus(422)->assertJsonPath('message', 'El asiento no cuadra: debe 100.00, haber 95.00');
        $this->assertSame('1.1.03', $this->lineas($entry->fresh())[0][0]);
    }

    public function test_editar_un_asiento_manual_y_pasarlo_de_borrador_a_pendiente(): void
    {
        $id = $this->postJson('/api/journal-entries', $this->manual('Gasto', [['5.1.01', 40, 0], ['1.1.01', 0, 40]], 'borrador'))->assertCreated()->json('id');

        $this->putJson("/api/journal-entries/{$id}", ['concepto' => 'Gasto de oficina', 'fecha' => '2026-03-07', 'estado' => 'pendiente',
            'lines' => $this->payload([['5.1.01', 45, 0], ['1.1.02', 0, 45]])])
            ->assertOk()->assertJsonPath('estado', 'pendiente')->assertJsonPath('manual', true)->assertJsonPath('editado_manualmente', false);

        $e = JournalEntry::findOrFail($id);
        $this->assertSame([['5.1.01', 45.0, 0.0, null], ['1.1.02', 0.0, 45.0, null]], $this->lineas($e));
        $this->assertEquals(45, $e->total_debe);
        // Un asiento generado no cambia de estado por esta vía
        $generado = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(10, '001-001-000000011'));
        $this->putJson("/api/journal-entries/{$generado->id}", ['fecha' => '2026-03-07', 'concepto' => 'x', 'estado' => 'borrador',
            'lines' => $this->payload([['1.1.03', 10, 0], ['4.1.01', 0, 10]])])->assertStatus(422);
    }

    // ------------------------------------------------------------- T4.1 eliminar

    public function test_se_elimina_un_manual_que_nunca_se_mayorizo_y_queda_en_la_auditoria(): void
    {
        $id = $this->postJson('/api/journal-entries', $this->manual('Para borrar', [['1.1.01', 20, 0], ['3.1.01', 0, 20]]))->assertCreated()->json('id');

        $this->deleteJson("/api/journal-entries/{$id}")->assertOk();

        $this->assertNull(JournalEntry::find($id));
        $this->assertSame(0, \App\Models\JournalEntryLine::where('journal_entry_id', $id)->count());
        $log = AuditLog::where('accion', 'elimino_asiento')->where('modelo_id', $id)->firstOrFail();
        $this->assertSame('1.1.01 Caja', $log->cambios['antes']['lineas'][0]['cuenta']);
        $this->assertSame(1, AuditLog::where('modelo', 'JournalEntry')->where('modelo_id', $id)->where('accion', 'like', 'elimin%')->count());
    }

    public function test_no_se_elimina_un_manual_que_ya_fue_mayorizado_ni_uno_generado(): void
    {
        $id = $this->postJson('/api/journal-entries', $this->manual('Mayorizado alguna vez', [['1.1.01', 20, 0], ['3.1.01', 0, 20]]))->assertCreated()->json('id');
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'ids' => [$id]])->assertOk()->assertJsonPath('mayorizados', 1);

        $this->deleteJson("/api/journal-entries/{$id}")->assertStatus(422);
        $this->postJson("/api/journal-entries/{$id}/desmayorizar")->assertOk();
        // Aunque se desmayorice, ya pasó por el mayor: no se elimina
        $this->deleteJson("/api/journal-entries/{$id}")->assertStatus(422)->assertJsonPath('message', 'Este asiento ya fue mayorizado y no se puede eliminar.');
        $this->assertNotNull(JournalEntry::find($id));

        $generado = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $this->deleteJson("/api/journal-entries/{$generado->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Un asiento generado por un documento no se elimina: se reversa al anular el documento.');
        $this->assertNotNull(JournalEntry::find($generado->id));

        // El libro diario solo ofrece Eliminar donde corresponde
        $diario = collect($this->getJson("/api/journal?company_id={$this->company->id}")->json())->keyBy('id');
        $this->assertFalse($diario[$id]['puede_eliminar']);
        $this->assertFalse($diario[$generado->id]['puede_eliminar']);
        $this->assertTrue($diario[$generado->id]['puede_editar']);
    }

    // ------------------------------------------------------------- roles y empresa

    public function test_el_cajero_no_puede_crear_editar_eliminar_ni_mayorizar(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $manual = JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-000900', 'fecha' => '2026-03-01', 'concepto' => 'm',
            'total_debe' => 0, 'total_haber' => 0, 'estado' => 'borrador', 'manual' => true]);
        Sanctum::actingAs(User::factory()->create(['rol' => 'cajero', 'activo' => true]));

        $this->postJson('/api/journal-entries', $this->manual('x', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]))->assertForbidden();
        $this->putJson("/api/journal-entries/{$entry->id}", ['fecha' => '2026-03-06', 'concepto' => 'x', 'lines' => $this->payload([['1.1.09', 100, 0], ['4.1.01', 0, 100]])])->assertForbidden();
        $this->deleteJson("/api/journal-entries/{$manual->id}")->assertForbidden();
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id])->assertForbidden();
        $this->postJson("/api/journal/{$entry->id}/desmayorizar")->assertForbidden();
        $this->assertSame('pendiente', $entry->fresh()->estado);
    }

    public function test_el_contador_si_puede_y_no_se_mezclan_las_empresas(): void
    {
        $entry = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        $cuerpo = ['fecha' => '2026-03-06', 'concepto' => 'Corrección', 'lines' => $this->payload([['1.1.09', 100, 0], ['4.1.01', 0, 100]])];

        // La contadora trabaja en la empresa que elige en el selector: sin empresa propia (T11.6: un usuario con empresa queda
        // confinado a ella con 403 antes del controlador; eso lo prueban AislamientoEntreEmpresasTest y compañía). Aquí se
        // conserva lo que verifica el controlador: pedir el asiento con la empresa equivocada es 404 y no cambia nada.
        Sanctum::actingAs(User::factory()->create(['rol' => 'contador', 'activo' => true, 'company_id' => null]));
        // Pedir el asiento con la empresa equivocada: 404 y no cambia nada
        $this->putJson("/api/journal-entries/{$entry->id}", ['company_id' => $this->otra->id] + $cuerpo)->assertNotFound();
        $this->deleteJson("/api/journal-entries/{$entry->id}?company_id={$this->otra->id}")->assertNotFound();
        $this->postJson("/api/journal/{$entry->id}/desmayorizar", ['company_id' => $this->otra->id])->assertNotFound();
        $this->assertSame('1.1.03', $this->lineas($entry->fresh())[0][0]);

        $this->putJson("/api/journal-entries/{$entry->id}", ['company_id' => $this->company->id] + $cuerpo)->assertOk();
        $this->postJson('/api/journal-entries', $this->manual('Del contador', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]))->assertCreated();
        // Una cuenta de la otra empresa sigue siendo 422 aunque la contadora sea de esa otra empresa
        $payload = $this->manual('Cuenta ajena', [['1.1.01', 10, 0], ['3.1.01', 0, 10]]);
        $payload['lines'][1]['account_id'] = Account::where('company_id', $this->otra->id)->where('codigo', '3.1.01')->value('id');
        $this->postJson('/api/journal-entries', $payload)->assertStatus(422);
    }

    // ------------------------------------------------------------- T4.2 mayorizar por mes

    public function test_mayorizar_por_mes_solo_toma_los_cuadrados_de_ese_mes_y_reporta_los_omitidos(): void
    {
        $enero1 = $this->crearManual('Enero 1', '2026-01-10');
        $enero2 = $this->crearManual('Enero 2', '2026-01-31');
        $descuadrado = $this->crearManual('Enero descuadrado', '2026-01-20');
        $borrador = $this->crearManual('Enero borrador', '2026-01-25', 'borrador');
        $febrero = $this->crearManual('Febrero', '2026-02-01');
        // Un asiento viejo que quedó descuadrado: la línea no suma lo que dice la cabecera
        JournalEntry::whereKey($descuadrado)->update(['total_debe' => 99]);

        $res = $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'mes' => 1, 'anio' => 2026])
            ->assertOk()->assertJsonPath('mayorizados', 2);

        $this->assertSame('mayorizado', JournalEntry::find($enero1)->estado);
        $this->assertSame('mayorizado', JournalEntry::find($enero2)->estado);
        $this->assertSame('pendiente', JournalEntry::find($febrero)->estado, 'Febrero está fuera del mes');
        $this->assertSame('pendiente', JournalEntry::find($descuadrado)->estado);
        $omitidos = collect($res->json('omitidos'))->keyBy('numero');
        $this->assertCount(2, $omitidos);
        $this->assertStringStartsWith('Descuadrado: debe', $omitidos[JournalEntry::find($descuadrado)->numero]['motivo']);
        $this->assertSame('Es un borrador: contabilízalo primero.', $omitidos[JournalEntry::find($borrador)->numero]['motivo']);
        $this->assertTrue(JournalEntry::find($enero1)->fue_mayorizado);

        // Con ids y un mes a la vez, el que está fuera del mes se reporta
        $res = $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'ids' => [$febrero], 'mes' => 1, 'anio' => 2026])
            ->assertOk()->assertJsonPath('mayorizados', 0);
        $this->assertSame('Está fuera del período elegido.', $res->json('omitidos.0.motivo'));
        $this->assertSame('pendiente', JournalEntry::find($febrero)->estado);

        // Con desde/hasta
        $this->postJson('/api/journal/mayorizar', ['company_id' => $this->company->id, 'desde' => '2026-02-01', 'hasta' => '2026-02-28'])
            ->assertOk()->assertJsonPath('mayorizados', 1)->assertJsonPath('numeros.0', JournalEntry::find($febrero)->numero);
        $this->assertSame('mayorizado', JournalEntry::find($febrero)->estado);

        // Ya mayorizado, pedido por id
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'ids' => [$enero1]])
            ->assertOk()->assertJsonPath('mayorizados', 0)->assertJsonPath('omitidos.0.motivo', 'Ya está mayorizado.');

        $this->assertSame(1, AuditLog::where('accion', 'mayorizo_asientos')->where('company_id', $this->company->id)->where('descripcion', '2 asientos mayorizados')->count());
    }

    public function test_mayorizar_sin_filtros_sigue_mayorizando_todos_los_pendientes(): void
    {
        $a = $this->crearManual('A', '2026-01-10');
        $b = $this->crearManual('B', '2026-02-10');
        $generado = app(GenerateInvoiceJournalEntry::class)->handle($this->facturaCredito(100));
        // Los de otra empresa no se tocan
        $ajeno = JournalEntry::create(['company_id' => $this->otra->id, 'numero' => 'AS-000001', 'fecha' => '2026-01-01', 'concepto' => 'ajeno',
            'total_debe' => 0, 'total_haber' => 0, 'estado' => 'pendiente']);

        $this->postJson('/api/journal/mayorizar', ['company_id' => $this->company->id])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('mayorizados', 3)->assertJsonPath('omitidos', []);

        foreach ([$a, $b, $generado->id] as $id) {
            $this->assertSame('mayorizado', JournalEntry::find($id)->estado);
        }
        $this->assertSame('pendiente', $ajeno->fresh()->estado);
        $this->postJson('/api/journal/mayorizar', [])->assertStatus(422);
    }

    public function test_el_libro_diario_filtra_por_mes_estado_y_texto(): void
    {
        $this->crearManual('Pago de arriendo', '2026-01-10');
        $this->crearManual('Compra de papelería', '2026-02-10', 'borrador');
        $this->crearManual('Otro de febrero', '2026-02-12');
        $url = "/api/journal?company_id={$this->company->id}";

        $this->assertCount(3, $this->getJson($url)->assertOk()->json());
        $this->assertCount(2, $this->getJson("$url&mes=2&anio=2026")->json());
        $this->assertCount(1, $this->getJson("$url&mes=2&anio=2026&estado=borrador")->json());
        $this->assertSame('Pago de arriendo', $this->getJson("$url&q=arriendo")->json('0.concepto'));
        $this->assertCount(1, $this->getJson("$url&q=AS-000002")->json());
        $this->assertCount(1, $this->getJson("$url&desde=2026-02-11")->json());
    }

    // ------------------------------------------------------------- chequeo contable

    public function test_el_chequeo_estricto_sigue_en_cero_con_asientos_manuales_y_ediciones_que_no_tocan_la_cartera(): void
    {
        $this->comprar();
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');
        $this->chequeo(['--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);

        // Un asiento manual entre cuentas que el chequeo no compara
        $this->postJson('/api/journal-entries', $this->manual('Gasto de oficina', [['5.1.01', 30, 0], ['1.1.01', 0, 30]]))->assertCreated();
        // Un borrador que tocara CxC no cuenta en el mayor
        $this->postJson('/api/journal-entries', $this->manual('Borrador sobre CxC', [['1.1.03', 500, 0], ['3.1.01', 0, 500]], 'borrador'))->assertCreated();
        // Editar la fecha y el concepto de la factura sin mover ninguna cuenta
        $entry = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->firstOrFail();
        $lineas = $entry->lines()->with('account')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber, $l->referencia])->all();
        $this->putJson("/api/journal-entries/{$entry->id}", ['fecha' => '2026-03-09', 'concepto' => 'Venta corregida', 'lines' => $this->payload($lineas)])->assertOk();

        $this->chequeo(['--estricto' => true])->expectsOutputToContain('TODO OK')->assertExitCode(0);
    }

    public function test_el_chequeo_explica_una_diferencia_de_cartera_causada_por_una_edicion(): void
    {
        $this->comprar();
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');   // 23.00 a crédito
        $this->chequeo(['--estricto' => true])->assertExitCode(0);

        // Mover la línea de CxC del asiento de la factura a Caja: el mayor de 1.1.03 queda en cero y la cartera dice 23.00
        $entry = JournalEntry::where('origen_type', $factura->getMorphClass())->where('origen_id', $factura->id)->firstOrFail();
        $lineas = $entry->lines()->with('account')->get()->map(fn ($l) => [
            $l->account->codigo === '1.1.03' ? '1.1.01' : $l->account->codigo, (float) $l->debe, (float) $l->haber, $l->referencia,
        ])->all();
        $this->putJson("/api/journal-entries/{$entry->id}", ['fecha' => $entry->fecha->toDateString(), 'concepto' => $entry->concepto, 'lines' => $this->payload($lineas)])
            ->assertOk()->assertJsonPath('editado_manualmente', true);

        $this->chequeo(['--estricto' => true])
            ->expectsOutputToContain('DIFERENCIA  Cuentas por cobrar (1.1.03): facturas pendientes − notas de crédito disponibles $23.00 ≠ mayor $0.00')
            ->expectsOutputToContain('hay asientos editados a mano ('.$entry->numero.')')
            ->assertExitCode(1);
        // Sin --estricto sigue sin hacer fallar, y también avisa
        $this->chequeo()->expectsOutputToContain('hay asientos editados a mano')->assertExitCode(0);
    }

    public function test_una_diferencia_sin_ediciones_no_menciona_asientos_editados(): void
    {
        $this->comprar();
        $this->facturar([['MERC-1', 2, 10]], 'credito');
        Invoice::where('company_id', $this->company->id)->increment('saldo_pendiente', 5);

        $this->chequeo(['--estricto' => true])
            ->expectsOutputToContain('DIFERENCIA  Cuentas por cobrar')
            ->doesntExpectOutputToContain('asientos editados a mano')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------- helpers

    private function chequeo(array $opciones = [])
    {
        return $this->artisan('contable:chequeo', ['--company' => $this->company->id] + $opciones);
    }

    /** @param  array<int,array{0:string,1:float|int,2:float|int,3?:?string}>  $filas [código de cuenta, debe, haber, referencia] */
    private function payload(array $filas, ?int $companyId = null): array
    {
        $companyId ??= $this->company->id;

        return array_map(function ($f) use ($companyId) {
            $l = [
                'account_id' => Account::where('company_id', $companyId)->where('codigo', $f[0])->firstOrFail()->id,
                'debe' => $f[1], 'haber' => $f[2],
            ];
            if (isset($f[3])) {
                $l['referencia'] = $f[3];
            }

            return $l;
        }, $filas);
    }

    private function manual(string $concepto, array $filas, ?string $estado = null, string $fecha = '2026-03-05'): array
    {
        $filas[0][3] ??= 'Aporte inicial';
        $cuerpo = ['company_id' => $this->company->id, 'fecha' => $fecha, 'concepto' => $concepto, 'lines' => $this->payload($filas)];
        if ($estado) {
            $cuerpo['estado'] = $estado;
        }

        return $cuerpo;
    }

    private function crearManual(string $concepto, string $fecha, ?string $estado = null): int
    {
        return $this->postJson('/api/journal-entries', $this->manual($concepto, [['1.1.01', 10, 0], ['3.1.01', 0, 10]], $estado, $fecha))
            ->assertCreated()->json('id');
    }

    /** @return array<int,array{0:string,1:float,2:float,3:?string}> */
    private function lineas(JournalEntry $e): array
    {
        return $e->lines()->with('account')->orderBy('id')->get()
            ->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber, $l->referencia])->all();
    }

    private function facturaCredito(float $total, string $numero = '001-001-000000010'): Invoice
    {
        return Invoice::create([
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'numero' => $numero, 'items' => [],
            'total_sin_impuestos' => $total, 'total_impuesto' => 0, 'importe_total' => $total,
            'forma_pago' => 'credito', 'saldo_pendiente' => $total, 'estado' => 'emitida', 'fecha_emision' => '2026-03-05',
        ]);
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
