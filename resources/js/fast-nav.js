/**
 * Next.js Link-style navigation: hover + idle prefetch for sidebar routes.
 * Turbo Drive handles the soft visit; this warms the cache before click.
 */
import * as Turbo from '@hotwired/turbo';

const PREFETCHED = new Set();
const MAX_IDLE_PREFETCH = 3;

/** Heavy routes — skip idle prefetch to avoid warming full HTML under load. */
const HEAVY_PATH_RE = /\/(communications\/(dialer|call-monitoring|agent-status)|maps-scraper)/i;

function sameOrigin(url) {
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin;
    } catch {
        return false;
    }
}

function isHeavyRoute(url) {
    try {
        return HEAVY_PATH_RE.test(new URL(url, window.location.origin).pathname);
    } catch {
        return false;
    }
}

function shouldPrefetch(link) {
    if (!(link instanceof HTMLAnchorElement) || !link.href) {
        return false;
    }
    if (link.dataset.turbo === 'false' || link.dataset.turboPrefetch === 'false') {
        return false;
    }
    if (link.hasAttribute('download') || (link.target && link.target !== '_self')) {
        return false;
    }
    if (!sameOrigin(link.href)) {
        return false;
    }
    if (link.classList.contains('sidebar-link-active')) {
        return false;
    }
    if (isHeavyRoute(link.href)) {
        return false;
    }
    return true;
}

function prefetchUrl(url) {
    if (!url || PREFETCHED.has(url)) {
        return;
    }
    PREFETCHED.add(url);

    try {
        if (typeof Turbo.cache?.prefetch === 'function') {
            Turbo.cache.prefetch(url);
            return;
        }
    } catch {
        // fall through to <link rel="prefetch">
    }

    if ([...document.querySelectorAll('link[data-fast-nav-prefetch]')].some((el) => el.href === url)) {
        return;
    }

    const hint = document.createElement('link');
    hint.rel = 'prefetch';
    hint.as = 'document';
    hint.href = url;
    hint.setAttribute('data-fast-nav-prefetch', '1');
    document.head.appendChild(hint);
}

function bindHoverPrefetch(link) {
    if (link.dataset.fastNavBound === '1') {
        return;
    }
    link.dataset.fastNavBound = '1';

    const run = () => {
        if (shouldPrefetch(link)) {
            prefetchUrl(link.href);
        }
    };

    link.addEventListener('mouseenter', run, { passive: true });
    link.addEventListener('focus', run, { passive: true });
    link.addEventListener('touchstart', run, { passive: true });
}

function idlePrefetchSidebar() {
    const links = [...document.querySelectorAll('.sidebar-nav .sidebar-link[href]')]
        .filter(shouldPrefetch)
        .slice(0, MAX_IDLE_PREFETCH);

    links.forEach((link, index) => {
        window.setTimeout(() => prefetchUrl(link.href), 120 * (index + 1));
    });
}

export function initFastNav() {
    document.querySelectorAll('.sidebar-nav .sidebar-link[href], a[data-turbo-preload]').forEach(bindHoverPrefetch);

    const scheduleIdle = () => {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(() => idlePrefetchSidebar(), { timeout: 2500 });
        } else {
            window.setTimeout(idlePrefetchSidebar, 900);
        }
    };

    if (document.readyState === 'complete') {
        scheduleIdle();
    } else {
        window.addEventListener('load', scheduleIdle, { once: true });
    }
}
