<?php
/**
 * @var array<string, mixed> $topic
 */
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('actions') ?>
<a class="btn btn-outline-secondary" href="<?= site_url('help') ?>"><i class="bi bi-arrow-left me-1"></i>All topics</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <div class="col-lg-3">
        <nav class="list-group" aria-label="On this page">
            <?php foreach ($topic['sections'] as $s): ?>
                <a class="list-group-item list-group-item-action" href="#<?= esc($s['id'], 'attr') ?>"><?= esc($s['heading']) ?></a>
            <?php endforeach; ?>
            <?php if ($topic['faqs'] !== []): ?>
                <a class="list-group-item list-group-item-action" href="#faqs"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>FAQ</a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="col-lg-9">
        <?php foreach ($topic['sections'] as $s): ?>
            <section class="card card-body mb-3" id="<?= esc($s['id'], 'attr') ?>">
                <h2 class="h5"><?= esc($s['heading']) ?></h2>
                <?php foreach ($s['body'] ?? [] as $p): ?><p><?= esc($p) ?></p><?php endforeach; ?>
                <?php if (! empty($s['steps'])): ?>
                    <ol class="mb-0">
                        <?php foreach ($s['steps'] as $step): ?><li><?= esc($step) ?></li><?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($topic['faqs'] !== []): ?>
            <section class="mb-3" id="faqs">
                <h2 class="h5">Frequently asked questions</h2>
                <?= view('help/_faq_list', ['faqs' => $topic['faqs'], 'prefix' => 'topic']) ?>
            </section>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/help.js') ?>"></script>
<?= $this->endSection() ?>
