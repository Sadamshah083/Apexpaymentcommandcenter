/**
 * Pagination clicks: debounce + preserve scroll position (no jump to top).
 */
import * as Turbo from '@hotwired/turbo';

const DEBOUNCE_MS = 280;
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

function visitPagination(url) {
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;

    // Prefer a hard navigation for heavy admin lists so Turbo doesn't leave document fetches pending.
    const heavyList = /\/admin\/(workflows|dashboard)(\?|$)/i.test(url);
    if (heavyList || typeof Turbo?.visit !== 'function') {
        window.location.assign(url);
        return;
    }

    if (typeof Turbo?.visit === 'function') {
        Turbo.visit(url, { action: 'advance', scroll: false });
    } else {
        window.location.assign(url);
        return;
    }

    const restore = () => {
        window.scrollTo(scrollX, scrollY);
    };

    document.addEventListener('turbo:load', restore, { once: true });
    document.addEventListener('turbo:render', restore, { once: true });
    window.setTimeout(restore, 40);
    window.setTimeout(restore, 120);
}

export function initPaginationPreserve() {
    if (document.documentElement.dataset.paginationPreserve === '1') {
        return;
    }
    document.documentElement.dataset.paginationPreserve = '1';

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
