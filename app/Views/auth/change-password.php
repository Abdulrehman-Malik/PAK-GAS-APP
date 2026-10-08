<?php
$errors = $GLOBALS['session']->pullFlash('errors', []);
$config = App\Core\Config::load(base_path());
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Change Password') ?> · <?= e($config['app_name']) ?></title>
    <meta name="csrf-token" content="<?= e($GLOBALS['csrf']->token()) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="login-page">
<main class="login-card card shadow-sm">
    <div class="card-body p-4 p-md-5">
        <div class="mb-4">
            <h1 class="h3 mb-1">Change your password</h1>
            <p class="text-secondary mb-0">A password change is required before you continue.</p>
        </div>

        <?php if (is_array($errors) && $errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e((string) ($errors['auth'] ?? reset($errors))) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/password/change')) ?>">
            <?= csrf_input() ?>
            <div class="mb-3">
                <label class="form-label" for="current_password">Current password</label>
                <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="255" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password">New password</label>
                <input class="form-control" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="255" required>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="255" required>
            </div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Change password</button>
        </form>
    </div>
</main>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
