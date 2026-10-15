<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SimpleEntry;
use App\Support\Cuentas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T4.3a: períodos contables (abierto / cerrado), cierre de período y cierre del ejercicio.
 * Cada prueba revisa valores reales: líneas del asiento, estados, saldos y que un documento rechazado no deje nada a medias.
 */
class PeriodosContablesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otra;
    private Contact $cliente;
    private Contact $proveedor;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Periodos SA', 'dir_matriz' => 'Av. Test 123',
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
        $this->admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function mensajeCerrado(string $periodo): string
    {
        return "El período {$periodo} está cerrado. Reábrelo para registrar o modificar movimientos de esa fecha.";
    }

    // ------------------------------------------------------------- el modelo

    public function test_un_mes_sin_registro_esta_abierto_y_el_modelo_sabe_si_esta_cerrado(): void
    {
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-09-15'));
        $this->assertSame(0, AccountingPeriod::count());

        $this->cerrar(2026, 9)->assertOk();

        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-09-15'));
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, Carbon::parse('2026-09-01 00:00:00')));
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-09-30 23:59:59'));
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-08-31'));
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-10-01'));
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2025-09-15'), 'Otro año');
        $this->assertFalse(AccountingPeriod::estaCerrado($this->otra->id, '2026-09-15'), 'Otra empresa');

        // Una sola fila por empresa, año y mes (índice único)
        $fila = AccountingPeriod::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(2026, (int) $fila->anio);
        $this->assertSame(9, (int) $fila->mes);
        $this->assertSame('cerrado', $fila->estado);
        $this->assertSame($this->admin->id, (int) $fila->cerrado_por);
        $this->assertNotNull($fila->cerrado_at);
        $this->expectException(\Illuminate\Database\QueryException::class);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 9, 'estado' => 'abierto']);
    }

    // ------------------------------------------------------------- listado

    public function test_el_listado_trae_los_doce_meses_con_estado_y_conteo_de_asientos_por_estado(): void
    {
        $this->manual('Marzo borrador', '2026-03-05', 'borrador', 10);
        $this->manual('Marzo pendiente', '2026-03-06', 'pendiente', 20);
        $this->manual('Marzo pendiente 2', '2026-03-07', 'pendiente', 30);
        $this->manual('Mayo pendiente', '2026-05-07', 'pendiente', 5);
        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id, 'mes' => 5, 'anio' => 2026])->assertOk();
        $this->cerrar(2026, 1)->assertOk();

        $res = $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2026")->assertOk();
        $meses = collect($res->json('meses'));
        $this->assertCount(12, $meses);
        $this->assertSame(range(1, 12), $meses->pluck('mes')->all());
        $this->assertSame('2026-01', $meses[0]['periodo']);
        $this->assertSame('cerrado', $meses[0]['estado']);

        $marzo = $meses->firstWhere('mes', 3);
        $this->assertSame('abierto', $marzo['estado']);
        $this->assertSame(['borrador' => 1, 'pendiente' => 2, 'mayorizado' => 0, 'total' => 3], $marzo['asientos']);
        // Solo cuentan en los totales los asientos que cuentan en los reportes (no el borrador)
        $this->assertEquals(50, $marzo['total_debe']);
        $this->assertEquals(50, $marzo['total_haber']);
        $this->assertFalse($marzo['puede_cerrar']);
        $this->assertCount(1, $marzo['bloqueos']);

        $mayo = $meses->firstWhere('mes', 5);
        $this->assertSame(['borrador' => 0, 'pendiente' => 0, 'mayorizado' => 1, 'total' => 1], $mayo['asientos']);
        $this->assertTrue($mayo['puede_cerrar']);

        $this->assertFalse($res->json('ejercicio.cerrado'));
        $this->assertFalse($res->json('ejercicio.puede_cerrar'));
        $this->assertCount(11, $res->json('ejercicio.meses_abiertos'));
    }

    // ------------------------------------------------------------- cerrar

    public function test_cerrar_un_mes_mayoriza_solo_los_pendientes_de_ese_mes_y_lo_marca_cerrado(): void
    {
        $febrero = $this->manual('Febrero', '2026-02-10', 'pendiente', 10);
        $marzoA = $this->manual('Marzo A', '2026-03-05', 'pendiente', 20);
        $marzoB = $this->manual('Marzo B', '2026-03-31', 'pendiente', 30);
        $abril = $this->manual('Abril', '2026-04-01', 'pendiente', 40);

        $res = $this->cerrar(2026, 3)->assertOk()
            ->assertJsonPath('periodo', '2026-03')->assertJsonPath('mayorizados', 2);
        $this->assertEqualsCanonicalizing([JournalEntry::find($marzoA)->numero, JournalEntry::find($marzoB)->numero], $res->json('numeros'));

        $this->assertSame('mayorizado', JournalEntry::find($marzoA)->estado);
        $this->assertTrue(JournalEntry::find($marzoA)->fue_mayorizado);
        $this->assertSame('mayorizado', JournalEntry::find($marzoB)->estado);
        $this->assertSame('pendiente', JournalEntry::find($febrero)->estado, 'Febrero no se toca');
        $this->assertSame('pendiente', JournalEntry::find($abril)->estado, 'Abril no se toca');
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-03-31'));

        $log = AuditLog::where('accion', 'cerro_periodo')->firstOrFail();
        $this->assertSame((int) $this->company->id, (int) $log->company_id);
        $this->assertSame($this->admin->id, (int) $log->user_id);
        $this->assertSame('2026-03', $log->descripcion);
        $this->assertSame(2, $log->cambios['mayorizados']);

        // El listado ya lo muestra cerrado, con todo mayorizado
        $marzo = collect($this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2026")->json('meses'))->firstWhere('mes', 3);
        $this->assertSame('cerrado', $marzo['estado']);
        $this->assertSame(['borrador' => 0, 'pendiente' => 0, 'mayorizado' => 2, 'total' => 2], $marzo['asientos']);
        $this->assertNotNull($marzo['cerrado_at']);
    }

    public function test_no_se_cierra_un_mes_con_borradores_y_la_respuesta_los_lista(): void
    {
        $borrador = $this->manual('Sin contabilizar', '2026-03-05', 'borrador', 10);
        $pendiente = $this->manual('Pendiente', '2026-03-06', 'pendiente', 20);

        $res = $this->cerrar(2026, 3)->assertStatus(422);
        $this->assertStringContainsString('2026-03', $res->json('message'));
        $this->assertStringContainsString('borrador', $res->json('message'));
        $this->assertCount(1, $res->json('bloqueos'));
        $this->assertSame(JournalEntry::find($borrador)->numero, $res->json('bloqueos.0.numero'));
        $this->assertSame('Sin contabilizar', $res->json('bloqueos.0.concepto'));
        $this->assertSame('borrador', $res->json('bloqueos.0.tipo'));

        // No se hizo nada: nada mayorizado, mes abierto
        $this->assertSame('pendiente', JournalEntry::find($pendiente)->estado);
        $this->assertSame('borrador', JournalEntry::find($borrador)->estado);
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));

        // Contabilizado el borrador, ya cierra
        $this->postJson("/api/journal-entries/{$borrador}/contabilizar", ['company_id' => $this->company->id])->assertOk();
        $this->cerrar(2026, 3)->assertOk()->assertJsonPath('mayorizados', 2);
    }

    public function test_no_se_cierra_un_mes_con_un_asiento_descuadrado(): void
    {
        $ok = $this->manual('Cuadrado', '2026-03-05', 'pendiente', 10);
        // Un asiento alterado por fuera: la cabecera dice 100 pero las líneas suman 90
        $malo = JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-009999', 'fecha' => '2026-03-08',
            'concepto' => 'Alterado', 'total_debe' => 100, 'total_haber' => 100, 'estado' => 'pendiente']);
        $malo->lines()->create(['account_id' => $this->cuenta('1.1.01'), 'debe' => 100, 'haber' => 0]);
        $malo->lines()->create(['account_id' => $this->cuenta('3.1.01'), 'debe' => 0, 'haber' => 90]);

        $res = $this->cerrar(2026, 3)->assertStatus(422);
        $this->assertCount(1, $res->json('bloqueos'));
        $this->assertSame('AS-009999', $res->json('bloqueos.0.numero'));
        $this->assertSame('descuadrado', $res->json('bloqueos.0.tipo'));
        $this->assertStringContainsString('debe 100.00, haber 90.00', $res->json('bloqueos.0.motivo'));
        $this->assertStringContainsString('descuadrado', $res->json('message'));
        $this->assertSame('pendiente', JournalEntry::find($ok)->estado);
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));

        $listado = collect($this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2026")->json('meses'))->firstWhere('mes', 3);
        $this->assertSame(1, $listado['descuadrados']);
        $this->assertFalse($listado['puede_cerrar']);
    }

    public function test_cerrar_un_mes_ya_cerrado_o_con_datos_invalidos_da_422(): void
    {
        $this->cerrar(2026, 3)->assertOk();
        $this->cerrar(2026, 3)->assertStatus(422)->assertJsonPath('message', 'El período 2026-03 ya está cerrado.');
        $this->cerrar(2026, 13)->assertStatus(422)->assertJsonValidationErrors('mes');
        $this->postJson('/api/accounting/periodos/cerrar', ['anio' => 2026, 'mes' => 4])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->assertSame(1, AccountingPeriod::count());
    }

    public function test_roles_el_contador_cierra_pero_solo_el_administrador_reabre(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => 'cajero', 'activo' => true]));
        $this->cerrar(2026, 3)->assertStatus(403);
        $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2026")->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['rol' => 'contador', 'activo' => true]));
        $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2026")->assertOk();
        $this->cerrar(2026, 3)->assertOk();
        $this->reabrir(2026, 3, 'Falta una factura')->assertStatus(403);
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'), 'El contador no pudo reabrir');

        Sanctum::actingAs($this->admin);
        $this->reabrir(2026, 3, 'Falta una factura')->assertOk();
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));
    }

    // ------------------------------------------------------------- reabrir

    public function test_reabrir_exige_motivo_queda_auditado_y_permite_registrar_otra_vez(): void
    {
        $id = $this->manual('Marzo', '2026-03-05', 'pendiente', 10);
        $this->cerrar(2026, 3)->assertOk();
        $this->postJson('/api/journal-entries', $this->cuerpoManual('Tarde', '2026-03-20', 5))
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-03'));

        // Sin motivo (o vacío): 422
        $this->postJson('/api/accounting/periodos/reabrir', ['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->reabrir(2026, 3, '   ')->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));

        $this->reabrir(2026, 3, 'Falta registrar el gasto de la luz')->assertOk()->assertJsonPath('periodo', '2026-03');
        $this->assertFalse(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));

        $fila = AccountingPeriod::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('abierto', $fila->estado);
        $this->assertSame('Falta registrar el gasto de la luz', $fila->motivo_reapertura);
        $this->assertSame($this->admin->id, (int) $fila->reabierto_por);
        $this->assertNotNull($fila->reabierto_at);

        $log = AuditLog::where('accion', 'reabrio_periodo')->firstOrFail();
        $this->assertSame('2026-03', $log->descripcion);
        $this->assertSame('Falta registrar el gasto de la luz', $log->cambios['motivo']);
        $this->assertSame($this->admin->id, (int) $log->user_id);

        // Se puede registrar otra vez; lo que ya estaba mayorizado sigue mayorizado
        $this->postJson('/api/journal-entries', $this->cuerpoManual('Tarde', '2026-03-20', 5))->assertCreated();
        $this->assertSame('mayorizado', JournalEntry::find($id)->estado);

        // Reabrir un mes que ya está abierto: 422. Y se puede volver a cerrar
        $this->reabrir(2026, 3, 'Otra vez')->assertStatus(422)->assertJsonPath('message', 'El período 2026-03 ya está abierto.');
        $this->cerrar(2026, 3)->assertOk()->assertJsonPath('mayorizados', 1);
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2026-03-10'));
        $this->assertSame(1, AccountingPeriod::count(), 'Sigue habiendo una sola fila por mes');
    }

    // ------------------------------------------------------------- la guarda central

    public function test_la_guarda_del_modelo_rechaza_crear_cambiar_y_borrar_en_un_mes_cerrado(): void
    {
        $abierto = $this->manual('Abierto', '2026-02-10', 'pendiente', 10);
        $this->cerrar(2026, 3)->assertOk();

        // Crear con el modelo (lo que hacen SimpleEntry, ContraAsiento y los Generate*JournalEntry)
        try {
            JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-000900', 'fecha' => '2026-03-15',
                'concepto' => 'x', 'total_debe' => 0, 'total_haber' => 0, 'estado' => 'pendiente']);
            $this->fail('Debió rechazar el asiento');
        } catch (ValidationException $e) {
            $this->assertSame($this->mensajeCerrado('2026-03'), $e->getMessage());
        }
        try {
            $this->travelTo(Carbon::parse('2026-03-20 10:00:00'));
            SimpleEntry::make($this->company->id, 'Por SimpleEntry', [
                ['codigo' => '1.1.01', 'nombre' => 'Caja', 'tipo' => 'activo', 'debe' => 5, 'haber' => 0],
                ['codigo' => '3.1.01', 'nombre' => 'Capital', 'tipo' => 'patrimonio', 'debe' => 0, 'haber' => 5],
            ]);
            $this->fail('SimpleEntry debió rechazar el asiento de hoy en un mes cerrado');
        } catch (ValidationException $e) {
            $this->assertSame($this->mensajeCerrado('2026-03'), $e->getMessage());
        }
        $this->travelBack();

        // Cambiar la fecha de un asiento abierto hacia el mes cerrado
        $e = JournalEntry::findOrFail($abierto);
        try {
            $e->update(['fecha' => '2026-03-01']);
            $this->fail('Debió rechazar el cambio de fecha');
        } catch (ValidationException $ex) {
            $this->assertSame($this->mensajeCerrado('2026-03'), $ex->getMessage());
        }
        $this->assertSame('2026-02-10', JournalEntry::find($abierto)->fecha->toDateString());

        // Un asiento que YA está en el mes cerrado no cambia ni sale de él
        $dentro = JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-000901', 'fecha' => '2026-04-01',
            'concepto' => 'Abril', 'total_debe' => 0, 'total_haber' => 0, 'estado' => 'pendiente']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 4, 'estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $this->admin->id]);
        foreach ([fn () => $dentro->update(['concepto' => 'Cambio']), fn () => $dentro->update(['fecha' => '2026-05-01']), fn () => $dentro->delete()] as $accion) {
            try {
                $accion();
                $this->fail('Debió rechazar la acción sobre un asiento de un mes cerrado');
            } catch (ValidationException $ex) {
                $this->assertSame($this->mensajeCerrado('2026-04'), $ex->getMessage());
            }
        }
        $this->assertSame('Abril', JournalEntry::find($dentro->id)->concepto);
        $this->assertSame('2026-04-01', JournalEntry::find($dentro->id)->fecha->toDateString());

        // Otra empresa con la misma fecha no se afecta
        $this->postJson('/api/journal-entries', ['company_id' => $this->otra->id, 'fecha' => '2026-03-15', 'concepto' => 'Otra empresa',
            'lines' => $this->lineasDe($this->otra->id, 10)])->assertCreated();
    }

    public function test_las_rutas_de_asientos_dan_422_con_el_mensaje_en_un_mes_cerrado(): void
    {
        $dentro = $this->manual('Marzo', '2026-03-05', 'pendiente', 10);
        $fuera = $this->manual('Febrero', '2026-02-05', 'pendiente', 10);
        $this->cerrar(2026, 3)->assertOk();
        $msg = $this->mensajeCerrado('2026-03');

        // Crear
        $this->postJson('/api/journal-entries', $this->cuerpoManual('Nuevo', '2026-03-10', 7))->assertStatus(422)->assertJsonPath('message', $msg);
        // Editar uno que está en el mes cerrado (el mensaje es el del período, no "desmayoriza")
        $this->putJson("/api/journal-entries/{$dentro}", $this->cuerpoManual('Cambio', '2026-03-05', 10))->assertStatus(422)->assertJsonPath('message', $msg);
        // Editar uno abierto cambiándole la fecha hacia el mes cerrado
        $this->putJson("/api/journal-entries/{$fuera}", $this->cuerpoManual('Febrero', '2026-03-20', 10))->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame('2026-02-05', JournalEntry::find($fuera)->fecha->toDateString());
        $this->assertSame(10.0, (float) JournalEntry::find($fuera)->lines()->sum('debe'), 'Las líneas quedaron como estaban');
        // Desmayorizar
        $this->postJson("/api/journal-entries/{$dentro}/desmayorizar", ['company_id' => $this->company->id])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame('mayorizado', JournalEntry::find($dentro)->estado);
        // Eliminar: el asiento manual no mayorizado de un mes que se cerró por otro camino (aquí, una fila de período puesta a mano)
        $suelto = $this->manual('Suelto', '2026-06-05', 'pendiente', 3);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 6, 'estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $this->admin->id]);
        $this->deleteJson("/api/journal-entries/{$suelto}", ['company_id' => $this->company->id])->assertStatus(422)
            ->assertJsonPath('message', $this->mensajeCerrado('2026-06'));
        $this->assertNotNull(JournalEntry::find($suelto));
        // Contabilizar un borrador que quedó en un mes cerrado también se rechaza (aquí se inserta directo, como si viniera de antes)
        $id = \Illuminate\Support\Facades\DB::table('journal_entries')->insertGetId(['company_id' => $this->company->id, 'numero' => 'AS-000950',
            'fecha' => '2026-03-12', 'concepto' => 'Borrador viejo', 'total_debe' => 4, 'total_haber' => 4, 'estado' => 'borrador',
            'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $id, 'account_id' => $this->cuenta('1.1.01'), 'debe' => 4, 'haber' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $id, 'account_id' => $this->cuenta('3.1.01'), 'debe' => 0, 'haber' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->postJson("/api/journal-entries/{$id}/contabilizar", ['company_id' => $this->company->id])->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertSame('borrador', JournalEntry::find($id)->estado);
    }

    public function test_mayorizar_omite_los_asientos_de_un_mes_cerrado(): void
    {
        $e = JournalEntry::create(['company_id' => $this->company->id, 'numero' => 'AS-000960', 'fecha' => '2026-07-10', 'concepto' => 'Julio',
            'total_debe' => 10, 'total_haber' => 10, 'estado' => 'pendiente']);
        $e->lines()->create(['account_id' => $this->cuenta('1.1.01'), 'debe' => 10, 'haber' => 0]);
        $e->lines()->create(['account_id' => $this->cuenta('3.1.01'), 'debe' => 0, 'haber' => 10]);
        AccountingPeriod::create(['company_id' => $this->company->id, 'anio' => 2026, 'mes' => 7, 'estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $this->admin->id]);

        $this->postJson('/api/journal-entries/mayorizar', ['company_id' => $this->company->id])->assertOk()
            ->assertJsonPath('mayorizados', 0)->assertJsonPath('omitidos.0.numero', 'AS-000960')
            ->assertJsonPath('omitidos.0.motivo', $this->mensajeCerrado('2026-07'));
        $this->assertSame('pendiente', $e->fresh()->estado);
    }

    // ------------------------------------------------------------- documentos en un mes cerrado

    public function test_emitir_una_factura_en_un_mes_cerrado_da_422_y_no_deja_nada_a_medias(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $this->cerrar(2026, 9)->assertOk();
        $antes = $this->fotoDeDatos();

        $this->postJson('/api/invoices', $this->cuerpoFactura([['MERC-1', 2, 10]], 'credito'))
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));

        $this->assertSame($antes, $this->fotoDeDatos(), 'Ni factura, ni kárdex, ni asiento, ni secuencial: todo volvió atrás');
        $this->assertSame(0, Invoice::count());
        $this->assertEquals(10, Product::where('codigo', 'MERC-1')->value('stock'));

        // Un mes después (abierto) sí se factura y el asiento sale con la fecha de ese día
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson('/api/invoices', $this->cuerpoFactura([['MERC-1', 2, 10]], 'credito'))->assertCreated();
        $this->assertSame('2026-10-14', JournalEntry::where('concepto', 'like', 'Venta factura%')->firstOrFail()->fecha->toDateString());
    }

    public function test_emitir_una_factura_en_un_mes_cerrado_no_firma_ni_envia_nada_al_sri(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $this->cerrar(2026, 9)->assertOk();

        // El comprobante se firma y se envía DENTRO de la transacción, antes del asiento: si el mes está cerrado
        // hay que rechazar ANTES, porque lo que el SRI ya recibió no se puede deshacer con un rollback.
        $this->mock(EmitirSriDocument::class)->shouldNotReceive('execute');

        $this->postJson('/api/invoices', $this->cuerpoFactura([['MERC-1', 2, 10]], 'credito'))
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));

        $this->assertSame(0, Invoice::count());
    }

    public function test_registrar_una_compra_con_fecha_en_un_mes_cerrado_da_422_y_vuelve_todo_atras(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->cerrar(2026, 9)->assertOk();
        $antes = $this->fotoDeDatos();

        $this->postJson('/api/purchases', $this->cuerpoCompra('001-001-000000002', '2026-09-12', 5))
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));

        $this->assertSame($antes, $this->fotoDeDatos());
        $this->assertSame(1, Purchase::count());
        $this->assertEquals(10, Product::where('codigo', 'MERC-1')->value('stock'));

        // La misma compra con fecha de un mes abierto sí entra
        $this->postJson('/api/purchases', $this->cuerpoCompra('001-001-000000002', '2026-10-02', 5))->assertCreated();
        $this->assertEquals(15, Product::where('codigo', 'MERC-1')->value('stock'));
    }

    public function test_un_cobro_en_un_mes_cerrado_da_422_y_no_baja_el_saldo_pero_en_un_mes_abierto_si(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');
        $this->cerrar(2026, 9)->assertOk();
        $saldo = (float) $factura->fresh()->saldo_pendiente;
        $this->assertGreaterThan(0, $saldo);
        $antes = $this->fotoDeDatos();

        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 5]]])
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));
        $this->assertSame($antes, $this->fotoDeDatos());
        $this->assertSame($saldo, (float) $factura->fresh()->saldo_pendiente);
        $this->assertSame(0, \App\Models\PaymentSplit::count());

        // En octubre (abierto) se cobra la factura de septiembre; el asiento del cobro es de octubre
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson("/api/receivables/{$factura->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 5]]])->assertOk();
        $this->assertEqualsWithDelta($saldo - 5, (float) $factura->fresh()->saldo_pendiente, 0.001);
        $this->assertSame('2026-10-14', JournalEntry::where('concepto', 'Cobro factura '.$factura->numero)->firstOrFail()->fecha->toDateString());
    }

    public function test_pagar_una_compra_en_un_mes_cerrado_da_422(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $compra = $this->comprar('001-001-000000001', '2026-09-05', 10);
        $this->cerrar(2026, 9)->assertOk();
        $saldo = (float) $compra->fresh()->saldo_pendiente;
        $antes = $this->fotoDeDatos();

        $this->postJson("/api/payables/{$compra->id}/pay", ['pagos' => [['tipo' => 'efectivo', 'valor' => 10]]])
            ->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));
        $this->assertSame($antes, $this->fotoDeDatos());
        $this->assertSame($saldo, (float) $compra->fresh()->saldo_pendiente);
    }

    public function test_una_nota_de_credito_en_un_mes_cerrado_da_422_y_no_toca_la_factura(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $factura = $this->facturar([['MERC-1', 2, 10]], 'credito');
        $this->cerrar(2026, 9)->assertOk();
        $saldo = (float) $factura->fresh()->saldo_pendiente;
        $antes = $this->fotoDeDatos();

        $cuerpo = ['company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'invoice_id' => $factura->id,
            'tipo' => 'interna', 'motivo' => 'Descuento comercial', 'importe_total' => 5];
        $this->postJson('/api/credit-notes', $cuerpo)->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));

        $this->assertSame($antes, $this->fotoDeDatos());
        $this->assertSame(0, CreditNote::count());
        $this->assertSame($saldo, (float) $factura->fresh()->saldo_pendiente);

        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson('/api/credit-notes', $cuerpo)->assertCreated();
        $this->assertSame(1, CreditNote::count());
    }

    public function test_anular_una_factura_cuyo_contra_asiento_cae_en_un_mes_cerrado_da_422_y_en_un_mes_abierto_se_puede(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $a = $this->facturar([['MERC-1', 2, 10]], 'credito');
        $b = $this->facturar([['MERC-1', 3, 10]], 'credito');
        $this->cerrar(2026, 9)->assertOk();
        $stockAntes = (float) Product::where('codigo', 'MERC-1')->value('stock');

        // (1) Se anula en octubre (abierto): el contra-asiento lleva la fecha de la ANULACIÓN, no la de la factura cerrada
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson("/api/invoices/{$a->id}/anular")->assertOk();
        $this->assertSame('anulado', $a->fresh()->estado);
        $reverso = JournalEntry::where('origen_type', $a->getMorphClass())->where('origen_id', $a->id)->where('concepto', 'like', 'Reversión%')->firstOrFail();
        $this->assertSame('2026-10-14', $reverso->fecha->toDateString());
        $original = JournalEntry::where('origen_type', $a->getMorphClass())->where('origen_id', $a->id)->where('concepto', 'not like', 'Reversión%')->firstOrFail();
        $this->assertSame('2026-09-10', $original->fecha->toDateString(), 'El asiento original queda en septiembre');
        $this->assertEquals($stockAntes + 2, Product::where('codigo', 'MERC-1')->value('stock'), 'Se devolvió el stock');

        // (2) Octubre se cierra y se intenta anular la otra en octubre: el contra-asiento caería en un mes cerrado
        $this->cerrar(2026, 10)->assertOk();
        $antes = $this->fotoDeDatos();
        $this->postJson("/api/invoices/{$b->id}/anular")->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-10'));
        $this->assertSame($antes, $this->fotoDeDatos(), 'Sin contra-asiento, sin stock devuelto');
        $this->assertSame('emitida', $b->fresh()->estado);
        $this->assertSame(0, AuditLog::where('accion', 'anulo')->where('modelo_id', $b->id)->count());

        // (3) El administrador reabre octubre y entonces sí se anula
        $this->reabrir(2026, 10, 'Anular la factura de septiembre')->assertOk();
        $this->postJson("/api/invoices/{$b->id}/anular")->assertOk();
        $this->assertSame('anulado', $b->fresh()->estado);
    }

    public function test_un_ajuste_de_inventario_en_un_mes_cerrado_da_422_y_no_mueve_el_kardex(): void
    {
        $this->comprar('001-001-000000001', '2026-01-10', 10);
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));
        $this->cerrar(2026, 9)->assertOk();
        $producto = Product::where('codigo', 'MERC-1')->firstOrFail();
        $antes = $this->fotoDeDatos();
        $cuerpo = ['company_id' => $this->company->id, 'product_id' => $producto->id, 'warehouse_id' => Warehouse::first()->id,
            'stock_fisico' => 8, 'motivo' => 'Merma'];

        $this->postJson('/api/inventory/ajuste', $cuerpo)->assertStatus(422)->assertJsonPath('message', $this->mensajeCerrado('2026-09'));
        $this->assertSame($antes, $this->fotoDeDatos());
        $this->assertEquals(10, $producto->fresh()->stock);

        $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson('/api/inventory/ajuste', $cuerpo)->assertOk();
        $this->assertEquals(8, $producto->fresh()->stock);
    }

    // ------------------------------------------------------------- cierre del ejercicio

    public function test_el_ejercicio_solo_se_cierra_con_los_doce_meses_cerrados(): void
    {
        $this->asientosDelEjercicio();
        foreach (range(1, 10) as $mes) {
            $this->cerrar(2025, $mes)->assertOk();
        }

        $res = $this->cerrarEjercicio(2025)->assertStatus(422);
        $this->assertSame([11, 12], $res->json('meses_abiertos'));
        $this->assertStringContainsString('Noviembre', $res->json('message'));
        $this->assertStringContainsString('Diciembre', $res->json('message'));
        $this->assertSame(0, JournalEntry::where('es_cierre', true)->count());

        $this->cerrar(2025, 11)->assertOk();
        $this->cerrar(2025, 12)->assertOk();
        $listado = $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2025")->assertOk();
        $this->assertTrue($listado->json('ejercicio.puede_cerrar'));
        $this->assertFalse($listado->json('ejercicio.cerrado'));
        $this->cerrarEjercicio(2025)->assertOk();
    }

    public function test_el_cierre_del_ejercicio_deja_un_solo_asiento_mayorizado_el_31_de_diciembre_que_cuadra(): void
    {
        $this->asientosDelEjercicio();
        $this->cerrarLosDoceMeses(2025);
        $this->assertSame(5, $this->conteoMayorizados(), 'Al cerrar los meses se mayorizó todo');

        $res = $this->cerrarEjercicio(2025)->assertOk()->assertJsonPath('anio', 2025);
        $this->assertEquals(650, $res->json('resultado'));
        $this->assertEquals(1000, $res->json('ingresos'));
        $this->assertEquals(350, $res->json('gastos'));

        $cierre = JournalEntry::where('company_id', $this->company->id)->where('es_cierre', true)->get();
        $this->assertCount(1, $cierre);
        $c = $cierre->first();
        $this->assertSame($res->json('asiento.numero'), $c->numero);
        $this->assertSame('2025-12-31', $c->fecha->toDateString());
        $this->assertSame('mayorizado', $c->estado);
        $this->assertTrue($c->fue_mayorizado);
        $this->assertFalse($c->manual);
        $this->assertSame('Cierre del ejercicio 2025', $c->concepto);
        $this->assertEquals(1000, $c->total_debe);
        $this->assertEquals(1000, $c->total_haber);
        $this->assertSame(
            [['3.1.02', 0.0, 650.0], ['4.1.01', 1000.0, 0.0], ['5.1.01', 0.0, 200.0], ['5.1.02', 0.0, 150.0]],
            $c->lines()->with('account')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->sortBy(0)->values()->all(),
            'Debe los ingresos / Haber los gastos / el resultado del ejercicio por la diferencia'
        );
        $this->assertSame('Resultado del ejercicio', Account::where('company_id', $this->company->id)->where('codigo', '3.1.02')->value('nombre'));
        $this->assertSame('patrimonio', Account::where('company_id', $this->company->id)->where('codigo', '3.1.02')->value('tipo'));

        // El mayor: ingresos y gastos quedan en cero y el resultado pasó a patrimonio
        $mayor = collect($this->getJson("/api/ledger?company_id={$this->company->id}")->assertOk()->json())->keyBy('codigo');
        $this->assertEquals(0, $mayor['4.1.01']['saldo']);
        $this->assertEquals(0, $mayor['5.1.01']['saldo']);
        $this->assertEquals(0, $mayor['5.1.02']['saldo']);
        $this->assertEquals(650, $mayor['3.1.02']['saldo']);

        // El diciembre cerrado no impidió crear el asiento de cierre, pero nadie más puede tocarlo
        $this->postJson("/api/journal-entries/{$c->id}/desmayorizar", ['company_id' => $this->company->id])->assertStatus(422);
        $this->putJson("/api/journal-entries/{$c->id}", $this->cuerpoManual('Cambio', '2025-12-31', 650))->assertStatus(422);
        $this->deleteJson("/api/journal-entries/{$c->id}", ['company_id' => $this->company->id])->assertStatus(422);

        // El listado lo muestra
        $listado = $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=2025")->assertOk();
        $this->assertTrue($listado->json('ejercicio.cerrado'));
        $this->assertSame($c->numero, $listado->json('ejercicio.asiento.numero'));
        $this->assertFalse($listado->json('ejercicio.puede_cerrar'));

        $log = AuditLog::where('accion', 'cerro_ejercicio')->firstOrFail();
        $this->assertSame('2025', $log->descripcion);
        $this->assertEquals(650, $log->cambios['resultado']);
    }

    public function test_cerrar_el_ejercicio_dos_veces_o_reabrir_un_mes_de_un_ejercicio_cerrado_da_422(): void
    {
        $this->asientosDelEjercicio();
        $this->cerrarLosDoceMeses(2025);
        $this->cerrarEjercicio(2025)->assertOk();

        $this->cerrarEjercicio(2025)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'El ejercicio 2025 ya está cerrado'));
        $this->assertSame(1, JournalEntry::where('es_cierre', true)->count());

        $this->reabrir(2025, 12, 'Quiero corregir')->assertStatus(422);
        $this->assertTrue(AccountingPeriod::estaCerrado($this->company->id, '2025-12-31'));
    }

    public function test_el_cierre_con_perdida_debita_el_resultado_del_ejercicio(): void
    {
        $this->crear('Aporte', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->crear('Gasto', '2025-04-15', [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->cerrarLosDoceMeses(2025);

        $this->cerrarEjercicio(2025)->assertOk()->assertJsonPath('resultado', -150);
        $c = JournalEntry::where('es_cierre', true)->firstOrFail();
        $this->assertSame(
            [['3.1.02', 150.0, 0.0], ['5.1.02', 0.0, 150.0]],
            $c->lines()->with('account')->get()->map(fn ($l) => [$l->account->codigo, (float) $l->debe, (float) $l->haber])->sortBy(0)->values()->all()
        );
        $this->assertEquals(150, $c->total_debe);
    }

    public function test_un_ejercicio_sin_ingresos_ni_gastos_no_tiene_nada_que_cerrar(): void
    {
        $this->crear('Aporte', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->cerrarLosDoceMeses(2025);

        $this->cerrarEjercicio(2025)->assertStatus(422)
            ->assertJsonPath('message', 'El ejercicio 2025 no tiene ingresos ni gastos que cerrar.');
        $this->assertSame(0, JournalEntry::where('es_cierre', true)->count());
    }

    public function test_el_cierre_no_rompe_el_chequeo_contable_estricto(): void
    {
        // Sin cuentas por cobrar ni por pagar a mano (el chequeo estricto las compara con las facturas): todo en caja
        $this->crear('Aporte de capital', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->crear('Venta de contado', '2025-02-05', [['1.1.01', 600, 0], ['4.1.01', 0, 600]]);
        $this->crear('Gasto general', '2025-04-15', [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('CUADRA: 1450 = 1450')->assertExitCode(0);

        $this->cerrarLosDoceMeses(2025);
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('Los 12 períodos cerrados no tienen asientos pendientes ni en borrador')->assertExitCode(0);
        $this->cerrarEjercicio(2025)->assertOk()->assertJsonPath('resultado', 450);

        // El asiento de cierre cuadra, la ecuación sigue cuadrando (el resultado ahora está en 3.1.02) y el chequeo estricto sigue en cero
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])
            ->expectsOutputToContain('CUADRA: 1450 = 1450')->assertExitCode(0);
    }

    public function test_el_chequeo_avisa_de_un_asiento_sin_mayorizar_dentro_de_un_mes_cerrado(): void
    {
        $this->crear('Aporte de capital', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->cerrar(2025, 1)->assertOk();
        // Alguien (o un script) dejó un asiento pendiente dentro del mes que se cerró
        \Illuminate\Support\Facades\DB::table('journal_entries')->insert(['company_id' => $this->company->id, 'numero' => 'AS-000800', 'fecha' => '2025-01-20',
            'concepto' => 'Colado', 'total_debe' => 0, 'total_haber' => 0, 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('contable:chequeo', ['--company' => $this->company->id])
            ->expectsOutputToContain('1 asientos sin mayorizar dentro de períodos cerrados')
            ->expectsOutputToContain('AS-000800 (2025-01-20) pendiente')
            ->assertExitCode(1);
    }

    public function test_el_resultado_del_ejercicio_entra_en_el_plan_central_y_se_siembra(): void
    {
        $this->assertSame('3.1.02', Cuentas::codigo('resultado_ejercicio'));
        $this->assertSame('Resultado del ejercicio', Cuentas::get('resultado_ejercicio')['nombre']);
        $this->assertSame('patrimonio', Cuentas::get('resultado_ejercicio')['tipo']);
        $codigos = array_column(config('cuentas.cuentas'), 'codigo');
        $this->assertSame($codigos, array_values(array_unique($codigos)), 'Un código = un concepto');
        $this->assertTrue(Account::where('company_id', $this->company->id)->where('codigo', '3.1.02')->exists());
    }

    public function test_los_periodos_de_una_empresa_no_afectan_a_otra(): void
    {
        $this->cerrar(2026, 3)->assertOk();
        $this->postJson('/api/journal-entries', ['company_id' => $this->otra->id, 'fecha' => '2026-03-15', 'concepto' => 'Otra',
            'lines' => $this->lineasDe($this->otra->id, 10)])->assertCreated();
        $listado = collect($this->getJson("/api/accounting/periodos?company_id={$this->otra->id}&anio=2026")->assertOk()->json('meses'))->firstWhere('mes', 3);
        $this->assertSame('abierto', $listado['estado']);
        $this->assertSame(1, $listado['asientos']['total']);
    }

    // ------------------------------------------------------------- helpers

    private function cerrar(int $anio, int $mes, ?int $companyId = null)
    {
        return $this->postJson('/api/accounting/periodos/cerrar', ['company_id' => $companyId ?? $this->company->id, 'anio' => $anio, 'mes' => $mes]);
    }

    private function reabrir(int $anio, int $mes, ?string $motivo)
    {
        return $this->postJson('/api/accounting/periodos/reabrir', ['company_id' => $this->company->id, 'anio' => $anio, 'mes' => $mes, 'motivo' => $motivo]);
    }

    private function cerrarEjercicio(int $anio)
    {
        return $this->postJson('/api/accounting/cierre-ejercicio', ['company_id' => $this->company->id, 'anio' => $anio]);
    }

    private function cerrarLosDoceMeses(int $anio): void
    {
        foreach (range(1, 12) as $mes) {
            $this->cerrar($anio, $mes)->assertOk();
        }
    }

    private function conteoMayorizados(): int
    {
        return JournalEntry::where('company_id', $this->company->id)->where('estado', 'mayorizado')->count();
    }

    private function cuenta(string $codigo, ?int $companyId = null): int
    {
        return Account::where('company_id', $companyId ?? $this->company->id)->where('codigo', $codigo)->firstOrFail()->id;
    }

    /** Líneas de un asiento simple Caja / Capital por $valor. */
    private function lineasDe(int $companyId, float $valor): array
    {
        return [
            ['account_id' => $this->cuenta('1.1.01', $companyId), 'debe' => $valor, 'haber' => 0],
            ['account_id' => $this->cuenta('3.1.01', $companyId), 'debe' => 0, 'haber' => $valor],
        ];
    }

    private function cuerpoManual(string $concepto, string $fecha, float $valor, ?string $estado = null): array
    {
        $c = ['company_id' => $this->company->id, 'fecha' => $fecha, 'concepto' => $concepto, 'lines' => $this->lineasDe($this->company->id, $valor)];
        if ($estado) {
            $c['estado'] = $estado;
        }

        return $c;
    }

    /** Crea un asiento manual simple Caja / Capital; devuelve su id. */
    private function manual(string $concepto, string $fecha, string $estado, float $valor): int
    {
        return $this->postJson('/api/journal-entries', $this->cuerpoManual($concepto, $fecha, $valor, $estado))->assertCreated()->json('id');
    }

    /** @param  array<int,array{0:string,1:float|int,2:float|int}>  $filas */
    private function crear(string $concepto, string $fecha, array $filas): int
    {
        $lineas = array_map(fn ($f) => ['account_id' => $this->cuenta($f[0]), 'debe' => $f[1], 'haber' => $f[2]], $filas);

        return $this->postJson('/api/journal-entries', ['company_id' => $this->company->id, 'fecha' => $fecha, 'concepto' => $concepto, 'lines' => $lineas])
            ->assertCreated()->json('id');
    }

    /** Ejercicio 2025: aporte 1000, ventas 600 + 400, gasto 150, compra a crédito 200 (ingresos 1000, gastos 350, resultado 650). */
    private function asientosDelEjercicio(): void
    {
        $this->crear('Aporte de capital', '2025-01-10', [['1.1.01', 1000, 0], ['3.1.01', 0, 1000]]);
        $this->crear('Venta de contado', '2025-02-05', [['1.1.01', 600, 0], ['4.1.01', 0, 600]]);
        $this->crear('Venta a crédito', '2025-03-10', [['1.1.03', 400, 0], ['4.1.01', 0, 400]]);
        $this->crear('Gasto general', '2025-04-15', [['5.1.02', 150, 0], ['1.1.01', 0, 150]]);
        $this->crear('Compra a crédito', '2025-05-20', [['5.1.01', 200, 0], ['2.1.01', 0, 200]]);
    }

    private function comprar(string $numero, string $fecha, int $cantidad): Purchase
    {
        $this->postJson('/api/purchases', $this->cuerpoCompra($numero, $fecha, $cantidad))->assertCreated();

        return Purchase::where('company_id', $this->company->id)->where('numero', $numero)->firstOrFail();
    }

    private function cuerpoCompra(string $numero, string $fecha, int $cantidad): array
    {
        return [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero, 'fecha_emision' => $fecha,
            'items' => [['codigo_principal' => 'MERC-1', 'descripcion' => 'MERC-1', 'cantidad' => $cantidad, 'precio_unitario' => 5, 'tarifa' => 15]],
        ];
    }

    private function cuerpoFactura(array $lineas, string $formaPago): array
    {
        return [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => $formaPago,
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ];
    }

    private function facturar(array $lineas, string $formaPago): Invoice
    {
        $this->postJson('/api/invoices', $this->cuerpoFactura($lineas, $formaPago))->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Todo lo que un documento a medias dejaría atrás: si no cambia entre dos fotos, la operación volvió atrás entera. */
    private function fotoDeDatos(): array
    {
        return [
            'asientos' => JournalEntry::count(),
            'lineas' => \App\Models\JournalEntryLine::count(),
            'facturas' => Invoice::count(),
            'compras' => Purchase::count(),
            'kardex' => InventoryMovement::count(),
            'stock' => (float) Product::sum('stock'),
            'secuencial' => (int) Company::whereKey($this->company->id)->value('secuencial'),
            'notas' => CreditNote::count(),
            'cobros' => \App\Models\PaymentSplit::count(),
            'saldos' => round((float) Invoice::sum('saldo_pendiente') + (float) Purchase::sum('saldo_pendiente'), 2),
            'estados' => Invoice::orderBy('id')->pluck('estado')->all(),
        ];
    }
}
