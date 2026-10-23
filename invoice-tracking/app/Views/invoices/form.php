<?php
/** @var array<string, mixed> $invoice */
$resub = in_array($status, ['returned', 'rejected'], true);
$val   = static fn (string $k, mixed $default = '') => old($k, $invoice[$k] ?? $default);
$maxMb = (int) setting('AppSettings.maxUploadMb');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-xl-9">
    <div class="page-header">
        <div>
            <h1><?= esc($title) ?></h1>
            <div class="subtitle">Enter invoice details and upload the supporting document. Fields marked <span class="text-danger">*</span> are required.</div>
        </div>
    </div>

    <?php if ($resub): ?>
        <div class="alert alert-warning">
            This invoice was <strong><?= $status === 'rejected' ? 'rejected' : 'returned for changes' ?></strong>.
            Fix what was asked, then resubmit with a note describing what you changed.
        </div>
    <?php endif; ?>

    <nav class="wizard-steps" id="wizard-steps" aria-label="Steps" hidden>
        <?php foreach (['Invoice Details', 'Financial Details', 'Documents', 'Review & Submit'] as $n => $label): ?>
            <button type="button" class="wizard-step" data-goto="<?= $n ?>"><span class="num"><?= $n + 1 ?></span><?= esc($label) ?></button>
        <?php endforeach; ?>
    </nav>

    <form method="post" enctype="multipart/form-data" action="<?= site_url($id === null ? 'invoices' : 'invoices/' . $id) ?>" class="card card-body" id="invoice-form" novalidate>
        <?= csrf_field() ?>

        <!-- Step 1: Invoice details -->
        <section data-step="0">
            <h2 class="h6 text-uppercase text-body-secondary mb-3 wizard-title">Invoice details</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="company_id">Company <span class="text-danger">*</span></label>
                    <select class="form-select" id="company_id" name="company_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (string) $val('company_id') === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['code']) ?> · <?= esc($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="invoice_type_id">Invoice type <span class="text-danger">*</span></label>
                    <select class="form-select" id="invoice_type_id" name="invoice_type_id" required data-selected="<?= esc((string) $val('invoice_type_id')) ?>" disabled>
                        <option value="">Choose a company first</option>
                    </select>
                </div>

                <div class="col-12" id="route-preview" hidden>
                    <div class="border rounded p-2 small bg-body-tertiary">
                        <div class="fw-semibold mb-1"><i class="bi bi-signpost-split me-1"></i>Approval route for this type</div>
                        <div id="route-steps" class="d-flex flex-wrap gap-1"></div>
                    </div>
                </div>

                <div class="col-12" id="extra-wrap" hidden>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="extra_approval" name="extra_approval" value="1" <?= (int) $val('extra_approval', 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="extra_approval">This invoice needs the extra approval step</label>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="supplier">Supplier</label>
                    <?php
                    $chosenSupplier = (string) $val('supplier');
                    $supplierList   = $suppliers;   // each: code + name; the name is what an invoice stores
                    if ($chosenSupplier !== '' && ! in_array($chosenSupplier, array_column($supplierList, 'name'), true)) {
                        array_unshift($supplierList, ['code' => '', 'name' => $chosenSupplier]);   // keep an older invoice's supplier selectable
                    }
                    ?>
                    <select class="form-select" id="supplier" name="supplier">
                        <option value="">— choose —</option>
                        <?php foreach ($supplierList as $sup): ?>
                            <option value="<?= esc($sup['name']) ?>" <?= $sup['name'] === $chosenSupplier ? 'selected' : '' ?>><?= esc(($sup['code'] !== '' ? $sup['code'] . ' · ' : '') . $sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($suppliers === []): ?><div class="form-text">No suppliers have been set up yet. Ask a manager to add them (Admin &gt; Suppliers).</div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="invoice_number">Invoice number <span class="text-danger">*</span></label>
                    <input class="form-control" id="invoice_number" name="invoice_number" required maxlength="100" value="<?= esc((string) $val('invoice_number')) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label" for="invoice_date">Invoice date <span class="text-danger">*</span></label>
                    <input class="form-control" type="date" id="invoice_date" name="invoice_date" required value="<?= esc((string) $val('invoice_date')) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label" for="due_date">Due date <span class="text-danger">*</span></label>
                    <input class="form-control" type="date" id="due_date" name="due_date" required value="<?= esc((string) $val('due_date')) ?>">
                </div>
            </div>
        </section>

        <!-- Step 2: Financial details -->
        <section data-step="1" class="mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-3 wizard-title">Financial details</h2>
            <div class="row g-3">
                <div class="col-7 col-md-4">
                    <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                    <input class="form-control" id="amount" name="amount" required inputmode="decimal" value="<?= esc((string) $val('amount')) ?>" placeholder="0.00">
                </div>
                <div class="col-5 col-md-2">
                    <label class="form-label" for="currency">Currency <span class="text-danger">*</span></label>
                    <?php
                    $currencies = invoice_currencies();
                    $chosen     = strtoupper((string) $val('currency', setting('AppSettings.defaultCurrency')));
                    if (! isset($currencies[$chosen])) {
                        $currencies = [$chosen => $chosen] + $currencies;   // keep an older invoice's code selectable
                    }
                    ?>
                    <select class="form-select" id="currency" name="currency" required>
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= esc($code) ?>" <?= $code === $chosen ? 'selected' : '' ?>><?= esc($code . ' - ' . $name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="po_reference">PO / reference</label>
                    <input class="form-control" id="po_reference" name="po_reference" maxlength="100" value="<?= esc((string) $val('po_reference')) ?>">
                </div>
            </div>
        </section>

        <!-- Step 3: Documents -->
        <section data-step="2" class="mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-3 wizard-title">Documents</h2>
            <?php if ($file): ?>
                <div class="file-card mb-3">
                    <i class="bi bi-file-earmark-pdf fs-3 text-danger"></i>
                    <div class="flex-grow-1"><div class="fw-semibold"><?= esc($file['original_name']) ?></div><div class="small text-body-secondary">Current file. Choose a new file only to replace it.</div></div>
                </div>
            <?php endif; ?>
            <div class="dropzone" id="dropzone" tabindex="0">
                <i class="bi bi-cloud-arrow-up fs-1 text-primary"></i>
                <div class="fw-semibold">Drag and drop the invoice here</div>
                <div class="small text-body-secondary">or click to browse · PDF or photos (JPEG, PNG, WebP), up to <?= $maxMb ?> MB each</div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button class="btn btn-outline-primary" type="button" id="take-photo"><i class="bi bi-camera me-1"></i>Take photo of paper invoice</button>
            </div>
            <input class="form-control mt-2" type="file" id="pdf" name="pdf" accept="application/pdf,.pdf,image/jpeg,image/png,image/webp" data-max-mb="<?= $maxMb ?>">
            <input class="d-none" type="file" id="camera" accept="image/*" capture="environment">
            <input class="d-none" type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
            <div id="photo-card" class="mt-3" hidden>
                <div class="small fw-semibold mb-1">Photos (<span id="photo-count">0</span> page<span id="photo-plural">s</span>, combined into one PDF)</div>
                <div class="photo-grid" id="photo-list"></div>
                <button class="btn btn-sm btn-outline-primary mt-2" type="button" id="add-page"><i class="bi bi-plus-lg me-1"></i>Add another page</button>
            </div>
            <div id="file-card" class="file-card mt-3" hidden>
                <i class="bi bi-file-earmark-pdf fs-3 text-danger"></i>
                <div class="flex-grow-1"><div class="fw-semibold" id="file-name"></div><div class="small text-body-secondary" id="file-size"></div></div>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="file-remove" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="small text-danger mt-2" id="file-error" hidden></div>
        </section>

        <!-- Step 4: Review & submit -->
        <section data-step="3" class="mt-4">
            <h2 class="h6 text-uppercase text-body-secondary mb-3 wizard-title">Review &amp; submit</h2>
            <dl class="row mb-3 small" id="review-list"></dl>
            <?php if ($resub): ?>
                <label class="form-label" for="note">What did you change? <span class="text-danger">*</span></label>
                <textarea class="form-control" id="note" name="note" rows="3" maxlength="5000" placeholder="Required to resubmit"><?= esc((string) old('note')) ?></textarea>
            <?php endif; ?>
            <div class="form-text mt-2">Nothing is sent to approvers until you submit. Required to submit: supplier, number, both dates, an amount above zero, and the invoice document (a PDF or photos).</div>
        </section>

        <div class="d-flex flex-wrap gap-2 mt-4 align-items-center">
            <button class="btn btn-outline-secondary" type="button" id="wiz-back" hidden><i class="bi bi-arrow-left me-1"></i>Back</button>
            <button class="btn btn-primary" type="button" id="wiz-next" hidden>Continue<i class="bi bi-arrow-right ms-1"></i></button>
            <?php if ($resub): ?>
                <button class="btn btn-primary" type="submit" name="action" value="resubmit" data-final><i class="bi bi-send me-1"></i>Resubmit for approval</button>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save">Save changes</button>
            <?php else: ?>
                <button class="btn btn-primary" type="submit" name="action" value="submit" data-final><i class="bi bi-send me-1"></i>Submit Invoice</button>
                <button class="btn btn-outline-secondary" type="submit" name="action" value="save">Save Draft</button>
            <?php endif; ?>
            <a class="btn btn-link" href="<?= site_url($id === null ? 'invoices' : 'invoices/' . $id) ?>">Cancel</a>
        </div>
    </form>

    <!-- Camera: live preview with a choice of every camera the browser detects -->
    <div class="modal fade" id="camera-modal" tabindex="-1" aria-labelledby="camera-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="camera-title">Take photo of paper invoice</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="camera-stage"><video id="camera-video" autoplay playsinline muted></video></div>
                    <div class="mt-3" id="camera-select-wrap" hidden>
                        <label class="form-label" for="camera-select">Camera</label>
                        <select class="form-select" id="camera-select"></select>
                    </div>
                    <div class="alert alert-warning mt-3 mb-0" id="camera-error" hidden>
                        <span id="camera-error-text"></span>
                        <button class="btn btn-sm btn-outline-secondary ms-2" type="button" id="camera-native"><i class="bi bi-phone me-1"></i>Use the device camera app instead</button>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <span class="small text-body-secondary" id="camera-count"></span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="camera-capture"><i class="bi bi-camera-fill me-1"></i>Capture page</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Done</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var company = document.getElementById('company_id');
    var type = document.getElementById('invoice_type_id');
    var preview = document.getElementById('route-preview');
    var stepsBox = document.getElementById('route-steps');
    var extraWrap = document.getElementById('extra-wrap');
    var types = [];
    var labels = { submit: 'Submit', approve: 'Approve', process: 'Process', notify: 'Copy' };

    function text(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function showRoute() {
        var t = types.filter(function (x) { return String(x.id) === type.value; })[0];
        preview.hidden = !t;
        extraWrap.hidden = !(t && t.extra_flag);
        if (!t) { return; }
        stepsBox.innerHTML = t.steps.map(function (s, i) {
            return '<span class="badge text-bg-light border fw-normal">' + (i + 1) + '. ' + text(s.role || labels[s.type] || '') +
                (s.who ? ' · ' + text(s.who) : '') + ' <span class="text-body-secondary">(' + (labels[s.type] || s.type) + ')</span></span>';
        }).join('');
    }

    function load() {
        type.innerHTML = '<option value="">Choose a company first</option>';
        type.disabled = true; preview.hidden = true; extraWrap.hidden = true;
        if (!company.value) { return; }
        fetch('<?= site_url('invoices/types') ?>?company=' + encodeURIComponent(company.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                types = data.types || [];
                type.innerHTML = '<option value="">' + (types.length ? '— choose —' : 'No invoice types with an active route') + '</option>' +
                    types.map(function (t) { return '<option value="' + t.id + '">' + text(t.name) + '</option>'; }).join('');
                type.disabled = types.length === 0;
                if (type.dataset.selected) { type.value = type.dataset.selected; type.dataset.selected = ''; }
                showRoute();
                review();
            });
    }

    company.addEventListener('change', function () { type.dataset.selected = ''; load(); });
    type.addEventListener('change', showRoute);
    load();

    // ---- Wizard: without JavaScript every section simply shows as one long form.
    var form = document.getElementById('invoice-form');
    var sections = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
    var nav = document.getElementById('wizard-steps');
    var back = document.getElementById('wiz-back');
    var next = document.getElementById('wiz-next');
    var finals = Array.prototype.slice.call(form.querySelectorAll('[data-final]'));
    var current = 0;

    function selText(sel) { return sel.selectedIndex > 0 ? sel.options[sel.selectedIndex].text : ''; }

    function review() {
        var rows = [
            ['Company', selText(company)], ['Invoice type', selText(type)],
            ['Supplier', val('supplier')], ['Invoice number', val('invoice_number')],
            ['Invoice date', val('invoice_date')], ['Due date', val('due_date')],
            ['Amount', (val('currency') + ' ' + val('amount')).trim()], ['PO / reference', val('po_reference')],
            ['Document', photoFiles.length ? photoFiles.length + (photoFiles.length === 1 ? ' photo' : ' photos') : fileInput.files.length ? fileInput.files[0].name : (<?= $file ? json_encode($file['original_name']) : 'null' ?> || '')]
        ];
        document.getElementById('review-list').innerHTML = rows.map(function (r) {
            return '<dt class="col-4 fw-normal text-body-secondary">' + text(r[0]) + '</dt><dd class="col-8 fw-semibold">' + (r[1] ? text(r[1]) : '<span class="text-body-secondary fw-normal">—</span>') + '</dd>';
        }).join('');
    }
    function val(id) { return document.getElementById(id).value.trim(); }

    function show(n) {
        current = Math.max(0, Math.min(sections.length - 1, n));
        sections.forEach(function (s, i) { s.hidden = i !== current; });
        Array.prototype.forEach.call(nav.children, function (b, i) {
            b.classList.toggle('active', i === current);
            b.classList.toggle('done', i < current);
        });
        back.hidden = current === 0;
        next.hidden = current === sections.length - 1;
        finals.forEach(function (b) { b.hidden = current !== sections.length - 1; });
        if (current === sections.length - 1) { review(); }
        window.scrollTo({ top: 0 });
    }

    nav.hidden = false;
    Array.prototype.forEach.call(sections, function (s) { s.classList.remove('mt-4'); var t = s.querySelector('.wizard-title'); if (t) { t.hidden = true; } });
    // Required fields: checked per step on Continue, and for every step before saving or submitting.
    function problems(step) {
        var bad = [];
        Array.prototype.forEach.call(sections[step].querySelectorAll('[required]'), function (f) {
            var empty = f.disabled || String(f.value).trim() === '';
            if (f.id === 'amount' && !empty) { var n = parseFloat(f.value.replace(/[, ]/g, '')); empty = !(n > 0); }
            f.classList.toggle('is-invalid', empty);
            if (empty) { bad.push(f); }
        });
        var due = document.getElementById('due_date'), inv = document.getElementById('invoice_date');
        if (step === 0 && due.value && inv.value && due.value < inv.value) { due.classList.add('is-invalid'); bad.push(due); }
        return bad;
    }
    nav.addEventListener('click', function (e) { var b = e.target.closest('[data-goto]'); if (b) { show(parseInt(b.dataset.goto, 10)); } });
    back.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { if (problems(current).length) { problems(current)[0].focus(); return; } show(current + 1); });
    form.addEventListener('submit', function (e) {
        for (var i = 0; i < sections.length; i++) {
            var bad = problems(i);
            if (bad.length) { e.preventDefault(); show(i); bad[0].focus(); return; }
        }
    });
    form.addEventListener('input', function (e) { e.target.classList.remove('is-invalid'); });

    // ---- Upload: a PDF, or one or more photos of a paper invoice (sent as photos[] and combined into a PDF)
    var fileInput = document.getElementById('pdf');
    var camera = document.getElementById('camera');
    var photosInput = document.getElementById('photos');
    var zone = document.getElementById('dropzone');
    var card = document.getElementById('file-card');
    var err = document.getElementById('file-error');
    var photoCard = document.getElementById('photo-card');
    var photoList = document.getElementById('photo-list');
    var maxBytes = parseInt(fileInput.dataset.maxMb, 10) * 1048576;
    var maxPages = 20;
    var photoFiles = [];
    var urls = [];

    function fail(msg) { err.textContent = msg; err.hidden = false; }
    function isPdf(f) { return /\.pdf$/i.test(f.name) || f.type === 'application/pdf'; }
    function isPhoto(f) { return /^image\/(jpeg|png|webp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name); }

    function setPdf(f) {
        if (!f) { fileInput.value = ''; card.hidden = true; return; }
        var dt = new DataTransfer(); dt.items.add(f); fileInput.files = dt.files;
        document.getElementById('file-name').textContent = f.name;
        document.getElementById('file-size').textContent = (f.size / 1024).toFixed(0) + ' KB';
        card.hidden = false;
    }

    function renderPhotos() {
        urls.forEach(function (u) { URL.revokeObjectURL(u); }); urls = [];
        var dt = new DataTransfer();
        photoList.innerHTML = '';
        photoFiles.forEach(function (f, i) {
            dt.items.add(f);
            var u = URL.createObjectURL(f); urls.push(u);
            var cell = document.createElement('div'); cell.className = 'photo-thumb';
            var img = document.createElement('img'); img.src = u; img.alt = 'Page ' + (i + 1);
            var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'btn btn-sm btn-light border'; rm.setAttribute('aria-label', 'Remove page ' + (i + 1)); rm.innerHTML = '<i class="bi bi-x-lg"></i>';
            rm.addEventListener('click', function () { photoFiles.splice(i, 1); err.hidden = true; renderPhotos(); });
            var n = document.createElement('span'); n.className = 'photo-num'; n.textContent = String(i + 1);
            cell.appendChild(img); cell.appendChild(n); cell.appendChild(rm); photoList.appendChild(cell);
        });
        photosInput.files = dt.files;
        document.getElementById('photo-count').textContent = String(photoFiles.length);
        document.getElementById('photo-plural').hidden = photoFiles.length === 1;
        photoCard.hidden = photoFiles.length === 0;
    }

    function addFiles(list) {
        err.hidden = true;
        Array.prototype.forEach.call(list, function (f) {
            if (maxBytes && f.size > maxBytes) { fail('"' + f.name + '" is larger than ' + fileInput.dataset.maxMb + ' MB.'); return; }
            if (isPdf(f)) { photoFiles = []; renderPhotos(); setPdf(f); }
            else if (isPhoto(f)) {
                if (photoFiles.length >= maxPages) { fail('At most ' + maxPages + ' photos can be added.'); return; }
                setPdf(null); photoFiles.push(f);
            }
            else { fail('Only PDF files or JPEG, PNG and WebP photos are accepted.'); }
        });
        renderPhotos();
    }

    zone.addEventListener('click', function () { fileInput.click(); });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('drag'); }); });
    zone.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) { addFiles(e.dataTransfer.files); } });
    // The picker input is also the field that carries a chosen PDF, so read it, then let addFiles put it back.
    fileInput.addEventListener('change', function () { var fs = Array.prototype.slice.call(fileInput.files); fileInput.value = ''; addFiles(fs); });
    camera.addEventListener('change', function () { var fs = Array.prototype.slice.call(camera.files); camera.value = ''; addFiles(fs); });

    // ---- Camera: list every camera the browser detects, preview it live, capture pages as JPEG files.
    // Falls back to the device camera app (the hidden capture input) whenever the browser camera cannot be used.
    var modalEl = document.getElementById('camera-modal');
    var video = document.getElementById('camera-video');
    var camSelect = document.getElementById('camera-select');
    var camSelectWrap = document.getElementById('camera-select-wrap');
    var camError = document.getElementById('camera-error');
    var camErrorText = document.getElementById('camera-error-text');
    var camCapture = document.getElementById('camera-capture');
    var stream = null;
    var shots = 0;
    var canUseCamera = !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && navigator.mediaDevices.enumerateDevices && window.bootstrap);

    function stopStream() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
    }
    function camFail(msg) {
        stopStream();
        camErrorText.textContent = msg;
        camError.hidden = false; camCapture.disabled = true;
    }
    function camMessage(e) {
        switch (e && e.name) {
            case 'NotAllowedError': case 'SecurityError': return 'Camera access was blocked. Allow the camera for this site in your browser settings, or use the device camera app.';
            case 'NotFoundError': case 'OverconstrainedError': return 'No camera was found.';
            case 'NotReadableError': case 'AbortError': return 'The camera is in use by another app. Close it and try again, or use the device camera app.';
            default: return 'The camera could not be started.';
        }
    }
    function listCameras() {
        return navigator.mediaDevices.enumerateDevices().then(function (devs) {
            var cams = devs.filter(function (d) { return d.kind === 'videoinput'; });
            var active = stream && stream.getVideoTracks()[0] ? stream.getVideoTracks()[0].getSettings().deviceId : '';
            camSelect.innerHTML = '';
            cams.forEach(function (d, i) {
                var o = document.createElement('option');
                o.value = d.deviceId; o.textContent = d.label || ('Camera ' + (i + 1));
                if (d.deviceId === active) { o.selected = true; }
                camSelect.appendChild(o);
            });
            camSelectWrap.hidden = cams.length < 2;
        });
    }
    function startCamera(deviceId) {
        stopStream(); camError.hidden = true; camCapture.disabled = true;
        var size = { width: { ideal: 2560 }, height: { ideal: 1920 } };
        var constraint = deviceId ? Object.assign({ deviceId: { exact: deviceId } }, size) : Object.assign({ facingMode: { ideal: 'environment' } }, size);
        return navigator.mediaDevices.getUserMedia({ video: constraint, audio: false }).then(function (s) {
            if (!modalEl.classList.contains('show')) { s.getTracks().forEach(function (t) { t.stop(); }); return; }   // closed while asking permission
            stream = s; video.srcObject = s;
            camCapture.disabled = false;
            return listCameras();     // device names are only available once permission has been given
        }).catch(function (e) { camFail(camMessage(e)); });
    }
    function updateCount() { document.getElementById('camera-count').textContent = shots ? shots + (shots === 1 ? ' page taken' : ' pages taken') : ''; }

    function openCamera() {
        if (!canUseCamera) { camera.click(); return; }
        shots = 0; updateCount();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    modalEl.addEventListener('shown.bs.modal', function () { startCamera(null); });
    modalEl.addEventListener('hidden.bs.modal', stopStream);
    window.addEventListener('pagehide', stopStream);
    camSelect.addEventListener('change', function () { startCamera(camSelect.value); });
    if (canUseCamera) {
        navigator.mediaDevices.addEventListener('devicechange', function () { if (stream) { listCameras(); } });
    }
    camCapture.addEventListener('click', function () {
        if (!stream || !video.videoWidth) { return; }
        var c = document.createElement('canvas');
        c.width = video.videoWidth; c.height = video.videoHeight;
        c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
        c.toBlob(function (blob) {
            if (!blob) { camFail('The photo could not be captured.'); return; }
            shots++; updateCount();
            addFiles([new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' })]);
            if (!err.hidden) { camErrorText.textContent = err.textContent; camError.hidden = false; }   // for example the page limit
        }, 'image/jpeg', 0.9);
    });
    document.getElementById('camera-native').addEventListener('click', function () {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        camera.click();
    });
    document.getElementById('take-photo').addEventListener('click', openCamera);
    document.getElementById('add-page').addEventListener('click', openCamera);
    document.getElementById('file-remove').addEventListener('click', function () { err.hidden = true; setPdf(null); });
    fileInput.classList.add('d-none');

    show(0);
})();
</script>
<?= $this->endSection() ?>
