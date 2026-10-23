<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?= $this->setData(["headerTitle" => "My Tasks & Approvals", "headerSubtitle" => "Invoices waiting on you, and your own invoices in progress", "headerActions" => ""])->include("invoices/components/page_header") ?>

<div class="card mb-4 <?= $waiting !== [] ? 'border-primary' : '' ?>">
    <div class="card-header"><strong>Waiting for you</strong> <span class="badge text-bg-primary"><?= count($waiting) ?></span>
        <span class="text-body-secondary small ms-1">oldest first</span></div>
    <div class="card-body p-0 <?= $waiting === [] ? 'p-3' : '' ?>">
        <?= $this->setVar('items', $waiting)->setVar('empty', 'Nothing is waiting for you right now.')->setVar('showStep', true)->include('invoices/approvals/_list') ?>
    </div>
</div>

<?php if ($mine['needs_action'] !== []): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header"><strong>Your invoices that need changes</strong> <span class="badge text-bg-warning"><?= count($mine['needs_action']) ?></span></div>
        <div class="card-body p-0"><?= $this->setVar('items', $mine['needs_action'])->setVar('empty', '')->setVar('showStep', false)->include('invoices/approvals/_list') ?></div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Your invoices in approval</strong> <span class="badge text-bg-secondary"><?= count($mine['waiting']) ?></span></div>
    <div class="card-body p-0 <?= $mine['waiting'] === [] ? 'p-3' : '' ?>"><?= $this->setVar('items', $mine['waiting'])->setVar('empty', 'None of your invoices are waiting on anyone.')->setVar('showStep', true)->include('invoices/approvals/_list') ?></div>
</div>

<?php if ($mine['drafts'] !== []): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Your drafts</strong> <span class="badge text-bg-secondary"><?= count($mine['drafts']) ?></span></div>
        <div class="card-body p-0"><?= $this->setVar('items', $mine['drafts'])->setVar('empty', '')->setVar('showStep', false)->include('invoices/approvals/_list') ?></div>
    </div>
<?php endif; ?>

<?php if ($groupLevel): ?>
    <div class="card mb-4 border-danger-subtle">
        <div class="card-header"><span class="badge text-bg-danger me-1">Group-level</span><strong>Everything currently in approval</strong> <span class="badge text-bg-secondary"><?= count($all) ?></span></div>
        <div class="card-body p-0 <?= $all === [] ? 'p-3' : '' ?>"><?= $this->setVar('items', $all)->setVar('empty', 'No invoices are in approval.')->setVar('showStep', true)->include('invoices/approvals/_list') ?></div>
        <div class="card-footer small text-body-secondary">You can act on any of these. Each action is recorded as a group-level action and needs a reason.</div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
