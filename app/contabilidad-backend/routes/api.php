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

    Route::get('/accounts', [AccountController::class, 'index']);

    // Fase 1 — Catálogos
    Route::apiResource('contacts', \App\Http\Controllers\ContactController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('products', \App\Http\Controllers\ProductController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('banks', \App\Http\Controllers\BankController::class)->only(['index', 'store', 'update', 'destroy']);

    // Fase 2 — Ventas
    Route::apiResource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store']);

    // Fase 3 — Compras (importar XML del SRI)
    Route::get("purchases", [\App\Http\Controllers\PurchaseController::class, "index"]);
    Route::post("purchases/import", [\App\Http\Controllers\PurchaseController::class, "import"]);

    // Fase 4 — Inventario
    Route::get("inventory/stock", [\App\Http\Controllers\InventoryController::class, "stock"]);
    Route::get("inventory/kardex/{product}", [\App\Http\Controllers\InventoryController::class, "kardex"]);
    // Fase 6 — Contabilidad
    Route::get("journal", [\App\Http\Controllers\AccountingController::class, "journal"]);
    Route::post("journal/mayorizar", [\App\Http\Controllers\AccountingController::class, "mayorizar"]);
    Route::post("journal/{entry}/desmayorizar", [\App\Http\Controllers\AccountingController::class, "desmayorizar"]);
    Route::get("income-statement", [\App\Http\Controllers\AccountingController::class, "incomeStatement"]);
    Route::get("balance-sheet", [\App\Http\Controllers\AccountingController::class, "balanceSheet"]);

    // Fase 5 — Caja
    Route::get("cash/current", [\App\Http\Controllers\CashController::class, "current"]);
    Route::post("cash/open", [\App\Http\Controllers\CashController::class, "open"]);
    Route::post("cash/{session}/movements", [\App\Http\Controllers\CashController::class, "addMovement"]);
    Route::post("cash/{session}/close", [\App\Http\Controllers\CashController::class, "close"]);

    // Cartera: cuentas por cobrar y por pagar
    Route::get("receivables", [\App\Http\Controllers\ReceivableController::class, "index"]);
    Route::post("receivables/{invoice}/pay", [\App\Http\Controllers\ReceivableController::class, "pay"]);
    Route::get("payables", [\App\Http\Controllers\PayableController::class, "index"]);
    Route::post("payables/{purchase}/pay", [\App\Http\Controllers\PayableController::class, "pay"]);
    // Retenciones recibidas (empate automático)
    Route::get("withholdings", [\App\Http\Controllers\WithholdingController::class, "index"]);
    Route::post("withholdings/import", [\App\Http\Controllers\WithholdingController::class, "import"]);
    // Módulo de documentos SRI (autorizar en lote)
    Route::get("sri-documents/pending", [\App\Http\Controllers\SriDocumentController::class, "pending"]);
    Route::post("sri-documents/authorize-batch", [\App\Http\Controllers\SriDocumentController::class, "authorizeBatch"]);
    // Cotizaciones
    Route::get("quotes", [\App\Http\Controllers\QuoteController::class, "index"]);
    Route::post("quotes", [\App\Http\Controllers\QuoteController::class, "store"]);
    Route::post("quotes/{quote}/convert", [\App\Http\Controllers\QuoteController::class, "convert"]);
    // Conciliación bancaria
    Route::get("bank-movements", [\App\Http\Controllers\BankMovementController::class, "index"]);
    Route::post("bank-movements", [\App\Http\Controllers\BankMovementController::class, "store"]);
    Route::post("bank-movements/{movement}/toggle", [\App\Http\Controllers\BankMovementController::class, "toggle"]);
    // Libro mayor + puntos de emisión + certificado .p12
    Route::get("ledger", [\App\Http\Controllers\AccountingController::class, "ledger"]);
    Route::get("emission-points", [\App\Http\Controllers\EmissionPointController::class, "index"]);
    Route::post("emission-points", [\App\Http\Controllers\EmissionPointController::class, "store"]);
    Route::delete("emission-points/{point}", [\App\Http\Controllers\EmissionPointController::class, "destroy"]);
    Route::post("companies/{company}/certificate", [\App\Http\Controllers\CompanyController::class, "uploadCertificate"]);
});
