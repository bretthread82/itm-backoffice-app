<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h3 mb-0">API documentation</h1>
    <a class="btn btn-outline-primary btn-sm" href="<?= site_url('account/api-tokens') ?>"><i class="bi bi-key me-1"></i>My API tokens</a>
</div>
<p class="text-body-secondary">
    Read-only access to invoices, approvals and reports. Ask an administrator for a token (you manage yours under <a href="<?= site_url('account/api-tokens') ?>">API tokens</a>),
    choose <strong>Authorize</strong> below, paste it, and try any call. The raw description is at
    <a href="<?= esc($specUrl, 'attr') ?>"><code>/api/v1/openapi.json</code></a>.
</p>
<link rel="stylesheet" href="<?= base_url('assets/vendor/swagger-ui/swagger-ui.css') ?>">
<div id="swagger-ui" class="card card-body" data-spec="<?= esc($specUrl, 'attr') ?>"></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/swagger-ui/swagger-ui-bundle.js') ?>"></script>
<script>
window.addEventListener('load', function () {
    var el = document.getElementById('swagger-ui');
    window.ui = SwaggerUIBundle({
        url: el.dataset.spec,
        dom_id: '#swagger-ui',
        deepLinking: false,
        persistAuthorization: false,
        tryItOutEnabled: true,
        defaultModelsExpandDepth: 0
    });
});
</script>
<?= $this->endSection() ?>
