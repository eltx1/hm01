/* Dashboard-only preference. Apply before CSS paints; no ad runtime dependencies. */
(() => {
    'use strict';
    const root = document.documentElement;
    const key = 'hm:dashboard:theme';
    const normalize = value => value === 'light' ? 'light' : 'dark';
    const paint = value => {
        const theme = normalize(value);
        root.dataset.hmTheme = theme;
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.setAttribute('aria-pressed', String(theme === 'light'));
            const action = theme === 'light' ? 'Switch to Dark Mode' : 'Switch to White Mode';
            const label = theme === 'light' ? 'Dark Mode' : 'White Mode';
            button.setAttribute('aria-label', action);
            // Icon buttons keep their SVGs and update only their text label.
            const text = typeof button.querySelector === 'function' ? button.querySelector('[data-theme-label]') : null;
            if (text) {
                text.textContent = label;
                button.setAttribute('title', action);
            } else {
                button.textContent = label;
            }
        });
    };
    let saved;
    try { saved = window.localStorage.getItem(key); } catch { /* Storage is optional. */ }
    paint(saved);
    document.addEventListener('DOMContentLoaded', () => {
        paint(root.dataset.hmTheme);
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.hidden = false;
            button.addEventListener('click', () => {
                const theme = root.dataset.hmTheme === 'light' ? 'dark' : 'light';
                paint(theme);
                try { window.localStorage.setItem(key, theme); } catch { /* Keep this page usable. */ }
            });
        });
    }, { once: true });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) paint(event.newValue);
    });
})();
