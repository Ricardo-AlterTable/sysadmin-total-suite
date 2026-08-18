/**
 * Sysadmin Total Suite — lógica de la pantalla de Profiling.
 * Los datos llegan desde PHP mediante wp_localize_script (STSUITE_PROFILER).
 */
(function () {
    'use strict';

    var D = window.STSUITE_PROFILER || {};

    document.addEventListener('DOMContentLoaded', function () {
        // ---- Botón "Lanzar prueba" ----
        var btn     = document.getElementById('stsuiteRunTest');
        var iframe  = document.getElementById('stsuiteTestFrame');
        var spinner = document.getElementById('stsuiteSpinner');

        if (btn && iframe && D.testUrl) {
            btn.addEventListener('click', function () {
                if (spinner) spinner.style.display = 'inline';
                iframe.onload = function () {
                    window.location.reload();
                };
                // testUrl ya viene firmada con su nonce desde PHP.
                iframe.src = D.testUrl + '&t=' + Date.now();
            });
        }

        // ---- Gráficas ----
        if (typeof window.Chart === 'undefined') return;

        Chart.defaults.color = '#98a2c0';
        Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
        Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";

        var gridOpts = {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { color: 'rgba(255,255,255,0.06)' } },
                y: { grid: { color: 'rgba(255,255,255,0.06)' }, beginAtZero: true }
            }
        };

        var el1 = document.getElementById('stsuiteProfilingChart');
        if (el1 && D.labels && D.values) {
            var ctx1 = el1.getContext('2d');
            var grad1 = ctx1.createLinearGradient(0, 0, 0, 300);
            grad1.addColorStop(0, 'rgba(124, 92, 255, 0.85)');
            grad1.addColorStop(1, 'rgba(34, 211, 238, 0.55)');

            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: D.labels,
                    datasets: [{
                        label: D.labelTime || '',
                        data: D.values,
                        backgroundColor: grad1,
                        borderColor: 'rgba(124, 92, 255, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: gridOpts
            });
        }

        var el2 = document.getElementById('stsuiteHistoryChart');
        if (el2 && D.histLabels && D.histValues) {
            new Chart(el2.getContext('2d'), {
                type: 'line',
                data: {
                    labels: D.histLabels,
                    datasets: [{
                        label: D.labelTotal || '',
                        data: D.histValues,
                        fill: true,
                        backgroundColor: 'rgba(34, 211, 238, 0.12)',
                        borderColor: '#22d3ee',
                        pointBackgroundColor: '#7c5cff',
                        tension: 0.35
                    }]
                },
                options: gridOpts
            });
        }
    });
})();
