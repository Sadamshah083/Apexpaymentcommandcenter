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

async function bootCommunicationsFeatures() {
    const needsDialer = Boolean(document.querySelector('.ghl-dialer-originate-form'));
    const needsWebphone = Boolean(document.querySelector('[data-webphone-panel]'));

    if (!needsDialer && !needsWebphone) {
        return;
    }

    if (needsDialer) {
        const dialerModule = await import('./communications-dialer.js');
        dialerModule.bootCommunicationsDialer();
    }

    if (needsWebphone) {
        const webphoneModule = await import('./communications-webphone.js');
        webphoneModule.bootCommunicationsWebphone();
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
    void bootCommunicationsFeatures();
});
document.addEventListener('turbo:before-cache', () => {
    teardownWorkspaceSync();
    teardownPortalDashboard();
    import('./communications-dialer.js').then((dialerModule) => {
        dialerModule.resetDialerButtonsForCache();
    }).catch(() => {});
});
