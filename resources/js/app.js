import '@hotwired/turbo';
import { initTurboAuthGuard } from './turbo-auth.js';
import { initToasts } from './toast.js';
import './system-notifications.js';
import { initTopnav } from './topnav.js';
import { initSidebar } from './sidebar.js';
import { initWorkspaceSync, teardownWorkspaceSync } from './workspace-sync.js';
import { initPushNotifications } from './push-notifications.js';
import { initFormLoading } from './form-loading.js';
import { initMemberManagement } from './member-management.js';
import { initWorkspaceAdmin } from './workspace-admin.js';
import { startProgressPoll } from './realtime-poll.js';
import { initPortalDashboard, teardownPortalDashboard } from './portal-dashboard.js';
import { updateAdminDetailPanel } from './admin-dashboard-detail.js';

window.startProgressPoll = startProgressPoll;
window.updateAdminDetailPanel = updateAdminDetailPanel;

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

function boot() {
    initTurboAuthGuard();
    initPageTransitions();
    initToasts();
    initTopnav();
    initSidebar();
    initWorkspaceSync();
    initPushNotifications();
    initFormLoading();
    initMemberManagement();
    initWorkspaceAdmin();
    initPortalDashboard();
    initHorizontalWheelScroll();
    void bootCommunicationsFeatures();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

document.addEventListener('turbo:load', () => {
    initToasts();
    initTopnav();
    initSidebar();
    initWorkspaceSync();
    initPushNotifications();
    initFormLoading();
    initMemberManagement();
    initWorkspaceAdmin();
    initPortalDashboard();
    initHorizontalWheelScroll();
    void bootCommunicationsFeatures();
});
document.addEventListener('turbo:before-cache', () => {
    teardownWorkspaceSync();
    teardownPortalDashboard();
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
