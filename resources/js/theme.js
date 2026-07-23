const THEME_KEY = 'apex-ui-theme';

function normalizeTheme(value) {
    return value === 'dark' ? 'dark' : 'light';
}

export function getStoredTheme() {
    try {
        return normalizeTheme(localStorage.getItem(THEME_KEY) || localStorage.getItem('communications.dialer_theme'));
    } catch {
        return 'light';
    }
}

export function applyTheme(theme) {
    const next = normalizeTheme(theme);
    document.documentElement.dataset.theme = next;
    document.documentElement.classList.toggle('theme-dark', next === 'dark');
    document.documentElement.classList.toggle('theme-light', next === 'light');
    document.body?.classList.toggle('theme-dark', next === 'dark');
    document.body?.classList.toggle('theme-light', next === 'light');
    document.body?.classList.toggle('comm-theme-dark', next === 'dark');
    document.body?.classList.toggle('comm-theme-light', next === 'light');
    document.documentElement.dataset.commTheme = next;

    document.querySelectorAll('[data-comm-theme-label], [data-theme-label]').forEach((el) => {
        el.textContent = next === 'dark' ? 'Light' : 'Dark';
    });
    document.querySelectorAll('[data-comm-theme-toggle], [data-theme-toggle]').forEach((btn) => {
        btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
        btn.title = next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    });

    try {
        localStorage.setItem(THEME_KEY, next);
        localStorage.setItem('communications.dialer_theme', next);
    } catch {
        // ignore
    }

    return next;
}

export function initThemeToggle(root = document) {
    applyTheme(getStoredTheme());

    root.querySelectorAll('[data-comm-theme-toggle], [data-theme-toggle]').forEach((btn) => {
        if (btn.dataset.themeBound === '1') {
            return;
        }
        btn.dataset.themeBound = '1';
        btn.addEventListener('click', () => {
            const current = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    });
}
