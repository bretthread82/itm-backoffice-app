// Runs in <head> before first paint so the page never flashes the wrong theme.
(function () {
    var root = document.documentElement;
    var pref = root.dataset.themePref;
    var saved = null;
    try { saved = localStorage.getItem('theme'); } catch (e) {}
    pref = saved || pref;
    if (pref === 'auto') pref = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    root.dataset.bsTheme = pref;
})();
