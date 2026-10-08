<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SettingsController;
use App\Controllers\PartyController;
use App\Controllers\CylinderGroupController;
use App\Controllers\CylinderController;
use App\Controllers\RateController;
use App\Controllers\OpeningStockController;
use App\Controllers\PosController;
use App\Controllers\CounterController;
use App\Controllers\ReceiptController;
use App\Controllers\PaymentController;
use App\Controllers\PurchaseController;
use App\Controllers\ChequeController;
use App\Controllers\ExpenseController;
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Services\CounterService;
use App\Services\LedgerService;
use App\Services\DocNumberService;
use App\Services\CashService;
use App\Services\PosService;
use App\Services\RateService;
use App\Services\StockService;
use App\Services\CodeGenerator;
use App\Services\ReceiptService;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\ChequeService;
use App\Services\ExpenseService;
use App\Services\ReportService;
use App\Services\AuditService;
use App\Services\UserService;
use App\Services\PasswordService;
use App\Services\SettingsService;
use App\Repositories\PartyRepository;
use App\Repositories\CylinderGroupRepository;
use App\Repositories\CylinderRepository;
use App\Repositories\RateRepository;
use App\Repositories\StockBatchRepository;
use App\Repositories\SettingsRepository;
use App\Core\Validator;
use App\Controllers\PasswordController;

$authController = new AuthController($auth, $request, new Validator(), $session);
$auditService = new AuditService($db);
$passwordService = new PasswordService($db, $auditService);
$passwordController = new PasswordController($auth, $request, $session, new Validator(), $passwordService);
$homeController = new HomeController($auth);

$partyController = new PartyController(new PartyRepository($db), $auth, $request, new Validator(), $auditService);
$groupController = new CylinderGroupController(new CylinderGroupRepository($db), $auth, $request, new Validator(), $auditService);
$cylinderController = new CylinderController(new CylinderRepository($db), new CodeGenerator($db), $auth, $request, new Validator(), $auditService, $db);
$rateController = new RateController(new RateRepository($db), $auth, $request, new Validator(), $auditService);

$stockService = new StockService($db, new CodeGenerator($db));
$openingStockController = new OpeningStockController(new StockBatchRepository($db), $stockService, $auth, $request, new Validator(), $auditService);

$ledgerService = new LedgerService($db);
$cashService = new CashService($db);
$docService = new DocNumberService($db);
$rateService = new RateService($db);

$posService = new PosService($db, $docService, $ledgerService, $cashService, $rateService, $stockService);
$posController = new PosController($posService, $rateService, $db, $auth, $request, new Validator());

$counterController = new CounterController(new CounterService($db), $auth, $request);

$receiptService = new ReceiptService($db, $docService, $ledgerService, $cashService);
$receiptController = new ReceiptController($receiptService, $auth, $request);

$paymentService = new PaymentService($db, $docService, $ledgerService, $cashService);
$paymentController = new PaymentController($paymentService, $auth, $request);

$purchaseService = new PurchaseService($db, $docService, $ledgerService, $cashService, $stockService, new CodeGenerator($db));
$purchaseController = new PurchaseController($purchaseService, $auth, $request);

$chequeService = new ChequeService($db, $ledgerService);
$chequeController = new ChequeController($chequeService, $auth, $request);

$expenseService = new ExpenseService($db, $cashService, $docService);
$expenseController = new ExpenseController($expenseService, $auth, $request);

$reportController = new ReportController(new ReportService($db), $auth, $request);

$userController = new UserController(new UserService($db, $auditService), $auth, $request);

$settingsController = new SettingsController(
    new SettingsService(new SettingsRepository($db)),
    $auth,
    $request,
    $auditService
);

$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout'], true);
$router->get('/password/change', [$passwordController, 'show'], true);
$router->post('/password/change', [$passwordController, 'change'], true);
$router->get('/', [$homeController, 'index'], true, 'dashboard.view');

$router->get('/settings', [$settingsController, 'index'], true, 'settings.view');
$router->post('/settings/pos', [$settingsController, 'updatePos'], true, 'settings.manage');

$router->get('/parties', [$partyController, 'index'], true, 'parties.view');
$router->get('/parties/data', [$partyController, 'data'], true, 'parties.view');
$router->post('/parties', [$partyController, 'store'], true, 'parties.create');

$router->get('/cylinder-groups', [$groupController, 'index'], true, 'cylinder_groups.view');
$router->get('/cylinder-groups/data', [$groupController, 'data'], true, 'cylinder_groups.view');
$router->post('/cylinder-groups', [$groupController, 'store'], true, 'cylinder_groups.create');

$router->get('/cylinders', [$cylinderController, 'index'], true, 'cylinders.view');
$router->get('/cylinders/data', [$cylinderController, 'data'], true, 'cylinders.view');
$router->post('/cylinders', [$cylinderController, 'store'], true, 'cylinders.create');

$router->get('/rates', [$rateController, 'index'], true, 'rates.view');
$router->get('/rates/data', [$rateController, 'data'], true, 'rates.view');
$router->post('/rates', [$rateController, 'store'], true, 'rates.create');

$router->get('/opening-stock', [$openingStockController, 'index'], true, 'opening_stock.view');
$router->get('/opening-stock/data', [$openingStockController, 'data'], true, 'opening_stock.view');
$router->post('/opening-stock', [$openingStockController, 'store'], true, 'opening_stock.create');
$router->post('/opening-stock/void', [$openingStockController, 'void'], true, 'opening_stock.void');

$router->get('/pos', [$posController, 'index'], true, 'sales.create');
$router->get('/pos/config', [$posController, 'config'], true, 'sales.create');
$router->get('/pos/cylinders', [$posController, 'cylinders'], true, 'sales.create');
$router->get('/pos/customers', [$posController, 'customers'], true, 'sales.create');
$router->get('/pos/info', [$posController, 'info'], true, 'sales.create');
$router->get('/pos/issued', [$posController, 'issued'], true, 'sales.create');
$router->post('/pos', [$posController, 'store'], true, 'sales.create');

$router->get('/sales-history', [$posController, 'history'], true, 'sales.view');
$router->get('/sales-history/detail', [$posController, 'detail'], true, 'sales.view');
$router->post('/sales-history/void', [$posController, 'void'], true, 'sales.void');

$router->get('/receipts', [$receiptController, 'index'], true, 'receipts.view');
$router->get('/receipts/data', [$receiptController, 'data'], true, 'receipts.view');
$router->get('/receipts/customers', [$receiptController, 'customers'], true, 'receipts.create');
$router->post('/receipts', [$receiptController, 'store'], true, 'receipts.create');
$router->post('/receipts/void', [$receiptController, 'void'], true, 'receipts.void');

$router->get('/purchases', [$purchaseController, 'index'], true, 'purchases.view');
$router->get('/purchases/data', [$purchaseController, 'data'], true, 'purchases.view');
$router->get('/purchases/suppliers', [$purchaseController, 'suppliers'], true, 'purchases.create');
$router->get('/purchases/cylinders', [$purchaseController, 'cylinders'], true, 'purchases.create');
$router->post('/purchases', [$purchaseController, 'store'], true, 'purchases.create');
$router->post('/purchases/void', [$purchaseController, 'void'], true, 'purchases.void');

$router->get('/payments', [$paymentController, 'index'], true, 'payments.view');
$router->get('/payments/data', [$paymentController, 'data'], true, 'payments.view');
$router->get('/payments/suppliers', [$paymentController, 'suppliers'], true, 'payments.create');
$router->post('/payments', [$paymentController, 'store'], true, 'payments.create');
$router->post('/payments/void', [$paymentController, 'void'], true, 'payments.void');

$router->get('/cheques', [$chequeController, 'index'], true, 'cheques.view');
$router->get('/cheques/data', [$chequeController, 'data'], true, 'cheques.view');
$router->post('/cheques/clear', [$chequeController, 'clear'], true, 'cheques.manage');
$router->post('/cheques/bounce', [$chequeController, 'bounce'], true, 'cheques.manage');

$router->get('/counter', [$counterController, 'index'], true, 'counter.view');
$router->get('/counter/status', [$counterController, 'status'], true, 'counter.view');
$router->post('/counter/open', [$counterController, 'open'], true, 'counter.open');
$router->post('/counter/close', [$counterController, 'close'], true, 'counter.close');

$router->get('/expenses', [$expenseController, 'index'], true, 'expenses.view');
$router->get('/expenses/data', [$expenseController, 'data'], true, 'expenses.view');
$router->get('/expenses/categories', [$expenseController, 'categories'], true, 'expenses.create');
$router->post('/expenses', [$expenseController, 'store'], true, 'expenses.create');
$router->post('/expenses/void', [$expenseController, 'void'], true, 'expenses.void');

$router->get('/reports', [$reportController, 'index'], true, 'reports.view');
$router->get('/reports/data', [$reportController, 'data'], true, 'reports.view');
$router->get('/reports/export', [$reportController, 'export'], true, 'reports.export');

$router->get('/users', [$userController, 'index'], true, 'users.view');
$router->get('/users/data', [$userController, 'data'], true, 'users.view');
$router->post('/users', [$userController, 'store'], true, 'users.manage');
