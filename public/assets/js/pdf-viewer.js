/*
 * Minimal in-browser PDF viewer on top of self-hosted PDF.js (UMD build).
 * Markup contract: <div class="pdf-viewer" data-url="..." data-worker="..."> with the toolbar and
 * canvas container created here. Works on desktop, tablet and phone (including iOS Safari), where
 * native embedded PDFs are unreliable. Always leave plain "open"/"download" links next to it.
 */
(function () {
    'use strict';

    function init(root) {
        var url = root.dataset.url;
        var status = root.querySelector('[data-pdf-status]');
        var pages = root.querySelector('[data-pdf-pages]');
        var label = root.querySelector('[data-pdf-label]');
        var scrollBox = pages;
        var scaleMode = 'fit'; // 'fit' or a number
        var scale = 1;
        var doc = null;
        var observer = null;
        var rendered = {};

        if (!window.pdfjsLib) { fail('The viewer could not load. Use "Open in new tab" instead.'); return; }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = root.dataset.worker;

        function fail(msg) { status.textContent = msg; status.hidden = false; status.classList.add('text-danger'); }

        function containerWidth() { return Math.max(200, pages.clientWidth - 16); }

        function setLabel() {
            var n = currentPage();
            label.textContent = doc ? (n + ' / ' + doc.numPages) : '';
        }

        function currentPage() {
            var items = pages.querySelectorAll('[data-page]');
            var top = pages.scrollTop + pages.clientHeight / 3, found = 1;
            for (var i = 0; i < items.length; i++) { if (items[i].offsetTop <= top) { found = i + 1; } }
            return found;
        }

        function build() {
            pages.innerHTML = '';
            rendered = {};
            if (observer) { observer.disconnect(); }
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) { if (e.isIntersecting) { renderPage(parseInt(e.target.dataset.page, 10)); } });
            }, { root: pages, rootMargin: '600px 0px' });

            var first = doc.getPage(1).then(function (p) {
                var base = p.getViewport({ scale: 1 });
                scale = scaleMode === 'fit' ? containerWidth() / base.width : scaleMode;
                for (var n = 1; n <= doc.numPages; n++) {
                    var holder = document.createElement('div');
                    holder.dataset.page = n;
                    holder.className = 'pdf-page mx-auto mb-2 bg-white shadow-sm position-relative';
                    holder.style.width = Math.floor(base.width * scale) + 'px';
                    holder.style.height = Math.floor(base.height * scale) + 'px';
                    pages.appendChild(holder);
                    observer.observe(holder);
                }
                setLabel();
            });
            return first;
        }

        function renderPage(n) {
            if (rendered[n]) { return; }
            rendered[n] = true;
            doc.getPage(n).then(function (page) {
                var viewport = page.getViewport({ scale: scale });
                var ratio = window.devicePixelRatio || 1;
                var canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width * ratio);
                canvas.height = Math.floor(viewport.height * ratio);
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';
                var holder = pages.querySelector('[data-page="' + n + '"]');
                if (!holder) { return; }
                holder.style.width = canvas.style.width;
                holder.style.height = canvas.style.height;
                holder.appendChild(canvas);
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
            });
        }

        function zoom(factor) {
            scaleMode = Math.min(4, Math.max(0.3, (scaleMode === 'fit' ? scale : scaleMode) * factor));
            build();
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pdf-action]');
            if (!btn || !doc) { return; }
            var act = btn.dataset.pdfAction;
            if (act === 'zoom-in') { zoom(1.25); }
            if (act === 'zoom-out') { zoom(0.8); }
            if (act === 'fit') { scaleMode = 'fit'; build(); }
            if (act === 'prev' || act === 'next') {
                var target = Math.min(doc.numPages, Math.max(1, currentPage() + (act === 'next' ? 1 : -1)));
                var el = pages.querySelector('[data-page="' + target + '"]');
                if (el) { pages.scrollTo({ top: el.offsetTop - 4, behavior: 'smooth' }); }
            }
            if (act === 'fullscreen') {
                var host = root;
                if (document.fullscreenElement) { document.exitFullscreen(); }
                else if (host.requestFullscreen) { host.requestFullscreen(); }
                else { host.classList.toggle('pdf-expanded'); } // iOS Safari: no element fullscreen
            }
        });

        pages.addEventListener('scroll', function () { window.requestAnimationFrame(setLabel); }, { passive: true });

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            if (scaleMode !== 'fit' || !doc) { return; }
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(build, 250);
        });

        window.pdfjsLib.getDocument({ url: url, withCredentials: true, disableRange: true, disableStream: true }).promise
            .then(function (d) { doc = d; status.hidden = true; return build(); })
            .catch(function () { fail('This PDF could not be shown here. Use "Open in new tab" or "Download".'); });
    }

    document.querySelectorAll('.pdf-viewer').forEach(init);
})();
