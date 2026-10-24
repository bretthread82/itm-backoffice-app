/* Customer invoice form: loads the invoice types (and a preview of their approval route) for the chosen company,
   and refuses an oversized or non-PDF file before the upload starts. The server checks everything again. */
(function () {
    'use strict';

    var form = document.getElementById('ar-form');
    if (!form) { return; }

    var company = document.getElementById('company_id');
    var type = document.getElementById('invoice_type_id');
    var preview = document.getElementById('route-preview');
    var stepsBox = document.getElementById('route-steps');
    var extraWrap = document.getElementById('extra-wrap');
    var types = [];
    var labels = { submit: 'Raise', approve: 'Approve', issue: 'Issue', notify: 'Copy' };

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
        fetch(form.dataset.typesUrl + '?company=' + encodeURIComponent(company.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                types = data.types || [];
                type.innerHTML = '<option value="">' + (types.length ? '— choose —' : 'No invoice types with an active route') + '</option>' +
                    types.map(function (t) { return '<option value="' + t.id + '">' + text(t.name) + '</option>'; }).join('');
                type.disabled = types.length === 0;
                if (type.dataset.selected) { type.value = type.dataset.selected; type.dataset.selected = ''; }
                showRoute();
            });
    }

    company.addEventListener('change', function () { type.dataset.selected = ''; load(); });
    type.addEventListener('change', showRoute);
    load();

    var pdf = document.getElementById('pdf');
    var error = document.getElementById('file-error');
    pdf.addEventListener('change', function () {
        var f = pdf.files[0], max = parseInt(pdf.dataset.maxMb, 10) * 1024 * 1024, msg = '';
        if (f && !/\.pdf$/i.test(f.name)) { msg = 'Only PDF files can be uploaded.'; }
        else if (f && f.size > max) { msg = 'That file is larger than ' + pdf.dataset.maxMb + ' MB.'; }
        error.hidden = msg === '';
        error.textContent = msg;
        if (msg) { pdf.value = ''; }
    });
}());
