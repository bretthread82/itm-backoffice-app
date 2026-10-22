// Profile page: swap the "new value" control to match the field being changed.
(function () {
    var field = document.getElementById('field');
    if (!field) return;
    var inputs = document.querySelectorAll('[data-for]');

    function sync() {
        inputs.forEach(function (el) {
            var on = el.dataset.for === field.value;
            el.classList.toggle('d-none', !on);
            el.disabled = !on;
            if (on) { el.setAttribute('name', 'new_value'); el.required = true; }
            else { el.removeAttribute('name'); el.required = false; }
        });
    }

    field.addEventListener('change', sync);
    sync();
})();
