<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Settings</h1>
        <p class="text-secondary mb-0">Phase 0 shell. Configuration groups are read from the database.</p>
    </div>
</div>
<?php if ($settings === []): ?>
    <div class="alert alert-warning">No settings have been seeded yet.</div>
<?php else: ?>
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <?php foreach (array_keys($settings) as $index => $group): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $index === 0 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= e($group) ?>" type="button" role="tab">
                    <?= e(ucwords(str_replace('_', ' ', $group))) ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content">
        <?php foreach ($settings as $index => $groupValues): ?>
            <?php $groupName = array_keys($settings)[$index]; ?>
            <div class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>" id="tab-<?= e($groupName) ?>" role="tabpanel">
                <div class="card shadow-sm"><div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($groupValues as $key => $value): ?>
                            <div class="col-12 col-md-6">
                                <label class="form-label text-capitalize"><?= e(str_replace('_', ' ', $key)) ?></label>
                                <input class="form-control" value="<?= e($value) ?>" readonly>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
