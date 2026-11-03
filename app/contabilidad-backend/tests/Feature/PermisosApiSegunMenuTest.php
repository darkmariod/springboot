<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\Warehouse;

/**
 * T11.5 (D5): los permisos de la API siguen a los del menú (`PERMISOS` en `src/modules.ts`).
 *   - El cajero llega solo a Punto de venta, Facturas, Cotizaciones y Clientes (más lo que esas pantallas necesitan para abrir).
 *   - El contador llega a todo menos a Administración (empresas, usuarios, firma y configuración de comprobantes, sucursales).
 *   - El administrador llega a todo.
 * En la prueba final un cajero recibía 200/422 en auditoría, usuarios, libro diario, mayor, compras, ajustes de inventario, caja, bancos y más.
 */
class PermisosApiSegunMenuTest extends SeguridadTestCase
{
    /** Rutas sin parámetros: el rol se revisa antes de validar, así que un cuerpo vacío basta para ver el 403. */
    public static function consultasDeGestion(): array
    {
        return array_map(fn ($ruta) => [$ruta], [
            'audit', 'audit/opciones', 'audit/exportar', 'users', 'journal', 'ledger', 'income-statement', 'balance-sheet',
            'accounting/periodos', 'accounting/estado-resultados', 'accounting/balance-general', 'accounts', 'accounts/siguiente-codigo',
            'purchases', 'inventory/stock', 'inventory/reorder', 'inventory/movimientos', 'inventory/stock-bodega', 'physical-counts',
            'cash/current', 'banks', 'deposits', 'bank-movements', 'receivables', 'payables', 'advances', 'credit-notes', 'withholdings',
            'withholdings-emitted', 'sri-documents/pending', 'sri-documents/authorized', 'cost-centers', 'tax/103', 'taxes/formulario104',
            'taxes/ats', 'reportes/ventas', 'reportes/compras', 'estado-cuenta/clientes', 'notas-debito', 'guias-remision',
            'liquidacion-compra', 'pending-imports', 'stock-reservations', 'dashboard/resumen', 'branches', 'series/buscar',
            'card/pendientes', 'cruce-saldos/documentos', 'credits/available',
        ]);
    }

    public static function accionesDeGestion(): array
    {
        return array_map(fn ($ruta) => [$ruta], [
            'users', 'purchases', 'purchases/import', 'inventory/ajuste', 'inventory/transferencia', 'journal-entries', 'journal/mayorizar',
            'cash/open', 'accounts', 'banks', 'advances', 'credit-notes', 'warehouses', 'products', 'cost-centers', 'bank-movements',
            'conversions', 'fractionations', 'invoices/masiva', 'pending-imports/process', 'withholdings/import', 'sri/importar', 'series',
            'series/existentes', 'physical-counts', 'emission-points', 'deposits', 'payables/pay-multiple', 'liquidacion-compra',
            'guias-remision', 'notas-debito', 'stock-reservations', 'card/settlements', 'accounting/periodos/cerrar', 'withholdings-emitted',
            'sri/importar-ventas-xml', 'branches',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('consultasDeGestion')]
    public function test_el_cajero_no_puede_consultar_la_gestion(string $ruta): void
    {
        $this->como('cajero');

        $this->getJson("/api/{$ruta}?company_id={$this->empresa->id}")->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accionesDeGestion')]
    public function test_el_cajero_no_puede_ejecutar_acciones_de_gestion(string $ruta): void
    {
        $this->como('cajero');

        $this->postJson("/api/{$ruta}", ['company_id' => $this->empresa->id])->assertForbidden();
    }

    public function test_el_cajero_no_puede_tocar_registros_concretos_de_gestion(): void
    {
        $this->como('cajero');
        $cliente = $this->cliente();
        $producto = Product::create(['company_id' => $this->empresa->id, 'codigo' => 'P-1', 'descripcion' => 'Artículo', 'tipo' => 'bien']);
        $bodega = Warehouse::create(['company_id' => $this->empresa->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);

        $this->deleteJson("/api/contacts/{$cliente->id}")->assertForbidden();
        $this->putJson("/api/products/{$producto->id}", ['descripcion' => 'Cambiado'])->assertForbidden();
        $this->deleteJson("/api/products/{$producto->id}")->assertForbidden();
        $this->getJson("/api/products/{$producto->id}/prices")->assertForbidden();
        $this->postJson("/api/products/{$producto->id}/prices", [])->assertForbidden();
        $this->putJson("/api/warehouses/{$bodega->id}", ['nombre' => 'Otro'])->assertForbidden();
        $this->deleteJson("/api/warehouses/{$bodega->id}")->assertForbidden();
        $this->getJson("/api/companies/{$this->empresa->id}/edoc-config")->assertForbidden();
        $this->postJson("/api/companies/{$this->empresa->id}/certificate", [])->assertForbidden();
        $this->postJson("/api/companies/{$this->empresa->id}/smtp/test", [])->assertForbidden();

        $this->assertNotNull(Contact::find($cliente->id));
        $this->assertSame('Artículo', $producto->fresh()->descripcion);
    }

    public function test_el_flujo_del_punto_de_venta_del_cajero_sigue_funcionando(): void
    {
        $this->prepararMemoriaParaPdf();
        $this->como('cajero');
        Product::create(['company_id' => $this->empresa->id, 'codigo' => 'SERV-1', 'descripcion' => 'Servicio', 'tipo' => 'servicio']);
        Warehouse::create(['company_id' => $this->empresa->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        $c = "company_id={$this->empresa->id}";

        // Lo que la aplicación pide al abrir y al abrir el punto de venta
        $this->getJson('/api/user')->assertOk()->assertJsonPath('rol', 'cajero');
        $this->getJson('/api/companies')->assertOk();
        $this->getJson("/api/companies/{$this->empresa->id}")->assertOk();
        $this->getJson("/api/companies/{$this->empresa->id}/plan")->assertOk();
        $this->getJson("/api/products?{$c}")->assertOk()->assertJsonCount(1);
        $this->getJson("/api/contacts?{$c}")->assertOk();
        $this->getJson('/api/emission-points')->assertOk();
        $this->getJson("/api/warehouses?{$c}")->assertOk();
        $this->getJson("/api/series?{$c}")->assertOk();
        $this->getJson("/api/series/lookup?{$c}&serie=XYZ")->assertStatus(404);
        $this->getJson('/api/sri/consulta?identificacion=123')->assertStatus(422);
        $this->getJson('/api/catalogos/formas-pago')->assertOk();
        $this->getJson('/api/catalogos/clases-contribuyente')->assertOk();

        // Crea y actualiza un cliente
        $nuevo = $this->postJson('/api/contacts', [
            'company_id' => $this->empresa->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000001', 'razon_social' => 'Cliente Nuevo', 'es_cliente' => true,
        ])->assertCreated();
        $clienteId = $nuevo->json('id');
        $this->putJson("/api/contacts/{$clienteId}", ['telefono' => '0999999999'])->assertOk();

        // Emite una factura, la lista, la imprime y la envía por correo (solo el historial)
        $item = ['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10, 'tarifa' => 15];
        $factura = $this->postJson('/api/invoices', [
            'company_id' => $this->empresa->id, 'contact_id' => $clienteId, 'forma_pago' => 'efectivo', 'items' => [$item],
        ])->assertCreated();
        $facturaId = $factura->json('invoice.id');
        $this->getJson("/api/invoices?{$c}")->assertOk();
        $this->get("/api/invoices/{$facturaId}/ride?{$c}")->assertOk();
        $this->getJson("/api/comprobantes/factura/{$facturaId}/correos?{$c}")->assertOk();

        // Cotizaciones: crear, editar, convertir y borrar
        $q1 = $this->postJson('/api/quotes', ['company_id' => $this->empresa->id, 'contact_id' => $clienteId, 'items' => [$item]])->assertCreated()->json('id');
        $q2 = $this->postJson('/api/quotes', ['company_id' => $this->empresa->id, 'contact_id' => $clienteId, 'items' => [$item]])->assertCreated()->json('id');
        $this->getJson("/api/quotes?{$c}")->assertOk();
        $this->putJson("/api/quotes/{$q1}", ['contact_id' => $clienteId, 'items' => [$item]])->assertOk();
        $this->postJson("/api/quotes/{$q1}/convert", ['forma_pago' => 'efectivo'])->assertSuccessful();
        $this->deleteJson("/api/quotes/{$q2}")->assertSuccessful();

        // Decisión del dueño (se informa en el reporte): el cajero sigue pudiendo anular facturas desde la pantalla de Facturas
        $this->postJson("/api/invoices/{$facturaId}/anular")->assertSuccessful();
    }

    public function test_el_contador_llega_a_la_gestion_pero_no_a_la_administracion(): void
    {
        $this->como('contador');
        $c = "company_id={$this->empresa->id}";

        foreach (['audit', 'journal', 'accounts', 'purchases', 'inventory/stock', 'banks', 'receivables', 'payables', 'advances', 'credit-notes',
            'withholdings', 'cost-centers', 'deposits', 'reportes/ventas', 'estado-cuenta/clientes', 'dashboard/resumen', 'physical-counts',
            'sri-documents/pending', 'notas-debito', 'guias-remision', 'stock-reservations', 'accounting/periodos'] as $ruta) {
            $this->assertSame(200, $this->getJson("/api/{$ruta}?{$c}")->status(), "El contador debe recibir 200 en GET /api/{$ruta}");
        }

        // Acciones que el contador sí hace
        $cliente = $this->cliente();
        $this->deleteJson("/api/contacts/{$cliente->id}")->assertNoContent();
        $this->postJson('/api/accounts', ['company_id' => $this->empresa->id])->assertStatus(422);
        $this->postJson('/api/purchases', ['company_id' => $this->empresa->id])->assertStatus(422);
        $this->postJson('/api/inventory/ajuste', ['company_id' => $this->empresa->id])->assertStatus(422);

        // Administración: solo el administrador
        $this->getJson("/api/companies/{$this->empresa->id}/edoc-config")->assertForbidden();
        $this->postJson("/api/companies/{$this->empresa->id}/certificate", [])->assertForbidden();
        $this->getJson("/api/branches?{$c}")->assertForbidden();
        $this->getJson("/api/users?{$c}")->assertForbidden();
        $this->putJson("/api/companies/{$this->empresa->id}", ['razon_social' => 'Otra'])->assertForbidden();
    }

    public function test_el_administrador_llega_a_todo(): void
    {
        $this->como('admin');
        $c = "company_id={$this->empresa->id}";

        foreach (['audit', 'users', 'journal', 'accounts', 'purchases', 'inventory/stock', 'banks', 'receivables', 'payables', 'branches',
            'cost-centers', 'deposits', 'reportes/ventas', 'dashboard/resumen', 'sri-documents/pending'] as $ruta) {
            $this->assertSame(200, $this->getJson("/api/{$ruta}?{$c}")->status(), "El administrador debe recibir 200 en GET /api/{$ruta}");
        }
        $this->getJson("/api/companies/{$this->empresa->id}/edoc-config")->assertOk();
    }

    private function cliente(): Contact
    {
        return Contact::create([
            'company_id' => $this->empresa->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000002', 'razon_social' => 'Cliente Prueba', 'es_cliente' => true,
        ]);
    }

    /** dompdf necesita más memoria que el límite por defecto de php.ini (ver RideFacturaTest). */
    private function prepararMemoriaParaPdf(): void
    {
        $limite = trim((string) ini_get('memory_limit'));
        $bytes = $limite === '-1' ? PHP_INT_MAX : (int) $limite * match (strtoupper(substr($limite, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };
        if ($bytes < 512 * 1048576) {
            ini_set('memory_limit', '512M');
        }
    }
}
