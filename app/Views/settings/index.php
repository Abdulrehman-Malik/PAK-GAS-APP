<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Settings</h1>
        <p class="text-secondary mb-0">Configure application and shop behavior.</p>
    </div>
</div>

<?php if ($settings === []): ?>
    <div class="alert alert-warning">No settings have been seeded yet.</div>
<?php else: ?>
    <?php
    $salesSettings = $settings['sales'] ?? [];
    $enabledTypes = array_values(array_filter(array_map('trim', explode(',', (string) ($salesSettings['pos_transaction_types'] ?? 'GAS_SALE,EMPTY_CYLINDER_SALE')))));
    $defaultType = (string) ($salesSettings['pos_default_transaction_type'] ?? ($enabledTypes[0] ?? 'GAS_SALE'));
    ?>
    <?php if ($GLOBALS['auth']->can('settings.manage')): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><strong>POS Transaction Types</strong></div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/settings/pos')) ?>">
                    <?= csrf_input() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label d-block">Visible transaction types</label>
                            <div class="form-check">
                                <input class="form-check-input pos-type" type="checkbox" name="transaction_types[]" value="GAS_SALE" id="posGas" <?= in_array('GAS_SALE', $enabledTypes, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="posGas">Gas Sale</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input pos-type" type="checkbox" name="transaction_types[]" value="EMPTY_CYLINDER_SALE" id="posEmpty" <?= in_array('EMPTY_CYLINDER_SALE', $enabledTypes, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="posEmpty">Empty Cylinder Sale</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input pos-type" type="checkbox" name="transaction_types[]" value="CYLINDER_RETURN" id="posReturn" <?= in_array('CYLINDER_RETURN', $enabledTypes, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="posReturn">Cylinder Return</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="posDefault">Default transaction type</label>
                            <select class="form-select" name="default_transaction_type" id="posDefault">
                                <option value="GAS_SALE" <?= $defaultType === 'GAS_SALE' ? 'selected' : '' ?>>Gas Sale</option>
                                <option value="EMPTY_CYLINDER_SALE" <?= $defaultType === 'EMPTY_CYLINDER_SALE' ? 'selected' : '' ?>>Empty Cylinder Sale</option>
                                <option value="CYLINDER_RETURN" <?= $defaultType === 'CYLINDER_RETURN' ? 'selected' : '' ?>>Cylinder Return</option>
                            </select>
                            <div class="form-text">Only enabled types appear on the POS screen.</div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit">Save POS Settings</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php $settingGroups = array_keys($settings); ?>
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <?php foreach ($settingGroups as $index => $groupName): ?>
            <?php $tabId = 'tab-' . $groupName; ?>
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link <?= $index === 0 ? 'active' : '' ?>"
                    id="<?= e($tabId) ?>-tab"
                    data-lpg-tab-target="#<?= e($tabId) ?>"
                    type="button"
                    role="tab"
                    onclick="return window.Lpg && window.Lpg.activateTab ? window.Lpg.activateTab(this) : false;"
                    aria-controls="<?= e($tabId) ?>"
                    aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                >
                    <?= e(ucwords(str_replace('_', ' ', $groupName))) ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content" id="settingsTabContent">
        <?php foreach ($settingGroups as $index => $groupName): ?>
            <?php $groupValues = $settings[$groupName]; ?>
            <?php $tabId = 'tab-' . $groupName; ?>
            <div
                class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>"
                id="<?= e($tabId) ?>"
                role="tabpanel"
                data-lpg-tab-pane
                aria-labelledby="<?= e($tabId) ?>-tab"
            >
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($groupValues as $key => $value): ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label text-capitalize"><?= e(str_replace('_', ' ', $key)) ?></label>
                                    <input class="form-control" value="<?= e($value) ?>" readonly>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
