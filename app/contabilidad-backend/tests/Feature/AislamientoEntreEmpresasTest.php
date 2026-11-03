<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTenant;
use App\Models\Company;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T11.6a: un usuario de la empresa A no ve ni toca nada de la empresa B, pase lo que pase por la petición:
 * `company_id` ajeno en la consulta o en el cuerpo, el id de un registro de B en la URL, o el id de un registro de B dentro de
 * un campo (`contact_id`, `items.*.warehouse_id`...). Si la petición no trae `company_id` se le pone el suyo, de modo que un
 * controlador que filtra por empresa nunca devuelve las filas de todos.
 *
 * El superadministrador (operador de la plataforma) y el usuario sin empresa no quedan restringidos.
 */
class AislamientoEntreEmpresasTest extends AislamientoTestCase
{
    private const NEGADO = 'No tienes acceso a esa empresa.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids['a']['empresa'] = $this->a->id;
        $this->ids['b']['empresa'] = $this->b->id;
    }

    // ------------------------------------------------------------------ listas con company_id de otra empresa

    /** @return array<string,array{string}> */
    public static function listas(): array
    {
        $uris = [
            'products', 'contacts', 'invoices', 'purchases', 'journal', 'employees', 'fixed-assets', 'physical-counts', 'credit-notes',
            'quotes', 'accounts', 'banks', 'warehouses', 'emission-points', 'advances', 'payrolls', 'cost-centers', 'branches', 'users',
            'audit', 'audit/opciones', 'dashboard/resumen', 'inventory/stock', 'inventory/reorder', 'inventory/movimientos',
            'receivables', 'payables', 'bank-movements', 'withholdings', 'notas-debito', 'guias-remision', 'sri-documents/pending',
            'stock-reservations', 'employee-leaves', 'employee-movements', 'cash/current', 'deposits', 'estado-cuenta/clientes',
            'estado-cuenta/proveedores', 'taxes/formulario104', 'ledger', 'income-statement', 'balance-sheet', 'card/pendientes',
            'liquidacion-compra', 'pending-imports', 'accounting/periodos', 'series', 'withholdings-emitted', 'catalogos/formas-pago',
        ];

        return array_combine($uris, array_map(fn ($u) => [$u], $uris));
    }

    #[DataProvider('listas')]
    public function test_listar_con_company_id_de_otra_empresa_da_403(string $uri): void
    {
        $this->confinado();

        $this->getJson("/api/$uri?company_id={$this->b->id}")->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    // ------------------------------------------------------------------ crear / cambiar con company_id de otra empresa en el cuerpo

    /** @return array<string,array{string,string}> */
    public static function escriturasConEmpresaAjena(): array
    {
        $casos = [
            ['POST', 'products'], ['POST', 'contacts'], ['POST', 'invoices'], ['POST', 'purchases'], ['POST', 'journal-entries'],
            ['POST', 'employees'], ['POST', 'fixed-assets'], ['POST', 'physical-counts'], ['POST', 'quotes'], ['POST', 'accounts'],
            ['POST', 'banks'], ['POST', 'warehouses'], ['POST', 'cost-centers'], ['POST', 'advances'], ['POST', 'credit-notes'],
            ['POST', 'emission-points'], ['POST', 'users'], ['POST', 'stock-reservations'], ['POST', 'branches'], ['POST', 'inventory/ajuste'],
            ['POST', 'inventory/transferencia'], ['POST', 'cash/open'], ['POST', 'bank-movements'], ['POST', 'accounting/cierre-ejercicio'],
            ['PUT', 'contacts/{a.contacto}'], ['PUT', 'products/{a.producto}'], ['POST', 'invoices/{a.factura}/anular'],
        ];

        return array_combine(array_map(fn ($c) => "$c[0] $c[1]", $casos), $casos);
    }

    #[DataProvider('escriturasConEmpresaAjena')]
    public function test_escribir_con_company_id_de_otra_empresa_en_el_cuerpo_da_403(string $metodo, string $uri): void
    {
        $this->confinado();

        $this->json($metodo, '/api/'.$this->ruta($uri), ['company_id' => $this->b->id])
            ->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    public function test_company_id_ajeno_en_la_consulta_con_un_cuerpo_que_dice_otra_cosa_tambien_da_403(): void
    {
        $this->confinado();

        // El cuerpo dice A y la consulta dice B: el controlador podría leer cualquiera de los dos
        $this->json('POST', "/api/products?company_id={$this->b->id}", ['company_id' => $this->a->id])
            ->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    public function test_company_id_ajeno_anidado_o_en_forma_de_arreglo_da_403(): void
    {
        $this->confinado();

        $this->postJson('/api/invoices', ['company_id' => $this->a->id, 'lineas' => [['company_id' => $this->b->id]]])->assertForbidden();
        $this->getJson("/api/products?company_id[]={$this->b->id}")->assertForbidden();
        $this->getJson("/api/products?company_id=0")->assertForbidden();
        $this->getJson("/api/products?company_id=0{$this->a->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ registros de B por la URL

    /** @return array<string,array{string,string}> */
    public static function registrosDeB(): array
    {
        $casos = [
            ['GET', 'fixed-assets/{b.activo_fijo}'], ['PUT', 'fixed-assets/{b.activo_fijo}'], ['DELETE', 'fixed-assets/{b.activo_fijo}'],
            ['POST', 'fixed-assets/{b.activo_fijo}/baja'],
            ['GET', 'physical-counts/{b.toma}'], ['PUT', 'physical-counts/{b.toma}'], ['DELETE', 'physical-counts/{b.toma}'],
            ['POST', 'physical-counts/{b.toma}/aplicar'], ['GET', 'physical-counts/{b.toma}/hoja'],
            ['PUT', 'contacts/{b.contacto}'], ['DELETE', 'contacts/{b.contacto}'],
            ['PUT', 'products/{b.producto}'], ['PATCH', 'products/{b.producto}'], ['DELETE', 'products/{b.producto}'],
            ['GET', 'products/{b.producto}/prices'], ['POST', 'products/{b.producto}/prices'], ['DELETE', 'product-prices/{b.precio}'],
            ['GET', 'inventory/kardex/{b.producto}'],
            ['POST', 'invoices/{b.factura}/anular'], ['GET', 'invoices/{b.factura}/ride'],
            ['PUT', 'purchases/{b.compra}'], ['DELETE', 'purchases/{b.compra}'], ['POST', 'payables/{b.compra}/pay'],
            ['PUT', 'journal-entries/{b.asiento}'], ['DELETE', 'journal-entries/{b.asiento}'],
            ['POST', 'journal-entries/{b.asiento}/contabilizar'], ['POST', 'journal/{b.asiento}/desmayorizar'],
            ['PUT', 'employees/{b.empleado}'], ['DELETE', 'employees/{b.empleado}'], ['GET', 'employees/{b.empleado}/vacaciones'],
            ['POST', 'employees/{b.empleado}/liquidacion'],
            ['PUT', 'quotes/{b.cotizacion}'], ['DELETE', 'quotes/{b.cotizacion}'], ['POST', 'quotes/{b.cotizacion}/convert'],
            ['POST', 'credit-notes/{b.nota}/anular'], ['GET', 'credit-notes/{b.nota}/ride'], ['POST', 'credit-notes/{b.nota}/emit'],
            ['PUT', 'warehouses/{b.bodega}'], ['DELETE', 'warehouses/{b.bodega}'],
            ['PUT', 'accounts/{b.cuenta}'], ['DELETE', 'accounts/{b.cuenta}'],
            ['PUT', 'emission-points/{b.punto}'], ['DELETE', 'emission-points/{b.punto}'],
            ['PUT', 'users/{b.usuario}'], ['DELETE', 'users/{b.usuario}'],
            ['PUT', 'advances/{b.anticipo}'], ['DELETE', 'advances/{b.anticipo}'],
            ['PUT', 'cost-centers/{b.centro}'], ['DELETE', 'cost-centers/{b.centro}'],
            ['PUT', 'branches/{b.sucursal}'], ['DELETE', 'branches/{b.sucursal}'],
            ['PUT', 'banks/{b.banco}'], ['DELETE', 'banks/{b.banco}'],
            ['GET', 'payrolls/{b.rol}'], ['POST', 'payrolls/{b.rol}/close'],
            ['GET', 'estado-cuenta/clientes/{b.contacto}'], ['GET', 'estado-cuenta/proveedores/{b.proveedor}'],
            ['GET', 'companies/{b.empresa}'], ['PUT', 'companies/{b.empresa}'], ['GET', 'companies/{b.empresa}/plan'],
            ['POST', 'companies/{b.empresa}/logo'], ['GET', 'companies/{b.empresa}/edoc-config'], ['POST', 'companies/{b.empresa}/edoc-config'],
            ['POST', 'companies/{b.empresa}/smtp/test'], ['POST', 'companies/{b.empresa}/certificate'], ['POST', 'companies/{b.empresa}/plan'],
        ];

        return array_combine(array_map(fn ($c) => "$c[0] $c[1]", $casos), $casos);
    }

    #[DataProvider('registrosDeB')]
    public function test_el_registro_de_otra_empresa_da_403_sin_company_id(string $metodo, string $uri): void
    {
        $this->confinado();

        $this->json($metodo, '/api/'.$this->ruta($uri))->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    #[DataProvider('registrosDeB')]
    public function test_el_registro_de_otra_empresa_da_403_aunque_la_peticion_diga_que_es_de_la_suya(string $metodo, string $uri): void
    {
        $this->confinado();

        // Disfrazar la petición con el company_id propio no sirve: el registro es de B
        $this->json($metodo, '/api/'.$this->ruta($uri), ['company_id' => $this->a->id])
            ->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    public function test_un_id_que_no_existe_responde_igual_que_uno_de_otra_empresa(): void
    {
        $this->confinado();

        // No se revela si el id existe en otra empresa: el mismo 403 y el mismo cuerpo
        $ajeno = $this->getJson('/api/fixed-assets/'.$this->ids['b']['activo_fijo'])->assertForbidden();
        $inexistente = $this->getJson('/api/fixed-assets/987654')->assertForbidden();
        $this->assertSame($ajeno->json(), $inexistente->json());

        $this->putJson('/api/contacts/987654', [])->assertForbidden()->assertJsonPath('message', self::NEGADO);
        $this->getJson('/api/companies/987654')->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    public function test_una_ruta_que_no_existe_sigue_dando_404(): void
    {
        $this->confinado();

        $this->getJson('/api/no-existe')->assertNotFound();
    }

    // ------------------------------------------------------------------ ids de B dentro de otros campos

    /** @return array<string,array{string,string,array}> */
    public static function referenciasAjenas(): array
    {
        $item = ['codigo_principal' => 'P-A', 'descripcion' => 'x', 'cantidad' => 1, 'precio_unitario' => 1, 'tarifa' => 15];

        return [
            'factura con cliente de B' => ['POST', 'invoices', ['contact_id' => '{b.contacto}', 'items' => [$item]]],
            'factura con bodega de B en un renglón' => ['POST', 'invoices', ['contact_id' => '{a.contacto}', 'items' => [$item + ['warehouse_id' => '{b.bodega}']]]],
            'factura con punto de emisión de B' => ['POST', 'invoices', ['contact_id' => '{a.contacto}', 'emission_point_id' => '{b.punto}', 'items' => [$item]]],
            'compra con proveedor de B' => ['POST', 'purchases', ['contact_id' => '{b.proveedor}', 'items' => [$item]]],
            'ajuste con artículo de B' => ['POST', 'inventory/ajuste', ['product_id' => '{b.producto}', 'warehouse_id' => '{a.bodega}', 'cantidad' => 1]],
            'transferencia a bodega de B' => ['POST', 'inventory/transferencia', ['product_id' => '{a.producto}', 'warehouse_origen_id' => '{a.bodega}', 'warehouse_destino_id' => '{b.bodega}', 'cantidad' => 1]],
            'nota de crédito sobre factura de B' => ['POST', 'credit-notes', ['contact_id' => '{a.contacto}', 'invoice_id' => '{b.factura}']],
            'toma física en bodega de B' => ['POST', 'physical-counts', ['warehouse_id' => '{b.bodega}']],
            'reserva de artículo de B' => ['POST', 'stock-reservations', ['product_id' => '{b.producto}', 'quantity' => 1]],
            'anticipo de cliente de B' => ['POST', 'advances', ['contact_id' => '{b.contacto}', 'monto' => 5]],
            'cotizaciones de B en una factura' => ['POST', 'quotes/convert-many', ['quote_ids' => ['{b.cotizacion}']]],
            'líneas de toma con artículo de B' => ['PUT', 'physical-counts/{a.toma}', ['lineas' => [['product_id' => '{b.producto}', 'cantidad_contada' => 1]]]],
            'usuario con punto de emisión de B' => ['POST', 'users', ['name' => 'X', 'email' => 'x@y.test', 'password' => 'clave-segura-1', 'rol' => 'cajero', 'emission_point_id' => '{b.punto}']],
            'pago con cuenta bancaria de B' => ['POST', 'payables/{a.compra}/pay', ['bank_id' => '{b.banco}', 'monto' => 1]],
            'pago a varias compras, una de B' => ['POST', 'payables/pay-multiple', ['pagos' => [['purchase_id' => '{b.compra}', 'monto' => 1]]]],
            'asiento manual con cuenta de B' => ['POST', 'journal-entries', ['cuenta_contable_id' => '{b.cuenta}']],
        ];
    }

    #[DataProvider('referenciasAjenas')]
    public function test_un_id_de_otra_empresa_dentro_de_un_campo_da_403(string $metodo, string $uri, array $cuerpo): void
    {
        $this->confinado();

        $this->json($metodo, '/api/'.$this->ruta($uri), $this->reemplazar($cuerpo) + ['company_id' => $this->a->id])
            ->assertForbidden()->assertJsonPath('message', self::NEGADO);
    }

    public function test_un_id_de_otra_empresa_en_la_consulta_da_403(): void
    {
        $this->confinado();

        $this->getJson('/api/audit?user_id='.$this->ids['b']['usuario'])->assertForbidden();
        $this->getJson('/api/series?warehouse_id='.$this->ids['b']['bodega'])->assertForbidden();
        $this->getJson('/api/estado-cuenta/clientes?contact_id='.$this->ids['b']['contacto'])->assertForbidden();
    }

    public function test_un_id_propio_dentro_de_un_campo_no_se_toca(): void
    {
        $this->confinado();

        // Ningún 403 del aislamiento: sea lo que responda la validación de negocio, no es «No tienes acceso a esa empresa.»
        $r = $this->postJson('/api/physical-counts', ['company_id' => $this->a->id, 'warehouse_id' => $this->ids['a']['bodega']]);
        $this->assertNotSame(403, $r->status());
    }

    // ------------------------------------------------------------------ lo que el middleware no puede cerrar (riesgos residuales verificados)

    public function test_barrido_ninguna_ruta_get_sin_parametros_deja_ver_datos_de_otra_empresa(): void
    {
        $this->confinado();

        $fugas = [];
        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/') || ! in_array('GET', $ruta->methods(), true) || str_contains($ruta->uri(), '{')) {
                continue;
            }
            if (str_contains($this->getJson('/'.$ruta->uri())->getContent(), 'MARCA-B')) {
                $fugas[] = $ruta->uri();
            }
        }

        // La fuga que quedaba (AuditController::opciones mostraba los usuarios de TODAS las empresas en el combo «usuarios») se cerró
        // filtrando por empresa en el controlador: ninguna ruta GET puede dejar ver datos de otra empresa.
        $this->assertSame([], $fugas, 'Una ruta GET dejó ver datos de otra empresa.');
    }

    public function test_el_id_de_un_saldo_de_otra_empresa_no_lo_ve_el_middleware_pero_lo_rechaza_el_controlador(): void
    {
        $this->confinado();

        // `id` + `tipo` no se puede mapear a una tabla desde un middleware: CreditApplicationController::apply compara la empresa del saldo con la de la factura
        $this->postJson('/api/credits/apply/'.$this->ids['a']['factura'], ['company_id' => $this->a->id, 'tipo' => 'anticipo', 'id' => $this->ids['b']['anticipo'], 'monto' => 1])
            ->assertStatus(422)->assertJsonPath('errors.id.0', 'Ese saldo es de otra empresa.');
    }

    // ------------------------------------------------------------------ sin company_id: se aplica la propia

    /** @return array<string,array{string}> */
    public static function listasPorEmpresa(): array
    {
        $uris = ['contacts', 'products', 'accounts', 'banks', 'warehouses', 'emission-points', 'employees', 'fixed-assets', 'physical-counts',
            'cost-centers', 'branches', 'users', 'quotes', 'credit-notes', 'journal', 'purchases', 'invoices', 'advances', 'audit'];

        return array_combine($uris, array_map(fn ($u) => [$u], $uris));
    }

    #[DataProvider('listasPorEmpresa')]
    public function test_sin_company_id_la_lista_trae_solo_las_filas_de_la_propia_empresa(string $uri): void
    {
        $this->confinado();

        $r = $this->getJson("/api/$uri")->assertOk();

        $this->assertStringNotContainsString('MARCA-B', $r->getContent(), "GET /api/$uri sin company_id dejó ver datos de otra empresa");
    }

    public function test_sin_company_id_las_listas_traen_lo_de_la_propia_empresa(): void
    {
        $this->confinado();

        foreach (['contacts', 'products', 'accounts', 'banks', 'warehouses', 'emission-points', 'employees', 'fixed-assets', 'cost-centers', 'quotes'] as $uri) {
            $r = $this->getJson("/api/$uri")->assertOk();
            $this->assertStringContainsString('MARCA-A', $r->getContent().json_encode($this->ids['a']), "GET /api/$uri sin company_id");
        }
        $this->assertStringContainsString('Cliente MARCA-A', $this->getJson('/api/contacts')->assertOk()->getContent());
        $this->assertStringContainsString('Producto MARCA-A', $this->getJson('/api/products')->assertOk()->getContent());
    }

    public function test_el_plan_y_los_modulos_se_calculan_con_la_empresa_propia_aunque_no_se_pida(): void
    {
        $this->confinado();
        $this->a->update(['plan' => 'basico']);

        // `feature:contabilidad` mira company_id: sin él antes se saltaba el plan. Ahora lo evalúa con la empresa del usuario
        $this->getJson('/api/cost-centers')->assertStatus(402);
    }

    // ------------------------------------------------------------------ lo propio sigue funcionando

    /** @return array<string,array{string,string,int}> */
    public static function propio(): array
    {
        $casos = [
            ['GET', 'contacts?company_id={a.empresa}'], ['GET', 'products?company_id={a.empresa}'], ['GET', 'invoices?company_id={a.empresa}'],
            ['GET', 'purchases?company_id={a.empresa}'], ['GET', 'journal?company_id={a.empresa}'], ['GET', 'employees?company_id={a.empresa}'],
            ['GET', 'fixed-assets?company_id={a.empresa}'], ['GET', 'physical-counts?company_id={a.empresa}'],
            ['GET', 'credit-notes?company_id={a.empresa}'], ['GET', 'quotes?company_id={a.empresa}'], ['GET', 'accounts?company_id={a.empresa}'],
            ['GET', 'banks?company_id={a.empresa}'], ['GET', 'warehouses?company_id={a.empresa}'], ['GET', 'emission-points?company_id={a.empresa}'],
            ['GET', 'advances?company_id={a.empresa}'], ['GET', 'cost-centers?company_id={a.empresa}'], ['GET', 'users?company_id={a.empresa}'],
            ['GET', 'companies'], ['GET', 'companies/{a.empresa}'], ['GET', 'companies/{a.empresa}/plan'], ['GET', 'companies/{a.empresa}/edoc-config'],
            ['GET', 'fixed-assets/{a.activo_fijo}?company_id={a.empresa}'], ['GET', 'physical-counts/{a.toma}?company_id={a.empresa}'],
            ['GET', 'products/{a.producto}/prices'], ['GET', 'user'], ['GET', 'dashboard/resumen?company_id={a.empresa}'],
        ];

        return array_combine(array_map(fn ($c) => "$c[0] $c[1]", $casos), $casos);
    }

    #[DataProvider('propio')]
    public function test_el_mismo_usuario_llega_a_todo_lo_de_su_empresa(string $metodo, string $uri): void
    {
        $this->confinado();

        $this->json($metodo, '/api/'.$this->ruta($uri))->assertSuccessful();
    }

    public function test_el_usuario_confinado_edita_y_borra_registros_de_su_empresa(): void
    {
        $this->confinado();

        $this->putJson('/api/contacts/'.$this->ids['a']['contacto'], ['company_id' => $this->a->id, 'razon_social' => 'Cliente renombrado', 'tipo_identificacion' => '05', 'identificacion' => '1700000001'])
            ->assertOk();
        $this->assertDatabaseHas('contacts', ['id' => $this->ids['a']['contacto'], 'razon_social' => 'Cliente renombrado']);
        $this->assertDatabaseHas('contacts', ['id' => $this->ids['b']['contacto'], 'razon_social' => 'Cliente MARCA-B']);
        $this->deleteJson('/api/cost-centers/'.$this->ids['a']['centro'])->assertSuccessful();
    }

    public function test_el_cajero_y_el_contador_de_una_empresa_tambien_quedan_confinados(): void
    {
        foreach (['cajero', 'contador'] as $rol) {
            $this->confinado($rol);
            $this->getJson("/api/contacts?company_id={$this->b->id}")->assertForbidden()->assertJsonPath('message', self::NEGADO);
            $this->getJson('/api/contacts')->assertOk();
        }
    }

    // ------------------------------------------------------------------ quien no queda confinado

    public function test_el_superadmin_llega_a_todas_las_empresas(): void
    {
        $this->superadmin();

        foreach (['contacts', 'products', 'fixed-assets', 'accounts', 'employees'] as $uri) {
            $r = $this->getJson("/api/$uri?company_id={$this->b->id}")->assertOk();
            $this->assertStringContainsString('MARCA-B', $r->getContent());
            $this->assertStringNotContainsString('MARCA-A', $r->getContent());
        }
        $this->getJson('/api/fixed-assets/'.$this->ids['b']['activo_fijo'].'?company_id='.$this->b->id)->assertOk();
        $this->getJson('/api/physical-counts/'.$this->ids['b']['toma'].'?company_id='.$this->b->id)->assertOk();
        $this->getJson('/api/companies/'.$this->b->id)->assertOk();
        $this->getJson('/api/companies/'.$this->b->id.'/plan')->assertOk();
        $this->getJson('/api/products/'.$this->ids['b']['producto'].'/prices')->assertOk();
        $this->putJson('/api/contacts/'.$this->ids['b']['contacto'], ['company_id' => $this->b->id, 'razon_social' => 'Cliente B nuevo', 'tipo_identificacion' => '05', 'identificacion' => '1700000002'])
            ->assertOk();
        $this->assertCount(2, $this->getJson('/api/companies')->assertOk()->json());
    }

    public function test_el_superadmin_sin_company_id_no_recibe_ninguna_empresa_impuesta(): void
    {
        $this->superadmin();

        // Sin company_id el superadmin NO queda atado a su empresa: nada se le inyecta
        $this->assertStringContainsString('MARCA-A', $this->getJson('/api/contacts')->assertOk()->getContent());
        $this->assertStringContainsString('MARCA-B', $this->getJson('/api/contacts')->assertOk()->getContent());
    }

    public function test_el_usuario_sin_empresa_no_queda_restringido(): void
    {
        $this->sinEmpresa();

        $this->getJson("/api/contacts?company_id={$this->b->id}")->assertOk();
        $this->getJson('/api/fixed-assets/'.$this->ids['b']['activo_fijo'].'?company_id='.$this->b->id)->assertOk();
        $this->assertCount(2, $this->getJson('/api/companies')->assertOk()->json());
    }

    // ------------------------------------------------------------------ guardas para que el aislamiento no se rompa en silencio

    public function test_todo_modelo_que_una_ruta_liga_por_su_id_se_ubica_en_una_empresa(): void
    {
        $sinEmpresa = [];
        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }
            foreach ($ruta->signatureParameters(['subClass' => UrlRoutable::class]) as $p) {
                $clase = $p->getType()->getName();
                $modelo = new $clase;
                $tiene = $clase === Company::class
                    || Schema::hasColumn($modelo->getTable(), 'company_id')
                    || Schema::hasColumn($modelo->getTable(), 'product_id');
                if (! $tiene) {
                    $sinEmpresa[$clase] = $ruta->uri();
                }
            }
        }

        $this->assertSame([], $sinEmpresa, 'Estos modelos se ligan por la URL pero no se pueden ubicar en una empresa (company_id o product_id): EnforceTenant no los protege.');
    }

    public function test_las_rutas_con_parametros_sin_modelo_estan_revisadas(): void
    {
        // Parámetros que NO se ligan a un modelo: el aislamiento depende de que el controlador compare con company_id (que EnforceTenant fija).
        $revisadas = ['tipo', 'id'];   // comprobantes/{tipo}/{id}: ComprobanteCorreoController::resolver exige que el documento sea de company_id
        $crudos = [];
        foreach (Route::getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }
            $ligados = array_map(fn ($p) => $p->getName(), $ruta->signatureParameters(['subClass' => UrlRoutable::class]));
            foreach ($ruta->parameterNames() as $nombre) {
                if (! in_array($nombre, $ligados, true) && ! in_array($nombre, $revisadas, true)) {
                    $crudos[] = $ruta->uri().' {'.$nombre.'}';
                }
            }
        }

        $this->assertSame([], $crudos, 'Una ruta nueva con un parámetro que no es un modelo: revisa que su controlador lo compare con company_id.');
    }

    public function test_cada_clave_id_que_leen_los_controladores_esta_clasificada(): void
    {
        $dir = app_path('Http/Controllers');
        $claves = [];
        foreach (glob("$dir/*.php") as $archivo) {
            $codigo = file_get_contents($archivo);
            // 'contact_id' => ..., 'items.*.warehouse_id' => ..., $r->input('bank_id'), $r->product_id
            preg_match_all("/['\"]([a-z_.*]*?_ids?)['\"]\\s*=>|input\\(['\"]([a-z_.*]*_ids?)['\"]|query\\(['\"]([a-z_.*]*_ids?)['\"]|->([a-z_]+_ids?)\\b/", $codigo, $m);
            foreach (array_merge($m[1], $m[2], $m[3], $m[4]) as $clave) {
                if ($clave !== '') {
                    $claves[basename($archivo)][] = last(explode('.', $clave));
                }
            }
        }

        $conocidas = array_merge(array_keys(EnforceTenant::REFERENCIAS), EnforceTenant::SIN_REFERENCIA, ['company_id']);
        $sinClasificar = [];
        foreach ($claves as $archivo => $lista) {
            foreach (array_unique($lista) as $clave) {
                if (! in_array($clave, $conocidas, true)) {
                    $sinClasificar[$clave][] = $archivo;
                }
            }
        }

        $this->assertSame([], $sinClasificar, 'Una clave *_id nueva: ponla en EnforceTenant::REFERENCIAS (apunta a un registro de una empresa) o en SIN_REFERENCIA.');
    }

    public function test_las_tablas_de_las_referencias_llevan_company_id(): void
    {
        foreach (array_unique(EnforceTenant::REFERENCIAS) as $tabla) {
            $this->assertTrue(Schema::hasColumn($tabla, 'company_id'), "La tabla $tabla no tiene company_id: EnforceTenant no puede comparar sus ids.");
        }
    }
}
