<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditNoteStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_nota_de_credito_devuelve_stock_y_su_anulacion_lo_revierte(): void
    {
        [$company, $contact] = $this->base();

        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'PROD-NC',
            'descripcion' => 'Producto de prueba',
            'tipo' => 'bien',
            'stock' => 10,
            'costo_promedio' => 5,
        ]);

        $invoice = $this->crearFactura($company, $contact, $product, 3);

        // Devolución (NC) de 1 unidad → stock 11
        // (antes del fix, la NC emitía 'salida' y NO sumaba nada al stock)
        $response = $this->postJson('/api/credit-notes', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'invoice_id' => $invoice->id,
            'tipo' => 'sri',
            'motivo' => 'Devolución de prueba',
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto de prueba',
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertSame(11.0, (float) $product->fresh()->stock);

        $creditNote = CreditNote::first();

        // Anular la NC → egreso de 1 → stock vuelve a 10
        // (antes 'salida' era un no-op silencioso y el stock quedaba en 11)
        $anular = $this->postJson("/api/credit-notes/{$creditNote->id}/anular");
        $anular->assertStatus(200);
        $this->assertSame(10.0, (float) $product->fresh()->stock);
        $this->assertSame('anulado', $creditNote->fresh()->tipo);
    }

    public function test_nota_de_credito_con_series_libera_y_su_anulacion_relockea(): void
    {
        [$company, $contact] = $this->base();

        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'P-NC-SERIE',
            'descripcion' => 'Producto con series',
            'stock' => 1,
            'costo_promedio' => 10,
            'maneja_series' => true,
        ]);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-NC-001', 'estado' => 'disponible']);

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'numero' => '001-001-000000001',
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto con series',
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
                'series' => ['SR-NC-001'],
            ]],
            'total_sin_impuestos' => 10,
            'total_impuesto' => 1.5,
            'importe_total' => 11.5,
            'forma_pago' => 'efectivo',
            'saldo_pendiente' => 0,
            'estado' => 'emitida',
            'fecha_emision' => now(),
        ]);
        // Simular que la venta marcó la serie como vendida
        ProductSerie::where('serie', 'SR-NC-001')->update(['estado' => 'vendida', 'invoice_id' => $invoice->id]);

        // NC sin series en el ítem → el controlador toma las series de la factura original
        $response = $this->postJson('/api/credit-notes', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'invoice_id' => $invoice->id,
            'tipo' => 'sri',
            'motivo' => 'Devolución',
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto con series',
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertSame('disponible', ProductSerie::where('serie', 'SR-NC-001')->first()->estado);

        // Anular la NC → la serie vuelve a quedar vendida
        $anular = $this->postJson('/api/credit-notes/' . CreditNote::first()->id . '/anular');
        $anular->assertStatus(200);
        $this->assertSame('vendida', ProductSerie::where('serie', 'SR-NC-001')->first()->estado);
    }

    private function base(): array
    {
        $company = Company::create([
            'ruc' => '1790000000001',
            'razon_social' => 'Empresa Test SA',
            'dir_matriz' => 'Av. Test 123',
            'estab' => '001',
            'pto_emi' => '001',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'tipo_identificacion' => '05',
            'identificacion' => '1700000001',
            'razon_social' => 'Cliente Test',
        ]);
        Sanctum::actingAs(User::factory()->create());

        return [$company, $contact];
    }

    private function crearFactura(Company $company, Contact $contact, Product $product, float $cantidad): Invoice
    {
        return Invoice::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'numero' => '001-001-000000001',
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto de prueba',
                'cantidad' => $cantidad,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
            'total_sin_impuestos' => 10 * $cantidad,
            'total_impuesto' => 1.5 * $cantidad,
            'importe_total' => 11.5 * $cantidad,
            'forma_pago' => 'efectivo',
            'saldo_pendiente' => 0,
            'estado' => 'emitida',
            'fecha_emision' => now(),
        ]);
    }
}
