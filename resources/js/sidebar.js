const STORAGE_KEY = 'app-sidebar-collapsed';
const NAV_SCROLL_KEY = 'sidebar-nav-scroll';
const BOUND_KEY = 'appSidebarBound';

function isMobile() {
    return window.matchMedia('(max-width: 1023px)').matches;
}

function normalizePath(path) {
    const normalized = path.replace(/\/+$/, '');

    return normalized === '' ? '/' : normalized;
}

function isLinkActive(linkPath, currentPath) {
    const link = normalizePath(linkPath);
    const current = normalizePath(currentPath);

    if (link === current) {
        return true;
    }

    return link !== '/' && current.startsWith(`${link}/`);
}

export function syncSidebarActiveLinks() {
    const currentPath = window.location.pathname;

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        let linkPath = link.dataset.navPath || '';

        if (! linkPath && link.href) {
            try {
                linkPath = new URL(link.href, window.location.origin).pathname;
            } catch {
                linkPath = '';
            }
        }

        const active = linkPath ? isLinkActive(linkPath, currentPath) : false;

        link.classList.toggle('sidebar-link-active', active);

        if (active) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });
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
        } else {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Close sidebar');
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
}

function setDesktopCollapsed(collapsed) {
    document.body.classList.toggle('app-sidebar-collapsed', collapsed);
    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    syncToggleButtons({ collapsed, mobileOpen: false });
    updateEdgeIcons(collapsed, false);
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

function bindSidebarEvents() {
    document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) => {
        if (btn.dataset.sidebarToggleBound === '1') {
            return;
        }

        btn.dataset.sidebarToggleBound = '1';
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            toggleSidebar();
        });
    });

    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    if (backdrop && backdrop.dataset.sidebarBackdropBound !== '1') {
        backdrop.dataset.sidebarBackdropBound = '1';
        backdrop.addEventListener('click', () => closeMobileSidebar());
    }

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        if (link.dataset.sidebarLinkBound === '1') {
            return;
        }

        link.dataset.sidebarLinkBound = '1';
        link.addEventListener('click', () => {
            saveSidebarNavScroll();
            closeMobileSidebar();
        });
    });
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
    });
    document.addEventListener('turbo:before-cache', () => {
        if (isMobile()) {
            setMobileOpen(false);
        }
    });

    window.addEventListener('resize', () => {
        applySidebarLayout();
    });
}

export function initSidebar() {
    applySidebarLayout();
    restoreSidebarNavScroll();
    syncSidebarActiveLinks();
    bindSidebarEvents();
    bindGlobalSidebarListeners();

    if (document.documentElement.dataset[BOUND_KEY] === '1') {
        requestAnimationFrame(enableSidebarTransitions);
        return;
    }

    document.documentElement.dataset[BOUND_KEY] = '1';
    requestAnimationFrame(enableSidebarTransitions);
}
