const STORAGE_KEY = 'app-sidebar-collapsed';
const NAV_SCROLL_KEY = 'sidebar-nav-scroll';
const BOUND_KEY = 'appSidebarBound';
const MOBILE_QUERY = '(max-width: 1023px)';
const mobileMedia = window.matchMedia(MOBILE_QUERY);
let activeSyncFrame = 0;
let layoutFrame = 0;

function isMobile() {
    return mobileMedia.matches;
}

function normalizePath(path) {
    const normalized = path.replace(/\/+$/, '');

    return normalized === '' ? '/' : normalized;
}

function normalizeQuery(query) {
    if (! query) {
        return '';
    }

    return query.startsWith('?') ? query : `?${query}`;
}

function isPrefixMatch(linkPath, currentPath) {
    const link = normalizePath(linkPath);
    const current = normalizePath(currentPath);

    if (link === current) {
        return true;
    }

    return link !== '/' && current.startsWith(`${link}/`);
}

function parseMatchPrefixes(link) {
    if (Array.isArray(link._navMatchPrefixes)) {
        return link._navMatchPrefixes;
    }

    const raw = link.dataset.navMatchPrefixes;

    if (! raw) {
        link._navMatchPrefixes = [];
        return [];
    }

    try {
        link._navMatchPrefixes = JSON.parse(raw).filter((prefix) => typeof prefix === 'string' && prefix !== '');
    } catch {
        link._navMatchPrefixes = [];
    }

    return link._navMatchPrefixes;
}

function parseExcludePrefixes(link) {
    if (Array.isArray(link._navExcludePrefixes)) {
        return link._navExcludePrefixes;
    }

    const raw = link.dataset.navExcludePrefixes;

    if (! raw) {
        link._navExcludePrefixes = [];
        return [];
    }

    try {
        link._navExcludePrefixes = JSON.parse(raw).filter((prefix) => typeof prefix === 'string' && prefix !== '');
    } catch {
        link._navExcludePrefixes = [];
    }

    return link._navExcludePrefixes;
}

function isLinkActive(link, currentLocation) {
    const linkPath = normalizePath(link.dataset.navPath || '');
    const linkQuery = normalizeQuery(link.dataset.navQuery || '');
    const queryMode = link.dataset.navQueryMode || 'ignore';
    const currentPath = normalizePath(currentLocation.pathname);
    const currentQuery = normalizeQuery(currentLocation.search);

    if (parseExcludePrefixes(link).some((prefix) => isPrefixMatch(prefix, currentPath))) {
        return false;
    }

    if (queryMode === 'exact') {
        if (linkPath === currentPath && linkQuery === currentQuery) {
            return true;
        }
    } else if (queryMode === 'empty') {
        if (linkPath === currentPath && currentQuery === '') {
            return true;
        }
    } else if (isPrefixMatch(linkPath, currentPath)) {
        return true;
    }

    return parseMatchPrefixes(link).some((prefix) => isPrefixMatch(prefix, currentPath));
}

export function syncSidebarActiveLinks() {
    cancelAnimationFrame(activeSyncFrame);
    activeSyncFrame = requestAnimationFrame(() => {
        const currentLocation = window.location;

        document.querySelectorAll('.sidebar-link').forEach((link) => {
            if (! link.dataset.navPath && link.href) {
                try {
                    const target = new URL(link.href, window.location.origin);
                    link.dataset.navPath = target.pathname;
                    link.dataset.navQuery = target.search;
                } catch {
                    link.dataset.navPath = '';
                    link.dataset.navQuery = '';
                }
            }

            const active = link.dataset.navPath ? isLinkActive(link, currentLocation) : false;

            link.classList.toggle('sidebar-link-active', active);

            if (active) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    });
}

function syncSidebarLinkTooltips() {
    const collapsed = ! isMobile() && document.body.classList.contains('app-sidebar-collapsed');

    document.querySelectorAll('.app-sidebar .sidebar-link').forEach((link) => {
        // Never expose raw URLs via native title tooltips.
        link.removeAttribute('title');

        if (! collapsed) {
            return;
        }

        const label = link.querySelector('.sidebar-link-label')?.textContent?.trim();
        if (label) {
            link.setAttribute('title', label);
        }
    });
}

function bindSidebarLinkStatusHide(link) {
    // Keep href intact so Turbo hover-prefetch and clicks stay fast.
    // Only strip native title tooltips that can show raw URLs.
    if (link.dataset.statusHrefBound === '1') {
        return;
    }

    link.dataset.statusHrefBound = '1';
    link.removeAttribute('title');
}

function bindAllSidebarLinkStatusHide() {
    document.querySelectorAll('.app-sidebar .sidebar-link').forEach(bindSidebarLinkStatusHide);
    syncSidebarLinkTooltips();
}

function updateEdgeIcons(collapsed, mobileOpen) {
    document.querySelectorAll('[data-sidebar-edge-icon]').forEach((icon) => {
        icon.textContent = collapsed ? '\u203A' : '\u2039';
    });

    document.querySelectorAll('[data-sidebar-mobile-icon]').forEach((icon) => {
        icon.textContent = mobileOpen ? '\u00D7' : '\u2630';
    });
}

function saveSidebarNavScroll() {
    const nav = document.querySelector('.sidebar-nav');
    if (nav) {
        sessionStorage.setItem(NAV_SCROLL_KEY, String(nav.scrollTop));
    }
}

function restoreSidebarNavScroll() {
    const nav = document.querySelector('.sidebar-nav');
    const saved = sessionStorage.getItem(NAV_SCROLL_KEY);
    if (nav && saved !== null) {
        nav.scrollTop = Number.parseInt(saved, 10) || 0;
    }
}

function syncToggleButtons({ collapsed, mobileOpen }) {
    document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) => {
        if (isMobile()) {
            btn.setAttribute('aria-expanded', mobileOpen ? 'true' : 'false');
            btn.setAttribute('aria-label', mobileOpen ? 'Close sidebar' : 'Open sidebar');
            btn.setAttribute('title', mobileOpen ? 'Close menu' : 'Open menu');
        } else {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Close sidebar');
            btn.setAttribute('title', collapsed ? 'Open sidebar' : 'Close sidebar');
        }
    });
}

function syncBackdrop(mobileOpen) {
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (! backdrop) {
        return;
    }

    const showBackdrop = isMobile() && mobileOpen;
    backdrop.hidden = ! showBackdrop;
    backdrop.setAttribute('aria-hidden', showBackdrop ? 'false' : 'true');
}

function lockPageScroll(lock) {
    if (! lock) {
        document.body.style.paddingRight = '';
        document.documentElement.style.overflow = '';
        return;
    }

    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.overflow = 'hidden';

    if (scrollbarWidth > 0) {
        document.body.style.paddingRight = `${scrollbarWidth}px`;
    }
}

function setMobileOpen(open) {
    document.body.classList.toggle('app-sidebar-mobile-open', open);
    lockPageScroll(open);
    syncBackdrop(open);
    syncToggleButtons({
        collapsed: document.body.classList.contains('app-sidebar-collapsed'),
        mobileOpen: open,
    });
    updateEdgeIcons(document.body.classList.contains('app-sidebar-collapsed'), open);
    syncSidebarLinkTooltips();
}

function setDesktopCollapsed(collapsed) {
    document.body.classList.toggle('app-sidebar-collapsed', collapsed);
    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    syncToggleButtons({ collapsed, mobileOpen: false });
    updateEdgeIcons(collapsed, false);
    syncSidebarLinkTooltips();
}

function applySidebarLayout() {
    if (isMobile()) {
        document.body.classList.remove('app-sidebar-collapsed');
        setMobileOpen(false);
        return;
    }

    document.body.classList.remove('app-sidebar-mobile-open');
    lockPageScroll(false);
    syncBackdrop(false);
    setDesktopCollapsed(localStorage.getItem(STORAGE_KEY) === '1');
}

function toggleSidebar() {
    if (isMobile()) {
        setMobileOpen(! document.body.classList.contains('app-sidebar-mobile-open'));
        return;
    }

    setDesktopCollapsed(! document.body.classList.contains('app-sidebar-collapsed'));
}

function closeMobileSidebar() {
    if (isMobile()) {
        setMobileOpen(false);
    }
}

function enableSidebarTransitions() {
    if (document.documentElement.dataset.sidebarTransitionsReady === '1') {
        return;
    }

    document.documentElement.dataset.sidebarTransitionsReady = '1';
    document.documentElement.classList.add('sidebar-transitions-ready');
}

function handleSidebarToggleClick(event) {
    const toggle = event.target.closest('[data-sidebar-toggle]');
    if (! toggle) {
        return;
    }

    event.preventDefault();
    toggleSidebar();
}

function handleSidebarLinkClick(event) {
    const link = event.target.closest('.sidebar-link');
    if (! link) {
        return;
    }

    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    // Optimistic active state so the switch feels instant while Turbo loads.
    document.querySelectorAll('.app-sidebar .sidebar-link').forEach((other) => {
        other.classList.remove('sidebar-link-active');
        other.removeAttribute('aria-current');
    });
    link.classList.add('sidebar-link-active');
    link.setAttribute('aria-current', 'page');

    saveSidebarNavScroll();
    closeMobileSidebar();
}

function bindSidebarEvents() {
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (backdrop && backdrop.dataset.sidebarBackdropBound !== '1') {
        backdrop.dataset.sidebarBackdropBound = '1';
        backdrop.addEventListener('click', () => closeMobileSidebar());
    }
}

function bindGlobalSidebarListeners() {
    if (document.documentElement.dataset.sidebarGlobalBound === '1') {
        return;
    }

    document.documentElement.dataset.sidebarGlobalBound = '1';

    document.addEventListener('turbo:before-visit', saveSidebarNavScroll);
    document.addEventListener('turbo:load', () => {
        restoreSidebarNavScroll();
        syncSidebarActiveLinks();
        bindAllSidebarLinkStatusHide();
    });
    document.addEventListener('turbo:before-cache', () => {
        if (isMobile()) {
            setMobileOpen(false);
        }
        // Restore hrefs before Turbo caches the page.
        document.querySelectorAll('.app-sidebar .sidebar-link[data-status-href]').forEach((link) => {
            const href = link.dataset.statusHref;
            if (href) {
                link.setAttribute('href', href);
            }
        });
    });
    document.addEventListener('click', handleSidebarToggleClick);
    document.addEventListener('click', handleSidebarLinkClick);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMobileSidebar();
        }
    });

    const scheduleSidebarLayout = () => {
        cancelAnimationFrame(layoutFrame);
        layoutFrame = requestAnimationFrame(() => {
            applySidebarLayout();
        });
    };

    if (typeof mobileMedia.addEventListener === 'function') {
        mobileMedia.addEventListener('change', scheduleSidebarLayout);
    } else if (typeof mobileMedia.addListener === 'function') {
        mobileMedia.addListener(scheduleSidebarLayout);
    }

    window.addEventListener('resize', scheduleSidebarLayout);
}

export function initSidebar() {
    applySidebarLayout();
    restoreSidebarNavScroll();
    syncSidebarActiveLinks();
    bindSidebarEvents();
    bindGlobalSidebarListeners();
    bindAllSidebarLinkStatusHide();

    if (document.documentElement.dataset[BOUND_KEY] === '1') {
        requestAnimationFrame(enableSidebarTransitions);
        return;
    }

    document.documentElement.dataset[BOUND_KEY] = '1';
    requestAnimationFrame(enableSidebarTransitions);
}
