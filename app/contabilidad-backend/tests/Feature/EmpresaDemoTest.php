<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\EmissionPoint;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Cuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T0.1: `php artisan demo:crear` arma la empresa "DEMO HASRESET" para grabar el demo de ventas.
 * Todo lo que se mueve (compras, ventas, pagos, nota de crédito, ajuste) pasa por los controladores
 * reales, así los asientos salen igual que cuando lo hace una persona desde la pantalla.
 */
class EmpresaDemoTest extends TestCase
{
    use RefreshDatabase;

    private const RUC_DEMO = '0999000001001';

    /** Tablas que cuentan lo que el comando podría duplicar si se ejecutara dos veces. */
    private const TABLAS = [
        'companies', 'branches', 'emission_points', 'warehouses', 'banks', 'cash_registers', 'cash_sessions',
        'cash_movements', 'accounts', 'contacts', 'products', 'product_series', 'purchases', 'purchase_payments',
        'invoices', 'invoice_payments', 'payment_splits', 'credit_notes', 'credit_applications', 'sri_documents',
        'inventory_movements', 'warehouse_stocks', 'journal_entries', 'journal_entry_lines', 'bank_movements',
    ];

    private Company $otra;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Una empresa que ya existe, con sus propios datos, y el administrador de siempre:
        // el comando no puede tocar nada de esto.
        $this->otra = Company::create([
            'ruc' => '1791234567001', 'razon_social' => 'Empresa Existente Test SA', 'dir_matriz' => 'Av. Test 123',
            'estab' => '001', 'pto_emi' => '001', 'plan' => 'negocio',
        ]);
        Cuentas::sembrar($this->otra->id);
        Warehouse::create(['company_id' => $this->otra->id, 'codigo' => 'B01', 'nombre' => 'Principal', 'por_defecto' => true]);
        Contact::create([
            'company_id' => $this->otra->id, 'tipo_identificacion' => '05',
            'identificacion' => '1700000001', 'razon_social' => 'Cliente Existente',
        ]);
        Product::create([
            'company_id' => $this->otra->id, 'codigo' => 'EXI-1', 'descripcion' => 'Producto existente',
            'tipo' => 'bien', 'precio' => 10, 'stock' => 7, 'costo_promedio' => 4,
        ]);
        $this->admin = User::factory()->create(['rol' => 'admin', 'company_id' => $this->otra->id]);
    }

    // ---------------------------------------------------------------- (a) creación

    public function test_crea_la_empresa_demo_con_plan_completo_y_los_datos_esperados(): void
    {
        $this->artisan('demo:crear')
            ->expectsOutputToContain('DEMO HASRESET')
            ->assertExitCode(0);

        $demo = $this->demo();
        $this->assertSame('DEMO HASRESET', $demo->razon_social);
        $this->assertSame(Company::PLAN_POR_DEFECTO, $demo->plan);
        $this->assertSame('completo', $demo->plan);
        $this->assertContains('contabilidad', $demo->features());
        $this->assertSame(1, (int) $demo->ambiente, 'El ambiente del SRI debe ser pruebas (1).');
        $this->assertSame(13, strlen($demo->ruc));
        $this->assertStringStartsWith('0999', $demo->ruc, 'El RUC es ficticio, no de un tercero real.');
        $this->assertNotSame('', trim($demo->dir_matriz));
        $this->assertNull($demo->certificado_p12, 'La empresa demo no lleva certificado de firma.');

        // Establecimiento y punto de emisión 001-001, plan de cuentas completo
        $this->assertTrue(Branch::where('company_id', $demo->id)->where('estab', '001')->where('es_matriz', true)->exists());
        $punto = EmissionPoint::where('company_id', $demo->id)->where('estab', '001')->where('punto', '001')->first();
        $this->assertNotNull($punto);
        $this->assertSame(
            collect(config('cuentas.cuentas'))->pluck('codigo')->sort()->values()->all(),
            Account::where('company_id', $demo->id)->whereIn('codigo', collect(config('cuentas.cuentas'))->pluck('codigo'))
                ->pluck('codigo')->sort()->values()->all(),
        );

        // Bodega, caja y banco
        $bodega = Warehouse::where('company_id', $demo->id)->first();
        $this->assertSame('Bodega principal', $bodega->nombre);
        $this->assertTrue($bodega->por_defecto);
        $this->assertSame('Caja principal', CashRegister::where('company_id', $demo->id)->value('nombre'));
        $this->assertSame(1, CashSession::where('company_id', $demo->id)->where('estado', 'abierta')->count());
        $banco = Bank::where('company_id', $demo->id)->first();
        $this->assertStringContainsString('Banco Demo', $banco->nombre);
        $this->assertSame(Cuentas::codigo('bancos'), $banco->cuenta_contable);
        $this->assertGreaterThanOrEqual(2, BankMovement::where('company_id', $demo->id)->count());

        // Clientes y proveedores
        $clientes = Contact::where('company_id', $demo->id)->where('es_cliente', true)->get();
        $proveedores = Contact::where('company_id', $demo->id)->where('es_proveedor', true)->get();
        $this->assertGreaterThanOrEqual(4, $clientes->count());
        $this->assertGreaterThanOrEqual(3, $proveedores->count());
        $this->assertNotNull($clientes->firstWhere('tipo_identificacion', '07'), 'Falta el consumidor final.');
        $this->assertNotNull($clientes->firstWhere('tipo_identificacion', '05'), 'Falta la persona natural.');
        $this->assertNotNull($clientes->firstWhere('tipo_identificacion', '04'), 'Falta la sociedad.');
        $this->assertNotNull($clientes->firstWhere('parte_relacionada', true), 'Falta la parte relacionada.');
        foreach ($clientes->concat($proveedores) as $c) {
            $largo = ['04' => 13, '05' => 10, '07' => 13][$c->tipo_identificacion] ?? null;
            $this->assertSame($largo, strlen($c->identificacion), "Identificación mal formada: {$c->razon_social}");
        }

        // Productos: 7 bienes (uno con series) y 3 servicios
        $productos = Product::where('company_id', $demo->id)->get();
        $this->assertCount(10, $productos);
        $this->assertCount(7, $productos->where('tipo', 'bien'));
        $this->assertCount(3, $productos->where('tipo', 'servicio'));
        $this->assertEqualsCanonicalizing([0.0, 15.0], $productos->pluck('tarifa_iva')->map(fn ($t) => (float) $t)->unique()->values()->all());
        $conSeries = $productos->where('maneja_series', true);
        $this->assertCount(1, $conSeries);
        $series = ProductSerie::where('product_id', $conSeries->first()->id)->get();
        $this->assertSame(3, $series->where('estado', 'disponible')->count());
        $this->assertSame(2, $series->where('estado', 'vendida')->count());
        $this->assertSame(0, Product::where('company_id', $demo->id)->where('stock', '<', 0)->count());

        // Movimientos de muestra: 3 compras (2 de bienes, 1 de servicio), 3 ventas, 1 nota de crédito, 1 ajuste
        $this->assertSame(3, Purchase::where('company_id', $demo->id)->count());
        $this->assertSame(3, Invoice::where('company_id', $demo->id)->count());
        $this->assertSame(['credito', 'credito', 'efectivo'], Invoice::where('company_id', $demo->id)->pluck('forma_pago')->sort()->values()->all());
        $nota = CreditNote::where('company_id', $demo->id)->firstOrFail();
        $this->assertSame('sri', $nota->tipo);
        $this->assertSame(1, InventoryMovement::where('company_id', $demo->id)->where('concepto', 'like', 'Devolución NC%')->count());
        $this->assertSame(1, InventoryMovement::where('company_id', $demo->id)->where('concepto', 'like', 'Ajuste inventario%')->where('tipo', 'egreso')->count());
        // Pagos de muestra: dos pagos a proveedor (uno parcial, uno total) y un cobro parcial de cliente
        $this->assertSame(2, DB::table('purchase_payments')->whereIn('purchase_id', Purchase::where('company_id', $demo->id)->select('id'))->count());
        $this->assertSame(1, DB::table('invoice_payments')->whereIn('invoice_id', Invoice::where('company_id', $demo->id)->select('id'))->count());
        // La nota de crédito baja el saldo de su factura al emitirse: ya no se "aplica" después (T3.2), no hay aplicaciones
        $this->assertSame(0, DB::table('credit_applications')->whereIn('invoice_id', Invoice::where('company_id', $demo->id)->select('id'))->count());

        // El kárdex termina así: la nota de crédito devolvió 1 tóner y el ajuste dio de baja 2 cables
        $this->assertEquals(
            ['CAB-001' => 27, 'LAP-001' => 3, 'LIB-001' => 15, 'MON-001' => 6, 'MOU-001' => 18, 'TEC-001' => 17, 'TON-001' => 11],
            $productos->where('tipo', 'bien')->mapWithKeys(fn ($p) => [$p->codigo => (float) $p->stock])->sortKeys()->all(),
        );
    }

    public function test_las_facturas_quedan_en_el_estado_que_deja_el_sistema_sin_certificado(): void
    {
        $this->crear();
        $demo = $this->demo();

        $documentos = SriDocument::where('company_id', $demo->id)->get();
        $this->assertCount(3, $documentos);
        foreach ($documentos as $doc) {
            $this->assertSame('generado', $doc->estado, 'Sin certificado la factura se queda generada, nunca autorizada.');
            $this->assertNull($doc->numero_autorizacion);
            $this->assertNull($doc->xml_firmado);
            $this->assertSame(49, strlen((string) $doc->clave_acceso));
            $this->assertStringContainsString($demo->ruc, (string) $doc->clave_acceso);
        }
        $this->assertSame(['001-001-000000001', '001-001-000000002', '001-001-000000003'],
            Invoice::where('company_id', $demo->id)->orderBy('id')->pluck('numero')->all());
    }

    public function test_los_movimientos_estan_repartidos_dentro_del_mes_actual_y_nunca_en_el_futuro(): void
    {
        $this->crear();
        $demo = $this->demo();

        $fechas = collect()
            ->merge(Purchase::where('company_id', $demo->id)->get()->map(fn ($p) => $p->fecha_emision))
            ->merge(Invoice::where('company_id', $demo->id)->get()->map(fn ($i) => $i->fecha_emision));

        foreach ($fechas as $f) {
            $this->assertSame(now()->format('Y-m'), $f->format('Y-m'));
            $this->assertTrue($f->lessThanOrEqualTo(now()->endOfDay()), 'Hay un documento con fecha futura.');
        }
    }

    // ---------------------------------------------------------------- (b) idempotencia

    public function test_ejecutarlo_dos_veces_no_duplica_nada_y_termina_bien(): void
    {
        $this->crear();
        $antes = $this->conteos();

        $this->artisan('demo:crear')
            ->expectsOutputToContain('ya existe')
            ->assertExitCode(0);

        $this->assertSame($antes, $this->conteos());
        $this->assertSame(1, Company::where('ruc', self::RUC_DEMO)->count());
    }

    public function test_rehacer_sin_confirmar_no_toca_nada(): void
    {
        $this->crear();
        $antes = $this->conteos();

        $this->artisan('demo:crear', ['--rehacer' => true])
            ->expectsConfirmation('Se borrarán los datos de DEMO HASRESET (solo de esa empresa) y se volverá a crear. ¿Continuar?', 'no')
            ->assertExitCode(1);

        $this->assertSame($antes, $this->conteos());
    }

    public function test_rehacer_con_force_borra_solo_los_datos_de_la_demo_y_la_reconstruye_igual(): void
    {
        $this->crear();
        $demo = $this->demo();
        $antes = $this->conteos();
        $numeros = Invoice::where('company_id', $demo->id)->orderBy('id')->pluck('numero')->all();
        $otraAntes = $this->fotoDeLaOtra();

        // Alguien agregó cosas a la demo: la reconstrucción las borra (son datos de la demo)
        Contact::create(['company_id' => $demo->id, 'tipo_identificacion' => '05', 'identificacion' => '0999000099', 'razon_social' => 'Sobrante']);
        Product::create(['company_id' => $demo->id, 'codigo' => 'SOBRA-1', 'descripcion' => 'Sobrante', 'tipo' => 'bien']);

        $this->artisan('demo:crear', ['--rehacer' => true, '--force' => true])->assertExitCode(0);

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($demo->id, $this->demo()->id, 'La empresa conserva su identificador.');
        $this->assertFalse(Contact::where('razon_social', 'Sobrante')->exists());
        $this->assertFalse(Product::where('codigo', 'SOBRA-1')->exists());
        $this->assertSame($numeros, Invoice::where('company_id', $demo->id)->orderBy('id')->pluck('numero')->all(), 'La numeración vuelve a empezar en 1.');
        $this->assertSame($otraAntes, $this->fotoDeLaOtra());
        $this->artisan('contable:chequeo', ['--company' => $demo->id, '--estricto' => true])->assertExitCode(0);
    }

    public function test_rehacer_no_toca_una_empresa_con_ese_ruc_que_no_es_la_demo(): void
    {
        Company::create(['ruc' => self::RUC_DEMO, 'razon_social' => 'Otra Empresa Real SA', 'dir_matriz' => 'Av. 1']);
        $antes = $this->conteos();

        $this->artisan('demo:crear', ['--rehacer' => true, '--force' => true])->assertExitCode(1);

        $this->assertSame($antes, $this->conteos());
        $this->assertSame('Otra Empresa Real SA', Company::where('ruc', self::RUC_DEMO)->value('razon_social'));
    }

    // ---------------------------------------------------------------- (c) contabilidad

    public function test_el_chequeo_estricto_pasa_y_el_comando_lo_muestra(): void
    {
        $this->artisan('demo:crear')
            ->expectsOutputToContain('OK  Cuentas por cobrar')
            ->expectsOutputToContain('OK  Cuentas por pagar')
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);

        $this->artisan('contable:chequeo', ['--company' => $this->demo()->id, '--estricto' => true])
            ->expectsOutputToContain('TODO OK')
            ->assertExitCode(0);
    }

    public function test_los_asientos_de_la_demo_salen_bien(): void
    {
        $this->crear();
        $demo = $this->demo();

        // Todo asiento cuadra
        $this->assertSame(0, JournalEntry::where('company_id', $demo->id)->whereRaw('ROUND(total_debe, 2) != ROUND(total_haber, 2)')->count());

        // La mercadería comprada va a Inventario (3975 + 540), no a gasto; el servicio comprado sí es gasto (120)
        $this->assertEqualsWithDelta(120.0, $this->saldo($demo, 'compras'), 0.005);
        // Costo de ventas: ventas 18.50 + 1210.00 + 105.00 - devolución del tóner 30.00
        $this->assertEqualsWithDelta(1303.5, $this->saldo($demo, 'costo_ventas'), 0.005);
        // El ajuste por faltante (2 cables a 4.50) tiene su asiento
        $this->assertEqualsWithDelta(9.0, $this->saldo($demo, 'faltantes_sobrantes'), 0.005);
        // Inventario del mayor = 4515 - 1303.50 - 9 - 0 = kárdex (existencias x costo promedio)
        $kardex = Product::where('company_id', $demo->id)->where('tipo', 'bien')->get()->sum(fn ($p) => (float) $p->stock * (float) $p->costo_promedio);
        $this->assertEqualsWithDelta(3202.5, $this->saldo($demo, 'inventario'), 0.005);
        $this->assertEqualsWithDelta($kardex, $this->saldo($demo, 'inventario'), 0.01);
        // Ventas (33 + 1720 + 196) y devolución sin IVA (48.00): el IVA de la devolución (7.20) se debita a IVA por pagar (T3.2)
        $this->assertEqualsWithDelta(1949.0, -$this->saldo($demo, 'ventas'), 0.005);
        $this->assertEqualsWithDelta(48.0, $this->saldo($demo, 'devoluciones_ventas'), 0.005);
        $nota = CreditNote::where('company_id', $demo->id)->firstOrFail();
        $ivaDeLaNota = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.origen_type', $nota->getMorphClass())->where('journal_entries.origen_id', $nota->id)
            ->where('accounts.codigo', Cuentas::codigo('iva_por_pagar'))->sum('journal_entry_lines.debe');
        $this->assertEqualsWithDelta(7.2, (float) $ivaDeLaNota, 0.005);
        // Cada movimiento del kárdex nació de un documento con asiento (el chequeo estricto lo revisa también)
        $this->assertSame(0, InventoryMovement::where('company_id', $demo->id)->whereNull('invoice_id')->whereNull('purchase_id')
            ->where('concepto', 'not like', 'Devolución NC%')->where('concepto', 'not like', 'Ajuste inventario%')->count());
    }

    public function test_cartera_y_cuentas_por_pagar_cuadran_con_los_documentos(): void
    {
        $this->crear();
        $demo = $this->demo();

        // Venta 2: 1978.00 - cobro 800.00; venta 3: 225.40 - nota de crédito 55.20 (baja su saldo al emitirse)
        $this->assertEqualsWithDelta(1348.20, (float) Invoice::where('company_id', $demo->id)->sum('saldo_pendiente'), 0.005);
        $this->assertEqualsWithDelta(0.0, (float) CreditNote::where('company_id', $demo->id)->sum('saldo_disponible'), 0.005);
        $this->assertEqualsWithDelta(1348.20, $this->saldo($demo, 'cxc'), 0.005);
        // Compra 1: 4571.25 - 2000 pagados; compra 2: 594.00 a crédito; compra 3: 138.00 pagada
        $this->assertEqualsWithDelta(3165.25, (float) Purchase::where('company_id', $demo->id)->sum('saldo_pendiente'), 0.005);
        $this->assertEqualsWithDelta(3165.25, -$this->saldo($demo, 'cxp'), 0.005);
    }

    // ---------------------------------------------------------------- (d) nada más cambia

    public function test_no_cambia_nada_de_las_otras_empresas_ni_de_los_usuarios(): void
    {
        $antes = $this->fotoDeLaOtra();
        $empresasAntes = Company::count();
        $productosAntes = Product::count();
        $usuariosAntes = User::count();

        $this->crear();

        $this->assertSame($empresasAntes + 1, Company::count());
        $this->assertSame($productosAntes + 10, Product::count());
        $this->assertSame($usuariosAntes, User::count(), 'El comando no crea ni borra usuarios.');
        $this->assertSame($antes, $this->fotoDeLaOtra());
        $this->assertSame(0, DB::table('journal_entries')->where('company_id', $this->otra->id)->count());
    }

    // ---------------------------------------------------------------- (e) acceso del administrador

    public function test_el_administrador_de_siempre_ve_la_empresa_en_el_selector_y_la_puede_usar(): void
    {
        $passwordAntes = $this->admin->fresh()->password;
        $this->crear();
        $demo = $this->demo();

        Sanctum::actingAs($this->admin->fresh());

        $this->getJson('/api/companies')->assertOk()
            ->assertJsonFragment(['razon_social' => 'DEMO HASRESET'])
            ->assertJsonFragment(['razon_social' => 'Empresa Existente Test SA']);
        $this->getJson("/api/companies/{$demo->id}/plan")->assertOk()->assertJsonPath('plan', 'completo');
        $this->getJson("/api/products?company_id={$demo->id}")->assertOk()->assertJsonCount(10);
        $this->getJson("/api/invoices?company_id={$demo->id}")->assertOk()->assertJsonCount(3);
        $this->getJson("/api/receivables?company_id={$demo->id}")->assertOk()->assertJsonPath('total', 1348.2);
        $this->getJson("/api/payables?company_id={$demo->id}")->assertOk()->assertJsonPath('total', 3165.25);
        $this->getJson("/api/journal?company_id={$demo->id}")->assertOk();

        // El usuario no se tocó: ni contraseña ni empresa por defecto
        $admin = User::findOrFail($this->admin->id);
        $this->assertSame($passwordAntes, $admin->password);
        $this->assertSame($this->otra->id, $admin->company_id);
        $this->assertSame('admin', $admin->rol);
    }

    // ---------------------------------------------------------------- (f) nombres

    public function test_los_nombres_son_ficticios_y_no_incluyen_prospectos_reales_ni_voseo(): void
    {
        $this->crear();
        $demo = $this->demo();

        $textos = collect([$demo->razon_social, $demo->nombre_comercial, $demo->dir_matriz, $demo->nota_pie])
            ->merge(Contact::where('company_id', $demo->id)->get()->flatMap(fn ($c) => [$c->razon_social, $c->nombre_comercial, $c->direccion, $c->email]))
            ->merge(Product::where('company_id', $demo->id)->get()->flatMap(fn ($p) => [$p->descripcion, $p->ubicacion]))
            ->merge(Bank::where('company_id', $demo->id)->pluck('nombre'))
            ->merge(Warehouse::where('company_id', $demo->id)->pluck('nombre'))
            ->merge(Branch::where('company_id', $demo->id)->get()->flatMap(fn ($b) => [$b->nombre, $b->direccion]))
            ->merge(EmissionPoint::where('company_id', $demo->id)->pluck('nombre'))
            ->merge(BankMovement::where('company_id', $demo->id)->pluck('concepto'))
            ->merge(Purchase::where('company_id', $demo->id)->pluck('observacion'))
            ->merge(CreditNote::where('company_id', $demo->id)->pluck('motivo'))
            ->merge(InventoryMovement::where('company_id', $demo->id)->pluck('concepto'))
            ->filter()->map(fn ($t) => Str::lower((string) $t));

        $this->assertGreaterThan(40, $textos->count());

        $prohibidos = ['piston', 'san rafael', 'taller de motos', 'laboratorio', 'emily', 'armendariz',
            'pichincha', 'distribuidora tecnologica', 'prueba test', 'empresa demo s.a.'];
        // Formas de voseo (con su tilde): la interfaz es español neutro de Ecuador, en «tú»
        $voseo = '/\b(vos|sos|tenés|podés|querés|sabés|hacé|mirá|usá|elegí|ponés|fijate|ponete)\b/u';
        foreach ($textos as $t) {
            foreach ($prohibidos as $p) {
                $this->assertStringNotContainsString($p, Str::ascii($t), "Texto con un nombre que no debe aparecer: «{$t}»");
            }
            $this->assertDoesNotMatchRegularExpression($voseo, $t, "Voseo en: «{$t}»");
        }
        $this->assertStringContainsString('DEMO HASRESET', $demo->razon_social);
    }

    // ---------------------------------------------------------------- ayudas

    private function crear(): void
    {
        $this->artisan('demo:crear')->assertExitCode(0);
    }

    private function demo(): Company
    {
        return Company::where('ruc', self::RUC_DEMO)->firstOrFail();
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $c = [];
        foreach (self::TABLAS as $tabla) {
            $c[$tabla] = DB::table($tabla)->count();
        }

        return $c;
    }

    /** Foto de lo que ya existía antes del comando: empresa, catálogo, usuarios y numeración. */
    private function fotoDeLaOtra(): array
    {
        $id = $this->otra->id;

        return [
            'empresa' => Company::find($id)->getAttributes(),
            'productos' => Product::where('company_id', $id)->orderBy('id')->get()->toArray(),
            'contactos' => Contact::where('company_id', $id)->orderBy('id')->get()->toArray(),
            'bodegas' => Warehouse::where('company_id', $id)->orderBy('id')->get()->toArray(),
            'cuentas' => Account::where('company_id', $id)->orderBy('id')->get()->toArray(),
            'usuarios' => User::orderBy('id')->get(['id', 'email', 'password', 'company_id', 'rol', 'activo'])->toArray(),
        ];
    }

    /** Saldo Debe - Haber de la cuenta de un concepto. */
    private function saldo(Company $empresa, string $concepto): float
    {
        $cuenta = Account::where('company_id', $empresa->id)->where('codigo', Cuentas::codigo($concepto))->first();
        if (! $cuenta) {
            return 0.0;
        }
        $l = DB::table('journal_entry_lines')->where('account_id', $cuenta->id);

        return round((float) $l->sum('debe') - (float) $l->sum('haber'), 2);
    }
}
