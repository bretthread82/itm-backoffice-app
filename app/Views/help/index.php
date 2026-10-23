<?php
/**
 * @var array<string, array<string, mixed>> $topics
 * @var list<string> $roles
 * @var string $q
 * @var list<array<string, string>> $hits
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('help/faq') ?>"><i class="bi bi-chat-left-text me-1"></i>FAQ</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form method="get" class="filter-bar mb-4" action="<?= site_url('help') ?>" role="search">
    <div class="flex-grow-1">
        <label class="form-label visually-hidden" for="help-q">Search help</label>
        <input class="form-control" id="help-q" type="search" name="q" value="<?= esc($q) ?>" placeholder="Search the manual and FAQ">
    </div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="<?= site_url('help') ?>">Clear</a><?php endif; ?>
</form>

<?php if ($q !== ''): ?>
    <h2 class="h5 mb-3"><?= count($hits) ?> result<?= count($hits) === 1 ? '' : 's' ?> for “<?= esc($q) ?>”</h2>
    <?php if ($hits === []): ?>
        <div class="card card-body text-body-secondary">Nothing matched. Try a shorter word, or browse the topics below.</div>
    <?php else: ?>
        <div class="list-group mb-4">
            <?php foreach ($hits as $h): ?>
                <a class="list-group-item list-group-item-action" href="<?= site_url('help/' . $h['topic']) ?>#<?= esc($h['anchor'], 'url') ?>">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="fw-semibold"><?= esc($h['label']) ?></span>
                        <span class="badge-status st-<?= $h['kind'] === 'FAQ' ? 'info' : 'draft' ?>"><?= esc($h['kind']) ?></span>
                    </div>
                    <div class="small text-body-secondary"><?= esc($h['title']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($topics as $slug => $t): ?>
        <div class="col-md-6 col-xl-4">
            <a class="card h-100 text-decoration-none" href="<?= site_url('help/' . $slug) ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-<?= esc($t['icon']) ?> fs-4 text-primary" aria-hidden="true"></i>
                        <h2 class="h5 mb-0"><?= esc($t['title']) ?></h2>
                    </div>
                    <p class="text-body-secondary mb-2"><?= esc($t['summary']) ?></p>
                    <span class="small text-body-secondary"><?= count($t['sections']) ?> section<?= count($t['sections']) === 1 ? '' : 's' ?> · <?= count($t['faqs']) ?> FAQ<?= count($t['faqs']) === 1 ? '' : 's' ?></span>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<p class="small text-body-secondary mt-4">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    This help shows only the areas your access allows<?= $roles !== [] ? ' (your roles: ' . esc(implode(', ', $roles)) . ')' : '' ?>.
    If you need something that is not listed, ask an administrator.
</p>
<?= $this->endSection() ?>
