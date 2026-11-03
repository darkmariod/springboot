<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F9-1: el administrador abre todas las empresas desde el selector (GET /companies las trae todas), así que el RIDE de la empresa
 * que eligió tiene que descargarse aunque no sea la suya. Cualquier otro rol solo ve los documentos de su propia empresa.
 * Un documento que no corresponde a la empresa pedida sigue dando 404 sin revelar un solo dato.
 */
class RideAdminVariasEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private Invoice $facturaB;
    private CreditNote $ncB;
    private Invoice $ndB;

    protected function setUp(): void
    {
        parent::setUp();

        // dompdf carga las tablas de las fuentes: con la suite completa en un solo proceso el límite de 128 MB se agota.
        $limite = trim((string) ini_get('memory_limit'));
        $bytes = $limite === '-1' ? PHP_INT_MAX : (int) $limite * match (strtoupper(substr($limite, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
        if ($bytes < 512 * 1048576) {
            ini_set('memory_limit', '512M');
        }

        $this->a = Company::create(['ruc' => '1790000000001', 'razon_social' => 'Empresa A SA', 'dir_matriz' => 'Av. A', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);
        $this->b = Company::create(['ruc' => '1791234567001', 'razon_social' => 'Empresa B SA', 'dir_matriz' => 'Av. B', 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo']);

        $cliente = Contact::create(['company_id' => $this->b->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente de B']);
        $this->facturaB = Invoice::create([
            'company_id' => $this->b->id, 'contact_id' => $cliente->id, 'numero' => '001-001-000000001', 'estado' => 'emitida', 'fecha_emision' => now(),
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]],
            'total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115, 'saldo_pendiente' => 115, 'forma_pago' => 'credito',
        ]);
        $this->ncB = CreditNote::create([
            'company_id' => $this->b->id, 'contact_id' => $cliente->id, 'invoice_id' => $this->facturaB->id, 'tipo' => 'sri',
            'numero' => '001-001-000000002', 'fecha' => '2026-10-07', 'motivo' => 'Devolución parcial',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15]],
            'total_sin_impuestos' => 10, 'total_impuesto' => 1.5, 'importe_total' => 11.5, 'saldo_disponible' => 0,
        ]);
        $this->ndB = Invoice::create([
            'company_id' => $this->b->id, 'contact_id' => $cliente->id, 'numero' => '001-001-000000003', 'tipo_comprobante' => 'nota_debito',
            'factura_referencia_id' => $this->facturaB->id, 'estado' => 'emitida', 'fecha_emision' => now(),
            'items' => [['razon' => 'Intereses de mora', 'valor' => 5, 'tarifa' => 0]],
            'total_sin_impuestos' => 5, 'total_impuesto' => 0, 'importe_total' => 5, 'saldo_pendiente' => 0, 'forma_pago' => 'credito',
        ]);
    }

    /**
     * T11.6: «el administrador de siempre» (el que abre todas las empresas desde el selector) es ahora el superadministrador de la
     * plataforma; los demás roles quedan confinados a su empresa y el middleware `tenant` les responde 403 ante la empresa ajena.
     */
    private function como(string $rol, Company $empresa): void
    {
        Sanctum::actingAs(User::factory()->create([
            'rol' => $rol, 'activo' => true, 'company_id' => $empresa->id, 'es_superadmin' => $rol === 'admin',
        ]));
    }

    public function test_un_administrador_de_la_empresa_a_descarga_el_ride_de_la_factura_de_la_empresa_b(): void
    {
        $this->como('admin', $this->a);

        $r = $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->b->id}")->assertOk();

        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('RIDE-001-001-000000001', $r->headers->get('Content-Disposition'));
    }

    public function test_un_administrador_descarga_tambien_el_ride_de_las_notas_de_credito_y_de_debito_de_otra_empresa(): void
    {
        $this->como('admin', $this->a);

        $this->get("/api/credit-notes/{$this->ncB->id}/ride?company_id={$this->b->id}")->assertOk()->assertSee('%PDF', false);
        $this->get("/api/notas-debito/{$this->ndB->id}/ride?company_id={$this->b->id}")->assertOk()->assertSee('%PDF', false);
    }

    public function test_un_administrador_sin_empresa_propia_tambien_descarga_el_ride(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => 'admin', 'activo' => true]));

        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->b->id}")->assertOk()->assertSee('%PDF', false);
    }

    public function test_un_cajero_de_la_empresa_a_no_descarga_documentos_de_la_empresa_b(): void
    {
        $this->como('cajero', $this->a);

        // Pidiendo la empresa B, que no es la suya (T11.6: antes 404 del controlador; ahora el aislamiento lo corta antes con 403)
        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->b->id}")->assertForbidden();
        // T11.5: las notas de crédito y de débito no son del cajero (su menú no las tiene): ni siquiera llega a buscarlas, 403 antes del 404
        $this->get("/api/credit-notes/{$this->ncB->id}/ride?company_id={$this->b->id}")->assertForbidden();
        $this->get("/api/notas-debito/{$this->ndB->id}/ride?company_id={$this->b->id}")->assertForbidden();
        // Y pidiéndolo con la suya: la factura no es de A (403 del aislamiento, que también cubre el id de la URL)
        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->a->id}")->assertForbidden();
    }

    public function test_un_contador_de_la_empresa_a_tampoco_ve_documentos_de_la_empresa_b(): void
    {
        $this->como('contador', $this->a);

        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->b->id}")->assertForbidden();
    }

    public function test_el_cajero_de_la_empresa_b_si_descarga_sus_propios_documentos(): void
    {
        $this->como('cajero', $this->b);

        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->b->id}")->assertOk()->assertSee('%PDF', false);
    }

    public function test_el_documento_de_b_pedido_con_la_empresa_a_da_404_aunque_quien_lo_pide_sea_administrador(): void
    {
        $this->como('admin', $this->a);

        $this->get("/api/invoices/{$this->facturaB->id}/ride?company_id={$this->a->id}")->assertNotFound();
        $this->get("/api/credit-notes/{$this->ncB->id}/ride?company_id={$this->a->id}")->assertNotFound();
        $this->get("/api/notas-debito/{$this->ndB->id}/ride?company_id={$this->a->id}")->assertNotFound();
    }

    public function test_sin_company_id_no_hay_ride_ni_para_el_administrador(): void
    {
        $this->como('admin', $this->a);

        $this->getJson("/api/invoices/{$this->facturaB->id}/ride")->assertStatus(422);
    }

    public function test_una_nota_de_debito_no_se_abre_como_factura(): void
    {
        $this->como('admin', $this->a);

        $this->get("/api/invoices/{$this->ndB->id}/ride?company_id={$this->b->id}")->assertNotFound();
    }
}
