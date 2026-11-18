/* mojavez-v3 — app.js: theme toggle (day/night) + nav active state.
   Persisted in localStorage('moj-theme'). Default: system preference. */
(function () {
    'use strict';

    var STORAGE_KEY = 'moj-theme';
    var root = document.documentElement;

    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function setStoredTheme(theme) {
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }
    }
    function getPreferredTheme() {
        var stored = getStoredTheme();
        if (stored === 'light' || stored === 'dark') return stored;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function applyTheme(theme) {
        root.setAttribute('data-bs-theme', theme);
        // Update toggle icon visibility handled by CSS.
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f172a' : '#0f766e');
    }

    // Apply before first paint (avoids flash).
    applyTheme(getPreferredTheme());

    // Listen for system changes (only if user hasn't explicitly chosen).
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (!getStoredTheme()) applyTheme(e.matches ? 'dark' : 'light');
    });

    // Wire up toggle buttons (after DOM ready).
    function initToggles() {
        document.querySelectorAll('.moj-theme-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var current = root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
                var next = current === 'dark' ? 'light' : 'dark';
                setStoredTheme(next);
                applyTheme(next);
            });
        });

        // Mark active nav link based on current path.
        var path = window.location.pathname;
        document.querySelectorAll('.moj-header .nav-link, .moj-admin-sidebar .nav-link').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href) return;
            if (href === path || (href !== '/' && path.indexOf(href) === 0)) {
                link.classList.add('active');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToggles);
    } else {
        initToggles();
    }
})();
