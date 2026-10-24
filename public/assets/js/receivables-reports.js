/* Collections chart. Colours come from the theme's --itm-* tokens (read when the page loads, so light and dark both work),
   never from hard-coded values. */
(function () {
    'use strict';

    var el = document.getElementById('collections-chart');
    if (!el || typeof Chart === 'undefined') { return; }

    var styles = getComputedStyle(document.documentElement);
    function token(name) { return styles.getPropertyValue(name).trim() || undefined; }

    var months = JSON.parse(el.dataset.months || '[]');
    var text = token('--itm-text-muted');

    new Chart(el, {
        type: 'bar',
        data: {
            labels: months.map(function (m) { return m.label; }),
            datasets: [
                { label: 'Invoiced', data: months.map(function (m) { return m.invoiced; }), backgroundColor: token('--itm-info') },
                { label: 'Received', data: months.map(function (m) { return m.received; }), backgroundColor: token('--itm-success') }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, color: text } } },
            scales: {
                y: { beginAtZero: true, ticks: { color: text } },
                x: { grid: { display: false }, ticks: { color: text } }
            }
        }
    });
}());
