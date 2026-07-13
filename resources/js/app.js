import '@hotwired/turbo';
import { initTurboAuthGuard } from './turbo-auth.js';
import { initToasts } from './toast.js';
import './system-notifications.js';
import { initTopnav } from './topnav.js';
import { initSidebar } from './sidebar.js';
import { initFormLoading } from './form-loading.js';
import { initFastImportNav } from './fast-import-nav.js';
import { startProgressPoll } from './realtime-poll.js';
import { updateAdminDetailPanel } from './admin-dashboard-detail.js';

window.startProgressPoll = startProgressPoll;
window.updateAdminDetailPanel = updateAdminDetailPanel;

let workspaceSyncModule = null;
let portalDashboardModule = null;

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

    if (!needsDialer && !needsWebphone && !needsWorkflow) {
        return;
    }

    const [dialerModule, webphoneModule, workflowModule] = await Promise.all([
        needsDialer ? import('./communications-dialer.js') : Promise.resolve(null),
        needsWebphone ? import('./communications-webphone.js') : Promise.resolve(null),
        needsWorkflow ? import('./comm-hub-workflow.js') : Promise.resolve(null),
    ]);

    if (dialerModule) {
        dialerModule.bootCommunicationsDialer();
    }

    if (webphoneModule) {
        webphoneModule.bootCommunicationsWebphone();
    }

    if (workflowModule) {
        workflowModule.initCommHubWorkflow();
    }
}

async function bootDeferredFeatures() {
    const tasks = [
        import('./push-notifications.js').then(({ initPushNotifications }) => initPushNotifications()),
    ];

    if (document.getElementById('workspace-member-management')) {
        tasks.push(Promise.all([
            import('./member-management.js'),
            import('./workspace-admin.js'),
        ]).then(([memberModule, adminModule]) => {
            memberModule.initMemberManagement();
            adminModule.initWorkspaceAdmin();
        }));
    }

    if (document.getElementById('portal-sync-context') || document.getElementById('portal-dash-widgets')) {
        tasks.push(import('./portal-dashboard.js').then((module) => {
            portalDashboardModule = module;
            module.initPortalDashboard();
        }));
    }

    if (document.querySelector('[data-auto-dial-hub], [data-call-summary-modal]')) {
        tasks.push(import('./communications-auto-dial.js').then((module) => module.initAutoDialHub()));
    }

    if (document.querySelector('[data-workflow-upload]')) {
        tasks.push(import('./workflow-upload.js').then((module) => module.initWorkflowUpload()));
    }

    if (document.querySelector('.js-pretty-select, select[data-pretty-select]')) {
        tasks.push(import('./pretty-select.js').then((module) => module.initPrettySelects()));
    }

    tasks.push(import('./workspace-sync.js').then((module) => {
        workspaceSyncModule = module;
        module.initWorkspaceSync();
    }));

    tasks.push(bootCommunicationsFeatures());

    await Promise.allSettled(tasks);
}

function scheduleDeferredBoot() {
    const run = () => {
        void bootDeferredFeatures();
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(run, { timeout: 1000 });
        return;
    }

    window.setTimeout(run, 1);
}

function bootCore() {
    initTurboAuthGuard();
    initPageTransitions();
    initToasts();
    initTopnav();
    initSidebar();
    initFormLoading();
    initHorizontalWheelScroll();
    initFastImportNav();
    scheduleDeferredBoot();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootCore, { once: true });
} else {
    bootCore();
}

document.addEventListener('turbo:load', () => {
    initToasts();
    initTopnav();
    initSidebar();
    initFormLoading();
    initHorizontalWheelScroll();
    initFastImportNav();
    scheduleDeferredBoot();
});

document.addEventListener('turbo:before-cache', () => {
    workspaceSyncModule?.teardownWorkspaceSync();
    portalDashboardModule?.teardownPortalDashboard();

    import('./communications-webphone.js').then((webphoneModule) => {
        webphoneModule.teardownWebphoneForTurbo?.();
    }).catch(() => {});

    import('./communications-dialer.js').then((dialerModule) => {
        dialerModule.resetDialerButtonsForCache();
    }).catch(() => {});

    import('./communications-phone-notes.js').then((notesModule) => {
        notesModule.teardownPhoneNotesForTurbo?.();
    }).catch(() => {});
});
