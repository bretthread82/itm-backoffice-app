<?php
/** @var string $title */
use App\Services\AccessScope;
use App\Services\ActiveCompany;
use App\Services\NavCounts;

$user    = auth()->user();
$uid     = (int) auth()->id();
$isAdmin   = $user !== null && (int) ($user->is_admin ?? 0) === 1;
$isManager = $user !== null && (int) ($user->is_manager ?? 0) === 1;
$isWorkflow = $user !== null && (int) ($user->is_workflow ?? 0) === 1;
$scope  = new AccessScope();
$active  = new ActiveCompany($scope);

$groupLevel = $scope->isGroupLevel($uid);
$companyCtx = [
    'companyMode'    => $active->mode($uid),
    'companyList'    => $active->available($uid),
    'companyCurrent' => $active->current($uid),
    'groupLevel'     => $groupLevel,
];
$roleLabel = $groupLevel ? 'Group-level user' : ($isAdmin ? 'Administrator' : ($isManager ? 'Manager' : ($isWorkflow ? 'Workflow Manager' : 'Team member')));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Invoicing') ?> · Maloney Group Invoicing</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/maloney.css') ?>">
</head>
<body>
<?= $this->setData(['navIsAdmin' => $isAdmin, 'navIsManager' => $isAdmin || $isManager, 'navIsWorkflow' => $isWorkflow, 'navCounts' => NavCounts::for($uid)])->include('components/sidebar') ?>

<div class="mg-main">
    <?= $this->setData(['navUser' => $user, 'navRole' => $roleLabel, 'navCompany' => $companyCtx])->include('components/topbar') ?>

    <main class="mg-content">
        <?php foreach (['message' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $key => $class): ?>
            <?php if (session()->getFlashdata($key)): ?>
                <div class="alert alert-<?= $class ?> alert-dismissible fade show" role="alert">
                    <?= esc(session()->getFlashdata($key)) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ((array) session()->getFlashdata('errors') as $e): ?>
                        <li><?= esc($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app-shell.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
