<?php

namespace App\Support;

use App\Http\Controllers\BankMovementController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\CreditApplicationController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\InventoryTransactionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PayableController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReceivableController;
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
use App\Models\Warehouse;
use App\Services\SimpleEntry;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * La empresa "DEMO HASRESET": datos ficticios para grabar el demo de ventas.
 *
 * - Se identifica por su RUC ficticio (nunca el de un tercero real) y solo toca lo que ella misma crea.
 * - El catálogo (bodega, banco, clientes, productos...) se crea directo; los movimientos de muestra
 *   (compras, ventas, pagos, nota de crédito, ajuste, caja y banco) pasan por los controladores reales,
 *   así los asientos y el kárdex salen exactamente como cuando una persona lo hace desde la pantalla.
 * - Las facturas se emiten por el camino normal: sin certificado de firma el sistema las deja en el
 *   estado "generado"; aquí no se inventa ninguna autorización del SRI.
 * - Las fechas se reparten dentro del mes actual (nunca en el futuro) y todo se arma en una sola
 *   transacción: o queda completa, o no queda nada.
 */
class EmpresaDemo
{
    public const RUC = '0999000001001';

    public const NOMBRE = 'DEMO HASRESET';

    /** No se borran al rehacer: son configuración de la empresa o del sistema, no datos de muestra. */
    private const CONSERVAR = ['companies', 'users', 'accounts', 'emission_points', 'branches', 'warehouses'];

    private Company $empresa;

    private Carbon $ahora;

    private int $orden = 0;

    private Branch $sucursal;

    private EmissionPoint $punto;

    private Warehouse $bodega;

    private Bank $banco;

    /** @var array<string,Contact> */
    private array $contactos = [];

    /** @var array<string,Product> */
    private array $productos = [];

    public function buscar(): ?Company
    {
        return Company::where('ruc', self::RUC)->first();
    }

    /** Lo que hay hoy en la empresa, para mostrarlo en pantalla: [concepto => valor]. */
    public function resumen(Company $empresa): array
    {
        $id = $empresa->id;
        $porCobrar = (float) Invoice::where('company_id', $id)->where('estado', '!=', 'anulado')->sum('saldo_pendiente');
        $porPagar = (float) Purchase::where('company_id', $id)->sum('saldo_pendiente');

        return [
            'Empresa' => $empresa->razon_social.' · RUC '.$empresa->ruc,
            'Plan / ambiente SRI' => $empresa->plan.' / '.((int) $empresa->ambiente === 1 ? 'pruebas' : 'producción'),
            'Clientes' => Contact::where('company_id', $id)->where('es_cliente', true)->count(),
            'Proveedores' => Contact::where('company_id', $id)->where('es_proveedor', true)->count(),
            'Productos (bienes / servicios)' => Product::where('company_id', $id)->where('tipo', 'bien')->count()
                .' / '.Product::where('company_id', $id)->where('tipo', 'servicio')->count(),
            'Series registradas' => ProductSerie::where('company_id', $id)->count(),
            'Compras' => Purchase::where('company_id', $id)->count(),
            'Facturas de venta' => Invoice::where('company_id', $id)->count(),
            'Notas de crédito' => CreditNote::where('company_id', $id)->count(),
            'Movimientos de kárdex' => InventoryMovement::where('company_id', $id)->count(),
            'Asientos contables' => JournalEntry::where('company_id', $id)->count(),
            'Por cobrar' => '$'.number_format($porCobrar, 2),
            'Por pagar' => '$'.number_format($porPagar, 2),
        ];
    }

    /**
     * Borra los datos de la empresa demo (y solo los suyos) y la vuelve a armar.
     * Con $existente se conserva la fila de la empresa, su plan de cuentas, sucursal, punto de emisión
     * y bodega; sin ella se crea todo desde cero.
     */
    public function construir(?Company $existente = null): Company
    {
        $previo = Carbon::getTestNow();
        $this->ahora = Carbon::now();
        $this->orden = 0;
        $this->contactos = $this->productos = [];

        try {
            return DB::transaction(function () use ($existente) {
                if ($existente) {
                    $this->borrarDatos($existente);
                }
                $this->empresa = $this->crearEmpresa();
                $this->crearEstructura();
                $this->crearContactos();
                $this->crearProductos();
                $this->crearMovimientos();

                return $this->empresa->fresh();
            });
        } finally {
            Carbon::setTestNow($previo);
        }
    }

    /**
     * Borra todo lo que cuelga de la empresa, de las tablas hijas a las padres (con las llaves foráneas
     * activas), y reinicia la numeración. No borra la empresa, los usuarios ni su configuración.
     */
    public function borrarDatos(Company $empresa): void
    {
        $id = $empresa->id;
        $de = fn (string $tabla) => DB::table($tabla)->where('company_id', $id)->select('id');

        // Hijas que no llevan company_id: se alcanzan por su documento
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $de('journal_entries'))->delete();
        DB::table('credit_applications')->whereIn('invoice_id', $de('invoices'))->delete();
        DB::table('invoice_payments')->whereIn('invoice_id', $de('invoices'))->delete();
        DB::table('purchase_payments')->whereIn('purchase_id', $de('purchases'))->delete();
        DB::table('cash_movements')->whereIn('cash_session_id', $de('cash_sessions'))->delete();
        DB::table('payroll_lines')->whereIn('payroll_id', $de('payrolls'))->delete();
        foreach (['price_lists', 'product_codes'] as $tabla) {
            DB::table($tabla)->whereIn('product_id', $de('products'))->delete();
        }
        DB::table('product_components')->whereIn('product_id', $de('products'))
            ->orWhereIn('component_id', $de('products'))->delete();
        DB::table('warehouse_stocks')->whereIn('warehouse_id', $de('warehouses'))
            ->orWhereIn('product_id', $de('products'))->delete();

        // Con company_id, de las hijas a las padres
        $orden = [
            'payment_splits', 'card_transactions', 'card_settlements', 'withholdings', 'sri_documents',
            'stock_reservations', 'advances', 'journal_entries', 'bank_movements', 'cash_sessions',
            'cash_registers', 'banks', 'inventory_movements', 'product_series', 'credit_notes', 'invoices',
            'purchases', 'quotes', 'pending_imports', 'payrolls', 'employees', 'cost_centers', 'products',
            'contacts', 'audit_logs',
        ];
        foreach ($orden as $tabla) {
            DB::table($tabla)->where('company_id', $id)->delete();
        }

        // Red de seguridad: cualquier otra tabla nueva con company_id (menos la configuración que se conserva)
        foreach (Schema::getTableListing() as $tabla) {
            $tabla = Str::afterLast($tabla, '.');
            if (in_array($tabla, array_merge(self::CONSERVAR, $orden), true) || ! Schema::hasColumn($tabla, 'company_id')) {
                continue;
            }
            DB::table($tabla)->where('company_id', $id)->delete();
        }

        // La numeración vuelve a empezar
        DB::table('companies')->where('id', $id)->update(['secuencial' => 1]);
        DB::table('emission_points')->where('company_id', $id)->update(['secuencial' => 1]);
    }

    // ------------------------------------------------------------------ estructura

    private function crearEmpresa(): Company
    {
        $empresa = Company::updateOrCreate(['ruc' => self::RUC], [
            'razon_social' => self::NOMBRE,
            'nombre_comercial' => 'HasReset Demostración',
            'dir_matriz' => 'Av. de las Demostraciones N12-34 y Calle Prueba, Quito',
            'telefonos' => '022000000',
            'estab' => '001',
            'pto_emi' => '001',
            'secuencial' => 1,
            'obligado_contabilidad' => true,
            'ambiente' => 1,   // pruebas del SRI
            'plan' => Company::PLAN_POR_DEFECTO,
            'plan_vence' => null,
            'nota_pie' => 'Empresa de demostración. Todos los datos son ficticios.',
        ]);

        // El plan de cuentas completo (idempotente: no duplica lo que ya está)
        Cuentas::sembrar($empresa->id);

        return $empresa;
    }

    private function crearEstructura(): void
    {
        $id = $this->empresa->id;

        $this->sucursal = Branch::firstOrCreate(
            ['company_id' => $id, 'estab' => '001'],
            ['nombre' => 'Matriz', 'direccion' => $this->empresa->dir_matriz, 'es_matriz' => true, 'activa' => true]
        );
        $this->punto = EmissionPoint::firstOrCreate(
            ['company_id' => $id, 'estab' => '001', 'punto' => '001'],
            ['nombre' => 'Caja principal', 'secuencial' => 1]
        );
        $this->punto->forceFill(['branch_id' => $this->sucursal->id, 'secuencial' => 1])->save();

        $this->bodega = Warehouse::firstOrCreate(
            ['company_id' => $id, 'codigo' => 'B01'],
            ['nombre' => 'Bodega principal', 'por_defecto' => true, 'activa' => true, 'branch_id' => $this->sucursal->id]
        );
        CashRegister::firstOrCreate(['company_id' => $id, 'nombre' => 'Caja principal']);
        $this->banco = Bank::create([
            'company_id' => $id,
            'nombre' => 'Banco Demo (cuenta corriente)',
            'numero_cuenta' => '0001002003',
            'cuenta_contable' => Cuentas::codigo('bancos'),
        ]);
    }

    private function crearContactos(): void
    {
        $id = $this->empresa->id;
        $direccion = 'Quito, Ecuador (dirección de demostración)';

        // Clientes. El consumidor final es el que define el SRI (solo hasta $50 por factura).
        $this->contactos['consumidor'] = Contact::create([
            'company_id' => $id, 'es_cliente' => true, 'es_proveedor' => false, 'tipo_identificacion' => '07',
            'identificacion' => '9999999999999', 'razon_social' => 'CONSUMIDOR FINAL', 'direccion' => 'Quito',
        ]);
        $this->contactos['natural'] = Contact::create([
            'company_id' => $id, 'es_cliente' => true, 'es_proveedor' => false, 'tipo_identificacion' => '05',
            'identificacion' => '0999000002', 'razon_social' => 'Andrea Prueba Demo', 'direccion' => $direccion,
            'telefono' => '0999999999', 'email' => 'andrea.prueba@example.com',
        ]);
        $this->contactos['sociedad'] = Contact::create([
            'company_id' => $id, 'es_cliente' => true, 'es_proveedor' => false, 'tipo_identificacion' => '04',
            'identificacion' => '0999000003001', 'razon_social' => 'Comercial Andes Demo S.A.',
            'nombre_comercial' => 'Comercial Andes Demo', 'direccion' => $direccion,
            'telefono' => '022000001', 'email' => 'compras@comercial-andes.example.com',
        ]);
        $this->contactos['relacionada'] = Contact::create([
            'company_id' => $id, 'es_cliente' => true, 'es_proveedor' => false, 'tipo_identificacion' => '04',
            'identificacion' => '0999000004001', 'razon_social' => 'Inversiones del Valle Demo Cía. Ltda.',
            'direccion' => $direccion, 'telefono' => '022000002', 'email' => 'contabilidad@inversiones-valle.example.com',
            'parte_relacionada' => true,
        ]);

        // Proveedores: dos de bienes y uno de servicios
        $this->contactos['bienes'] = Contact::create([
            'company_id' => $id, 'es_cliente' => false, 'es_proveedor' => true, 'tipo_identificacion' => '04',
            'identificacion' => '0999000011001', 'razon_social' => 'Importadora Andina Demo S.A.',
            'nombre_comercial' => 'Importadora Andina Demo', 'direccion' => $direccion,
            'telefono' => '022000011', 'email' => 'ventas@importadora-andina.example.com',
        ]);
        $this->contactos['servicios'] = Contact::create([
            'company_id' => $id, 'es_cliente' => false, 'es_proveedor' => true, 'tipo_identificacion' => '04',
            'identificacion' => '0999000012001', 'razon_social' => 'Servicios Técnicos Demo Cía. Ltda.',
            'nombre_comercial' => 'Servicios Técnicos Demo', 'direccion' => $direccion,
            'telefono' => '022000012', 'email' => 'facturacion@servicios-tecnicos.example.com',
        ]);
        $this->contactos['suministros'] = Contact::create([
            'company_id' => $id, 'es_cliente' => false, 'es_proveedor' => true, 'tipo_identificacion' => '04',
            'identificacion' => '0999000013001', 'razon_social' => 'Suministros de Oficina Demo S.A.',
            'nombre_comercial' => 'Suministros de Oficina Demo', 'direccion' => $direccion,
            'telefono' => '022000013', 'email' => 'pedidos@suministros-oficina.example.com',
        ]);
    }

    private function crearProductos(): void
    {
        // [código, descripción, tipo, precio de venta, IVA %, series, stock mínimo, ubicación]
        $catalogo = [
            ['LAP-001', 'Laptop 14 pulgadas', 'bien', 650, 15, true, 2, 'Estante A'],
            ['MON-001', 'Monitor 24 pulgadas', 'bien', 180, 15, false, 3, 'Estante A'],
            ['TEC-001', 'Teclado inalámbrico', 'bien', 25, 15, false, 5, 'Estante B'],
            ['MOU-001', 'Mouse óptico', 'bien', 12, 15, false, 5, 'Estante B'],
            ['CAB-001', 'Cable HDMI de 2 metros', 'bien', 9, 15, false, 10, 'Estante B'],
            ['TON-001', 'Tóner negro compatible', 'bien', 48, 15, false, 15, 'Estante C'],
            ['LIB-001', 'Libro de contabilidad básica', 'bien', 20, 0, false, 5, 'Estante C'],
            ['SRV-001', 'Servicio técnico', 'servicio', 60, 15, false, 0, null],
            ['SRV-002', 'Mano de obra', 'servicio', 25, 15, false, 0, null],
            ['SRV-003', 'Asesoría', 'servicio', 80, 15, false, 0, null],
        ];
        foreach ($catalogo as [$codigo, $descripcion, $tipo, $precio, $iva, $series, $minimo, $ubicacion]) {
            $this->productos[$codigo] = Product::create([
                'company_id' => $this->empresa->id, 'codigo' => $codigo, 'descripcion' => $descripcion,
                'tipo' => $tipo, 'precio' => $precio, 'tarifa_iva' => $iva, 'maneja_series' => $series,
                'stock' => 0, 'costo_promedio' => 0, 'stock_minimo' => $minimo, 'ubicacion' => $ubicacion,
            ]);
        }
    }

    // ------------------------------------------------------------------ movimientos de muestra

    private function crearMovimientos(): void
    {
        $id = $this->empresa->id;
        $series = ['DEMO-LAP-0001', 'DEMO-LAP-0002', 'DEMO-LAP-0003', 'DEMO-LAP-0004', 'DEMO-LAP-0005'];

        // Aporte inicial: sin él, Bancos y Caja quedarían en negativo desde la primera compra
        $this->en(0.0, function () use ($id) {
            SimpleEntry::make($id, 'Aporte inicial de capital', [
                Cuentas::linea('bancos', 8000, 0, 'APORTE'),
                Cuentas::linea('caja', 500, 0, 'APORTE'),
                Cuentas::linea('capital', 0, 8500, 'APORTE'),
            ]);
            $aporte = $this->movimientoBanco('credito', 8000, 'Aporte inicial de capital');
            $this->api(BankMovementController::class, 'toggle', [], ['movement' => BankMovement::findOrFail($aporte['id'])]);
        });

        // Compra 1: bienes con series, pago parcial al proveedor
        $compra1 = $this->en(0.05, fn () => $this->comprar($this->contactos['bienes'], '001-001-000004521', 'Compra de equipos para la venta', [
            $this->lineaCompra('LAP-001', 5, 480, 15, $series),
            $this->lineaCompra('MON-001', 8, 125),
            $this->lineaCompra('TEC-001', 20, 15),
            $this->lineaCompra('MOU-001', 20, 7),
            $this->lineaCompra('CAB-001', 30, 4.5),
        ]));
        $this->en(0.15, function () use ($compra1) {
            $this->api(PayableController::class, 'pay', [
                'pagos' => [['tipo' => 'transferencia', 'valor' => 2000, 'bank_id' => $this->banco->id]],
            ], ['purchase' => Purchase::findOrFail($compra1['id'])]);
            // El egreso de banco ya lo deja el propio pago (App\Support\MovimientosBanco): no se repite a mano
        });

        // Compra 2: bienes a crédito (queda por pagar completa)
        $this->en(0.2, fn () => $this->comprar($this->contactos['suministros'], '001-001-000001873', 'Compra de suministros a crédito', [
            $this->lineaCompra('TON-001', 12, 30),
            $this->lineaCompra('LIB-001', 15, 12, 0),
        ]));

        // Compra 3: un servicio, pagado en efectivo
        $compra3 = $this->en(0.3, fn () => $this->comprar($this->contactos['servicios'], '001-001-000000642', 'Servicio técnico contratado', [
            $this->lineaCompra('SRV-001', 1, 120),
        ]));
        $this->en(0.3, fn () => $this->api(PayableController::class, 'pay', [
            'pagos' => [['tipo' => 'efectivo', 'valor' => 138]],
        ], ['purchase' => Purchase::findOrFail($compra3['id'])]));

        // Venta 1: de contado a consumidor final (el SRI lo limita a $50)
        $this->en(0.4, fn () => $this->facturar($this->contactos['consumidor'], 'efectivo', [
            $this->lineaVenta('MOU-001', 2), $this->lineaVenta('CAB-001', 1),
        ]));

        // Venta 2: a crédito con series, cobro parcial por transferencia
        $venta2 = $this->en(0.5, fn () => $this->facturar($this->contactos['sociedad'], 'credito', [
            $this->lineaVenta('LAP-001', 2, [$series[0], $series[1]]),
            $this->lineaVenta('MON-001', 2),
            $this->lineaVenta('SRV-001', 1),
        ]));
        $this->en(0.6, function () use ($venta2) {
            $this->api(ReceivableController::class, 'pay', [
                'pagos' => [['tipo' => 'transferencia', 'valor' => 800, 'bank_id' => $this->banco->id]],
            ], ['invoice' => Invoice::findOrFail($venta2['invoice']['id'])]);
            // El ingreso de banco ya lo deja el propio cobro (App\Support\MovimientosBanco): no se repite a mano
        });

        // Venta 3: a crédito y una nota de crédito que devuelve un tóner al inventario
        $venta3 = $this->en(0.7, fn () => $this->facturar($this->contactos['natural'], 'credito', [
            $this->lineaVenta('TEC-001', 3), $this->lineaVenta('TON-001', 2), $this->lineaVenta('SRV-002', 1),
        ]));
        $this->en(0.8, function () use ($id, $venta3) {
            // La nota baja el saldo de su factura al emitirse (no hace falta aplicarla después)
            $this->api(CreditNoteController::class, 'store', [
                'company_id' => $id, 'contact_id' => $this->contactos['natural']->id,
                'invoice_id' => $venta3['invoice']['id'], 'tipo' => 'sri', 'motivo' => 'Devolución de mercadería',
                'items' => [$this->lineaVenta('TON-001', 1)],
            ]);
        });

        // Ajuste de inventario: faltante de 2 cables en el conteo físico
        $this->en(0.9, function () use ($id) {
            $cables = $this->productos['CAB-001']->fresh();
            $this->api(InventoryTransactionController::class, 'ajuste', [
                'company_id' => $id, 'product_id' => $cables->id, 'warehouse_id' => $this->bodega->id,
                'stock_fisico' => (float) $cables->stock - 2, 'motivo' => 'Faltante en conteo físico',
            ]);
        });

        // Caja: sesión abierta con un ingreso y un egreso
        $this->en(1.0, function () use ($id) {
            $sesion = $this->api(CashController::class, 'open', ['company_id' => $id, 'saldo_inicial' => 500]);
            $this->api(CashController::class, 'addMovement', [
                'tipo' => 'ingreso', 'monto' => 50, 'concepto' => 'Reposición de caja chica',
            ], ['session' => CashSession::findOrFail($sesion['id'])]);
            $this->api(CashController::class, 'addMovement', [
                'tipo' => 'egreso', 'monto' => 12.5, 'concepto' => 'Pago de movilización',
            ], ['session' => CashSession::findOrFail($sesion['id'])]);
        });
    }

    private function comprar(Contact $proveedor, string $numero, string $observacion, array $items): array
    {
        return $this->api(PurchaseController::class, 'store', [
            'company_id' => $this->empresa->id, 'contact_id' => $proveedor->id, 'numero' => $numero,
            'fecha_emision' => now()->toDateString(), 'warehouse_id' => $this->bodega->id,
            'observacion' => $observacion, 'items' => $items,
        ]);
    }

    private function facturar(Contact $cliente, string $formaPago, array $items): array
    {
        return $this->api(InvoiceController::class, 'store', [
            'company_id' => $this->empresa->id, 'contact_id' => $cliente->id,
            'emission_point_id' => $this->punto->id, 'forma_pago' => $formaPago, 'items' => $items,
        ]);
    }

    private function movimientoBanco(string $tipo, float $monto, string $concepto): array
    {
        return $this->api(BankMovementController::class, 'store', [
            'company_id' => $this->empresa->id, 'bank_id' => $this->banco->id, 'fecha' => now()->toDateString(),
            'tipo' => $tipo, 'monto' => $monto, 'concepto' => $concepto,
        ]);
    }

    private function lineaCompra(string $codigo, float $cantidad, float $precio, float $iva = 15, array $series = []): array
    {
        return [
            'codigo_principal' => $codigo, 'descripcion' => $this->productos[$codigo]->descripcion,
            'cantidad' => $cantidad, 'precio_unitario' => $precio, 'tarifa' => $iva, 'series' => $series,
        ];
    }

    /** Línea de venta al precio del catálogo. */
    private function lineaVenta(string $codigo, float $cantidad, array $series = []): array
    {
        $producto = $this->productos[$codigo];
        $linea = [
            'codigo_principal' => $codigo, 'descripcion' => $producto->descripcion,
            'cantidad' => $cantidad, 'precio_unitario' => (float) $producto->precio, 'tarifa' => (float) $producto->tarifa_iva,
        ];

        return $series ? $linea + ['series' => $series] : $linea;
    }

    // ------------------------------------------------------------------ mecánica

    /**
     * Ejecuta $accion "en" un momento del mes actual. $fraccion (0 a 1) reparte los hechos entre el día 1
     * y hoy; el reloj se congela ahí para que facturas, pagos y asientos lleven esa fecha, y nunca cae en
     * el futuro.
     */
    private function en(float $fraccion, callable $accion): mixed
    {
        $dias = (int) floor($fraccion * ($this->ahora->day - 1));
        $momento = $this->ahora->copy()->startOfMonth()->addDays($dias)->setTime(9, 0)->addMinutes(7 * $this->orden++);
        Carbon::setTestNow($momento->greaterThan($this->ahora) ? $this->ahora->copy() : $momento);

        return $accion();
    }

    /**
     * Llama al método de un controlador real, como si llegara el pedido de la pantalla (sin pasar por la
     * red ni por el inicio de sesión). Devuelve el cuerpo como arreglo; un error de validación o una
     * respuesta 4xx/5xx detiene todo con el motivo.
     */
    private function api(string $controlador, string $metodo, array $datos = [], array $parametros = []): array
    {
        $pedido = Request::create('/demo', 'POST', $datos);
        $pedido->headers->set('Accept', 'application/json');
        $donde = class_basename($controlador).'@'.$metodo;

        try {
            $respuesta = app()->call([app($controlador), $metodo], ['r' => $pedido] + $parametros);
        } catch (ValidationException $e) {
            throw new RuntimeException("{$donde}: ".collect($e->errors())->flatten()->implode(' '));
        }

        if ($respuesta instanceof JsonResponse) {
            $cuerpo = (array) $respuesta->getData(true);
            if ($respuesta->getStatusCode() >= 400) {
                throw new RuntimeException("{$donde}: ".($cuerpo['message'] ?? $cuerpo['error'] ?? json_encode($cuerpo, JSON_UNESCAPED_UNICODE)));
            }

            return $cuerpo;
        }

        return $respuesta instanceof Arrayable ? $respuesta->toArray() : (array) $respuesta;
    }
}
