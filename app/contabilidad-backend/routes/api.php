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

// Protegido por token Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::post('/companies/{company}/logo', [CompanyController::class, 'logo']);
    Route::put('/companies/{company}', [CompanyController::class, 'update']);
    Route::get('/companies/{company}/plan', [CompanyController::class, 'plan']);
    Route::post('/companies/{company}/plan', [CompanyController::class, 'cambiarPlan']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::put('/accounts/{account}', [AccountController::class, 'update']);
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);

    // Resumen ejecutivo de la pestaña Inicio
    Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

    // Catálogos
    Route::apiResource('contacts', \App\Http\Controllers\ContactController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('sri/consulta', [\App\Http\Controllers\SriLookupController::class, 'consulta']);
    Route::apiResource('products', \App\Http\Controllers\ProductController::class)->only(['index', 'store', 'update', 'destroy']);
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
    Route::apiResource('banks', \App\Http\Controllers\BankController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('products/lookup', [\App\Http\Controllers\ProductController::class, 'lookup']);

    // Ventas
    Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store']);
    Route::post("invoices/{invoice}/anular", [\App\Http\Controllers\InvoiceController::class, "anular"]);

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

    // Contabilidad
    Route::get("journal", [\App\Http\Controllers\AccountingController::class, "journal"]);
    Route::post("journal/mayorizar", [\App\Http\Controllers\AccountingController::class, "mayorizar"]);
    Route::post("journal/{entry}/desmayorizar", [\App\Http\Controllers\AccountingController::class, "desmayorizar"]);
    Route::get("income-statement", [\App\Http\Controllers\AccountingController::class, "incomeStatement"]);
    Route::get("balance-sheet", [\App\Http\Controllers\AccountingController::class, "balanceSheet"]);
    Route::get("ledger", [\App\Http\Controllers\AccountingController::class, "ledger"]);

    // Caja
    Route::get("cash/current", [\App\Http\Controllers\CashController::class, "current"]);
    Route::post("cash/open", [\App\Http\Controllers\CashController::class, "open"]);
    Route::post("cash/{session}/movements", [\App\Http\Controllers\CashController::class, "addMovement"]);
    Route::post("cash/{session}/close", [\App\Http\Controllers\CashController::class, "close"]);

    // Cartera
    Route::get("receivables", [\App\Http\Controllers\ReceivableController::class, "index"]);
    Route::post("receivables/{invoice}/pay", [\App\Http\Controllers\ReceivableController::class, "pay"]);
    Route::get("payables", [\App\Http\Controllers\PayableController::class, "index"]);
    Route::post("payables/{purchase}/pay", [\App\Http\Controllers\PayableController::class, "pay"]);
    Route::post("payables/pay-multiple", [\App\Http\Controllers\PayableController::class, 'payMultiple']);

    // Retenciones
    Route::get("withholdings", [\App\Http\Controllers\WithholdingController::class, "index"]);
    Route::post("withholdings/import", [\App\Http\Controllers\WithholdingController::class, "import"]);

    // Documentos SRI
    Route::get("sri-documents/pending", [\App\Http\Controllers\SriDocumentController::class, "pending"]);
    Route::post("sri-documents/authorize-batch", [\App\Http\Controllers\SriDocumentController::class, "authorizeBatch"]);

    // Cotizaciones
    Route::get("quotes", [\App\Http\Controllers\QuoteController::class, "index"]);
    Route::post("quotes", [\App\Http\Controllers\QuoteController::class, "store"]);
    Route::post("quotes/{quote}/convert", [\App\Http\Controllers\QuoteController::class, "convert"]);
    Route::delete("quotes/{quote}", [\App\Http\Controllers\QuoteController::class, "destroy"]);

    // Bancos y conciliación
    Route::middleware('feature:conciliacion')->group(function () {
        Route::get("bank-movements", [\App\Http\Controllers\BankMovementController::class, "index"]);
        Route::post("bank-movements", [\App\Http\Controllers\BankMovementController::class, "store"]);
        Route::post("bank-movements/{movement}/toggle", [\App\Http\Controllers\BankMovementController::class, "toggle"]);
        Route::post("bank-movements/auto-match", [\App\Http\Controllers\BankMovementController::class, "autoMatch"]);
    });

    // Puntos de emisión + certificado
    Route::get("emission-points", [\App\Http\Controllers\EmissionPointController::class, "index"]);
    Route::post("emission-points", [\App\Http\Controllers\EmissionPointController::class, "store"]);
    Route::delete("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "destroy"]);
    Route::post("companies/{company}/certificate", [\App\Http\Controllers\CompanyController::class, "uploadCertificate"]);
    // EDocuments — configuración completa (firma + SRI + correo), como KVS
    Route::get("companies/{company}/edoc-config", [\App\Http\Controllers\EdocConfigController::class, "show"]);
    Route::post("companies/{company}/edoc-config", [\App\Http\Controllers\EdocConfigController::class, "update"]);
    Route::post("companies/{company}/smtp/test", [\App\Http\Controllers\SmtpTestController::class, "send"]);

    // Fase 2 — Series (garantías)
    Route::middleware('feature:series')->group(function () {
        Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
        Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
        Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
        Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);
        Route::put("series/{serie}", [\App\Http\Controllers\SerieController::class, "update"]);
        Route::delete("series/{serie}", [\App\Http\Controllers\SerieController::class, "destroy"]);
    });

    // Fase 3 — Usuarios y auditoría
    Route::get("users", [\App\Http\Controllers\UserController::class, "index"]);
    Route::post("users", [\App\Http\Controllers\UserController::class, "store"]);
    Route::put("users/{user}", [\App\Http\Controllers\UserController::class, "update"]);
    Route::delete("users/{user}", [\App\Http\Controllers\UserController::class, "destroy"]);

    Route::middleware('feature:auditoria')->group(function () {
        Route::get("audit", [\App\Http\Controllers\AuditController::class, "index"]);
    });

    // Fase 4 — Notas de crédito, anticipos, uso de saldos
    Route::get("advances", [\App\Http\Controllers\AdvanceController::class, "index"]);
    Route::post("advances", [\App\Http\Controllers\AdvanceController::class, "store"]);
    Route::get("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "index"]);
    Route::post("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "store"]);
    Route::get("credits/available", [\App\Http\Controllers\CreditApplicationController::class, "available"]);
    Route::post("credits/apply/{invoice}", [\App\Http\Controllers\CreditApplicationController::class, "apply"]);

    // ── Liquidación de compra ──
    Route::get('liquidacion-compra', [LiquidacionCompraController::class, 'index']);
    Route::post('liquidacion-compra', [LiquidacionCompraController::class, 'store']);
    Route::post('liquidacion-compra/{purchase}/emit', [LiquidacionCompraController::class, 'emit']);

    // ── Nota de débito ──
    Route::get('notas-debito', [NotaDebitoController::class, 'index']);
    Route::post('notas-debito', [NotaDebitoController::class, 'store']);
    Route::post('notas-debito/{notaDebito}/emit', [NotaDebitoController::class, 'emit']);

    // ── Guía de remisión ──
    Route::get('guias-remision', [GuiaRemisionController::class, 'index']);
    Route::post('guias-remision', [GuiaRemisionController::class, 'store']);
    Route::post('guias-remision/{guia}/emit', [GuiaRemisionController::class, 'emit']);

    // Fase 5 — Importación en lote
    Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]);
    Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]);
    Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]);
    Route::post("pending-imports/{pending}/process", [\App\Http\Controllers\PendingImportController::class, "processOne"]);

    // Fase 7 — Nómina
    Route::middleware('feature:nomina')->group(function () {
        Route::apiResource("employees", \App\Http\Controllers\EmployeeController::class)
            ->only(['index','store','update','destroy']);
        Route::get("payrolls", [\App\Http\Controllers\PayrollController::class, "index"]);
        Route::get("payrolls/{payroll}", [\App\Http\Controllers\PayrollController::class, "show"]);
        Route::post("payrolls/generate", [\App\Http\Controllers\PayrollController::class, "generate"]);
        Route::post("payrolls/{payroll}/close", [\App\Http\Controllers\PayrollController::class, "close"]);
        Route::post("employees/{employee}/liquidacion", [\App\Http\Controllers\PayrollController::class, "liquidacion"]);
    });

    // Fase A — Catálogos (formas de pago, sustentos tributarios)
    Route::get("catalogos/formas-pago", [\App\Http\Controllers\CatalogosController::class, "formasPago"]);
    Route::get("catalogos/sustentos", [\App\Http\Controllers\CatalogosController::class, "sustentos"]);

    // Fase B — Bodegas
    Route::apiResource('warehouses', \App\Http\Controllers\WarehouseController::class)->only(['index', 'store', 'update', 'destroy']);

    // Fase D — Notas de crédito electrónicas + retenciones emitidas
    Route::post("credit-notes/{creditNote}/emit", [\App\Http\Controllers\CreditNoteController::class, "emit"]);
    Route::get("withholdings-emitted", [\App\Http\Controllers\WithholdingEmitController::class, "index"]);
    Route::post("withholdings-emitted", [\App\Http\Controllers\WithholdingEmitController::class, "store"]);

    // Fase E — Módulo Impuestos
    Route::get("taxes/formulario104", [\App\Http\Controllers\TaxController::class, "formulario104"]);
    Route::get("taxes/ats", [\App\Http\Controllers\TaxController::class, "ats"]);

    // Fase F — Transacciones de inventario
    Route::post("inventory/ajuste", [\App\Http\Controllers\InventoryTransactionController::class, "ajuste"]);
    Route::post("inventory/transferencia", [\App\Http\Controllers\InventoryTransactionController::class, "transferencia"]);
    Route::get("inventory/kardex/{product}/bodega", [\App\Http\Controllers\InventoryTransactionController::class, "kardexBodega"]);

    // Fase 5 — Reportes
    Route::get("reports/stock", [\App\Http\Controllers\ReportController::class, "stockReport"]);
    Route::get("reports/kardex/{product}", [\App\Http\Controllers\ReportController::class, "kardexReport"]);
    Route::post("reports/pdf", [\App\Http\Controllers\ReportController::class, "generatePdf"]);
    Route::get("reports/csv", [\App\Http\Controllers\ReportController::class, "exportCsv"]);
    Route::get("reports/series", [\App\Http\Controllers\ReportController::class, "seriesReport"]);
    Route::get("reports/series-csv", [\App\Http\Controllers\ReportController::class, "exportSeriesCsv"]);

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
    Route::put("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "update"]);

    // Fase 2b — Importación SRI pluggable
    Route::post("sri/importar", [\App\Http\Controllers\SriImportController::class, "importar"]);
});
