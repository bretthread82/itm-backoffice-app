/* Draws every <canvas data-chart="..."> on the long-term analytics pages with Chart.js.
 * The spec is built server-side: { labels: [], unit: 'count'|'money'|'%'|'days'|'hours', stacked: bool,
 *   datasets: [{ label, data: [], color, type?: 'bar'|'line', dash?: bool, stack? }] }.
 * The same numbers are always in a table under each chart, so the chart is a convenience, not the only view. */
(function () {
    'use strict';
    if (typeof Chart === 'undefined') { return; }

    function fmt(unit, v) {
        if (v === null || v === undefined) { return '—'; }
        if (unit === '%') { return v + '%'; }
        if (unit === 'days') { return v + ' d'; }
        if (unit === 'hours') { return v + ' h'; }
        return Number(v).toLocaleString(undefined, { maximumFractionDigits: unit === 'money' ? 2 : 0 });
    }

    var css = getComputedStyle(document.documentElement);
    var grid = css.getPropertyValue('--bs-border-color').trim() || '#dee2e6';
    var text = css.getPropertyValue('--bs-secondary-color').trim() || '#6c757d';

    document.querySelectorAll('canvas[data-chart]').forEach(function (el) {
        var spec;
        try { spec = JSON.parse(el.dataset.chart || '{}'); } catch (e) { return; }
        var unit = spec.unit || 'count';
        var datasets = (spec.datasets || []).map(function (d) {
            var line = d.type === 'line';
            return {
                type: d.type || 'bar', label: d.label, data: d.data, stack: d.stack,
                backgroundColor: d.color, borderColor: d.color, borderWidth: line ? 2 : 0,
                borderDash: d.dash ? [6, 4] : [], pointRadius: line ? 2 : 0, pointHoverRadius: 4,
                fill: false, tension: 0.2, spanGaps: false, order: line ? 0 : 1
            };
        });
        new Chart(el, {
            type: 'bar',
            data: { labels: spec.labels || [], datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, color: text } },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(unit, c.parsed.y); } } }
                },
                scales: {
                    x: { stacked: !!spec.stacked, grid: { display: false }, ticks: { color: text, maxRotation: 60, autoSkip: true } },
                    y: {
                        stacked: !!spec.stacked, beginAtZero: true, grid: { color: grid },
                        ticks: { color: text, precision: 0, callback: function (v) { return fmt(unit, v); } }
                    }
                }
            }
        });
    });
}());
