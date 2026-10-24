/* Customer subscription form: loads the invoice types for the chosen company. The server checks everything again. */
(function () {
    'use strict';

    var form = document.getElementById('sub-form');
    if (!form) { return; }

    var company = document.getElementById('company_id');
    var type = document.getElementById('invoice_type_id');

    function text(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function load() {
        type.innerHTML = '<option value="">Choose a company first</option>';
        type.disabled = true;
        if (!company.value) { return; }
        fetch(form.dataset.typesUrl + '?company=' + encodeURIComponent(company.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var types = data.types || [];
                type.innerHTML = '<option value="">' + (types.length ? '— choose —' : 'No invoice types with an active route') + '</option>' +
                    types.map(function (t) { return '<option value="' + t.id + '">' + text(t.name) + '</option>'; }).join('');
                type.disabled = types.length === 0;
                if (type.dataset.selected) { type.value = type.dataset.selected; type.dataset.selected = ''; }
            });
    }

    company.addEventListener('change', function () { type.dataset.selected = ''; load(); });
    load();
}());
