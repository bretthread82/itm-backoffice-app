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

    function setBadge(count) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.toggle('d-none', count < 1);
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
            list.appendChild(a);
        });
    }

    function load(withList) {
        fetch(feedUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
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
