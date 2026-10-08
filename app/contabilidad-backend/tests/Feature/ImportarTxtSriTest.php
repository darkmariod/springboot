<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PendingImport;
use App\Models\User;
use App\Services\SriXmlDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.7b: el TXT de "comprobantes recibidos" del SRI se lee por NOMBRE de columna cuando trae encabezado
 * (la razón social ya no se toma a ciegas de la columna 2), y sin encabezado se usa el orden de siempre.
 * El tipo de comprobante sale de la clave de acceso (dígitos 9 y 10): solo las facturas (01) quedan
 * "pendiente"; retenciones, notas de crédito, etc. quedan "omitido" con su motivo, no como "error".
 */
class ImportarTxtSriTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa TXT Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    // ------------------------------------------------------------ con encabezado

    public function test_con_encabezado_del_portal_toma_la_razon_social_de_su_columna(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000123', '10092026');
        $txt = $this->tsv([
            ['RUC_EMISOR', 'RAZON_SOCIAL_EMISOR', 'TIPO_COMPROBANTE', 'SERIE_COMPROBANTE', 'CLAVE_ACCESO', 'FECHA_AUTORIZACION', 'FECHA_EMISION', 'IDENTIFICACION_RECEPTOR', 'VALOR_SIN_IMPUESTOS', 'IVA', 'IMPORTE_TOTAL', 'NUMERO_DOCUMENTO_MODIFICADO'],
            ['1790011223001', 'DISTRIBUIDORA ANDINA S.A.', 'Factura', '001-001-000000123', $factura, '10/09/2026 10:01:11', '10/09/2026', '1791234567001', '100.00', '15.00', '115.00', ''],
        ]);

        $r = $this->subir($txt)->assertOk();

        $this->assertSame(1, $r->json('insertadas'));
        $fila = PendingImport::firstOrFail();
        $this->assertSame('DISTRIBUIDORA ANDINA S.A.', $fila->razon_social);   // no la serie
        $this->assertSame('1790011223001', $fila->ruc_emisor);
        $this->assertSame($factura, $fila->clave_acceso);
        $this->assertSame('2026-09-10', substr((string) $fila->fecha, 0, 10));
        $this->assertSame('pendiente', $fila->estado);
        $this->assertNull($fila->error);
    }

    public function test_con_otro_orden_de_columnas_y_encabezado_con_tildes_la_serie_no_es_la_razon_social(): void
    {
        // Layout donde la columna 2 es la SERIE (justo el caso que antes se guardaba como razón social)
        $factura = $this->clave('01', '1790011224001', '000004521', '12092026');
        $txt = $this->tsv([
            ['COMPROBANTE', 'SERIE_COMPROBANTE', 'RUC_EMISOR', 'RAZÓN SOCIAL EMISOR', 'FECHA EMISIÓN', 'FECHA AUTORIZACIÓN', 'TIPO EMISIÓN', 'IDENTIFICACIÓN RECEPTOR', 'CLAVE ACCESO', 'NÚMERO AUTORIZACIÓN'],
            ['FACTURA', '001-002-000004521', '1790011224001', 'CORPORACIÓN PEÑA S.A.', '12/09/2026', '12/09/2026', 'NORMAL', '1791234567001', $factura, $factura],
        ]);

        $this->subir($txt)->assertOk()->assertJsonPath('insertadas', 1);

        $fila = PendingImport::firstOrFail();
        $this->assertSame('CORPORACIÓN PEÑA S.A.', $fila->razon_social);
        $this->assertSame('1790011224001', $fila->ruc_emisor);
        $this->assertSame($factura, $fila->clave_acceso);
        $this->assertSame('pendiente', $fila->estado);
    }

    public function test_un_archivo_en_latin1_se_guarda_en_utf8(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000124', '10092026');
        $txt = $this->tsv([
            ['RUC_EMISOR', 'RAZON_SOCIAL_EMISOR', 'TIPO_COMPROBANTE', 'SERIE_COMPROBANTE', 'CLAVE_ACCESO'],
            ['1790011223001', 'IMPORTADORA NUÑEZ Y HNOS.', 'Factura', '001-001-000000124', $factura],
        ]);

        $this->subir(mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8'))->assertOk();

        $this->assertSame('IMPORTADORA NUÑEZ Y HNOS.', PendingImport::firstOrFail()->razon_social);
    }

    // ------------------------------------------------------------ sin encabezado

    public function test_sin_encabezado_usa_el_orden_de_siempre_con_punto_y_coma(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000125', '10092026');
        $txt = "1790011223001;PROVEEDOR UNO CIA. LTDA.;Factura;001-001-000000125;$factura;10/09/2026\n";

        $this->subir($txt)->assertOk()->assertJsonPath('insertadas', 1);

        $fila = PendingImport::firstOrFail();
        $this->assertSame('PROVEEDOR UNO CIA. LTDA.', $fila->razon_social);
        $this->assertSame('1790011223001', $fila->ruc_emisor);
        $this->assertSame('pendiente', $fila->estado);
    }

    public function test_sin_encabezado_si_la_columna_2_es_una_serie_no_la_guarda_como_razon_social(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000126', '10092026');
        $sinNombre = $this->tsv([['Factura', '001-001-000000126', $factura, '10/09/2026']]);

        $this->subir($sinNombre)->assertOk();

        $fila = PendingImport::firstOrFail();
        $this->assertNull($fila->razon_social);
        $this->assertSame('1790011223001', $fila->ruc_emisor);        // de la clave de acceso
        $this->assertSame('pendiente', $fila->estado);
    }

    public function test_una_lista_de_solo_claves_sigue_funcionando(): void
    {
        $a = $this->clave('01', '1790011223001', '000000127', '10092026');
        $b = $this->clave('01', '1790011224001', '000000128', '11092026');

        $this->subir("$a\n$b\n\nlinea de texto sin clave\n")->assertOk()
            ->assertJsonPath('insertadas', 2)->assertJsonPath('sin_clave', 1);

        $this->assertSame(['pendiente', 'pendiente'], PendingImport::orderBy('id')->pluck('estado')->all());
    }

    // ------------------------------------------------------------ tipos de comprobante

    public function test_un_archivo_mixto_deja_pendiente_la_factura_y_omitidos_la_retencion_y_la_nota_de_credito(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000130', '10092026');
        $retencion = $this->clave('07', '1790011224001', '000000007', '11092026');
        $nota = $this->clave('04', '1790011225001', '000000008', '12092026');
        $txt = $this->tsv([
            ['RUC_EMISOR', 'RAZON_SOCIAL_EMISOR', 'TIPO_COMPROBANTE', 'SERIE_COMPROBANTE', 'CLAVE_ACCESO', 'FECHA_EMISION'],
            ['1790011223001', 'PROVEEDOR FACTURA S.A.', 'Factura', '001-001-000000130', $factura, '10/09/2026'],
            ['1790011224001', 'CLIENTE QUE RETIENE S.A.', 'Comprobante de Retención', '001-001-000000007', $retencion, '11/09/2026'],
            ['1790011225001', 'PROVEEDOR NOTA S.A.', 'Notas de Crédito', '001-001-000000008', $nota, '12/09/2026'],
        ]);

        $r = $this->subir($txt)->assertOk();
        $this->assertSame(1, $r->json('insertadas'));
        $this->assertSame(2, $r->json('omitidas'));
        $this->assertSame(0, $r->json('repetidas'));

        $filas = PendingImport::orderBy('id')->get()->keyBy('clave_acceso');
        $this->assertSame('pendiente', $filas[$factura]->estado);
        $this->assertNull($filas[$factura]->error);

        $this->assertSame('omitido', $filas[$retencion]->estado);
        $this->assertStringContainsString('comprobante de retención', $filas[$retencion]->error);
        $this->assertStringContainsString('no es una factura de compra', $filas[$retencion]->error);
        $this->assertSame('CLIENTE QUE RETIENE S.A.', $filas[$retencion]->razon_social);

        $this->assertSame('omitido', $filas[$nota]->estado);
        $this->assertStringContainsString('nota de crédito', $filas[$nota]->error);

        $this->assertSame(0, PendingImport::where('estado', 'error')->count());
    }

    public function test_cada_tipo_que_no_es_factura_se_omite_con_su_nombre(): void
    {
        $esperado = ['03' => 'liquidación de compra', '04' => 'nota de crédito', '05' => 'nota de débito', '06' => 'guía de remisión', '07' => 'comprobante de retención'];
        $lineas = [];
        $n = 200;
        foreach ($esperado as $codigo => $nombre) {
            $lineas[] = $this->clave($codigo, '1790011223001', str_pad((string) $n++, 9, '0', STR_PAD_LEFT), '10092026');
        }

        $this->subir(implode("\n", $lineas))->assertOk()->assertJsonPath('omitidas', 5)->assertJsonPath('insertadas', 0);

        foreach (array_keys($esperado) as $i => $codigo) {
            $fila = PendingImport::where('clave_acceso', $lineas[$i])->firstOrFail();
            $this->assertSame('omitido', $fila->estado);
            $this->assertStringContainsString($esperado[$codigo], $fila->error);
        }
    }

    public function test_volver_a_subir_el_archivo_no_duplica_y_procesar_solo_toca_las_facturas(): void
    {
        $factura = $this->clave('01', '1790011223001', '000000140', '10092026');
        $retencion = $this->clave('07', '1790011224001', '000000009', '11092026');
        $txt = "1790011223001;PROVEEDOR S.A.;Factura;001-001-000000140;$factura\n1790011224001;OTRO S.A.;Retención;001-001-000000009;$retencion\n";

        $this->subir($txt)->assertOk()->assertJsonPath('insertadas', 1)->assertJsonPath('omitidas', 1);
        $this->subir($txt)->assertOk()->assertJsonPath('insertadas', 0)->assertJsonPath('omitidas', 0)->assertJsonPath('repetidas', 2);
        $this->assertSame(2, PendingImport::count());

        // "Traer XML y procesar" solo pide el XML de la factura; la retención omitida ni se intenta ni pasa a error
        $this->mock(SriXmlDownloader::class)->shouldReceive('download')->once()->andReturn(null);
        $r = $this->postJson('/api/pending-imports/process', ['company_id' => $this->company->id])->assertOk();
        $this->assertSame(1, $r->json('errores'));

        $this->assertSame('omitido', PendingImport::where('clave_acceso', $retencion)->value('estado'));
        $this->assertSame('error', PendingImport::where('clave_acceso', $factura)->value('estado'));
    }

    public function test_la_lista_trae_los_omitidos_con_su_motivo(): void
    {
        $retencion = $this->clave('07', '1790011224001', '000000010', '11092026');
        $this->subir($retencion)->assertOk();

        $lista = $this->getJson('/api/pending-imports?company_id='.$this->company->id)->assertOk()->json();
        $this->assertSame('omitido', $lista[0]['estado']);
        $this->assertNotEmpty($lista[0]['error']);
    }

    // ------------------------------------------------------------ helpers

    private function subir(string $contenido)
    {
        return $this->post('/api/pending-imports/upload-txt', [
            'company_id' => $this->company->id,
            'txt' => UploadedFile::fake()->createWithContent('comprobantes.txt', $contenido),
        ], ['Accept' => 'application/json']);
    }

    /** @param array<int,array<int,string>> $filas */
    private function tsv(array $filas): string
    {
        return implode("\r\n", array_map(fn ($f) => implode("\t", $f), $filas))."\r\n";
    }

    /** Clave de acceso de 49 dígitos: fecha(8) tipo(2) ruc(13) ambiente(1) serie(6) secuencial(9) código(8) emisión(1) verificador(1). */
    private function clave(string $tipo, string $ruc, string $secuencial, string $fecha): string
    {
        $clave = $fecha.$tipo.$ruc.'2'.'001001'.$secuencial.'12345678'.'1'.'3';
        $this->assertSame(49, strlen($clave));

        return $clave;
    }
}
