<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\ArchivoBanco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T5.3 / C4: conciliación bancaria con el CSV del banco. Leer el archivo y proponer las parejas no marca ni asienta nada;
 * aceptar solo marca como conciliados los movimientos elegidos. Se prueban dos formas de archivo (columnas Débito/Crédito
 * con punto y coma y Latin-1; Valor + Tipo con comas) y las otras que reconoce el lector.
 */
class ConciliacionArchivoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Bank $banco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Conciliacion SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        $this->banco = Bank::create(['company_id' => $this->company->id, 'nombre' => 'Banco Test', 'cuenta_contable' => '1.1.02']);
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_forma_a_columnas_debito_y_credito_con_punto_y_coma_en_latin1(): void
    {
        $cheque = $this->mov('debito', 115, '2026-10-02', 'Pago a proveedor', '004512');
        $deposito = $this->mov('credito', 500, '2026-10-03', 'Depósito de caja');
        $sinBanco = $this->mov('credito', 77.70, '2026-10-03', 'Cobro que el banco no trae');
        $this->mov('credito', 1250, '2026-10-20', 'Fuera del archivo');          // cae fuera del período del archivo
        $this->mov('credito', 500, '2026-10-03', 'Ya conciliado antes', null, true);
        $this->mov('debito', 50, '2026-10-02', 'Cheque posfechado', '9001', false, '2099-01-01');

        $csv = "Fecha;Descripción;Documento;Débito;Crédito;Saldo\n"
            ."02/10/2026;PAGO CHEQUE;0004512;115,00;;885,00\n"
            ."03/10/2026;DEPOSITO EFECTIVO;778;;500,00;1385,00\n"
            ."04/10/2026;NOTA DE CRÉDITO INTERÉS;;;12,34;1397,34\n"
            ."Total;;;115,00;512,34;\n";
        $r = $this->importar(mb_convert_encoding($csv, 'ISO-8859-1', 'UTF-8'))->assertOk();

        $this->assertSame('columnas débito y crédito', $r->json('formato'));
        $this->assertEquals(['lineas' => 3, 'con_pareja' => 2, 'sin_pareja_sistema' => 1, 'sin_pareja_banco' => 1, 'ignoradas' => 1, 'fuera_del_periodo' => 0], $r->json('resumen'));
        $this->assertSame(['desde' => '2026-10-02', 'hasta' => '2026-10-04'], $r->json('periodo'));

        $lineas = collect($r->json('lineas'))->keyBy('descripcion');
        $this->assertSame($cheque->id, $lineas['PAGO CHEQUE']['movimiento']['id']);
        $this->assertSame('documento', $lineas['PAGO CHEQUE']['criterio']);
        $this->assertSame('debito', $lineas['PAGO CHEQUE']['tipo']);
        $this->assertSame($deposito->id, $lineas['DEPOSITO EFECTIVO']['movimiento']['id']);
        $this->assertSame('fecha y monto', $lineas['DEPOSITO EFECTIVO']['criterio']);
        $this->assertNull($lineas['NOTA DE CRÉDITO INTERÉS']['movimiento']);
        $this->assertSame('credito', $lineas['NOTA DE CRÉDITO INTERÉS']['tipo']);
        $this->assertEquals(12.34, $lineas['NOTA DE CRÉDITO INTERÉS']['monto']);
        $this->assertSame([$sinBanco->id], collect($r->json('sin_pareja_banco'))->pluck('id')->all());
        $this->assertSame(5, $r->json('ignoradas.0.fila'), 'La fila de totales del banco se ignora y dice cuál era');
        $this->assertStringContainsString('fecha', $r->json('ignoradas.0.motivo'));

        // Proponer no marca ni asienta nada
        $this->assertSame(0, BankMovement::where('conciliado', true)->whereIn('id', [$cheque->id, $deposito->id])->count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_forma_b_valor_y_tipo_con_comas_y_miles(): void
    {
        $transferencia = $this->mov('credito', 1250, '2026-10-05', 'Transferencia de cliente', 'TRF-9');
        $comision = $this->mov('debito', 3.50, '2026-10-06', 'Comisión');

        $csv = "Fecha,Detalle,Referencia,Valor,Tipo\n"
            ."05-10-2026,TRANSFERENCIA RECIBIDA,TRF-9,\"1,250.00\",C\n"
            ."06-10-2026,COMISION,,\$3.50,D\n";
        $r = $this->importar($csv)->assertOk();

        $this->assertSame('valor y tipo', $r->json('formato'));
        $this->assertEquals(['lineas' => 2, 'con_pareja' => 2, 'sin_pareja_sistema' => 0, 'sin_pareja_banco' => 0, 'ignoradas' => 0, 'fuera_del_periodo' => 0], $r->json('resumen'));
        $pares = collect($r->json('lineas'))->mapWithKeys(fn ($l) => [$l['descripcion'] => $l['movimiento']['id']]);
        $this->assertSame($transferencia->id, $pares['TRANSFERENCIA RECIBIDA']);
        $this->assertSame($comision->id, $pares['COMISION']);
        $this->assertEquals(1250.0, collect($r->json('lineas'))->firstWhere('descripcion', 'TRANSFERENCIA RECIBIDA')['monto']);
        $this->assertSame(2, collect($r->json('lineas'))->where('movimiento', '!=', null)->count());
    }

    public function test_aceptar_las_parejas_solo_marca_conciliado_y_no_asienta_nada(): void
    {
        $a = $this->mov('debito', 115, '2026-10-02', 'Pago', '004512');
        $b = $this->mov('credito', 500, '2026-10-03', 'Depósito');
        $c = $this->mov('credito', 77.70, '2026-10-03', 'Otro');
        $posfechado = $this->mov('debito', 50, '2026-10-02', 'Posfechado', '9001', false, '2099-01-01');

        $this->postJson('/api/bank-movements/importar/aplicar', [
            'company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'ids' => [$a->id, $b->id, $posfechado->id],
        ])->assertOk()->assertJsonPath('conciliados', 2)->assertJsonPath('omitidos', 1);

        $this->assertTrue((bool) $a->fresh()->conciliado);
        $this->assertTrue((bool) $b->fresh()->conciliado);
        $this->assertFalse((bool) $c->fresh()->conciliado, 'Los que no se aceptan quedan igual');
        $this->assertFalse((bool) $posfechado->fresh()->conciliado, 'Un cheque posfechado no se concilia antes de su fecha');
        $this->assertSame(0, JournalEntry::count(), 'Conciliar no asienta nada');

        $lista = $this->getJson('/api/bank-movements?company_id='.$this->company->id.'&bank_id='.$this->banco->id)->assertOk();
        $this->assertEquals(round(-115 + 500, 2), $lista->json('saldo_conciliado'));

        // Sin movimientos que aceptar, o con un banco ajeno
        $this->postJson('/api/bank-movements/importar/aplicar', ['company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'ids' => []])->assertStatus(422);
        $otra = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Otra SA', 'dir_matriz' => 'Av. 1', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $ajeno = Bank::create(['company_id' => $otra->id, 'nombre' => 'Banco Ajeno']);
        $this->postJson('/api/bank-movements/importar/aplicar', ['company_id' => $this->company->id, 'bank_id' => $ajeno->id, 'ids' => [$c->id]])
            ->assertStatus(422)->assertJsonValidationErrors('bank_id');
        $this->assertFalse((bool) $c->fresh()->conciliado);
    }

    public function test_un_cheque_cobrado_dias_despues_se_empareja_por_su_numero_pero_no_por_fecha_sola(): void
    {
        $cheque = $this->mov('debito', 200, '2026-10-02', 'Cheque a proveedor', '000778');
        $this->mov('debito', 200, '2026-10-02', 'Otro egreso igual', null);

        // Mismo monto, 7 días después: solo el número de documento lo une; el egreso sin número queda sin pareja
        $csv = "Fecha;Descripción;Referencia;Débito;Crédito\n09/10/2026;CHEQUE PAGADO;778;200,00;\n";
        $r = $this->importar($csv)->assertOk();
        $this->assertSame($cheque->id, $r->json('lineas.0.movimiento.id'));
        $this->assertSame('documento', $r->json('lineas.0.criterio'));

        // Sin número y a 7 días no hay pareja
        $csv = "Fecha;Descripción;Referencia;Débito;Crédito\n09/10/2026;SIN NUMERO;;200,00;\n";
        $this->assertNull($this->importar($csv)->assertOk()->json('lineas.0.movimiento'));
    }

    public function test_dos_lineas_iguales_toman_dos_movimientos_distintos(): void
    {
        $uno = $this->mov('credito', 40, '2026-10-02', 'Cobro 1');
        $dos = $this->mov('credito', 40, '2026-10-03', 'Cobro 2');

        $csv = "Fecha;Detalle;Documento;Débito;Crédito\n02/10/2026;ABONO A;;;40,00\n03/10/2026;ABONO B;;;40,00\n04/10/2026;ABONO C;;;40,00\n";
        $r = $this->importar($csv)->assertOk();

        $ids = collect($r->json('lineas'))->pluck('movimiento.id')->all();
        $this->assertSame([$uno->id, $dos->id, null], $ids);
        $this->assertSame(1, $r->json('resumen.sin_pareja_sistema'));
    }

    public function test_el_periodo_filtra_las_lineas_del_archivo(): void
    {
        $this->mov('credito', 10, '2026-10-02', 'Dentro');
        $csv = "Fecha;Detalle;Débito;Crédito\n02/10/2026;DENTRO;;10,00\n20/10/2026;FUERA;;99,00\n";

        $r = $this->importar($csv, ['desde' => '2026-10-01', 'hasta' => '2026-10-10'])->assertOk();

        $this->assertSame(1, $r->json('resumen.lineas'));
        $this->assertSame(1, $r->json('resumen.fuera_del_periodo'));
        $this->assertSame(1, $r->json('resumen.con_pareja'));
        $this->importar($csv, ['desde' => '2026-11-01', 'hasta' => '2026-11-30'])->assertStatus(422)->assertJsonValidationErrors('csv');
    }

    public function test_formas_que_reconoce_el_lector(): void
    {
        // Valor con signo (los débitos en negativo), fechas ISO y encabezado con tildes y mayúsculas
        $a = ArchivoBanco::leer("FECHA MOVIMIENTO;DESCRIPCIÓN;N° DOCUMENTO;MONTO\n2026-10-05;Pago luz;112233;-45,60\n2026-10-06;Depósito;998;1.200,00\n");
        $this->assertSame('valor con signo', $a['formato']);
        $this->assertSame(['debito', 45.60, '2026-10-05', '112233'], [$a['lineas'][0]['tipo'], $a['lineas'][0]['monto'], $a['lineas'][0]['fecha'], $a['lineas'][0]['documento']]);
        $this->assertSame(['credito', 1200.0], [$a['lineas'][1]['tipo'], $a['lineas'][1]['monto']]);

        // Sin encabezado: fecha, detalle, valor (el archivo de la primera versión)
        $b = ArchivoBanco::leer("05/10/2026,Pago luz,-45.60\n06/10/2026,Depósito,1200.00\n");
        $this->assertSame('sin encabezado (fecha, detalle, valor)', $b['formato']);
        $this->assertSame(['debito', 'credito'], array_column($b['lineas'], 'tipo'));
        $this->assertSame(1, $b['lineas'][0]['fila']);

        // Valor + tipo con palabras y una línea de título antes del encabezado, con tabulaciones
        $c = ArchivoBanco::leer("Estado de cuenta\n\nFecha\tDetalle\tReferencia\tImporte\tTipo de movimiento\n05/10/26\tCOMISION\t\t3,50\tDebito\n06/10/26\tINTERES\t\t0,12\tCredito\n");
        $this->assertSame('valor y tipo', $c['formato']);
        $this->assertSame(['debito', 3.5, 'credito', 0.12], [$c['lineas'][0]['tipo'], $c['lineas'][0]['monto'], $c['lineas'][1]['tipo'], $c['lineas'][1]['monto']]);
        $this->assertSame('2026-10-05', $c['lineas'][0]['fecha']);
    }

    public function test_un_archivo_de_forma_desconocida_se_rechaza_con_un_mensaje_claro(): void
    {
        $r = $this->importar("Nombre;Edad;Ciudad\nAna;30;Quito\n")->assertStatus(422)->assertJsonValidationErrors('csv');
        $mensaje = $r->json('errors.csv.0');
        $this->assertStringContainsString('No reconozco el formato del archivo', $mensaje);
        $this->assertStringContainsString('Nombre, Edad, Ciudad', $mensaje);

        $this->assertStringContainsString('vacío', $this->importar("\n\n")->assertStatus(422)->json('errors.csv.0'));
        $this->assertStringContainsString('no tiene movimientos', $this->importar("Fecha;Detalle;Débito;Crédito\n")->assertStatus(422)->json('errors.csv.0'));
        $this->importar("Fecha;Detalle;Débito;Crédito\nayer;algo;;5,00\n")->assertStatus(422);
        $this->postJson('/api/bank-movements/importar', ['company_id' => $this->company->id, 'bank_id' => $this->banco->id])->assertStatus(422)->assertJsonValidationErrors('csv');
    }

    // ------------------------------------------------------------ helpers

    private function mov(string $tipo, float $monto, string $fecha, string $concepto, ?string $documento = null, bool $conciliado = false, ?string $fechaCobro = null): BankMovement
    {
        return BankMovement::create([
            'company_id' => $this->company->id, 'bank_id' => $this->banco->id, 'fecha' => $fecha, 'tipo' => $tipo, 'monto' => $monto,
            'concepto' => $concepto, 'documento' => $documento, 'conciliado' => $conciliado,
            'forma' => $fechaCobro ? 'cheque' : null, 'fecha_cobro' => $fechaCobro,
        ]);
    }

    private function importar(string $contenido, array $extra = [])
    {
        return $this->post('/api/bank-movements/importar', [
            'company_id' => $this->company->id, 'bank_id' => $this->banco->id,
            'csv' => UploadedFile::fake()->createWithContent('estado.csv', $contenido),
        ] + $extra, ['Accept' => 'application/json']);
    }
}
