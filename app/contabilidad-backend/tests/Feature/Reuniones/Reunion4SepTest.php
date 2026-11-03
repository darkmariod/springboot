<?php

namespace Tests\Feature\Reuniones;

use App\Models\Invoice;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\Group;

/**
 * Reunión del 4 de septiembre: primera demostración de HasReset al consultor y a Javier.
 * Series por unidad, toma física masiva, origen de los movimientos del kárdex y quién puede hacer qué según su rol.
 */
#[Group('reuniones')]
class Reunion4SepTest extends ReunionesTestCase
{
    private function camara(): Product
    {
        return Product::create([
            'company_id' => $this->company->id, 'codigo' => 'CAM-001', 'descripcion' => 'Cámara IP DS-2CD2143G0-I',
            'tipo' => 'bien', 'maneja_series' => true, 'precio' => 150,
        ]);
    }

    /** Compra de la cámara con las series dadas (una por unidad), opcionalmente a una bodega. */
    private function comprarCamaras(string $numero, int $cantidad, array $series, ?Warehouse $bodega = null)
    {
        $cuerpo = [
            'company_id' => $this->company->id, 'contact_id' => $this->proveedor->id, 'numero' => $numero, 'fecha_emision' => now()->toDateString(),
            'items' => [['codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => $cantidad, 'precio_unitario' => 100, 'tarifa' => 15, 'series' => $series]],
        ];
        if ($bodega) {
            $cuerpo['warehouse_id'] = $bodega->id;
        }

        return $this->postJson('/api/purchases', $cuerpo);
    }

    private function bodegaPrincipal(): Warehouse
    {
        return Warehouse::where('company_id', $this->company->id)->where('por_defecto', true)->firstOrFail();
    }

    // ============================================================ R15 · series por unidad

    /**
     * Pedido por: CONS-4sep, CONS-18sep
     * Pedido: «Si compro 3 cámaras, el sistema me tiene que pedir las 3 series, una por cada unidad.»
     * Se prueba: 3 unidades con 2 series, o 2 con 3, dan 422 y no suben el stock; 3 con 3 entran y el stock es igual a las series disponibles.
     */
    #[Group('reuniones')]
    #[Group('R15')]
    public function test_R15_una_compra_de_N_unidades_exige_N_series(): void
    {
        // Arrange
        $camara = $this->camara();

        // Act + Assert: faltan series
        $this->comprarCamaras('001-001-000000701', 3, ['SN-1', 'SN-2'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto CAM-001 maneja series: indica exactamente 3 series, una por unidad (se indicaron 2).');
        // Act + Assert: sobran series
        $this->comprarCamaras('001-001-000000702', 2, ['SN-1', 'SN-2', 'SN-3'])->assertStatus(422)
            ->assertJsonPath('errors.series.0', 'El producto CAM-001 maneja series: indica exactamente 2 series, una por unidad (se indicaron 3).');
        // Assert: nada entró
        $this->assertSame(0, Purchase::count());
        $this->assertEquals(0.0, (float) $camara->fresh()->stock);
        $this->assertSame(0, ProductSerie::count());

        // Act: 3 unidades con 3 series
        $this->comprarCamaras('001-001-000000703', 3, ['SN-1', 'SN-2', 'SN-3'])->assertCreated();

        // Assert: el stock es igual a las series disponibles; una serie repetida se rechaza
        $this->assertEquals(3.0, (float) $camara->fresh()->stock);
        $this->assertSame(3, ProductSerie::where('product_id', $camara->id)->where('estado', 'disponible')->count());
        $this->comprarCamaras('001-001-000000704', 1, ['SN-2'])->assertStatus(422);
        $this->assertEquals(3.0, (float) $camara->fresh()->stock);
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-4sep, CONS-18sep
     * Pedido: «Quiero buscar una serie por un pedazo del número y ver su historial: quién me la vendió y a quién se la vendí.»
     * Se prueba: la búsqueda por un pedazo, sin importar mayúsculas, trae las series con producto, bodega, compra y venta.
     */
    #[Group('reuniones')]
    #[Group('R15')]
    public function test_R15_la_busqueda_por_un_pedazo_de_la_serie_trae_su_historial(): void
    {
        // Arrange: se compran 3 cámaras y se venden 2
        $this->camara();
        $this->comprarCamaras('001-001-000000710', 3, ['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003'])->assertCreated();
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [['codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => 2, 'precio_unitario' => 150, 'tarifa' => 15,
                'series' => ['DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003']]],
        ])->assertCreated();
        $factura = Invoice::firstOrFail();

        // Act: se busca por un pedazo en minúsculas
        $r = $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=cd2143g0')->assertOk();

        // Assert: aparecen las tres, con su historial
        $this->assertSame(['DS-2CD2143G0-I-0001', 'DS-2CD2143G0-I-0002', 'DS-2CD2143G0-I-0003'], array_column($r->json(), 'serie'));
        $this->assertSame('disponible', $r->json('0.estado'));
        $this->assertSame('CAM-001', $r->json('0.producto.codigo'));
        $this->assertSame('001-001-000000710', $r->json('0.compra.numero'));
        $this->assertSame('vendida', $r->json('1.estado'));
        $this->assertSame($factura->numero, $r->json('1.venta.numero'));
        $this->assertSame(['compra', 'venta'], array_column($r->json('1.historial'), 'tipo'));
        // Assert: un pedazo del final también la encuentra, y con menos de 3 caracteres no busca
        $this->assertCount(1, $this->getJson('/api/series/buscar?company_id='.$this->company->id.'&q=I-0002')->assertOk()->json());
    }

    /**
     * Pedido por: CONS-4sep, CONS-18sep
     * Pedido: «Las series tienen que pertenecer a una bodega: si la cámara está en la sucursal, no la puedo vender desde la principal.»
     * Se prueba: una serie comprada para la bodega secundaria se rechaza en una venta desde la principal y se vende al indicar su bodega.
     */
    #[Group('reuniones')]
    #[Group('R15')]
    public function test_R15_las_series_pertenecen_a_una_bodega(): void
    {
        // Arrange: P-1 en la bodega principal y S-1 en la sucursal
        $this->camara();
        $sucursal = Warehouse::create(['company_id' => $this->company->id, 'codigo' => 'B02', 'nombre' => 'Sucursal Norte']);
        $this->comprarCamaras('001-001-000000720', 1, ['P-1'])->assertCreated();
        $this->comprarCamaras('001-001-000000721', 1, ['S-1'], $sucursal)->assertCreated();
        $this->assertSame($sucursal->id, ProductSerie::where('serie', 'S-1')->value('warehouse_id'));
        $this->assertSame($this->bodegaPrincipal()->id, ProductSerie::where('serie', 'P-1')->value('warehouse_id'));
        $linea = fn (array $extra = []) => [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [['codigo_principal' => 'CAM-001', 'descripcion' => 'Cámara IP', 'cantidad' => 1, 'precio_unitario' => 150, 'tarifa' => 15, 'series' => ['S-1']] + $extra],
        ];

        // Act + Assert 1: desde la bodega principal, S-1 no está
        $r = $this->postJson('/api/invoices', $linea())->assertStatus(422);
        $this->assertSame('La serie S-1 no está en la bodega Principal: está en la bodega Sucursal Norte.', $r->json('message'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame(0, Invoice::count());

        // Act + Assert 2: indicando la bodega de la sucursal sí se vende
        $this->postJson('/api/invoices', $linea(['warehouse_id' => $sucursal->id]))->assertCreated();
        $this->assertSame('vendida', ProductSerie::where('serie', 'S-1')->value('estado'));
        $this->assertSame('disponible', ProductSerie::where('serie', 'P-1')->value('estado'), 'La de la principal sigue disponible');
        $this->assertContabilidadCuadra();
    }

    // ============================================================ R16 · toma física y kárdex

    /** Compra del inventario inicial para la toma física: A 10 u a $10, B 20 u a $5, C 8 u a $2.50. */
    private function inventarioInicial(): void
    {
        $this->comprar($this->proveedor, [['A-001', 10, 10], ['B-002', 20, 5], ['C-003', 8, 2.5]]);
    }

    private function producto(string $codigo): Product
    {
        return Product::where('company_id', $this->company->id)->where('codigo', $codigo)->firstOrFail();
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «La toma física no puede ser producto por producto: quiero contar varios productos en un solo documento y que genere UN asiento.»
     * Se prueba: la toma TF-000001 con A (faltan 2), B (sobran 5) y C sin contar mueve solo A y B, deja un asiento de $45 y no toca C.
     */
    #[Group('reuniones')]
    #[Group('R16')]
    public function test_R16_la_toma_fisica_masiva_junta_varios_productos_en_un_documento_con_un_solo_asiento(): void
    {
        // Arrange: inventario inicial y borrador de la toma de la bodega principal
        $this->inventarioInicial();
        $bodega = $this->bodegaPrincipal();
        $toma = $this->postJson('/api/physical-counts', ['company_id' => $this->company->id, 'warehouse_id' => $bodega->id, 'motivo' => 'Inventario de fin de mes'])
            ->assertCreated()->assertJsonPath('numero', 'TF-000001')->assertJsonPath('estado', 'borrador');
        $this->assertSame(['A-001', 'B-002', 'C-003'], array_column($toma->json('lineas'), 'codigo'), 'Entran solo los bienes con stock');
        $movimientos = InventoryMovement::count();
        $asientos = JournalEntry::count();

        // Act 1: se cuenta en bloque (C se deja en blanco: no se contó)
        $this->putJson('/api/physical-counts/'.$toma->json('id'), [
            'company_id' => $this->company->id,
            'lineas' => [['product_id' => $this->producto('A-001')->id, 'contado' => 8], ['product_id' => $this->producto('B-002')->id, 'contado' => 25]],
        ])->assertOk();
        // Act 2: se aplica
        $r = $this->postJson('/api/physical-counts/'.$toma->json('id').'/aplicar', ['company_id' => $this->company->id])->assertOk();

        // Assert: el stock quedó como se contó; lo que no se contó no se tocó
        $r->assertJsonPath('estado', 'aplicada')->assertJsonPath('movimientos', 2);
        $this->assertEquals([8.0, 25.0, 8.0], [(float) $this->producto('A-001')->stock, (float) $this->producto('B-002')->stock, (float) $this->producto('C-003')->stock]);
        $this->assertSame($movimientos + 2, InventoryMovement::count());
        // Assert: UN asiento para toda la toma (faltante 2 × $10 = $20, sobrante 5 × $5 = $25)
        $this->assertSame($asientos + 1, JournalEntry::count());
        $asiento = JournalEntry::where('concepto', 'Toma física TF-000001')->sole();
        $this->assertEquals(45.0, (float) $asiento->total_debe);
        $this->assertLineas($asiento, [['1.1.05', 25, 0], ['5.1.03', 0, 25], ['5.1.03', 20, 0], ['1.1.05', 0, 20]]);
        // Assert: una toma aplicada no se aplica otra vez
        $this->postJson('/api/physical-counts/'.$toma->json('id').'/aplicar', ['company_id' => $this->company->id])->assertStatus(422);
        $this->assertSame($asientos + 1, JournalEntry::count());
        $this->assertContabilidadCuadra();
    }

    /**
     * Pedido por: CONS-18sep
     * Pedido: «En el kárdex quiero ver de dónde viene cada movimiento: la compra, la factura, la toma física.»
     * Se prueba: el kárdex dice el origen de cada movimiento con su número (compra del proveedor, venta al cliente, toma TF- de la bodega).
     */
    #[Group('reuniones')]
    #[Group('R16')]
    public function test_R16_el_kardex_dice_el_origen_de_cada_movimiento(): void
    {
        // Arrange: una compra, una venta y una toma física del mismo producto
        $compra = $this->comprar($this->proveedor, [['A-001', 10, 10]]);
        $factura = $this->facturarBienes([['A-001', 2, 20]]);
        $bodega = $this->bodegaPrincipal();
        $tomaId = $this->postJson('/api/physical-counts', ['company_id' => $this->company->id, 'warehouse_id' => $bodega->id])->assertCreated()->json('id');
        $this->putJson("/api/physical-counts/{$tomaId}", [
            'company_id' => $this->company->id, 'lineas' => [['product_id' => $this->producto('A-001')->id, 'contado' => 7]],
        ])->assertOk();
        $this->postJson("/api/physical-counts/{$tomaId}/aplicar", ['company_id' => $this->company->id])->assertOk();

        // Act
        $kardex = $this->getJson('/api/inventory/kardex/'.$this->producto('A-001')->id)->assertOk()->json('movimientos');

        // Assert: [tipo de origen, etiqueta, número, detalle] de cada fila, en orden
        $origen = array_map(fn ($m) => [$m['origen_tipo'], $m['origen_label'], $m['origen_numero'], $m['origen_detalle']], $kardex);
        $this->assertSame([
            ['compra', 'Compra', $compra->numero, 'Proveedor Test SA'],
            ['venta', 'Venta', $factura->numero, 'María Fernanda Pazmiño'],
            ['toma_fisica', 'Toma física', 'TF-000001', 'Principal'],
        ], $origen);
        // Assert: el reporte de movimientos se puede filtrar por origen
        $soloVentas = $this->getJson('/api/inventory/movimientos?company_id='.$this->company->id.'&origen=venta')->assertOk()->json('movimientos');
        $this->assertSame(['venta'], array_values(array_unique(array_column($soloVentas, 'origen_tipo'))));
    }

    // ============================================================ R23 · roles

    /**
     * Pedido por: CONS-4sep, CONS-18sep
     * Pedido: «El cajero solo tiene que vender: no puede crear usuarios ni ver la contabilidad.»
     * Se prueba: el cajero factura, cotiza y crea clientes (2xx) y recibe 403 en usuarios, libro diario, plan de cuentas, compras, bancos, nómina y períodos.
     */
    #[Group('reuniones')]
    #[Group('R23')]
    public function test_R23_el_cajero_solo_vende_y_no_crea_usuarios_ni_ve_contabilidad(): void
    {
        $this->como('cajero');

        // Lo que sí hace: vender
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id, 'forma_pago' => 'efectivo',
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 20, 'tarifa' => 15]],
        ])->assertCreated();
        $this->postJson('/api/quotes', [
            'company_id' => $this->company->id, 'contact_id' => $this->cliente->id,
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 20, 'tarifa' => 15]],
        ])->assertCreated();
        $this->postJson('/api/contacts', [
            'company_id' => $this->company->id, 'tipo_identificacion' => '05', 'identificacion' => '1700000003', 'razon_social' => 'Cliente del mostrador',
        ])->assertCreated();

        // Lo que no hace: crear usuarios
        $this->postJson('/api/users', ['company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@empresa.test', 'password' => 'clave-segura-1', 'rol' => 'admin'])
            ->assertStatus(403);
        $this->assertSame(0, User::where('email', 'otro@empresa.test')->count());
        $this->getJson('/api/users?company_id='.$this->company->id)->assertStatus(403);

        // Lo que no ve: la contabilidad y la administración
        foreach (['journal', 'accounts', 'purchases', 'banks', 'payrolls', 'accounting/periodos', 'balance-sheet', 'ledger', 'audit', 'dashboard/resumen'] as $ruta) {
            $this->getJson("/api/{$ruta}?company_id={$this->company->id}")->assertStatus(403, "El cajero no debe entrar a /api/{$ruta}");
        }
        $this->postJson('/api/journal-entries', ['company_id' => $this->company->id])->assertStatus(403);
    }

    /**
     * Pedido por: CONS-4sep, CONS-18sep
     * Pedido: «El contador lleva la contabilidad, pero no administra: no maneja usuarios ni cambia la empresa.»
     * Se prueba: el contador ve libro diario y plan de cuentas y registra compras (2xx) y recibe 403 en usuarios, sucursales, alta de empresas y puntos de emisión.
     */
    #[Group('reuniones')]
    #[Group('R23')]
    public function test_R23_el_contador_lleva_la_contabilidad_pero_no_administra(): void
    {
        $this->como('contador');

        // Lo que sí hace
        $this->getJson("/api/journal?company_id={$this->company->id}")->assertOk();
        $this->getJson("/api/accounts?company_id={$this->company->id}")->assertOk();
        $this->getJson("/api/accounting/periodos?company_id={$this->company->id}&anio=".now()->year)->assertOk();
        $this->comprar($this->proveedor, [['SERV-1', 1, 50]]);

        // Lo que no hace: administrar
        $this->getJson('/api/users?company_id='.$this->company->id)->assertStatus(403);
        $this->postJson('/api/users', ['company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@empresa.test', 'password' => 'clave-segura-1', 'rol' => 'cajero'])
            ->assertStatus(403);
        $this->getJson('/api/branches?company_id='.$this->company->id)->assertStatus(403);
        $this->postJson('/api/emission-points', ['company_id' => $this->company->id, 'estab' => '002', 'punto' => '001', 'nombre' => 'Otro'])->assertStatus(403);
        $this->postJson('/api/companies', ['ruc' => '1792233445001', 'razon_social' => 'Otra', 'dir_matriz' => 'Av. 1', 'admin_nombre' => 'X', 'admin_email' => 'x@x.test', 'admin_password' => 'Clave-Inicial-2026'])
            ->assertStatus(403);
        $this->postJson("/api/accounting/periodos/reabrir", ['company_id' => $this->company->id, 'anio' => now()->year, 'mes' => 1, 'motivo' => 'x'])->assertStatus(403);
    }
}
