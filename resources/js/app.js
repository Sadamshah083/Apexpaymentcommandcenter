import * as Turbo from '@hotwired/turbo';
import { initTurboAuthGuard } from './turbo-auth.js';
import { initToasts } from './toast.js';
import { initTopnav } from './topnav.js';
import { initSidebar } from './sidebar.js';
import { initFormLoading } from './form-loading.js';
import { initFastImportNav } from './fast-import-nav.js';
import { initFastNav } from './fast-nav.js';
import { initPaginationPreserve } from './pagination-preserve.js';
import { startProgressPoll } from './realtime-poll.js';
import { updateAdminDetailPanel } from './admin-dashboard-detail.js';
import { initThemeToggle, applyTheme, getStoredTheme } from './theme.js';

window.startProgressPoll = startProgressPoll;
window.updateAdminDetailPanel = updateAdminDetailPanel;

// Delay the top loader slightly so fast sidebar clicks do not flash it.
// Still shows on slow/blocked visits; hide is handled by Turbo when the visit ends.
if (Turbo.config?.drive) {
    Turbo.config.drive.progressBarDelay = 120;
} else if (typeof Turbo.session?.drive?.progressBar?.setDelay === 'function') {
    Turbo.session.drive.progressBar.setDelay(120);
}

let workspaceSyncModule = null;
let portalDashboardModule = null;
let dialerModuleRef = null;
let webphoneModuleRef = null;
let phoneNotesModuleRef = null;
let callMonitoringModuleRef = null;

function initPageTransitions() {
    if (document.documentElement.dataset.pageTransitionsInit === '1') {
        return;
    }
    document.documentElement.dataset.pageTransitionsInit = '1';

    document.addEventListener('turbo:before-render', (event) => {
        event.detail.newBody.classList.add('turbo-page-enter');
    });

    document.addEventListener('turbo:render', () => {
        document.body.classList.remove('turbo-page-enter');
    });
}

function initHorizontalWheelScroll() {
    const selectors = ['.ghl-inbox-nav', '.ghl-inbox-toolbar-channels'];
    const strips = document.querySelectorAll(selectors.join(','));

    strips.forEach((strip) => {
        if (!(strip instanceof HTMLElement) || strip.dataset.wheelScrollBound === '1') {
            return;
        }

        strip.dataset.wheelScrollBound = '1';
        strip.addEventListener('wheel', (event) => {
            if (!strip.matches(':hover')) {
                return;
            }

            const delta = Math.abs(event.deltaY) > Math.abs(event.deltaX) ? event.deltaY : event.deltaX;
            if (!delta) {
                return;
            }

            const maxScrollLeft = strip.scrollWidth - strip.clientWidth;
            if (maxScrollLeft <= 0) {
                return;
            }

            const nextLeft = strip.scrollLeft + delta;
            const canScrollFurther = (delta < 0 && strip.scrollLeft > 0)
                || (delta > 0 && strip.scrollLeft < maxScrollLeft);
            if (!canScrollFurther) {
                return;
            }

            event.preventDefault();
            strip.scrollLeft = nextLeft;
        }, { passive: false });
    });
}

async function bootCommunicationsFeatures() {
    const needsDialer = Boolean(document.querySelector('.ghl-dialer-originate-form'));
    const needsWebphone = Boolean(document.querySelector('[data-webphone-panel]'));
    const needsWorkflow = Boolean(document.querySelector('[data-comm-workflow]'));
    const needsCallSummary = Boolean(document.querySelector('[data-call-summary-modal], [data-auto-dial-hub], [data-phone-workspace]'));

    if (!needsDialer && !needsWebphone && !needsWorkflow && !needsCallSummary) {
        return;
    }

    const [dialerModule, webphoneModule, workflowModule] = await Promise.all([
        needsDialer ? import('./communications-dialer.js') : Promise.resolve(null),
        needsWebphone ? import('./communications-webphone.js') : Promise.resolve(null),
        needsWorkflow ? import('./comm-hub-workflow.js') : Promise.resolve(null),
    ]);

    if (dialerModule) {
        dialerModuleRef = dialerModule;
        dialerModule.bootCommunicationsDialer();
    }

    if (webphoneModule) {
        webphoneModuleRef = webphoneModule;
        webphoneModule.bootCommunicationsWebphone();
    }

    if (workflowModule) {
        workflowModule.initCommHubWorkflow();
    }

    // Load disposition modal with the dialer — not after idle delay — so hangup always opens it.
    // bootDeferredFeatures also calls initAutoDialHub; module guards prevent double listeners.
    if (needsCallSummary) {
        void import('./communications-auto-dial.js').then((module) => {
            module.initAutoDialHub();
        }).catch(() => {});
    }

    if (needsDialer || needsWebphone) {
        void import('./communications-phone-notes.js').then((notesModule) => {
            phoneNotesModuleRef = notesModule;
            notesModule.initCommunicationsPhoneNotes?.();
        }).catch(() => {});
    }
}

async function bootDeferredFeatures() {
    const tasks = [];

    // Push only if permission already granted — never block first paint probing permission.
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        tasks.push(import('./push-notifications.js').then(({ initPushNotifications }) => initPushNotifications()));
    }

    if (document.getElementById('workspace-member-management')) {
        tasks.push(Promise.all([
            import('./member-management.js'),
            import('./workspace-admin.js'),
            import('./pretty-select.js'),
        ]).then(([memberModule, adminModule, prettyModule]) => {
            memberModule.initMemberManagement();
            adminModule.initWorkspaceAdmin();
            prettyModule.initPrettySelects();
        }));
    }

    if (document.getElementById('portal-sync-context') || document.getElementById('portal-dash-widgets')) {
        tasks.push(import('./portal-dashboard.js').then((module) => {
            portalDashboardModule = module;
            module.initPortalDashboard();
        }));
    }

    if (document.querySelector('[data-auto-dial-hub], [data-call-summary-modal], [data-phone-workspace]')) {
        // Safe to call again — module-level guards prevent double disposition listeners.
        tasks.push(import('./communications-auto-dial.js').then((module) => module.initAutoDialHub()));
    } else if (document.querySelector('[data-presence-url]')) {
        // Non-dialer portal pages: tiny presence module only (never pull sip.js / dialer).
        tasks.push(import('./agent-presence.js').then((module) => module.initAgentPresenceLite()));
    }

    // OS notifications helpers — defer; not needed for first paint.
    tasks.push(import('./system-notifications.js').catch(() => null));

    if (document.querySelector('[data-workflow-upload]')) {
        tasks.push(import('./workflow-upload.js').then((module) => module.initWorkflowUpload()));
    }

    if (document.querySelector('.js-pretty-select, select[data-pretty-select], select.app-input, select.um-select, select.um-input')) {
        tasks.push(import('./pretty-select.js').then((module) => {
            module.initPrettySelects();
            window.initPrettySelects = module.initPrettySelects;
            window.refreshPrettySelect = module.refreshPrettySelect;
        }));
    }

    if (document.querySelector('input[type="date"].js-pretty-date, input[type="date"][data-pretty-date]')) {
        tasks.push(import('./pretty-date.js').then((module) => {
            module.initPrettyDates();
            window.initPrettyDates = module.initPrettyDates;
        }));
    }

    const syncScope = document.body?.dataset?.workspaceSyncScope || '';
    const syncUrl = document.body?.dataset?.workspaceSyncUrl || '';
    if (syncUrl && syncScope && syncScope !== 'off') {
        tasks.push(import('./workspace-sync.js').then((module) => {
            workspaceSyncModule = module;
            module.initWorkspaceSync();
        }));
    }

    tasks.push(bootCommunicationsFeatures());

    // Dialer pages: no /live polling — SIP WebSocket + call-events own realtime.
    // Call Monitoring wallboard still boots when the board is present.
    const onDialerPage = Boolean(document.querySelector('[data-phone-workspace], [data-auto-dial-hub]'));
    const hasMonitoringBoard = Boolean(document.querySelector('[data-call-monitoring]'));
    if (hasMonitoringBoard || (!onDialerPage && document.querySelector('[data-call-monitoring-nav]'))) {
        tasks.push(import('./call-monitoring.js').then((module) => {
            callMonitoringModuleRef = module;
            module.initCallMonitoring();
        }));
    }

    await Promise.allSettled(tasks);
}

let deferredBootGeneration = 0;

function scheduleDeferredBoot() {
    // DOMContentLoaded and turbo:load both fire on first navigation — only the
    // latest generation should boot, otherwise call-monitoring aborts /live mid-flight.
    const generation = ++deferredBootGeneration;
    const run = () => {
        if (generation !== deferredBootGeneration) {
            return;
        }
        void bootDeferredFeatures();
    };

    const onDialer = Boolean(document.querySelector('[data-phone-workspace], [data-auto-dial-hub], .ghl-dialer-originate-form'));
    // Dialer: boot ASAP after paint. Other pages keep a longer idle window.
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(run, { timeout: onDialer ? 800 : 2500 });
        return;
    }

    window.setTimeout(run, onDialer ? 120 : 300);
}

function tearDownBlockingRuntimes() {
    // Stop sync/SSE/monitoring immediately so the next Turbo visit is never blocked.
    document.dispatchEvent(new CustomEvent('workspace:teardown-request'));
    workspaceSyncModule?.teardownWorkspaceSync?.();
    callMonitoringModuleRef?.teardownCallMonitoring?.();
}

function bootCore() {
    initTurboAuthGuard();
    initPageTransitions();
    applyTheme(getStoredTheme());
    initThemeToggle();
    initToasts();
    initTopnav();
    initSidebar();
    initFormLoading();
    initHorizontalWheelScroll();
    initFastImportNav();
    initFastNav();
    initPaginationPreserve();
    scheduleDeferredBoot();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootCore, { once: true });
} else {
    bootCore();
}

document.addEventListener('turbo:load', () => {
    applyTheme(getStoredTheme());
    initThemeToggle();
    initToasts();
    initTopnav();
    initSidebar();
    initFormLoading();
    initHorizontalWheelScroll();
    initFastImportNav();
    initFastNav();
    scheduleDeferredBoot();
});

document.addEventListener('workspace:teardown-request', () => {
    workspaceSyncModule?.teardownWorkspaceSync?.();
    callMonitoringModuleRef?.teardownCallMonitoring?.();
});

document.addEventListener('turbo:before-visit', () => {
    tearDownBlockingRuntimes();
});

document.addEventListener('turbo:submit-start', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const method = (form.getAttribute('method') || 'get').toLowerCase();
    const override = (form.querySelector('input[name="_method"]')?.value || '').toLowerCase();
    const isDestructive = method === 'post' && ['delete', 'put', 'patch'].includes(override);
    const isDeleteImport = form.id === 'import-delete-form';

    // Import delete (and other mutating Turbo forms) do not fire turbo:before-visit.
    // Tear down streams first so Network does not keep failing SSE/live after the resource is gone.
    if (isDeleteImport || isDestructive) {
        workspaceSyncModule?.teardownWorkspaceSync?.();
        callMonitoringModuleRef?.teardownCallMonitoring?.();
    }
});

window.addEventListener('pagehide', () => {
    workspaceSyncModule?.teardownWorkspaceSync?.();
    callMonitoringModuleRef?.teardownCallMonitoring?.();
});

document.addEventListener('turbo:before-cache', () => {
    workspaceSyncModule?.teardownWorkspaceSync();
    portalDashboardModule?.teardownPortalDashboard();

    webphoneModuleRef?.teardownWebphoneForTurbo?.();
    dialerModuleRef?.resetDialerButtonsForCache?.();
    phoneNotesModuleRef?.teardownPhoneNotesForTurbo?.();
    callMonitoringModuleRef?.teardownCallMonitoring?.();
});
