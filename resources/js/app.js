import '@hotwired/turbo';
import { initToasts } from './toast.js';
import './system-notifications.js';
import { initTopnav } from './topnav.js';
import { initWorkspaceSync, teardownWorkspaceSync } from './workspace-sync.js';
import { initPushNotifications } from './push-notifications.js';
import { initFormLoading } from './form-loading.js';
import { initMemberManagement } from './member-management.js';
import { initWorkspaceAdmin } from './workspace-admin.js';
import { startProgressPoll } from './realtime-poll.js';
import { initWorkflowUpload } from './workflow-upload.js';
import { initAdminDashboard, teardownAdminDashboard } from './admin-dashboard.js';

window.startProgressPoll = startProgressPoll;

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

function boot() {
    initPageTransitions();
    initToasts();
    initTopnav();
    initWorkspaceSync();
    initPushNotifications();
    initFormLoading();
    initMemberManagement();
    initWorkspaceAdmin();
    initWorkflowUpload();
    initAdminDashboard();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

document.addEventListener('turbo:load', () => {
    initTopnav();
    initWorkspaceSync();
    initPushNotifications();
    initFormLoading();
    initMemberManagement();
    initWorkspaceAdmin();
    initWorkflowUpload();
    initAdminDashboard();
});
document.addEventListener('turbo:before-cache', () => {
    teardownWorkspaceSync();
    teardownAdminDashboard();
});
