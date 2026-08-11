<?php

namespace Tests\Feature;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\SriDocument;
use App\Models\User;
use App\Services\RegisterInventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);
    }

    public function test_venta_descuenta_stock_del_producto(): void
    {
        [$company, $contact] = $this->base();

        $product = $this->crearProducto($company);
        app(RegisterInventoryMovement::class)->handle($product, 'ingreso', 10, 5, 'Compra test', '2026-01-01');

        $response = $this->postJson('/api/invoices', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto de prueba',
                'cantidad' => 3,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertSame(7.0, (float) $product->fresh()->stock);
        $this->assertSame(1, Invoice::count());
    }

    public function test_venta_que_deja_stock_negativo_devuelve_422_y_no_persiste_nada(): void
    {
        [$company, $contact] = $this->base();

        $product = $this->crearProducto($company);
        app(RegisterInventoryMovement::class)->handle($product, 'ingreso', 10, 5, 'Compra test', '2026-01-01');

        $response = $this->postJson('/api/invoices', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto de prueba',
                'cantidad' => 100,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => "Stock insuficiente de {$product->codigo}: hay 10, se piden 100."]);
        $this->assertSame(10.0, (float) $product->fresh()->stock);
        $this->assertSame(0, Invoice::count());
    }

    public function test_venta_de_producto_con_series_sin_indicarlas_devuelve_422(): void
    {
        [$company, $contact] = $this->base();

        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'P-SERIE',
            'descripcion' => 'Producto con series',
            'stock' => 2,
            'costo_promedio' => 10,
            'maneja_series' => true,
        ]);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-001', 'estado' => 'disponible']);

        $response = $this->postJson('/api/invoices', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto con series',
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => "El producto {$product->codigo} maneja series; indique las series a vender."]);
        $this->assertSame(2.0, (float) $product->fresh()->stock);
        $this->assertSame(0, Invoice::count());
    }

    public function test_venta_con_series_validas_marca_la_serie_vendida(): void
    {
        [$company, $contact] = $this->base();

        $product = Product::create([
            'company_id' => $company->id,
            'codigo' => 'P-SERIE',
            'descripcion' => 'Producto con series',
            'stock' => 2,
            'costo_promedio' => 10,
            'maneja_series' => true,
        ]);
        ProductSerie::create(['company_id' => $company->id, 'product_id' => $product->id, 'serie' => 'SR-001', 'estado' => 'disponible']);

        $response = $this->postJson('/api/invoices', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'items' => [[
                'codigo_principal' => $product->codigo,
                'descripcion' => 'Producto con series',
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
                'series' => ['SR-001'],
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertSame(1.0, (float) $product->fresh()->stock);
        $serie = ProductSerie::where('serie', 'SR-001')->first();
        $this->assertSame('vendida', $serie->estado);
        $this->assertSame(Invoice::first()->id, $serie->invoice_id);
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

    private function crearProducto(Company $company): Product
    {
        return Product::create([
            'company_id' => $company->id,
            'codigo' => 'PROD-1',
            'descripcion' => 'Producto de prueba',
            'tipo' => 'bien',
        ]);
    }
}
