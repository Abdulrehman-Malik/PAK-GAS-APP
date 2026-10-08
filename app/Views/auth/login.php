<?php
$errors = $GLOBALS['session']->pullFlash('errors', []);
$old = $GLOBALS['session']->pullFlash('old', []);
$config = App\Core\Config::load(base_path());
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Login') ?> · <?= e($config['app_name']) ?></title>
    <meta name="csrf-token" content="<?= e($GLOBALS['csrf']->token()) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-page">
<main class="login-card card shadow-sm">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <h1 class="h3 mb-1"><?= e($config['app_name']) ?></h1>
            <p class="text-secondary mb-0">Secure staff login</p>
        </div>

        <?php if (is_array($errors) && $errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e((string) ($errors['auth'] ?? reset($errors))) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/login')) ?>" novalidate>
            <?= csrf_input() ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" maxlength="100" value="<?= e($old['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" maxlength="255" required>
            </div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
        </form>
    </div>
</main>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
