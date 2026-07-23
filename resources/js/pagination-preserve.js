/**
 * Pagination clicks: debounce + preserve scroll position (no jump to top).
 */
import * as Turbo from '@hotwired/turbo';

const DEBOUNCE_MS = 280;
const SCROLL_KEY = 'apex.pagination.scroll';
let lastVisitAt = 0;
let pendingTimer = null;
let pendingUrl = '';

function isPaginationLink(anchor) {
    if (!(anchor instanceof HTMLAnchorElement)) {
        return false;
    }
    if (!anchor.href || anchor.target === '_blank') {
        return false;
    }
    return Boolean(
        anchor.closest('.pag-nav, .app-pagination, [data-pagination], nav[aria-label*="Pagination"]')
        || anchor.rel === 'prev'
        || anchor.rel === 'next'
        || /[?&]page=\d+/i.test(anchor.search || '')
    );
}

function captureTableScrolls() {
    const tables = [];
    document.querySelectorAll('.app-data-table .app-table-wrap, [data-table-scroll], .import-workflows-table-scroll').forEach((el) => {
        if (el.scrollTop > 0 || el.scrollLeft > 0) {
            tables.push({
                top: el.scrollTop,
                left: el.scrollLeft,
                key: el.getAttribute('data-table-scroll')
                    || el.className
                    || 'table',
            });
        }
    });
    return tables;
}

function restoreTableScrolls(tables) {
    if (!Array.isArray(tables) || tables.length === 0) {
        return;
    }
    const wraps = Array.from(document.querySelectorAll(
        '.app-data-table .app-table-wrap, [data-table-scroll], .import-workflows-table-scroll'
    ));
    tables.forEach((saved, index) => {
        const el = wraps[index];
        if (!el) {
            return;
        }
        el.scrollTop = saved.top || 0;
        el.scrollLeft = saved.left || 0;
    });
}

function saveScrollState() {
    try {
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
            x: window.scrollX,
            y: window.scrollY,
            tables: captureTableScrolls(),
            at: Date.now(),
        }));
    } catch {
        // ignore storage failures
    }
}

function restoreScrollState() {
    let saved = null;
    try {
        const raw = sessionStorage.getItem(SCROLL_KEY);
        if (!raw) {
            return;
        }
        saved = JSON.parse(raw);
        sessionStorage.removeItem(SCROLL_KEY);
    } catch {
        return;
    }

    if (!saved || Date.now() - (saved.at || 0) > 15000) {
        return;
    }

    const apply = () => {
        window.scrollTo(saved.x || 0, saved.y || 0);
        restoreTableScrolls(saved.tables || []);
    };

    apply();
    requestAnimationFrame(apply);
    window.setTimeout(apply, 40);
    window.setTimeout(apply, 120);
    window.setTimeout(apply, 280);
}

function visitPagination(url) {
    saveScrollState();

    if (typeof Turbo?.visit === 'function') {
        Turbo.visit(url, { action: 'advance', scroll: false });
        document.addEventListener('turbo:load', restoreScrollState, { once: true });
        document.addEventListener('turbo:render', restoreScrollState, { once: true });
        window.setTimeout(restoreScrollState, 40);
        window.setTimeout(restoreScrollState, 120);
        return;
    }

    window.location.assign(url);
}

export function initPaginationPreserve() {
    if (document.documentElement.dataset.paginationPreserve === '1') {
        return;
    }
    document.documentElement.dataset.paginationPreserve = '1';

    // Restore after hard navigations (full page reload).
    restoreScrollState();

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = event.target.closest?.('a');
        if (!isPaginationLink(anchor)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const url = anchor.href;
        const now = Date.now();
        if (now - lastVisitAt < DEBOUNCE_MS && pendingUrl === url) {
            return;
        }

        pendingUrl = url;
        if (pendingTimer) {
            clearTimeout(pendingTimer);
        }

        pendingTimer = window.setTimeout(() => {
            pendingTimer = null;
            lastVisitAt = Date.now();
            visitPagination(url);
        }, Math.max(0, DEBOUNCE_MS - (now - lastVisitAt)));
    }, true);
}
