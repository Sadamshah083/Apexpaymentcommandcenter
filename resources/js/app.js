import '@hotwired/turbo';
import { initToasts } from './toast.js';
import './system-notifications.js';
import { initTopnav } from './topnav.js';
import { initWorkspaceSync, teardownWorkspaceSync } from './workspace-sync.js';
import { initPushNotifications } from './push-notifications.js';
import { initFormLoading } from './form-loading.js';
import { initMemberManagement } from './member-management.js';

function boot() {
    initToasts();
    initTopnav();
    initWorkspaceSync();
    initPushNotifications();
    initFormLoading();
    initMemberManagement();
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
});
document.addEventListener('turbo:before-cache', teardownWorkspaceSync);
