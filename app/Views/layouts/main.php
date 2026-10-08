<?php
$config = App\Core\Config::load(base_path());
$appName = $config['app_name'];
$user = $user ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? $appName) ?> · <?= e($appName) ?></title>
    <meta name="csrf-token" content="<?= e($GLOBALS['csrf']->token()) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar">
        <div class="sidebar-header">
            <a class="brand" href="<?= e(url('/')) ?>"><?= e($appName) ?></a>
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-bs-dismiss="offcanvas" aria-label="Close">×</button>
        </div>
        <nav class="nav flex-column px-2 pb-3">
            <?php if ($GLOBALS['auth']->can('dashboard.view')): ?>
                <a class="nav-link" href="<?= e(url('/')) ?>">Dashboard</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('sales.view') || $GLOBALS['auth']->can('sales.create')): ?>
                <a class="nav-link" href="<?= e(url('/pos')) ?>">POS</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('counter.view')): ?>
                <a class="nav-link" href="<?= e(url('/counter')) ?>">Cash Counter</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('receipts.view')): ?>
                <a class="nav-link" href="<?= e(url('/receipts')) ?>">Receipts</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('purchases.view')): ?>
                <a class="nav-link" href="<?= e(url('/purchases')) ?>">Purchases</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('payments.view')): ?>
                <a class="nav-link" href="<?= e(url('/payments')) ?>">Payments</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('cheques.view')): ?>
                <a class="nav-link" href="<?= e(url('/cheques')) ?>">Cheques</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('expenses.view')): ?>
                <a class="nav-link" href="<?= e(url('/expenses')) ?>">Expenses</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('reports.view')): ?>
                <a class="nav-link" href="<?= e(url('/reports')) ?>">Reports</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('users.view')): ?>
                <a class="nav-link" href="<?= e(url('/users')) ?>">Users & Roles</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('sales.view')): ?>
                <a class="nav-link" href="<?= e(url('/sales-history')) ?>">Sales History</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('settings.view')): ?>
                <a class="nav-link" href="<?= e(url('/settings')) ?>">Settings</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('parties.view')): ?>
                <a class="nav-link" href="<?= e(url('/parties')) ?>">Parties</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('cylinder_groups.view')): ?>
                <a class="nav-link" href="<?= e(url('/cylinder-groups')) ?>">Cylinder Groups</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('cylinders.view')): ?>
                <a class="nav-link" href="<?= e(url('/cylinders')) ?>">Cylinders</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('rates.view')): ?>
                <a class="nav-link" href="<?= e(url('/rates')) ?>">Rates</a>
            <?php endif; ?>

            <?php if ($GLOBALS['auth']->can('opening_stock.view')): ?>
                <a class="nav-link" href="<?= e(url('/opening-stock')) ?>">Opening Stock</a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="small text-secondary mb-2"><?= e($user['full_name'] ?? $user['username'] ?? '') ?></div>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_input() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger w-100">Logout</button>
            </form>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar">☰</button>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="small text-secondary"><?= e($user['role_name'] ?? '') ?></span>
                <span class="fw-semibold"><?= e($user['username'] ?? '') ?></span>
            </div>
        </header>

        <main class="container-fluid py-4">
            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
