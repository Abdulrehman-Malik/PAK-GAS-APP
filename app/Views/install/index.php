<?php
$requirements = $status['requirements'] ?? [];
$missing = $status['missing_requirements'] ?? [];
$db = $status['db'] ?? [];
$canInstall = ($status['env_exists'] ?? false)
    && $missing === []
    && empty($status['db_error']);
$isInstalled = (bool) ($status['installed'] ?? false);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Installation') ?> · Pak Gas POS</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style>
        body { background: #f5f7fb; }
        .installer { max-width: 900px; margin: 40px auto; }
        .installer .card { border: 0; border-radius: 16px; }
        .check-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 0; border-bottom:1px solid #edf0f5; }
        .check-row:last-child { border-bottom:0; }
        .status-ok { color:#198754; }
        .status-bad { color:#dc3545; }
    </style>
</head>
<body>
<main class="container installer">
    <div class="text-center mb-4">
        <h1 class="h3 mb-2">Pak Gas POS Installation</h1>
        <p class="text-secondary mb-0">
            Set the values in <code>.env</code>, then let the application create and initialize the database automatically.
        </p>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Installation could not continue.</strong><br>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!($status['env_exists'] ?? false)): ?>
        <div class="alert alert-warning">
            <strong>.env file is missing.</strong>
            Copy <code>.env.example</code> to <code>.env</code> and set
            <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>
            and the admin credentials.
        </div>
    <?php endif; ?>

    <?php if ($missing !== []): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5">Server requirements</h2>
                <p class="text-secondary">
                    The following mandatory requirements must be enabled before installation can start.
                </p>

                <?php foreach ($requirements as $check): ?>
                    <div class="check-row">
                        <span><?= e($check['label']) ?></span>
                        <?php if ($check['ok']): ?>
                            <span class="status-ok fw-semibold">✓ <?= e($check['actual']) ?></span>
                        <?php else: ?>
                            <span class="status-bad fw-semibold">✗ <?= e($check['actual']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="alert alert-info mt-3 mb-0">
                    After enabling an extension in XAMPP <code>php.ini</code>, restart Apache and refresh this page.
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <h2 class="h5">Database</h2>

                        <div class="check-row"><span>Host</span><strong><?= e($db['host'] ?? '') ?></strong></div>
                        <div class="check-row"><span>Port</span><strong><?= e($db['port'] ?? '') ?></strong></div>
                        <div class="check-row"><span>Database</span><strong><?= e($db['name'] ?? '') ?></strong></div>
                        <div class="check-row"><span>User</span><strong><?= e($db['user'] ?? '') ?></strong></div>
                        <div class="check-row"><span>Password</span><strong><?= !empty($db['password_configured']) ? 'Configured' : 'Empty' ?></strong></div>

                        <div class="check-row">
                            <span>Database status</span>
                            <?php if (!empty($status['db_error'])): ?>
                                <span class="status-bad fw-semibold">Connection failed</span>
                            <?php elseif (!empty($status['database_exists'])): ?>
                                <span class="status-ok fw-semibold">Found</span>
                            <?php else: ?>
                                <span class="text-secondary fw-semibold">Will be created</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($status['db_error'])): ?>
                            <div class="alert alert-danger mt-3 mb-0">
                                <?= e($status['db_error']) ?>
                            </div>
                        <?php else: ?>
                            <div class="small text-secondary mt-3">
                                No SQL command is required. The installer uses the database information from
                                <code>.env</code>, creates the database when needed, imports
                                <code>database/schema.sql</code>, and runs queued migration files.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <h2 class="h5">Administrator</h2>
                        <div class="check-row">
                            <span>Username</span>
                            <strong><?= e($status['admin_username'] ?? 'admin') ?></strong>
                        </div>

                        <p class="small text-secondary mt-3">
                            The password is never shown here. It is read from
                            <code>SEED_ADMIN_PASSWORD</code> in <code>.env</code> and stored as a secure password hash.
                        </p>

                        <?php if ($canInstall): ?>
                            <form method="post" action="<?= e(url('/install')) ?>" class="mt-4">
                                <?= csrf_input() ?>
                                <button class="btn btn-primary btn-lg w-100" type="submit">
                                    <?= $isInstalled ? 'Run Database Updates' : 'Install Pak Gas POS' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-lg w-100 mt-4" type="button" disabled>
                                Fix the items above first
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-center text-secondary small mt-4">
        After successful installation or database updates, the application redirects to the login page automatically.
    </div>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
