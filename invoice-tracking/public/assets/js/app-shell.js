// Shell behaviour: sidebar drawer/collapse, Ctrl+K search, notification bell.
(function () {
    var body = document.body;
    var toggle = document.getElementById('sidebar-toggle');
    var wide = window.matchMedia('(min-width: 992px)');

    try { if (localStorage.getItem('mg-collapsed') === '1' && wide.matches) { body.classList.add('mg-collapsed'); } } catch (e) {}

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (wide.matches) {
                var c = body.classList.toggle('mg-collapsed');
                try { localStorage.setItem('mg-collapsed', c ? '1' : '0'); } catch (e) {}
            } else {
                body.classList.toggle('mg-drawer-open');
            }
        });
    }
    // Row action menus sit inside .table-responsive, which clips overflow, so a short table hides
    // the menu. Fixed positioning lets the menu float above the table's edge instead.
    if (window.bootstrap) {
        document.querySelectorAll('.table-responsive [data-bs-toggle="dropdown"]').forEach(function (el) {
            window.bootstrap.Dropdown.getOrCreateInstance(el, { popperConfig: function (cfg) { return Object.assign({}, cfg, { strategy: 'fixed' }); } });
        });
    }

    document.addEventListener('click', function (e) {
        if (body.classList.contains('mg-drawer-open') && !e.target.closest('#sidebar') && !e.target.closest('#sidebar-toggle')) {
            body.classList.remove('mg-drawer-open');
        }
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            var s = document.getElementById('global-search');
            if (s) { e.preventDefault(); s.focus(); s.select(); }
        }
    });

    // Notification bell: unread count and the latest alerts, refreshed about once a minute.
    var bell = document.getElementById('bell');
    if (!bell) { return; }
    var count = document.getElementById('bell-count');
    var list = document.getElementById('bell-list');
    var readAll = document.getElementById('bell-read-all');

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    function render(data) {
        if (data.count > 0) { count.textContent = data.count > 99 ? '99+' : data.count; count.hidden = false; }
        else { count.hidden = true; }
        if (readAll) { readAll.hidden = !(data.count > 0); }
        if (!data.items.length) {
            list.innerHTML = '<div class="list-group-item text-body-secondary small">No unread notifications.</div>';
            return;
        }
        list.innerHTML = data.items.map(function (n) {
            return '<div class="list-group-item list-group-item-action bg-primary-subtle d-flex gap-2 align-items-start">' +
                '<a class="text-decoration-none text-reset flex-grow-1" href="' + esc(n.url) + '">' +
                '<div class="small fw-semibold">' + (n.priority ? '<span class="badge text-bg-danger me-1">HIGH</span>' : '') + esc(n.title) + '</div>' +
                '<div class="small text-body-secondary">' + esc(n.ago) + '</div></a>' +
                '<button type="button" class="btn btn-sm btn-link p-0 text-nowrap" data-read-url="' + esc(n.readUrl) + '">Mark read</button></div>';
        }).join('');
    }

    // "Mark read" removes the item from the bell; it stays on the full Notifications page.
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-read-url]');
        if (!btn) { return; }
        btn.disabled = true;
        fetch(btn.dataset.readUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) { poll(true); } else { btn.disabled = false; } })
            .catch(function () { btn.disabled = false; });
    });

    function poll(force) {
        if (document.hidden && force !== true) { return; }
        fetch(bell.dataset.url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) { render(d); } })
            .catch(function () {});
    }

    poll();
    setInterval(poll, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
