<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base de las pruebas de aislamiento entre empresas (T11.6).
 *
 * Dos empresas con la misma batería de registros: A (`$this->a`) y B (`$this->b`). Cada registro lleva en su texto una marca
 * (`MARCA-A` / `MARCA-B`) para que una prueba pueda decir «en la respuesta hay datos de A y ninguno de B» sin depender del
 * formato de cada lista. Los ids de cada registro quedan en `$this->ids['a'|'b'][clave]`.
 *
 * Los usuarios llevan SIEMPRE `company_id` y `es_superadmin` explícitos: lo que se prueba es justamente quién queda confinado.
 */
abstract class AislamientoTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $a;
    protected Company $b;

    /** @var array{a: array<string,int>, b: array<string,int>} */
    protected array $ids = ['a' => [], 'b' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->empresa('1790000000001', 'Empresa A SA', 'A');
        $this->b = $this->empresa('1791234567001', 'Empresa B SA', 'B');
        $this->ids['a'] = $this->sembrar($this->a, 'A');
        $this->ids['b'] = $this->sembrar($this->b, 'B');
    }

    protected function empresa(string $ruc, string $nombre, string $letra): Company
    {
        return Company::create([
            'ruc' => $ruc, 'razon_social' => $nombre, 'dir_matriz' => "Av. $letra 1", 'estab' => '001', 'pto_emi' => '001', 'plan' => 'completo',
        ]);
    }

    /** Usuario confinado a la empresa A (o a la indicada): el cliente normal. */
    protected function confinado(string $rol = 'admin', ?Company $empresa = null): User
    {
        $u = User::factory()->create([
            'rol' => $rol, 'activo' => true, 'company_id' => ($empresa ?? $this->a)->id, 'es_superadmin' => false,
            'name' => "Cliente $rol", 'email' => $rol.'.'.uniqid().'@cliente.test',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    /** El operador de la plataforma. Tiene empresa propia (la A), pero no queda confinado a ella. */
    protected function superadmin(): User
    {
        $u = User::factory()->create([
            'rol' => 'admin', 'activo' => true, 'company_id' => $this->a->id, 'es_superadmin' => true,
            'name' => 'Operador', 'email' => 'operador.'.uniqid().'@plataforma.test',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    /** Usuario sin empresa (convención de las pruebas antiguas y de cuentas viejas): sin restricción. */
    protected function sinEmpresa(string $rol = 'admin'): User
    {
        $u = User::factory()->create(['rol' => $rol, 'activo' => true, 'company_id' => null]);
        Sanctum::actingAs($u);

        return $u;
    }

    /** Reemplaza `{a.clave}` y `{b.clave}` por el id real del registro. */
    protected function ruta(string $plantilla): string
    {
        return preg_replace_callback('/\{(a|b)\.(\w+)\}/', fn ($m) => (string) $this->ids[$m[1]][$m[2]], $plantilla);
    }

    protected function reemplazar(mixed $datos): mixed
    {
        if (is_string($datos)) {
            return $this->ruta($datos);
        }
        if (is_array($datos)) {
            return array_map(fn ($v) => $this->reemplazar($v), $datos);
        }

        return $datos;
    }

    /**
     * La batería de registros de una empresa, creada directo en la base (sin pasar por los controladores).
     * Los textos llevan la marca de la empresa.
     *
     * @return array<string,int>
     */
    protected function sembrar(Company $e, string $m): array
    {
        $cid = $e->id;
        $ahora = now();
        $t = ['created_at' => $ahora, 'updated_at' => $ahora];
        $i = [];

        $i['contacto'] = DB::table('contacts')->insertGetId([
            'company_id' => $cid, 'identificacion' => ($m === 'A' ? '1700000001' : '1700000002'), 'razon_social' => "Cliente MARCA-$m",
            'tipo_identificacion' => '05', 'es_cliente' => true, 'es_proveedor' => true,
        ] + $t);
        $i['proveedor'] = DB::table('contacts')->insertGetId([
            'company_id' => $cid, 'identificacion' => ($m === 'A' ? '1790011223001' : '1790011224001'), 'razon_social' => "Proveedor MARCA-$m",
            'tipo_identificacion' => '04', 'es_cliente' => false, 'es_proveedor' => true,
        ] + $t);
        $i['producto'] = DB::table('products')->insertGetId([
            'company_id' => $cid, 'codigo' => "P-$m", 'descripcion' => "Producto MARCA-$m", 'tipo' => 'bien', 'precio' => 10,
        ] + $t);
        $i['bodega'] = DB::table('warehouses')->insertGetId([
            'company_id' => $cid, 'codigo' => 'B01', 'nombre' => "Bodega MARCA-$m", 'por_defecto' => true, 'activa' => true,
        ] + $t);
        $i['banco'] = DB::table('banks')->insertGetId(['company_id' => $cid, 'nombre' => "Banco MARCA-$m"] + $t);
        $i['cuenta'] = DB::table('accounts')->insertGetId([
            'company_id' => $cid, 'codigo' => '1.1.01', 'nombre' => "Cuenta MARCA-$m", 'tipo' => 'activo',
        ] + $t);
        $i['punto'] = DB::table('emission_points')->insertGetId([
            'company_id' => $cid, 'estab' => '001', 'punto' => '001', 'nombre' => "Punto MARCA-$m", 'secuencial' => 1,
        ] + $t);
        $i['centro'] = DB::table('cost_centers')->insertGetId(['company_id' => $cid, 'codigo' => 'CC1', 'nombre' => "Centro MARCA-$m"] + $t);
        $i['sucursal'] = DB::table('branches')->insertGetId(['company_id' => $cid, 'estab' => '001', 'nombre' => "Sucursal MARCA-$m"] + $t);

        $items = json_encode([['codigo_principal' => "P-$m", 'descripcion' => "Item MARCA-$m", 'cantidad' => 1, 'precio_unitario' => 100, 'tarifa' => 15]]);
        $totales = ['total_sin_impuestos' => 100, 'total_impuesto' => 15, 'importe_total' => 115];
        $i['factura'] = DB::table('invoices')->insertGetId([
            'company_id' => $cid, 'contact_id' => $i['contacto'], 'numero' => "001-001-MARCA-$m", 'estado' => 'emitida',
            'fecha_emision' => $ahora, 'items' => $items, 'saldo_pendiente' => 115, 'forma_pago' => 'credito',
        ] + $totales + $t);
        $i['compra'] = DB::table('purchases')->insertGetId([
            'company_id' => $cid, 'contact_id' => $i['proveedor'], 'numero' => "COMPRA-MARCA-$m", 'fecha_emision' => $ahora->toDateString(),
            'items' => $items, 'saldo_pendiente' => 115,
        ] + $totales + $t);
        $i['cotizacion'] = DB::table('quotes')->insertGetId([
            'company_id' => $cid, 'contact_id' => $i['contacto'], 'items' => $items,
        ] + $totales + $t);
        $i['nota'] = DB::table('credit_notes')->insertGetId([
            'company_id' => $cid, 'contact_id' => $i['contacto'], 'invoice_id' => $i['factura'], 'tipo' => 'interna', 'numero' => "NC-MARCA-$m",
            'fecha' => $ahora->toDateString(), 'motivo' => "Motivo MARCA-$m", 'importe_total' => 11.5,
        ] + $t);
        $i['asiento'] = DB::table('journal_entries')->insertGetId([
            'company_id' => $cid, 'numero' => "AS-MARCA-$m", 'fecha' => $ahora->toDateString(), 'concepto' => "Asiento MARCA-$m",
            'total_debe' => 10, 'total_haber' => 10,
        ] + $t);
        $i['empleado'] = DB::table('employees')->insertGetId([
            'company_id' => $cid, 'cedula' => ($m === 'A' ? '1710000001' : '1710000002'), 'nombres' => "Empleado MARCA-$m",
            'fecha_ingreso' => '2026-01-01', 'sueldo' => 500,
        ] + $t);
        $i['activo_fijo'] = DB::table('fixed_assets')->insertGetId([
            'company_id' => $cid, 'codigo' => "AF-$m", 'nombre' => "Activo MARCA-$m", 'grupo' => 'equipo_computacion',
            'fecha_adquisicion' => '2026-01-01', 'costo' => 1200, 'vida_util_meses' => 36, 'fecha_inicio_depreciacion' => '2026-01-01',
        ] + $t);
        $i['toma'] = DB::table('physical_counts')->insertGetId([
            'company_id' => $cid, 'warehouse_id' => $i['bodega'], 'numero' => "TF-MARCA-$m", 'fecha' => $ahora->toDateString(),
        ] + $t);
        $i['anticipo'] = DB::table('advances')->insertGetId([
            'company_id' => $cid, 'contact_id' => $i['contacto'], 'fecha' => $ahora->toDateString(), 'monto' => 50, 'saldo' => 50,
            'forma_pago' => 'efectivo', 'tipo' => 'cliente',
        ] + $t);
        $i['rol'] = DB::table('payrolls')->insertGetId(['company_id' => $cid, 'anio' => 2026, 'mes' => 10] + $t);
        $i['precio'] = DB::table('price_lists')->insertGetId(['product_id' => $i['producto'], 'nombre' => "Lista MARCA-$m", 'precio' => 9] + $t);
        $i['usuario'] = DB::table('users')->insertGetId([
            'name' => "Usuario MARCA-$m", 'email' => 'usuario.'.strtolower($m).'@empresa.test', 'password' => 'x', 'company_id' => $cid,
            'rol' => 'cajero', 'activo' => true, 'es_superadmin' => false,
        ] + $t);

        return $i;
    }
}
