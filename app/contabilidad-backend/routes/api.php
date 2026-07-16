<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

// Público
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Protegido por token Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/companies/{company}/plan', [CompanyController::class, 'plan']);
    Route::post('/companies/{company}/plan', [CompanyController::class, 'cambiarPlan']);

    Route::get('/accounts', [AccountController::class, 'index']);

    // Catálogos
    Route::apiResource('contacts', \App\Http\Controllers\ContactController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('products', \App\Http\Controllers\ProductController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('banks', \App\Http\Controllers\BankController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('products/lookup', [\App\Http\Controllers\ProductController::class, 'lookup']);

    // Ventas
    Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store']);

    // Compras
    Route::get("purchases", [\App\Http\Controllers\PurchaseController::class, "index"]);
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

    // Bancos y conciliación
    Route::get("bank-movements", [\App\Http\Controllers\BankMovementController::class, "index"]);
    Route::post("bank-movements", [\App\Http\Controllers\BankMovementController::class, "store"]);
    Route::post("bank-movements/{movement}/toggle", [\App\Http\Controllers\BankMovementController::class, "toggle"]);
    Route::post("bank-movements/auto-match", [\App\Http\Controllers\BankMovementController::class, "autoMatch"]);

    // Puntos de emisión + certificado
    Route::get("emission-points", [\App\Http\Controllers\EmissionPointController::class, "index"]);
    Route::post("emission-points", [\App\Http\Controllers\EmissionPointController::class, "store"]);
    Route::delete("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "destroy"]);
    Route::post("companies/{company}/certificate", [\App\Http\Controllers\CompanyController::class, "uploadCertificate"]);

    // Fase 2 — Series (garantías)
    Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
    Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
    Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
    Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);

    // Fase 3 — Usuarios y auditoría
    Route::get("users", [\App\Http\Controllers\UserController::class, "index"]);
    Route::post("users", [\App\Http\Controllers\UserController::class, "store"]);
    Route::put("users/{user}", [\App\Http\Controllers\UserController::class, "update"]);
    Route::get("audit", [\App\Http\Controllers\AuditController::class, "index"]);

    // Fase 4 — Notas de crédito, anticipos, uso de saldos
    Route::get("advances", [\App\Http\Controllers\AdvanceController::class, "index"]);
    Route::post("advances", [\App\Http\Controllers\AdvanceController::class, "store"]);
    Route::get("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "index"]);
    Route::post("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "store"]);
    Route::get("credits/available", [\App\Http\Controllers\CreditApplicationController::class, "available"]);
    Route::post("credits/apply/{invoice}", [\App\Http\Controllers\CreditApplicationController::class, "apply"]);

    // Fase 5 — Importación en lote
    Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]);
    Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]);
    Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]);
    Route::post("pending-imports/{pending}/process", [\App\Http\Controllers\PendingImportController::class, "processOne"]);

    // Fase 7 — Nómina
    Route::apiResource("employees", \App\Http\Controllers\EmployeeController::class)
        ->only(['index','store','update','destroy']);
    Route::get("payrolls", [\App\Http\Controllers\PayrollController::class, "index"]);
    Route::get("payrolls/{payroll}", [\App\Http\Controllers\PayrollController::class, "show"]);
    Route::post("payrolls/generate", [\App\Http\Controllers\PayrollController::class, "generate"]);
    Route::post("payrolls/{payroll}/close", [\App\Http\Controllers\PayrollController::class, "close"]);
    Route::post("employees/{employee}/liquidacion", [\App\Http\Controllers\PayrollController::class, "liquidacion"]);
});
