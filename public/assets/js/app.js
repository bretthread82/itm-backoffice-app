(function () {
    'use strict';

    var root = document.documentElement;
    var toggle = document.getElementById('themeToggle');

    function setIcon() {
        if (!toggle) return;
        var dark = root.dataset.bsTheme === 'dark';
        toggle.innerHTML = '<i class="bi bi-' + (dark ? 'sun' : 'moon-stars') + '"></i>';
    }

    if (toggle) {
        setIcon();
        toggle.addEventListener('click', function () {
            var next = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
            root.dataset.bsTheme = next;
            try { localStorage.setItem('theme', next); } catch (e) {}
            setIcon();
        });
    }
})();

// Notification bell: poll the unread count; load the list when the panel opens.
(function () {
    'use strict';

    var menu = document.getElementById('notifMenu');
    if (!menu) return;

    var feedUrl = menu.dataset.feed;
    var badge = document.getElementById('notifBadge');
    var list = document.getElementById('notifList');
    var readAll = document.getElementById('notifReadAll');
    var csrfHeader = menu.dataset.csrfHeader;
    var csrf = '';

    function setBadge(count) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.toggle('d-none', count < 1);
        if (readAll) readAll.disabled = count < 1;
    }

    // POST to a mark-read route; the CSRF token rotates, so keep the one each response returns.
    function post(url, done) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        headers[csrfHeader] = csrf;
        fetch(url, { method: 'POST', headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                csrf = data.csrf;
                setBadge(data.count);
                done();
            })
            .catch(function () {});
    }

    if (readAll) {
        readAll.addEventListener('click', function () {
            post(readAll.dataset.url, function () { load(true); });
        });
    }

    function render(items) {
        list.textContent = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'text-center text-body-secondary small py-4';
            empty.textContent = "You're all caught up.";
            list.appendChild(empty);
            return;
        }
        items.forEach(function (n) {
            var a = document.createElement('a');
            a.className = 'list-group-item list-group-item-action' + (n.unread ? ' notif-unread' : '');
            a.href = n.open;
            var t = document.createElement('div');
            t.className = n.unread ? 'fw-semibold small' : 'small';
            t.textContent = n.title;
            var m = document.createElement('div');
            m.className = 'small text-body-secondary';
            m.textContent = n.ago;
            a.appendChild(t);
            a.appendChild(m);

            if (!n.unread) {
                list.appendChild(a);
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'd-flex align-items-center list-group-item p-0 notif-unread';
            a.className = 'list-group-item list-group-item-action border-0 flex-fill bg-transparent';
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-link me-1';
            b.title = 'Mark as read';
            b.setAttribute('aria-label', 'Mark as read');
            b.innerHTML = '<i class="bi bi-check2"></i>';
            b.addEventListener('click', function () {
                post(n.read, function () { load(true); });
            });
            wrap.appendChild(a);
            wrap.appendChild(b);
            list.appendChild(wrap);
        });
    }

    function load(withList) {
        fetch(feedUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                csrf = data.csrf;
                setBadge(data.count);
                if (withList) render(data.items);
            })
            .catch(function () {});
    }

    menu.addEventListener('show.bs.dropdown', function () { load(true); });

    var timer = setInterval(function () { if (!document.hidden) load(false); }, 45000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(false); });
    window.addEventListener('pagehide', function () { clearInterval(timer); });
})();

// Confirmation prompts: <form data-confirm="Are you sure?"> (inline handlers are blocked by our CSP).
document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Shell: sidebar collapse (desktop) / drawer (mobile).
(function () {
    'use strict';

    var body = document.body;
    var toggle = document.getElementById('sidebar-toggle');
    var wide = window.matchMedia('(min-width: 992px)');
    if (!toggle) return;

    try { if (localStorage.getItem('itm-collapsed') === '1' && wide.matches) body.classList.add('itm-collapsed'); } catch (e) {}

    toggle.addEventListener('click', function () {
        if (wide.matches) {
            var collapsed = body.classList.toggle('itm-collapsed');
            try { localStorage.setItem('itm-collapsed', collapsed ? '1' : '0'); } catch (e) {}
        } else {
            body.classList.toggle('itm-drawer-open');
        }
    });

    document.addEventListener('click', function (e) {
        if (body.classList.contains('itm-drawer-open') && !e.target.closest('#sidebar') && !e.target.closest('#sidebar-toggle')) {
            body.classList.remove('itm-drawer-open');
        }
    });
})();

// CSP-safe replacements for inline handlers used by modules.
(function () {
    'use strict';

    // <button data-confirm-click="Sure?"> asks before the click goes ahead (form submit or link).
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-confirm-click]');
        if (el && !window.confirm(el.getAttribute('data-confirm-click'))) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    // <input data-autosubmit> submits its form when changed.
    document.addEventListener('change', function (event) {
        var el = event.target;
        if (el.matches && el.matches('[data-autosubmit]') && el.form) {
            el.form.submit();
        }
    });

    // <div data-width="42"> is drawn 42% wide (bars); set through the CSSOM, which the CSP allows.
    document.querySelectorAll('[data-width]').forEach(function (el) {
        el.style.width = Math.max(0, Math.min(100, parseFloat(el.getAttribute('data-width')) || 0)) + '%';
    });
})();

// Sidebar groups: remember which collapsible groups the user opened or closed (the group holding the current page always opens).
(function () {
    'use strict';

    var KEY = 'itm-nav-groups';
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}

    document.querySelectorAll('[data-nav-group]').forEach(function (group) {
        var name = group.getAttribute('data-nav-group');
        var panel = group.querySelector('.collapse');
        var toggle = group.querySelector('[data-bs-toggle="collapse"]');
        if (!panel || !toggle) return;

        if (saved[name] === true && !panel.classList.contains('show')) {
            panel.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
        }

        panel.addEventListener('shown.bs.collapse', function () { saved[name] = true; persist(); });
        panel.addEventListener('hidden.bs.collapse', function () { saved[name] = false; persist(); });
    });

    function persist() {
        try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
    }
})();
