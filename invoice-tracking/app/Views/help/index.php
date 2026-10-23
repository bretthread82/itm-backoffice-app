<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
/**
 * Already filtered to what this user may use (see Controllers\Help).
 *
 * @var list<array<string, mixed>> $topics
 * @var list<string>               $abilities
 */
$groups = ['use' => 'Using the system', 'group' => 'Group-level users', 'admin' => 'Administrators'];
$groupOf = static function (array $t): string {
    $needs = $t['needs'] ?? [];

    return in_array('admin', $needs, true) ? 'admin' : ($needs === ['group'] ? 'group' : 'use');
};
$subtitle = 'How the invoice approval system works, and answers to common questions.'
    . ($abilities !== [] ? ' This guide covers what you can do here: ' . implode(', ', $abilities) . '.' : '');
?>
<?= view('components/page_header', [
    'headerTitle'    => 'Help & Manual',
    'headerSubtitle' => $subtitle,
    'headerActions'  => '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>',
]) ?>

<div class="row g-4 help-page">
    <div class="col-lg-3 d-print-none">
        <nav class="help-nav" aria-label="Help topics">
            <label class="visually-hidden" for="help-search">Search the manual</label>
            <div class="input-group input-group-sm mb-3">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" id="help-search" placeholder="Search help…" autocomplete="off">
            </div>
            <?php foreach ($groups as $aud => $heading): $in = array_filter($topics, static fn (array $t): bool => $groupOf($t) === $aud); ?>
                <?php if ($in === []) { continue; } ?>
                <div class="help-nav-heading"><?= esc($heading) ?></div>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($in as $t): ?>
                        <li data-help-link="<?= esc($t['id']) ?>"><a href="#<?= esc($t['id']) ?>"><i class="bi <?= esc($t['icon']) ?> me-2"></i><?= esc($t['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="col-lg-9">
        <div id="help-empty" class="alert alert-info" hidden>Nothing in the manual matches that search. Try a shorter word, or ask an administrator.</div>

        <?php foreach ($topics as $t): $tid = (string) $t['id']; ?>
            <section class="card mb-4 help-topic" id="<?= esc($tid) ?>" data-help-topic="<?= esc($tid) ?>">
                <div class="card-body">
                    <h2 class="h4 d-flex align-items-center gap-2">
                        <i class="bi <?= esc($t['icon']) ?> text-body-secondary"></i><?= esc($t['title']) ?>
                        <?php if ($groupOf($t) !== 'use'): ?><span class="badge text-bg-secondary fw-normal fs-6"><?= esc($groups[$groupOf($t)]) ?></span><?php endif; ?>
                    </h2>
                    <p class="text-body-secondary"><?= esc($t['summary']) ?></p>

                    <?php foreach ($t['parts'] as $html): ?><?= $html ?><?php endforeach; ?>

                    <?php if (! empty($t['events'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>You are alerted when…</th></tr></thead>
                                <tbody>
                                    <?php foreach ($t['events'] as $label): ?><tr><td><?= esc($label) ?></td></tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($t['faqs'])): ?>
                        <h3 class="h6 mt-4 mb-2">Common questions</h3>
                        <div class="accordion help-faq" id="faq-<?= esc($tid) ?>">
                            <?php foreach ($t['faqs'] as $i => [$q, $a]): $fid = 'faq-' . $tid . '-' . $i; ?>
                                <div class="accordion-item">
                                    <h4 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= esc($fid) ?>" aria-expanded="false" aria-controls="<?= esc($fid) ?>"><?= esc($q) ?></button>
                                    </h4>
                                    <div id="<?= esc($fid) ?>" class="accordion-collapse collapse" data-bs-parent="#faq-<?= esc($tid) ?>">
                                        <div class="accordion-body"><?= $a ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <p class="small text-body-secondary d-print-none">Still stuck? Tell your administrator what you were doing and the invoice number, and include any message shown on screen.</p>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var box = document.getElementById('help-search');
    var empty = document.getElementById('help-empty');
    var topics = Array.prototype.slice.call(document.querySelectorAll('[data-help-topic]'));

    function apply() {
        var q = box.value.trim().toLowerCase();
        var shown = 0;
        topics.forEach(function (sec) {
            var hit = q === '' || sec.textContent.toLowerCase().indexOf(q) !== -1;
            sec.hidden = !hit;
            var link = document.querySelector('[data-help-link="' + sec.dataset.helpTopic + '"]');
            if (link) { link.hidden = !hit; }
            if (hit) { shown += 1; }

            // Open the questions that mention the search, close the rest.
            sec.querySelectorAll('.accordion-item').forEach(function (item) {
                var panel = item.querySelector('.accordion-collapse');
                var button = item.querySelector('.accordion-button');
                var open = q !== '' && item.textContent.toLowerCase().indexOf(q) !== -1;
                panel.classList.toggle('show', open);
                button.classList.toggle('collapsed', !open);
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
        empty.hidden = shown > 0;
    }

    box.addEventListener('input', apply);
})();
</script>
<?= $this->endSection() ?>
