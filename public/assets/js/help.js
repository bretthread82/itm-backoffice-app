// Opens the FAQ item named in the URL hash (search results link straight to a question).
(function () {
    var id = decodeURIComponent(location.hash.slice(1));
    if (!id || id.indexOf('f-') !== 0) { return; }

    var body = document.getElementById(id + '-body');
    if (!body || !window.bootstrap) { return; }

    window.bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).show();
    body.closest('.accordion-item').scrollIntoView({ block: 'center' });
}());
