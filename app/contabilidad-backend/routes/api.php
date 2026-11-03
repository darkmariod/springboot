<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuiaRemisionController;
use App\Http\Controllers\LiquidacionCompraController;
use App\Http\Controllers\NotaDebitoController;
use Illuminate\Support\Facades\Route;

// Público
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');

// Protegido por token Sanctum. `activo` corta al usuario desactivado aunque su token siga vivo. `tenant` (EnforceTenant) confina a cada
// usuario con empresa a la suya: company_id, ids de la URL e ids dentro de los campos de otra empresa dan 403; el superadministrador
// (operador de la plataforma) y el usuario sin empresa pasan sin restricción (T11.6).
//
// Los permisos de la API siguen a los del menú (`PERMISOS` en contabilidad-vue/src/modules.ts):
//   - cajero: Punto de venta, Facturas, Cotizaciones y Clientes. Solo lo que esas pantallas necesitan está en el primer bloque.
//   - contador: todo menos Administración (empresas, usuarios, firma y comprobantes, sucursales): segundo bloque.
//   - administrador: todo; lo de Administración lleva además `rol:admin`.
// Una ruta nueva va en el segundo bloque salvo que el cajero la necesite para vender: así nace cerrada para él.
Route::middleware(['auth:sanctum', 'activo', 'tenant'])->group(function () {

    // ───────────────────────── Bloque 1 · abierto a todo usuario autenticado ─────────────────────────
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Al abrir la aplicación se leen la empresa y su plan
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/companies/{company}/plan', [CompanyController::class, 'plan']);

    // Clientes: el cajero los consulta, los crea y los edita (borrarlos es del bloque 2)
    Route::apiResource('contacts', \App\Http\Controllers\ContactController::class)->only(['index', 'store', 'update']);
    Route::get('sri/consulta', [\App\Http\Controllers\SriLookupController::class, 'consulta']);

    // Punto de venta: artículos, puntos de emisión, bodegas y series para armar la factura
    Route::get('products', [\App\Http\Controllers\ProductController::class, 'index']);
    Route::get('products/lookup', [\App\Http\Controllers\ProductController::class, 'lookup']);
    Route::get("emission-points", [\App\Http\Controllers\EmissionPointController::class, "index"]);
    Route::get('warehouses', [\App\Http\Controllers\WarehouseController::class, 'index']);
    Route::middleware('feature:series')->group(function () {
        Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
        Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
    });

    // Catálogos de solo lectura (formas de pago, clases de contribuyente, sustentos, retenciones)
    Route::get("catalogos/formas-pago", [\App\Http\Controllers\CatalogosController::class, "formasPago"]);
    Route::get("catalogos/sustentos", [\App\Http\Controllers\CatalogosController::class, "sustentos"]);
    Route::get("catalogos/tipos-comprobante-compra", [\App\Http\Controllers\CatalogosController::class, "tiposComprobanteCompra"]);
    Route::get("catalogos/retenciones", [\App\Http\Controllers\CatalogosController::class, "retenciones"]);
    Route::get("catalogos/clases-contribuyente", [\App\Http\Controllers\CatalogosController::class, "clasesContribuyente"]);

    // Ventas: facturas, su RIDE y la anulación desde la pantalla de Facturas
    Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store']);
    Route::post("invoices/{invoice}/anular", [\App\Http\Controllers\InvoiceController::class, "anular"]);
    // RIDE en PDF (representación impresa con los datos del emisor y de la autorización del SRI)
    Route::get("invoices/{invoice}/ride", [\App\Http\Controllers\RideController::class, "factura"]);
    // Comprobantes por correo (RIDE en PDF + XML) con el servidor de correo de la empresa; también el historial de envíos
    Route::middleware('rol:admin,contador,cajero')->group(function () {
        Route::post('comprobantes/{tipo}/{id}/enviar-correo', [\App\Http\Controllers\ComprobanteCorreoController::class, 'enviar'])->whereNumber('id');
        Route::get('comprobantes/{tipo}/{id}/correos', [\App\Http\Controllers\ComprobanteCorreoController::class, 'correos'])->whereNumber('id');
    });

    // Cotizaciones
    Route::get("quotes", [\App\Http\Controllers\QuoteController::class, "index"]);
    Route::post("quotes", [\App\Http\Controllers\QuoteController::class, "store"]);
    // Varias cotizaciones en UNA factura (vista previa de la unión y conversión); van antes de las rutas con {quote}
    Route::post("quotes/merge-preview", [\App\Http\Controllers\QuoteController::class, "mergePreview"]);
    Route::post("quotes/convert-many", [\App\Http\Controllers\QuoteController::class, "convertMany"]);
    Route::post("quotes/{quote}/convert", [\App\Http\Controllers\QuoteController::class, "convert"]);
    Route::put("quotes/{quote}", [\App\Http\Controllers\QuoteController::class, "update"]);
    Route::delete("quotes/{quote}", [\App\Http\Controllers\QuoteController::class, "destroy"]);

    // ───────────────────────── Bloque 2 · administrador y contador ─────────────────────────
    // El rol va primero: un cajero recibe 403 aunque el plan de la empresa no incluya el módulo.
    Route::middleware('rol:admin,contador')->group(function () {

        Route::delete('contacts/{contact}', [\App\Http\Controllers\ContactController::class, 'destroy'])->name('contacts.destroy');

        // Resumen ejecutivo de la pestaña Inicio
        Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

        // Centros de costo
        Route::middleware('feature:contabilidad')->group(function () {
            Route::get('cost-centers', [\App\Http\Controllers\CostCenterController::class, 'index']);
            Route::post('cost-centers', [\App\Http\Controllers\CostCenterController::class, 'store']);
            Route::put('cost-centers/{costCenter}', [\App\Http\Controllers\CostCenterController::class, 'update']);
            Route::delete('cost-centers/{costCenter}', [\App\Http\Controllers\CostCenterController::class, 'destroy']);
            Route::get('cost-centers/resultados', [\App\Http\Controllers\CostCenterController::class, 'resultados']);
        });
        // Formulario 103 y ATS (obligaciones mensuales ante el SRI)
        Route::middleware('feature:contabilidad')->group(function () {
            Route::get('tax/103', [\App\Http\Controllers\Form103Controller::class, 'formulario']);
            Route::get('tax/103/catalogo', [\App\Http\Controllers\Form103Controller::class, 'catalogo']);
            Route::get('tax/ats/xml', [\App\Http\Controllers\AtsController::class, 'xml']);
        });
        // Conciliacion de tarjetas: cobros con tarjeta vs. lo que deposita el procesador
        Route::middleware('feature:conciliacion_tarjetas')->group(function () {
            Route::get('card/pendientes', [\App\Http\Controllers\CardReconciliationController::class, 'pendientes']);
            Route::get('card/resumen', [\App\Http\Controllers\CardReconciliationController::class, 'resumen']);
            Route::get('card/settlements', [\App\Http\Controllers\CardReconciliationController::class, 'liquidaciones']);
            Route::post('card/settlements', [\App\Http\Controllers\CardReconciliationController::class, 'crearLiquidacion']);
            Route::post('card/settlements/{settlement}/asignar', [\App\Http\Controllers\CardReconciliationController::class, 'asignar']);
            Route::get('card/settlements/{settlement}/sugerir', [\App\Http\Controllers\CardReconciliationController::class, 'sugerir']);
            Route::delete('card/settlements/{settlement}', [\App\Http\Controllers\CardReconciliationController::class, 'eliminarLiquidacion']);
        });
        // Sucursales (multisede): cada una es un establecimiento ante el SRI. Es Administración: solo el administrador.
        Route::middleware(['rol:admin', 'feature:sucursales'])->group(function () {
            Route::get('branches', [\App\Http\Controllers\BranchController::class, 'index']);
            Route::post('branches', [\App\Http\Controllers\BranchController::class, 'store']);
            Route::put('branches/{branch}', [\App\Http\Controllers\BranchController::class, 'update']);
            Route::delete('branches/{branch}', [\App\Http\Controllers\BranchController::class, 'destroy']);
        });
        // Alta de una empresa nueva (cliente): solo el superadministrador (lo revisa el controlador). Cambiar el plan, también.
        Route::post('/companies', [CompanyController::class, 'store'])->middleware('rol:admin');
        Route::post('/companies/{company}/logo', [CompanyController::class, 'logo'])->middleware('rol:admin');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->middleware('rol:admin');
        Route::post('/companies/{company}/plan', [CompanyController::class, 'cambiarPlan'])->middleware('rol:admin');

        Route::get('/accounts', [AccountController::class, 'index']);
        // Código siguiente libre de un nivel del plan (sugerencia al crear una cuenta)
        Route::get('/accounts/siguiente-codigo', [AccountController::class, 'siguienteCodigo']);
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::put('/accounts/{account}', [AccountController::class, 'update']);
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);

        // Catálogo: altas, cambios y bajas de artículos (la consulta para vender está en el bloque 1)
        Route::post('products', [\App\Http\Controllers\ProductController::class, 'store']);
        Route::match(['put', 'patch'], 'products/{product}', [\App\Http\Controllers\ProductController::class, 'update']);
        Route::delete('products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy']);
        // Ficha de artículo estilo KVS: precios, componentes, códigos alternos
        Route::get("products/{product}/prices", [\App\Http\Controllers\ProductExtrasController::class, "prices"]);
        Route::post("products/{product}/prices", [\App\Http\Controllers\ProductExtrasController::class, "storePrice"]);
        Route::delete("product-prices/{price}", [\App\Http\Controllers\ProductExtrasController::class, "destroyPrice"]);
        Route::get("products/{product}/components", [\App\Http\Controllers\ProductExtrasController::class, "components"]);
        Route::post("products/{product}/components", [\App\Http\Controllers\ProductExtrasController::class, "storeComponent"]);
        Route::delete("product-components/{component}", [\App\Http\Controllers\ProductExtrasController::class, "destroyComponent"]);
        Route::get("products/{product}/codes", [\App\Http\Controllers\ProductExtrasController::class, "codes"]);
        Route::post("products/{product}/codes", [\App\Http\Controllers\ProductExtrasController::class, "storeCode"]);
        Route::delete("product-codes/{code}", [\App\Http\Controllers\ProductExtrasController::class, "destroyCode"]);
        Route::middleware('feature:bancos')->group(function () { Route::apiResource('banks', \App\Http\Controllers\BankController::class)->only(['index', 'store', 'update', 'destroy']); });

        // Notas de crédito y de débito: el RIDE de las que no son factura
        Route::get("credit-notes/{creditNote}/ride", [\App\Http\Controllers\RideController::class, "notaCredito"]);
        Route::get("notas-debito/{notaDebito}/ride", [\App\Http\Controllers\RideController::class, "notaDebito"]);

        // Compras
        Route::get("purchases", [\App\Http\Controllers\PurchaseController::class, "index"]);
        Route::post("purchases", [\App\Http\Controllers\PurchaseController::class, "store"]);
        Route::put("purchases/{purchase}", [\App\Http\Controllers\PurchaseController::class, "update"]);
        Route::delete("purchases/{purchase}", [\App\Http\Controllers\PurchaseController::class, "destroy"]);
        Route::post("purchases/import", [\App\Http\Controllers\PurchaseController::class, "import"]);

        // Inventario
        Route::get("inventory/stock", [\App\Http\Controllers\InventoryController::class, "stock"]);
        Route::get("inventory/kardex/{product}", [\App\Http\Controllers\InventoryController::class, "kardex"]);
        Route::get("inventory/reorder", [\App\Http\Controllers\InventoryController::class, "reorder"]);
        Route::get("inventory/movimientos", [\App\Http\Controllers\InventoryController::class, "movimientos"]);

        // Contabilidad
        Route::middleware('feature:contabilidad')->group(function () { Route::get("journal", [\App\Http\Controllers\AccountingController::class, "journal"]); });
        // Mayorizar, desmayorizar y los asientos manuales o corregidos a mano: solo administrador y contador
        Route::middleware(['feature:contabilidad', 'rol:admin,contador'])->group(function () {
            Route::post("journal/mayorizar", [\App\Http\Controllers\AccountingController::class, "mayorizar"]);
            Route::post("journal-entries/mayorizar", [\App\Http\Controllers\AccountingController::class, "mayorizar"]);
            Route::post("journal/{entry}/desmayorizar", [\App\Http\Controllers\AccountingController::class, "desmayorizar"]);
            Route::post("journal-entries/{entry}/desmayorizar", [\App\Http\Controllers\AccountingController::class, "desmayorizar"]);
            Route::post("journal-entries", [\App\Http\Controllers\JournalEntryController::class, "store"]);
            Route::put("journal-entries/{entry}", [\App\Http\Controllers\JournalEntryController::class, "update"]);
            Route::delete("journal-entries/{entry}", [\App\Http\Controllers\JournalEntryController::class, "destroy"]);
            Route::post("journal-entries/{entry}/contabilizar", [\App\Http\Controllers\JournalEntryController::class, "contabilizar"]);
        });
        // Períodos contables (cerrar / reabrir), cierre del ejercicio y estados financieros por período con detalle por cuenta.
        // Reabrir un período cerrado es solo del administrador; el resto, de administrador y contador.
        Route::middleware(['feature:contabilidad', 'rol:admin,contador'])->group(function () {
            Route::get("accounting/periodos", [\App\Http\Controllers\PeriodoContableController::class, "index"]);
            Route::post("accounting/periodos/cerrar", [\App\Http\Controllers\PeriodoContableController::class, "cerrar"]);
            Route::post("accounting/cierre-ejercicio", [\App\Http\Controllers\PeriodoContableController::class, "cerrarEjercicio"]);
            Route::get("accounting/estado-resultados", [\App\Http\Controllers\EstadosFinancierosController::class, "resultados"]);
            Route::get("accounting/balance-general", [\App\Http\Controllers\EstadosFinancierosController::class, "balance"]);
        });
        Route::middleware(['feature:contabilidad', 'rol:admin'])->group(function () {
            Route::post("accounting/periodos/reabrir", [\App\Http\Controllers\PeriodoContableController::class, "reabrir"]);
        });
        Route::get("income-statement", [\App\Http\Controllers\AccountingController::class, "incomeStatement"]);
        Route::get("balance-sheet", [\App\Http\Controllers\AccountingController::class, "balanceSheet"]);
        Route::middleware('feature:contabilidad')->group(function () { Route::get("ledger", [\App\Http\Controllers\AccountingController::class, "ledger"]); });

        // Caja
        Route::middleware('feature:bancos')->group(function () { Route::get("cash/current", [\App\Http\Controllers\CashController::class, "current"]); });
        Route::middleware('feature:bancos')->group(function () { Route::post("cash/open", [\App\Http\Controllers\CashController::class, "open"]); });
        Route::middleware('feature:bancos')->group(function () { Route::post("cash/{session}/movements", [\App\Http\Controllers\CashController::class, "addMovement"]); });
        Route::middleware('feature:bancos')->group(function () { Route::post("cash/{session}/close", [\App\Http\Controllers\CashController::class, "close"]); });
        // Comprobante de ingreso/egreso en PDF y depósito de caja a banco (T5.3)
        Route::middleware('feature:bancos')->group(function () {
            Route::get("cash-movements/{movement}/comprobante", [\App\Http\Controllers\CashController::class, "comprobante"]);
            Route::get("deposits", [\App\Http\Controllers\DepositoController::class, "index"]);
            Route::get("deposits/disponible", [\App\Http\Controllers\DepositoController::class, "disponible"]);
            Route::post("deposits", [\App\Http\Controllers\DepositoController::class, "store"]);
        });

        // Cartera
        Route::middleware('feature:cartera')->group(function () { Route::get("receivables", [\App\Http\Controllers\ReceivableController::class, "index"]); });
        Route::middleware('feature:cartera')->group(function () { Route::post("receivables/{invoice}/pay", [\App\Http\Controllers\ReceivableController::class, "pay"]); });
        Route::middleware('feature:cartera')->group(function () { Route::get("payables", [\App\Http\Controllers\PayableController::class, "index"]); });
        Route::middleware('feature:cartera')->group(function () { Route::post("payables/{purchase}/pay", [\App\Http\Controllers\PayableController::class, "pay"]); });
        Route::middleware('feature:cartera')->group(function () { Route::post("payables/pay-multiple", [\App\Http\Controllers\PayableController::class, 'payMultiple']); });
        // Cruce de saldos: documentos abiertos del mismo contacto contra los que se puede cruzar
        Route::middleware('feature:cartera')->group(function () { Route::get("cruce-saldos/documentos", [\App\Http\Controllers\CruceSaldosController::class, "documentos"]); });

        // Estado de cuenta de clientes y de proveedores: por documento y resumen por contacto (JSON, Excel o PDF)
        Route::middleware('feature:cartera')->group(function () {
            Route::get("estado-cuenta/clientes", [\App\Http\Controllers\EstadoCuentaController::class, "clientes"]);
            Route::get("estado-cuenta/clientes/{contact}", [\App\Http\Controllers\EstadoCuentaController::class, "cliente"]);
            Route::get("estado-cuenta/proveedores", [\App\Http\Controllers\EstadoCuentaController::class, "proveedores"]);
            Route::get("estado-cuenta/proveedores/{contact}", [\App\Http\Controllers\EstadoCuentaController::class, "proveedor"]);
        });

        // Retenciones
        Route::get("withholdings", [\App\Http\Controllers\WithholdingController::class, "index"]);
        Route::post("withholdings/import", [\App\Http\Controllers\WithholdingController::class, "import"]);

        // Documentos SRI
        Route::middleware('feature:facturacion_sri')->group(function () { Route::get("sri-documents/pending", [\App\Http\Controllers\SriDocumentController::class, "pending"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::get("sri-documents/authorized", [\App\Http\Controllers\SriDocumentController::class, "authorized"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post("sri-documents/authorize-batch", [\App\Http\Controllers\SriDocumentController::class, "authorizeBatch"]); });

        // Bancos y conciliación
        Route::middleware('feature:conciliacion')->group(function () {
            Route::get("bank-movements", [\App\Http\Controllers\BankMovementController::class, "index"]);
            Route::post("bank-movements", [\App\Http\Controllers\BankMovementController::class, "store"]);
            Route::post("bank-movements/{movement}/toggle", [\App\Http\Controllers\BankMovementController::class, "toggle"]);
            Route::post("bank-movements/auto-match", [\App\Http\Controllers\BankMovementController::class, "autoMatch"]);
            Route::post("bank-movements/importar", [\App\Http\Controllers\BankMovementController::class, "importar"]);
            Route::post("bank-movements/importar/aplicar", [\App\Http\Controllers\BankMovementController::class, "aplicarImportacion"]);
        });

        // Puntos de emisión (la consulta está en el bloque 1) + certificado
        Route::post("emission-points", [\App\Http\Controllers\EmissionPointController::class, "store"])->middleware('rol:admin');
        Route::delete("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "destroy"])->middleware('rol:admin');
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post("companies/{company}/certificate", [\App\Http\Controllers\CompanyController::class, "uploadCertificate"])->middleware('rol:admin'); });
        // EDocuments — configuración completa (firma + SRI + correo), como KVS: es Administración, solo el administrador
        Route::get("companies/{company}/edoc-config", [\App\Http\Controllers\EdocConfigController::class, "show"])->middleware('rol:admin');
        Route::post("companies/{company}/edoc-config", [\App\Http\Controllers\EdocConfigController::class, "update"])->middleware('rol:admin');
        Route::post("companies/{company}/smtp/test", [\App\Http\Controllers\SmtpTestController::class, "send"])->middleware('rol:admin');

        // Fase 2 — Series (garantías): consultar para vender está en el bloque 1; aquí, administrarlas
        Route::middleware('feature:series')->group(function () {
            Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
            Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);
            Route::get("series/buscar", [\App\Http\Controllers\SerieController::class, "buscar"]);
            Route::put("series/{serie}", [\App\Http\Controllers\SerieController::class, "update"]);
            Route::delete("series/{serie}", [\App\Http\Controllers\SerieController::class, "destroy"]);
        });

        // Fase 3 — Usuarios y auditoría. Los usuarios son Administración: solo el administrador los crea, ve, cambia o borra.
        Route::middleware(['rol:admin', 'feature:usuarios'])->group(function () {
            Route::get("users", [\App\Http\Controllers\UserController::class, "index"]);
            Route::post("users", [\App\Http\Controllers\UserController::class, "store"]);
            Route::put("users/{user}", [\App\Http\Controllers\UserController::class, "update"]);
            Route::delete("users/{user}", [\App\Http\Controllers\UserController::class, "destroy"]);
        });

        Route::middleware('feature:auditoria')->group(function () {
            Route::get("audit", [\App\Http\Controllers\AuditController::class, "index"]);
            Route::get('audit/opciones', [\App\Http\Controllers\AuditController::class, 'opciones']);
            Route::get('audit/exportar', [\App\Http\Controllers\AuditController::class, 'exportar']);
        });

        // Fase 4 — Notas de crédito, anticipos, uso de saldos
        Route::middleware('feature:cartera')->group(function () { Route::get("advances", [\App\Http\Controllers\AdvanceController::class, "index"]); });
        Route::middleware('feature:cartera')->group(function () { Route::post("advances", [\App\Http\Controllers\AdvanceController::class, "store"]); });
        Route::middleware('feature:cartera')->group(function () { Route::get("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "index"]); });
        // Las notas internas solo regulan saldos (cartera); la del SRI además exige facturación electrónica
        Route::middleware('feature:cartera')->group(function () { Route::post("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "store"])->middleware('feature:facturacion_sri,salvo_interna'); });
        Route::get("credits/available", [\App\Http\Controllers\CreditApplicationController::class, "available"]);
        Route::post("credits/apply/{invoice}", [\App\Http\Controllers\CreditApplicationController::class, "apply"]);
        // Anticipos a proveedores: los abiertos de un proveedor y su aplicación a una compra
        Route::middleware('feature:cartera')->group(function () { Route::get("credits/available-supplier", [\App\Http\Controllers\CreditApplicationController::class, "availableSupplier"]); });
        Route::middleware('feature:cartera')->group(function () { Route::post("credits/apply-purchase/{purchase}", [\App\Http\Controllers\CreditApplicationController::class, "applyPurchase"]); });

        // ── Liquidación de compra ──
        Route::get('liquidacion-compra', [LiquidacionCompraController::class, 'index']);
        Route::post('liquidacion-compra', [LiquidacionCompraController::class, 'store']);
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post('liquidacion-compra/{purchase}/emit', [LiquidacionCompraController::class, 'emit']); });

        // ── Nota de débito ──
        // Listar, crear y anular van con 'cartera' (las internas existen en planes sin facturación electrónica);
        // crear una del SRI y emitirla exigen además 'facturacion_sri'.
        Route::middleware('feature:cartera')->group(function () {
            Route::get('notas-debito', [NotaDebitoController::class, 'index']);
            Route::post('notas-debito', [NotaDebitoController::class, 'store'])->middleware('feature:facturacion_sri,salvo_interna');
            Route::post('notas-debito/{notaDebito}/anular', [NotaDebitoController::class, 'anular']);
        });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post('notas-debito/{notaDebito}/emit', [NotaDebitoController::class, 'emit']); });

        // ── Guía de remisión ──
        Route::get('guias-remision', [GuiaRemisionController::class, 'index']);
        Route::post('guias-remision', [GuiaRemisionController::class, 'store']);
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post('guias-remision/{guia}/emit', [GuiaRemisionController::class, 'emit']); });

        // Fase 5 — Importación en lote
        Route::middleware('feature:import_lote')->group(function () { Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]); });
        Route::middleware('feature:import_lote')->group(function () { Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]); });
        Route::middleware('feature:import_lote')->group(function () { Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]); });
        Route::middleware('feature:import_lote')->group(function () { Route::post("pending-imports/{pending}/process", [\App\Http\Controllers\PendingImportController::class, "processOne"]); });

        // Fase 7 — Nómina
        // La nómina es del administrador y del contador: el cajero no ve sueldos ni toca roles (T7.1)
        Route::middleware(['feature:nomina', 'rol:admin,contador'])->group(function () {
            Route::apiResource("employees", \App\Http\Controllers\EmployeeController::class)
                ->only(['index','store','update','destroy']);
            Route::get("payrolls", [\App\Http\Controllers\PayrollController::class, "index"]);
            Route::get("payrolls/{payroll}", [\App\Http\Controllers\PayrollController::class, "show"]);
            Route::post("payrolls/generate", [\App\Http\Controllers\PayrollController::class, "generate"]);
            Route::post("payrolls/{payroll}/consumos", [\App\Http\Controllers\PayrollController::class, "consumos"]);
            Route::post("payrolls/{payroll}/close", [\App\Http\Controllers\PayrollController::class, "close"]);
            Route::get("payrolls/{payroll}/rol/{employee}", [\App\Http\Controllers\PayrollController::class, "rolIndividual"]);
            Route::get("payrolls/{payroll}/rol-general", [\App\Http\Controllers\PayrollController::class, "rolGeneral"]);
            Route::post("employees/{employee}/liquidacion", [\App\Http\Controllers\PayrollController::class, "liquidacion"]);
            // Movimientos de empleado (anticipos, préstamos, descuentos, ingresos extra) y permisos y vacaciones
            Route::get("employee-movements", [\App\Http\Controllers\EmployeeMovementController::class, "index"]);
            Route::post("employee-movements", [\App\Http\Controllers\EmployeeMovementController::class, "store"]);
            Route::post("employee-movements/{movement}/cancelar", [\App\Http\Controllers\EmployeeMovementController::class, "cancelar"]);
            Route::get("employee-leaves", [\App\Http\Controllers\EmployeeLeaveController::class, "index"]);
            Route::post("employee-leaves", [\App\Http\Controllers\EmployeeLeaveController::class, "store"]);
            Route::put("employee-leaves/{leave}", [\App\Http\Controllers\EmployeeLeaveController::class, "update"]);
            Route::get("employees/{employee}/vacaciones", [\App\Http\Controllers\EmployeeLeaveController::class, "vacaciones"]);
        });

        // Activos fijos (T7.2): ficha, depreciación mensual en línea recta y baja. Plan Completo; solo administrador y contador.
        // Las rutas fijas van antes de {asset} para que «depreciar», «compras» y «siguiente-codigo» no se tomen por un id.
        Route::middleware(['feature:activos_fijos', 'rol:admin,contador'])->group(function () {
            Route::get("fixed-assets", [\App\Http\Controllers\FixedAssetController::class, "index"]);
            Route::get("fixed-assets/siguiente-codigo", [\App\Http\Controllers\FixedAssetController::class, "siguienteCodigo"]);
            Route::get("fixed-assets/compras", [\App\Http\Controllers\FixedAssetController::class, "compras"]);
            Route::post("fixed-assets/depreciar", [\App\Http\Controllers\FixedAssetController::class, "depreciar"]);
            Route::post("fixed-assets", [\App\Http\Controllers\FixedAssetController::class, "store"]);
            Route::get("fixed-assets/{asset}", [\App\Http\Controllers\FixedAssetController::class, "show"]);
            Route::put("fixed-assets/{asset}", [\App\Http\Controllers\FixedAssetController::class, "update"]);
            Route::delete("fixed-assets/{asset}", [\App\Http\Controllers\FixedAssetController::class, "destroy"]);
            Route::post("fixed-assets/{asset}/baja", [\App\Http\Controllers\FixedAssetController::class, "baja"]);
        });

        // Fase B — Bodegas (la consulta está en el bloque 1)
        Route::post('warehouses', [\App\Http\Controllers\WarehouseController::class, 'store']);
        Route::match(['put', 'patch'], 'warehouses/{warehouse}', [\App\Http\Controllers\WarehouseController::class, 'update']);
        Route::delete('warehouses/{warehouse}', [\App\Http\Controllers\WarehouseController::class, 'destroy']);

        // Fase D — Notas de crédito electrónicas + retenciones emitidas
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post("credit-notes/{creditNote}/emit", [\App\Http\Controllers\CreditNoteController::class, "emit"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::get("withholdings-emitted", [\App\Http\Controllers\WithholdingEmitController::class, "index"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post("withholdings-emitted", [\App\Http\Controllers\WithholdingEmitController::class, "store"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::post("withholdings-emitted/{withholding}/emit", [\App\Http\Controllers\WithholdingEmitController::class, "emit"]); });
        Route::middleware('feature:facturacion_sri')->group(function () { Route::get("purchases/{purchase}/withholdings", [\App\Http\Controllers\WithholdingEmitController::class, "porCompra"]); });

        // Fase E — Módulo Impuestos
        Route::get("taxes/formulario104", [\App\Http\Controllers\TaxController::class, "formulario104"]);
        Route::get("taxes/ats", [\App\Http\Controllers\TaxController::class, "ats"]);

        // Fase F — Transacciones de inventario
        Route::get("inventory/stock-bodega", [\App\Http\Controllers\InventoryTransactionController::class, "stockBodega"]);
        Route::post("inventory/ajuste", [\App\Http\Controllers\InventoryTransactionController::class, "ajuste"]);
        Route::post("inventory/transferencia", [\App\Http\Controllers\InventoryTransactionController::class, "transferencia"]);
        // Las compras y los ajustes validan las series aunque el plan no incluya la pantalla de Series: este aviso previo no se restringe por plan
        Route::post("series/existentes", [\App\Http\Controllers\SerieController::class, "existentes"]);
        Route::get("inventory/kardex/{product}/bodega", [\App\Http\Controllers\InventoryTransactionController::class, "kardexBodega"]);

        // T5.2 — Toma física masiva (un borrador por bodega; al aplicarla crea los movimientos y UN asiento)
        Route::middleware('feature:inventario')->group(function () {
            Route::get("physical-counts", [\App\Http\Controllers\PhysicalCountController::class, "index"]);
            Route::post("physical-counts", [\App\Http\Controllers\PhysicalCountController::class, "store"]);
            Route::get("physical-counts/{physicalCount}", [\App\Http\Controllers\PhysicalCountController::class, "show"]);
            Route::put("physical-counts/{physicalCount}", [\App\Http\Controllers\PhysicalCountController::class, "update"]);
            Route::delete("physical-counts/{physicalCount}", [\App\Http\Controllers\PhysicalCountController::class, "destroy"]);
            Route::post("physical-counts/{physicalCount}/aplicar", [\App\Http\Controllers\PhysicalCountController::class, "aplicar"]);
            Route::get("physical-counts/{physicalCount}/hoja", [\App\Http\Controllers\PhysicalCountController::class, "hoja"]);
        });

        // Reportes de ventas y compras (Excel/PDF por tipo)
        Route::get("reportes/comprobantes", [\App\Http\Controllers\ReportesController::class, "comprobantes"]);
        Route::get("reportes/ventas", [\App\Http\Controllers\ReportesController::class, "ventas"]);
        Route::get("reportes/ventas-detalle", [\App\Http\Controllers\ReportesController::class, "ventasDetalle"]);
        Route::get("reportes/compras", [\App\Http\Controllers\ReportesController::class, "compras"]);
        Route::get("reportes/compras-sustento", [\App\Http\Controllers\ReportesController::class, "comprasPorSustento"]);

        // Fase 6 — Conversión de artículos
        Route::middleware('feature:conversion_articulos')->group(function () {
            Route::post("conversions", [\App\Http\Controllers\ArticleConversionController::class, "store"]);
        });

        // Fase 6 — Facturación masiva
        Route::middleware('feature:facturacion_masiva')->group(function () {
            Route::post("invoices/masiva", [\App\Http\Controllers\MassInvoiceController::class, "store"]);
        });

        // Fase 6 — Fraccionamiento de unidades
        Route::middleware('feature:fraccionamiento')->group(function () {
            Route::post("fractionations", [\App\Http\Controllers\FractionationController::class, "store"]);
        });

        // Fase 6 — Reservas de stock
        Route::middleware('feature:reservas_stock')->group(function () {
            Route::get("stock-reservations", [\App\Http\Controllers\StockReservationController::class, "index"]);
            Route::post("stock-reservations", [\App\Http\Controllers\StockReservationController::class, "store"]);
            Route::post("stock-reservations/{reservation}/cancel", [\App\Http\Controllers\StockReservationController::class, "cancel"]);
        });

        // Fase 1 — CRUD faltante (update/destroy/anular)
        Route::put("advances/{advance}", [\App\Http\Controllers\AdvanceController::class, "update"]);
        Route::delete("advances/{advance}", [\App\Http\Controllers\AdvanceController::class, "destroy"]);
        Route::post("credit-notes/{creditNote}/anular", [\App\Http\Controllers\CreditNoteController::class, "anular"]);
        Route::put("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "update"])->middleware('rol:admin');

        // Fase 2b — Importación SRI pluggable
        Route::post("sri/importar", [\App\Http\Controllers\SriImportController::class, "importar"]);
        // Ventas emitidas desde el portal del SRI: se registran subiendo el XML autorizado de cada factura
        Route::middleware('feature:import_lote')->group(function () { Route::post("sri/importar-ventas-xml", [\App\Http\Controllers\SriImportController::class, "importarVentasXml"]); });
    });
});
