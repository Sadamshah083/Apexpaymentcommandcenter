/**
 * Prevent Turbo from rendering login pages inside the app shell.
 * Common when prefetch/cache captures a transient unauthenticated response.
 */

function loginPathnames() {
    const fromMeta = document.querySelector('meta[name="auth-login-paths"]')?.content;
    if (fromMeta) {
        try {
            return JSON.parse(fromMeta);
        } catch {
            // fall through
        }
    }

    return ['/admin/login', '/portal/login', '/login'];
}

function isLoginPath(url) {
    try {
        const path = new URL(url, window.location.origin).pathname;

        return loginPathnames().some((loginPath) => path === loginPath || path.startsWith(`${loginPath}/`));
    } catch {
        return false;
    }
}

function forceFullVisit(url) {
    window.location.assign(url);
}

function pageLooksLikeLogin() {
    return Boolean(
        document.querySelector('form[action*="login"]')
        || document.querySelector('[data-portal-login]')
        || document.querySelector('[data-admin-login]'),
    );
}

function initTurboAuthGuard() {
    if (document.documentElement.dataset.turboAuthInit === '1') {
        return;
    }
    document.documentElement.dataset.turboAuthInit = '1';

    document.addEventListener('turbo:before-fetch-response', (event) => {
        const { fetchResponse } = event.detail;
        const response = fetchResponse?.response;
        if (!response) {
            return;
        }

        const finalUrl = response.url || fetchResponse.location?.href || '';
        if (response.redirected && isLoginPath(finalUrl)) {
            event.preventDefault();
            forceFullVisit(finalUrl);
        }
    });

    document.addEventListener('turbo:load', () => {
        if (isLoginPath(window.location.pathname)) {
            return;
        }

        if (document.querySelector('.app-content-shell') && pageLooksLikeLogin()) {
            window.location.reload();
        }
    });

    document.addEventListener('turbo:before-cache', (event) => {
        if (isLoginPath(window.location.pathname) || pageLooksLikeLogin()) {
            event.preventDefault();
        }
    });
}

export { initTurboAuthGuard };
