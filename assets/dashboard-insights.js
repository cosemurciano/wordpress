/**
 * Affiliate Link Manager AI - Insight strategici della Dashboard
 *
 * Grafico trend click (giorno/settimana/mese) con Chart.js sui dati dello
 * snapshot precalcolato (nessuna query al caricamento); pulsanti
 * "Aggiorna ora" (ricostruzione snapshot) e "Genera consigli AI".
 */
(function () {
    'use strict';

    function cfg() {
        return window.almaInsights || {};
    }

    var chart = null;

    function renderChart(range) {
        var canvas = document.getElementById('alma-insights-chart');
        if (!canvas || typeof window.Chart === 'undefined') { return; }
        var series = (cfg().series || {})[range] || { labels: [], data: [] };
        if (chart) { chart.destroy(); }
        chart = new window.Chart(canvas, {
            type: range === 'daily' ? 'bar' : 'line',
            data: {
                labels: series.labels,
                datasets: [{
                    label: (cfg().strings || {}).clicks || 'Click',
                    data: series.data,
                    borderColor: '#2271b1',
                    backgroundColor: range === 'daily' ? 'rgba(34,113,177,0.55)' : 'rgba(34,113,177,0.12)',
                    fill: range !== 'daily',
                    tension: 0.25,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    function setActiveButton(range) {
        var buttons = document.querySelectorAll('.alma-insights-range');
        Array.prototype.forEach.call(buttons, function (button) {
            button.classList.toggle('button-primary', button.getAttribute('data-range') === range);
        });
    }

    function post(action, feedbackEl, onSuccess) {
        var params = new URLSearchParams();
        params.set('action', action);
        params.set('nonce', cfg().nonce || '');
        return fetch(cfg().ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (r) { return r.json(); }).then(function (response) {
            if (response && response.success) {
                onSuccess(response.data || {});
            } else {
                var message = response && response.data && response.data.message ? response.data.message : (cfg().strings || {}).error;
                if (feedbackEl) { feedbackEl.textContent = message; }
            }
        }).catch(function () {
            if (feedbackEl) { feedbackEl.textContent = (cfg().strings || {}).error; }
        });
    }

    function boot() {
        renderChart('daily');
        setActiveButton('daily');

        document.addEventListener('click', function (event) {
            var target = event.target;

            if (target.classList && target.classList.contains('alma-insights-range')) {
                event.preventDefault();
                var range = target.getAttribute('data-range');
                renderChart(range);
                setActiveButton(range);
                return;
            }

            if (target.id === 'alma-insights-rebuild') {
                event.preventDefault();
                var feedback = document.getElementById('alma-insights-rebuild-feedback');
                if (feedback) { feedback.textContent = (cfg().strings || {}).rebuilding; }
                target.disabled = true;
                post('alma_insights_rebuild', feedback, function () {
                    if (feedback) { feedback.textContent = (cfg().strings || {}).rebuilt; }
                    window.location.reload();
                }).then(function () { target.disabled = false; });
                return;
            }

            if (target.id === 'alma-insights-ai') {
                event.preventDefault();
                var aiFeedback = document.getElementById('alma-insights-ai-feedback');
                var aiBox = document.getElementById('alma-insights-ai-text');
                if (aiFeedback) { aiFeedback.textContent = (cfg().strings || {}).aiWorking; }
                target.disabled = true;
                post('alma_insights_ai_advice', aiFeedback, function (data) {
                    if (aiFeedback) { aiFeedback.textContent = ''; }
                    if (aiBox) {
                        aiBox.textContent = data.text || '';
                        aiBox.style.display = 'block';
                    }
                    var meta = document.getElementById('alma-insights-ai-meta');
                    if (meta) {
                        var parts = [];
                        if (data.generated_at) { parts.push(data.generated_at); }
                        if (data.model) { parts.push(data.model); }
                        if (data.estimated_cost) { parts.push('~$' + Number(data.estimated_cost).toFixed(4)); }
                        meta.textContent = parts.join(' · ');
                    }
                }).then(function () { target.disabled = false; });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
