'use strict';

(() => {
    const form = document.querySelector('[data-pilot-report-timing]');
    if (form) {
        const timeByChild = new Map();
        let currentChild = null;
        let lastActivity = 0;

        function measureUntil(now) {
            if (currentChild !== null) {
                const activeMs = Math.min(now - lastActivity, 60_000);
                timeByChild.set(currentChild, (timeByChild.get(currentChild) || 0) + activeMs);
            }
            lastActivity = now;
        }

        function measureInteraction(event) {
            const card = event.target.closest('.report-child-card');
            if (document.hidden) return;
            measureUntil(performance.now());
            currentChild = card ? card.dataset.childId : null;
        }

        for (const eventType of ['focusin', 'input', 'change', 'click']) {
            form.addEventListener(eventType, measureInteraction);
        }
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                measureUntil(performance.now());
                currentChild = null;
            }
        });
        form.addEventListener('formdata', event => {
            measureUntil(performance.now());
            for (const [childId, workMs] of timeByChild) {
                const roundedMs = Math.round(workMs);
                if (roundedMs > 0) event.formData.set('report_work_ms[' + childId + ']', String(roundedMs));
            }
        });
    }

    const viewer = document.querySelector('[data-pilot-view-url]');
    if (!viewer || !('IntersectionObserver' in window)) return;
    const unread = new Set(viewer.querySelectorAll('[data-pilot-report-view]'));
    const timers = new Map();
    const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
            clearTimeout(timers.get(entry.target));
            if (entry.intersectionRatio < 0.6 || document.hidden) continue;
            const marker = entry.target;
            timers.set(marker, setTimeout(async () => {
                if (document.hidden) return;
                const body = new URLSearchParams({
                    csrf_token: viewer.dataset.pilotViewToken,
                    report_id: marker.dataset.pilotReportView,
                });
                try {
                    const response = await fetch(viewer.dataset.pilotViewUrl, {method: 'POST', body, keepalive: true});
                    if (response.status === 204) {
                        unread.delete(marker);
                        observer.unobserve(marker);
                    }
                } catch {
                    // A later visit can capture the view if connectivity returns.
                }
            }, 1000));
        }
    }, {threshold: 0.6});
    for (const marker of unread) observer.observe(marker);
    document.addEventListener('visibilitychange', () => {
        for (const timer of timers.values()) clearTimeout(timer);
        timers.clear();
        if (!document.hidden) {
            for (const marker of unread) {
                observer.unobserve(marker);
                observer.observe(marker);
            }
        }
    });
})();
