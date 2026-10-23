<?php
/**
 * @var list<array<string, mixed>> $groups
 * @var string $q
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('help') ?>"><i class="bi bi-book me-1"></i>User manual</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form method="get" class="filter-bar mb-4" action="<?= site_url('help/faq') ?>" role="search">
    <div class="flex-grow-1">
        <label class="form-label visually-hidden" for="faq-q">Search questions</label>
        <input class="form-control" id="faq-q" type="search" name="q" value="<?= esc($q) ?>" placeholder="Search questions and answers">
    </div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="<?= site_url('help/faq') ?>">Clear</a><?php endif; ?>
</form>

<?php if ($groups === []): ?>
    <div class="card card-body text-body-secondary">No questions match<?= $q !== '' ? ' “' . esc($q) . '”' : '' ?>.</div>
<?php endif; ?>

<?php foreach ($groups as $g): ?>
    <section class="mb-4">
        <h2 class="h5 mb-2"><i class="bi bi-<?= esc($g['icon']) ?> me-2" aria-hidden="true"></i><a class="text-reset text-decoration-none" href="<?= site_url('help/' . $g['slug']) ?>"><?= esc($g['title']) ?></a></h2>
        <?= view('help/_faq_list', ['faqs' => $g['faqs'], 'prefix' => $g['slug']]) ?>
    </section>
<?php endforeach; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/help.js') ?>"></script>
<?= $this->endSection() ?>
