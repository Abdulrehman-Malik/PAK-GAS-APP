<?php
$config = App\Core\Config::load(base_path());
$appName = $config['app_name'];
$user = $user ?? null;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$appBasePath = parse_url(rtrim(url('/'), '/'), PHP_URL_PATH) ?: '';
if ($appBasePath !== '' && $appBasePath !== '/' && str_starts_with($requestPath, $appBasePath)) {
    $requestPath = substr($requestPath, strlen($appBasePath));
}
$currentPath = trim($requestPath, '/');

$isActive = static function (string $path) use ($currentPath): bool {
    $target = trim($path, '/');
    return $currentPath === $target || ($target !== '' && str_starts_with($currentPath, $target . '/'));
};

$hasAny = static function (array $permissions): bool {
    foreach ($permissions as $permission) {
        if ($GLOBALS['auth']->can($permission)) {
            return true;
        }
    }
    return false;
};

$navIcon = static function (string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
        'sales' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6.5h14v13H5z"/><path d="M8 6.5V4h8v2.5M8 11h8M8 15h5"/></svg>',
        'history' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.34-5.66L4 8.7"/><path d="M4 4v4.7h4.7M12 8v4l2.8 1.8"/></svg>',
        'cash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6.5 8.5h.01M17.5 15.5h.01"/></svg>',
        'receipt' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
        'purchase' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l1.5 10.5h9L19 8H7"/><circle cx="10" cy="19" r="1.4"/><circle cx="16" cy="19" r="1.4"/><path d="M9 8h8"/></svg>',
        'payment' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
        'cheque' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="1.5"/><path d="M7 10h10M7 14h6"/></svg>',
        'expense' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
        'report' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V10M12 20V4M19 20v-7"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.2 2.4-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M15.5 14.5c2.5-.1 4.3 1.4 5 4"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-2.8 7.9-7 10-4.2-2.1-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5A3.5 3.5 0 1 0 12 15.5 3.5 3.5 0 0 0 12 8.5z"/><path d="M19 12a7.5 7.5 0 0 0-.12-1.33l2-1.55-2-3.46-2.37.9A7.5 7.5 0 0 0 14.2 5L13.9 2.5h-3.8L9.8 5a7.5 7.5 0 0 0-2.31 1.33l-2.37-.9-2 3.46 2 1.55A7.5 7.5 0 0 0 3 12c0 .45.04.9.12 1.33l-2 1.55 2 3.46 2.37-.9A7.5 7.5 0 0 0 9.8 19l.3 2.5h3.8l.3-2.5a7.5 7.5 0 0 0 2.31-1.33l2.37.9 2-3.46-2-1.55c.08-.43.12-.88.12-1.33z"/></svg>',
        'party' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-3.2 2.4-5 5.5-5s4.9 1.8 5.5 5"/><path d="M15 8.5h5M17.5 6v5"/></svg>',
        'group' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="7" height="7" rx="1"/><rect x="13" y="12" width="7" height="7" rx="1"/><path d="M11 8.5h2M9.5 12v6.5h3.5"/></svg>',
        'cylinder' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="4" width="8" height="16" rx="4"/><path d="M9 6h6M9 18h6"/></svg>',
        'rate' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4v16M18 4v16M4 7h16M4 12h16M4 17h16"/></svg>',
        'stock' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7l8-4 8 4-8 4zM4 7v10l8 4 8-4V7M12 11v10"/></svg>',
        'chevron' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
        'collapse' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l6 6-6 6M4 12h16"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>',
    ];
    return $icons[$name] ?? '';
};

$groups = [
    [
        'key' => 'overview',
        'label' => 'Overview',
        'icon' => 'dashboard',
        'visible' => $hasAny(['dashboard.view', 'reports.view']),
        'items' => [
            ['label' => 'Dashboard', 'path' => '/', 'permission' => 'dashboard.view', 'icon' => 'dashboard'],
            ['label' => 'Reports', 'path' => '/reports', 'permission' => 'reports.view', 'icon' => 'report'],
        ],
    ],
    [
        'key' => 'sales',
        'label' => 'Sales',
        'icon' => 'sales',
        'visible' => $hasAny(['sales.view', 'sales.create']),
        'items' => [
            ['label' => 'Point of Sale', 'path' => '/pos', 'permission' => 'sales.create', 'icon' => 'sales', 'alternate_permission' => 'sales.view'],
            ['label' => 'Sales History', 'path' => '/sales', 'permission' => 'sales.view', 'icon' => 'history'],
        ],
    ],
    [
        'key' => 'transactions',
        'label' => 'Transactions',
        'icon' => 'receipt',
        'visible' => $hasAny(['receipts.view', 'receipts.create', 'purchases.view', 'payments.view', 'cheques.view', 'expenses.view']),
        'items' => [
            ['label' => 'Receipts', 'path' => '/receipts', 'permission' => 'receipts.view', 'icon' => 'receipt'],
            ['label' => 'Purchases', 'path' => '/purchases', 'permission' => 'purchases.view', 'icon' => 'purchase'],
            ['label' => 'Payments', 'path' => '/payments', 'permission' => 'payments.view', 'icon' => 'payment'],
            ['label' => 'Cheques', 'path' => '/cheques', 'permission' => 'cheques.view', 'icon' => 'cheque'],
            ['label' => 'Expenses', 'path' => '/expenses', 'permission' => 'expenses.view', 'icon' => 'expense'],
        ],
    ],
    [
        'key' => 'parties',
        'label' => 'Parties & Ledgers',
        'icon' => 'party',
        'visible' => $hasAny(['parties.view']),
        'items' => [
            ['label' => 'Parties', 'path' => '/parties', 'permission' => 'parties.view', 'icon' => 'party'],
        ],
    ],
    [
        'key' => 'inventory',
        'label' => 'Inventory',
        'icon' => 'cylinder',
        'visible' => $hasAny(['cylinder_groups.view', 'cylinders.view', 'rates.view', 'opening_stock.view']),
        'items' => [
            ['label' => 'Cylinder Groups', 'path' => '/cylinder-groups', 'permission' => 'cylinder_groups.view', 'icon' => 'group'],
            ['label' => 'Cylinders', 'path' => '/cylinders', 'permission' => 'cylinders.view', 'icon' => 'cylinder'],
            ['label' => 'Rates', 'path' => '/rates', 'permission' => 'rates.view', 'icon' => 'rate'],
            ['label' => 'Opening Stock', 'path' => '/opening-stock', 'permission' => 'opening_stock.view', 'icon' => 'stock'],
        ],
    ],
    [
        'key' => 'cash',
        'label' => 'Cash Counter',
        'icon' => 'cash',
        'visible' => $hasAny(['counter.view']),
        'items' => [
            ['label' => 'Cash Counter', 'path' => '/counter', 'permission' => 'counter.view', 'icon' => 'cash'],
        ],
    ],
    [
        'key' => 'administration',
        'label' => 'Administration',
        'icon' => 'users',
        'visible' => $hasAny(['users.view', 'settings.view']),
        'items' => [
            ['label' => 'Users & Roles', 'path' => '/users', 'permission' => 'users.view', 'icon' => 'users'],
            ['label' => 'Audit Log', 'path' => '/audit', 'permission' => 'users.view', 'icon' => 'audit'],
            ['label' => 'Shop Settings', 'path' => '/settings', 'permission' => 'settings.view', 'icon' => 'settings'],
        ],
    ],
];

$firstVisibleGroup = null;
foreach ($groups as $group) {
    if ($group['visible']) {
        $firstVisibleGroup = $group['key'];
        break;
    }
}
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
<div class="app-shell" id="appShell">
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-label="Main navigation">
        <div class="sidebar-header">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e($appName) ?> home">
                <span class="brand-mark">PG</span>
                <span class="brand-copy">
                    <span class="brand-name"><?= e($appName) ?></span>
                    <span class="brand-subtitle">LPG POS Management</span>
                </span>
            </a>
            <button class="sidebar-close btn-icon d-lg-none" type="button" data-bs-dismiss="offcanvas" aria-label="Close navigation">
                <span class="icon"><?= $navIcon('menu') ?></span>
            </button>
        </div>

        <div class="sidebar-scroll">
            <div class="sidebar-label">Navigation</div>
            <nav class="sidebar-nav" aria-label="Application sections">
                <?php foreach ($groups as $group): ?>
                    <?php if (!$group['visible']) { continue; } ?>
                    <?php
                    $visibleItems = array_values(array_filter(
                        $group['items'],
                        static function (array $item): bool {
                            if ($GLOBALS['auth']->can($item['permission'])) {
                                return true;
                            }
                            return isset($item['alternate_permission']) && $GLOBALS['auth']->can($item['alternate_permission']);
                        }
                    ));
                    if ($visibleItems === []) { continue; }
                    $groupActive = false;
                    foreach ($visibleItems as $item) {
                        if ($isActive($item['path'])) {
                            $groupActive = true;
                            break;
                        }
                    }
                    $panelId = 'nav-group-' . $group['key'];
                    ?>
                    <div class="nav-group <?= $groupActive ? 'is-active' : '' ?>" data-nav-group="<?= e($group['key']) ?>">
                        <button
                            class="nav-group-toggle"
                            type="button"
                            aria-expanded="<?= $groupActive ? 'true' : 'false' ?>"
                            aria-controls="<?= e($panelId) ?>"
                        >
                            <span class="nav-group-main">
                                <span class="nav-icon"><?= $navIcon($group['icon']) ?></span>
                                <span class="nav-group-label"><?= e($group['label']) ?></span>
                            </span>
                            <span class="nav-chevron"><?= $navIcon('chevron') ?></span>
                        </button>
                        <div class="nav-children <?= $groupActive ? 'show' : '' ?>" id="<?= e($panelId) ?>">
                            <?php foreach ($visibleItems as $item): ?>
                                <?php $itemActive = $isActive($item['path']); ?>
                                <a class="nav-child <?= $itemActive ? 'active' : '' ?>" href="<?= e(url($item['path'])) ?>" <?= $itemActive ? 'aria-current="page"' : '' ?>>
                                    <span class="nav-child-icon"><?= $navIcon($item['icon']) ?></span>
                                    <span><?= e($item['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= e(strtoupper(substr((string)($user['full_name'] ?? $user['username'] ?? 'U'), 0, 1))) ?></div>
                <div class="user-meta">
                    <div class="user-name"><?= e($user['full_name'] ?? $user['username'] ?? '') ?></div>
                    <div class="user-role"><?= e($user['role_name'] ?? 'User') ?></div>
                </div>
            </div>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_input() ?>
                <button type="submit" class="logout-btn">
                    <span class="nav-icon"><?= $navIcon('logout') ?></span>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>

    <div class="sidebar-scrim d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-label="Close navigation"></div>

    <div class="app-main">
        <header class="topbar">
            <div class="topbar-start">
                <button class="btn-icon sidebar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open navigation">
                    <span class="icon"><?= $navIcon('menu') ?></span>
                </button>
                <button class="btn-icon sidebar-collapse d-none d-lg-inline-flex" type="button" id="sidebarCollapse" aria-label="Collapse navigation">
                    <span class="icon"><?= $navIcon('collapse') ?></span>
                </button>
                <div class="page-context">
                    <span class="page-context-title"><?= e($pageTitle ?? $appName) ?></span>
                    <span class="page-context-divider">/</span>
                    <span class="page-context-app"><?= e($appName) ?></span>
                </div>
            </div>
            <div class="topbar-user">
                <span class="status-dot"></span>
                <span class="topbar-role"><?= e($user['role_name'] ?? '') ?></span>
                <span class="topbar-username"><?= e($user['username'] ?? '') ?></span>
            </div>
        </header>

        <main class="container-fluid py-4 app-content">
            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
