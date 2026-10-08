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
use App\Controllers\SalesController;
use App\Controllers\ReportController;
use App\Controllers\AuditController;
use App\Controllers\UserController;
use App\Controllers\ExpenseController;
use App\Controllers\ChequeController;
use App\Controllers\PurchaseController;
use App\Controllers\PaymentController;
use App\Controllers\ReceiptController;
use App\Controllers\CounterController;
use App\Services\CounterService;
use App\Services\LedgerService;
use App\Services\DocNumberService;
use App\Services\CashService;
use App\Services\PosService;
use App\Services\RateService;
use App\Repositories\PartyRepository;
use App\Repositories\CylinderGroupRepository;
use App\Repositories\CylinderRepository;
use App\Repositories\RateRepository;
use App\Repositories\StockBatchRepository;
use App\Services\CodeGenerator;
use App\Services\StockService;
use App\Services\CylinderStatus;
use App\Services\AuditService;
use App\Core\Validator;
use App\Controllers\PasswordController;
use App\Services\PasswordService;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use App\Services\ImportService;
use App\Services\XlsxService;
use App\Services\UserService;
use App\Services\ExpenseService;
use App\Services\ChequeService;
use App\Services\PurchaseService;
use App\Services\PaymentService;
use App\Services\ReceiptService;

$authController = new AuthController($auth, $request, new Validator(), $session);
$passwordService = new PasswordService($db, new AuditService($db));
$passwordController = new PasswordController($auth, $request, $session, new Validator(), $passwordService);
$homeController = new HomeController($auth);
$auditService = new AuditService($db);
$partyController = new PartyController(new PartyRepository($db), $auth, $request, new Validator(), $auditService);
$groupController = new CylinderGroupController(new CylinderGroupRepository($db), $auth, $request, new Validator(), $auditService, new CodeGenerator($db));
$cylinderController = new CylinderController(new CylinderRepository($db), new CodeGenerator($db), $auth, $request, new Validator(), $auditService, $db);
$rateController = new RateController(new RateRepository($db), $auth, $request, new Validator(), $auditService);
$importStockService = new StockService($db, new CodeGenerator($db));
$importService = new ImportService($db, new XlsxService(), $importStockService, $auditService);
$openingStockController = new OpeningStockController(new StockBatchRepository($db), $importStockService, $db, $auth, $request, new Validator(), $auditService, $importService, new XlsxService());
$settingsController = new SettingsController(
    new SettingsService(new SettingsRepository($db)),
    $auth,
    $request,
    $auditService
);
$counterController = new CounterController(new CounterService($db), $auth, $request);
$posStockService = new StockService($db, new CodeGenerator($db));
$posService = new PosService(
    $db,
    new DocNumberService($db),
    new LedgerService($db),
    new CashService($db),
    new RateService($db),
    $posStockService,
    $auditService
);
$posController = new PosController(
    $posService,
    new RateService($db),
    $db,
    $auth,
    $request,
    new Validator()
);
$salesController = new SalesController($db, $auth, $request, $posService);
$receiptService = new ReceiptService($db, new DocNumberService($db), new LedgerService($db), new CashService($db), $auditService);
$paymentService = new PaymentService($db, new DocNumberService($db), new LedgerService($db), new CashService($db), $auditService);
$purchaseService = new PurchaseService($db, new DocNumberService($db), new LedgerService($db), new CashService($db), $posStockService, new CodeGenerator($db), $auditService);
$chequeService = new ChequeService($db, new LedgerService($db), $auditService);
$expenseService = new ExpenseService($db, new CashService($db), $auditService);
$userService = new UserService($db, $auditService);
$receiptController = new ReceiptController($db, $auth, $request, $receiptService);
$paymentController = new PaymentController($db, $auth, $request, $paymentService);
$purchaseController = new PurchaseController($db, $auth, $request, $purchaseService);
$chequeController = new ChequeController($db, $auth, $request, $chequeService);
$expenseController = new ExpenseController($db, $auth, $request, $expenseService);
$userController = new UserController($db, $auth, $request, $userService);
$reportController = new ReportController($db, $auth, $request);
$auditController = new AuditController($db, $auth, $request);


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
$router->post('/parties/{id}/delete', [$partyController, 'delete'], true, 'parties.create');
$router->get('/cylinder-groups', [$groupController, 'index'], true, 'cylinder_groups.view');
$router->get('/cylinder-groups/data', [$groupController, 'data'], true, 'cylinder_groups.view');
$router->post('/cylinder-groups', [$groupController, 'store'], true, 'cylinder_groups.create');
$router->post('/cylinder-groups/{id}/delete', [$groupController, 'delete'], true, 'cylinder_groups.create');
$router->get('/cylinders', [$cylinderController, 'index'], true, 'cylinders.view');
$router->get('/cylinders/data', [$cylinderController, 'data'], true, 'cylinders.view');
$router->post('/cylinders', [$cylinderController, 'store'], true, 'cylinders.create');
$router->get('/cylinders/{id}/history', [$cylinderController, 'history'], true, 'cylinders.view');
$router->post('/cylinders/{id}', [$cylinderController, 'update'], true, 'cylinders.create');
$router->post('/cylinders/{id}/delete', [$cylinderController, 'delete'], true, 'cylinders.create');
$router->get('/rates', [$rateController, 'index'], true, 'rates.view');
$router->get('/rates/data', [$rateController, 'data'], true, 'rates.view');
$router->post('/rates', [$rateController, 'store'], true, 'rates.create');
$router->get('/opening-stock', [$openingStockController, 'index'], true, 'opening_stock.view');
$router->get('/opening-stock/data', [$openingStockController, 'data'], true, 'opening_stock.view');
$router->post('/opening-stock', [$openingStockController, 'store'], true, 'opening_stock.create');
$router->get('/opening-stock/template', [$openingStockController, 'template'], true, 'opening_stock.create');
$router->post('/opening-stock/import/preview', [$openingStockController, 'importPreview'], true, 'opening_stock.create');
$router->post('/opening-stock/import/commit', [$openingStockController, 'importCommit'], true, 'opening_stock.create');
$router->post('/opening-stock/void', [$openingStockController, 'void'], true, 'opening_stock.void');

$router->get('/pos', [$posController, 'index'], true, 'sales.create');
$router->get('/pos/cylinders', [$posController, 'cylinders'], true, 'sales.create');
$router->get('/pos/customers', [$posController, 'customers'], true, 'sales.create');
$router->get('/pos/issued/{customerId}', [$posController, 'issued'], true, 'sales.create');
$router->get('/pos/config', [$posController, 'config'], true, 'sales.create');
$router->post('/pos', [$posController, 'store'], true, 'sales.create');
$router->get('/sales', [$salesController, 'index'], true, 'sales.view');
$router->get('/sales/data', [$salesController, 'data'], true, 'sales.view');
$router->get('/sales/{id}', [$salesController, 'detail'], true, 'sales.view');
$router->post('/sales/{id}/void', [$salesController, 'void'], true, 'sales.void');

$router->get('/receipts', [$receiptController, 'index'], true, 'receipts.view');
$router->get('/receipts/data', [$receiptController, 'data'], true, 'receipts.view');
$router->get('/receipts/{id}/print', [$receiptController, 'print'], true, 'receipts.view');
$router->post('/receipts', [$receiptController, 'store'], true, 'receipts.create');
$router->post('/receipts/{id}/void', [$receiptController, 'void'], true, 'receipts.void');

$router->get('/purchases', [$purchaseController, 'index'], true, 'purchases.view');
$router->get('/purchases/data', [$purchaseController, 'data'], true, 'purchases.view');
$router->post('/purchases', [$purchaseController, 'store'], true, 'purchases.create');
$router->post('/purchases/{id}/void', [$purchaseController, 'void'], true, 'purchases.void');

$router->get('/payments', [$paymentController, 'index'], true, 'payments.view');
$router->get('/payments/data', [$paymentController, 'data'], true, 'payments.view');
$router->post('/payments', [$paymentController, 'store'], true, 'payments.create');
$router->post('/payments/{id}/void', [$paymentController, 'void'], true, 'payments.void');

$router->get('/cheques', [$chequeController, 'index'], true, 'cheques.view');
$router->get('/cheques/data', [$chequeController, 'data'], true, 'cheques.view');
$router->post('/cheques/{id}/clear', [$chequeController, 'clear'], true, 'cheques.manage');
$router->post('/cheques/{id}/bounce', [$chequeController, 'bounce'], true, 'cheques.manage');

$router->get('/expenses', [$expenseController, 'index'], true, 'expenses.view');
$router->get('/expenses/data', [$expenseController, 'data'], true, 'expenses.view');
$router->post('/expenses', [$expenseController, 'store'], true, 'expenses.create');
$router->post('/expenses/{id}/void', [$expenseController, 'void'], true, 'expenses.void');

$router->get('/users', [$userController, 'index'], true, 'users.view');
$router->post('/users', [$userController, 'save'], true, 'users.manage');
$router->get('/users/roles/{role}/permissions', [$userController, 'rolePermissions'], true, 'users.view');
$router->post('/users/roles/{role}/permissions', [$userController, 'saveRolePermissions'], true, 'users.manage');

$router->get('/reports', [$reportController, 'index'], true, 'reports.view');
$router->get('/reports/data', [$reportController, 'data'], true, 'reports.view');
$router->get('/reports/csv', [$reportController, 'csv'], true, 'reports.view');
$router->get('/audit', [$auditController, 'index'], true, 'users.view');
$router->get('/audit/data', [$auditController, 'data'], true, 'users.view');

$router->get('/counter', [$counterController, 'index'], true, 'counter.view');
$router->get('/counter/status', [$counterController, 'status'], true, 'counter.view');
$router->get('/counter/summary', [$counterController, 'summary'], true, 'counter.view');
$router->post('/counter/open', [$counterController, 'open'], true, 'counter.open');
$router->post('/counter/manual', [$counterController, 'manual'], true, 'counter.open');
$router->post('/counter/close', [$counterController, 'close'], true, 'counter.close');
