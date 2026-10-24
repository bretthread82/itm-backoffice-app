<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData(['headerTitle' => 'My customer invoice tasks', 'headerSubtitle' => 'Invoices waiting on you, your own in progress, and overdue ones to chase', 'headerActions' => ''])->include('payables/components/page_header') ?>

<div class="card mb-4 <?= $waiting !== [] ? 'border-primary' : '' ?>">
    <div class="card-header"><strong>Waiting for you</strong> <span class="badge text-bg-primary"><?= count($waiting) ?></span>
        <span class="text-body-secondary small ms-1">oldest first</span></div>
    <div class="card-body p-0 <?= $waiting === [] ? 'p-3' : '' ?>">
        <?= view('receivables/tasks/_list', ['items' => $waiting, 'empty' => 'Nothing is waiting for you right now.', 'mode' => 'step']) ?>
    </div>
</div>

<?php if ($overdue !== []): ?>
    <div class="card mb-4 border-danger">
        <div class="card-header"><strong>Overdue to chase</strong> <span class="badge text-bg-danger"><?= count($overdue) ?></span>
            <span class="text-body-secondary small ms-1">invoices you raised, and those in companies where you record payments</span></div>
        <div class="card-body p-0"><?= view('receivables/tasks/_list', ['items' => $overdue, 'empty' => '', 'mode' => 'owed']) ?></div>
    </div>
<?php endif; ?>

<?php if ($mine['needs_action'] !== []): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header"><strong>Your invoices that need changes</strong> <span class="badge text-bg-warning"><?= count($mine['needs_action']) ?></span></div>
        <div class="card-body p-0"><?= view('receivables/tasks/_list', ['items' => $mine['needs_action'], 'empty' => '', 'mode' => 'status']) ?></div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Your invoices in approval</strong> <span class="badge text-bg-secondary"><?= count($mine['waiting']) ?></span></div>
    <div class="card-body p-0 <?= $mine['waiting'] === [] ? 'p-3' : '' ?>"><?= view('receivables/tasks/_list', ['items' => $mine['waiting'], 'empty' => 'None of your invoices are waiting on anyone.', 'mode' => 'step']) ?></div>
</div>

<?php if ($mine['drafts'] !== []): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Your drafts</strong> <span class="badge text-bg-secondary"><?= count($mine['drafts']) ?></span></div>
        <div class="card-body p-0"><?= view('receivables/tasks/_list', ['items' => $mine['drafts'], 'empty' => '', 'mode' => 'status']) ?></div>
    </div>
<?php endif; ?>

<?php if ($groupLevel): ?>
    <div class="card mb-4 border-danger-subtle">
        <div class="card-header"><span class="badge text-bg-danger me-1">Group-level</span><strong>Everything currently in approval</strong> <span class="badge text-bg-secondary"><?= count($all) ?></span></div>
        <div class="card-body p-0 <?= $all === [] ? 'p-3' : '' ?>"><?= view('receivables/tasks/_list', ['items' => $all, 'empty' => 'No customer invoices are in approval.', 'mode' => 'step']) ?></div>
        <div class="card-footer small text-body-secondary">You can act on any of these. Each action is recorded as a group-level action and needs a reason.</div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
