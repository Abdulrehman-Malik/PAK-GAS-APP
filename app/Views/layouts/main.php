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
            <a class="nav-link" href="<?= e(url('/')) ?>">Dashboard</a>
            <?php if ($GLOBALS['auth']->can('settings.view')): ?>
                <a class="nav-link" href="<?= e(url('/settings')) ?>">Settings</a>
                <a class="nav-link" href="<?= e(url('/parties')) ?>">Parties</a>
                <a class="nav-link" href="<?= e(url('/cylinder-groups')) ?>">Cylinder Groups</a>
                <a class="nav-link" href="<?= e(url('/cylinders')) ?>">Cylinders</a>
                <a class="nav-link" href="<?= e(url('/rates')) ?>">Rates</a>
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
