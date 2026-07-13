<?php

use App\Http\Controllers\BusinessResearchController;
use App\Http\Controllers\CommunicationsHubController;
use App\Http\Controllers\ContentAnalyzerController;
use App\Http\Controllers\CrmCampaignController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\EmailListController;
use App\Http\Controllers\ReputationController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\SalesOpsController;
use App\Http\Controllers\WorkspaceAuthController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceSyncController;
use App\Http\Controllers\ServerMonitoringController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\MorpheusHubController;
use Illuminate\Support\Facades\Route;

// Morpheus Event Push — public, no session auth (configure this URL in Morpheus admin)
Route::get('/webhooks/morpheus/calls', [MorpheusHubController::class, 'webhookHealth'])
    ->name('webhooks.morpheus.calls.health');
Route::post('/webhooks/morpheus/calls', [MorpheusHubController::class, 'receiveCallWebhook'])
    ->name('webhooks.morpheus.calls');

// Redirect root to portal login
Route::get('/', function () {
    return redirect()->route('portal.login');
});

// Shared push fallback for service worker (works for admin + portal sessions)
Route::middleware('auth')->get('/push/latest', [PushNotificationController::class, 'latest'])->name('push.latest');

// Admin Auth
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [WorkspaceAuthController::class, 'showAdminLogin'])->name('login');
    Route::post('/login', [WorkspaceAuthController::class, 'adminLogin']);
    Route::get('/register-workspace', [WorkspaceAuthController::class, 'showRegister'])->name('register-workspace');
    Route::post('/register-workspace', [WorkspaceAuthController::class, 'register']);
    Route::post('/logout', [WorkspaceAuthController::class, 'adminLogout'])->name('logout');
});

// Portal Auth
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/login', [WorkspaceAuthController::class, 'showPortalLogin'])->name('login');
    Route::post('/login', [WorkspaceAuthController::class, 'portalLogin']);
    Route::post('/logout', [WorkspaceAuthController::class, 'portalLogout'])->name('logout');
    Route::get('/invite/{token}', [WorkspaceAuthController::class, 'showAcceptInvite'])->name('invite.accept');
    Route::post('/invite/{token}', [WorkspaceAuthController::class, 'acceptInvite'])->name('invite.store');
});

// Protected Admin Routes
Route::prefix('admin')->name('admin.')->middleware([
    \App\Http\Middleware\AdminPortalMiddleware::class,
    \App\Http\Middleware\EnsureAdminModuleAccess::class,
])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/realtime-data', [AdminDashboardController::class, 'realtimeData'])->name('dashboard.realtime-data');

    Route::prefix('sales-ops')->name('sales-ops.')->group(function () {
        Route::get('/', [SalesOpsController::class, 'index'])->name('index');
        Route::get('/performance', [SalesOpsController::class, 'performance'])->name('performance');
        Route::get('/distribution', [SalesOpsController::class, 'distribution'])->name('distribution');
        Route::get('/reactivation', [SalesOpsController::class, 'reactivation'])->name('reactivation');
        Route::post('/leads/{lead}/reactivate', [SalesOpsController::class, 'enrollReactivation'])->name('reactivate');
    });

    Route::post('leads/{lead}/activities', [SalesOpsController::class, 'logActivity'])->name('leads.activities.store');

    Route::resource('lists', EmailListController::class)->except(['edit', 'update']);
    Route::get('lists/{list}/progress', [EmailListController::class, 'progress'])->name('lists.progress');
    Route::get('lists/{list}/export', [EmailListController::class, 'export'])->name('lists.export');
    Route::post('lists/{list}/pause', [EmailListController::class, 'pause'])->name('lists.pause');
    Route::post('lists/{list}/resume', [EmailListController::class, 'resume'])->name('lists.resume');

    Route::get('deliverability', [DeliverabilityController::class, 'index'])->name('deliverability.index');
    Route::post('deliverability', [DeliverabilityController::class, 'store'])->name('deliverability.store');
    Route::get('deliverability/{deliverability}/status', [DeliverabilityController::class, 'status'])->name('deliverability.status');
    Route::get('deliverability/{deliverability}', [DeliverabilityController::class, 'show'])->name('deliverability.show');
    Route::post('deliverability/quick-check', [DeliverabilityController::class, 'quickCheck'])->name('deliverability.quick-check');
    Route::post('deliverability/inbox', [DeliverabilityController::class, 'createInbox'])->name('deliverability.inbox');
    Route::get('deliverability/inbox/{inbox}/status', [DeliverabilityController::class, 'inboxStatus'])->name('deliverability.inbox.status');

    Route::get('content-analyzer', [ContentAnalyzerController::class, 'index'])->name('content.index');
    Route::post('content-analyzer', [ContentAnalyzerController::class, 'analyze'])->name('content.analyze');
    Route::get('content-analyzer/{contentAnalysis}', [ContentAnalyzerController::class, 'show'])->name('content.show');

    Route::get('reputation', [ReputationController::class, 'index'])->name('reputation.index');
    Route::post('reputation/log', [ReputationController::class, 'storeLog'])->name('reputation.log');
    Route::post('reputation/warmup', [ReputationController::class, 'warmupCalculator'])->name('reputation.warmup');
    Route::post('reputation/compliance', [ReputationController::class, 'complianceCheck'])->name('reputation.compliance');

    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('/', [CrmCampaignController::class, 'index'])->name('index');
        Route::get('/create', [CrmCampaignController::class, 'create'])->name('create');
        Route::post('/', [CrmCampaignController::class, 'store'])->name('store');
        Route::get('/{crm}', [CrmCampaignController::class, 'show'])->name('show');
        Route::get('/{crm}/progress', [CrmCampaignController::class, 'progress'])->name('progress');
        Route::post('/{crm}/reupload', [CrmCampaignController::class, 'reupload'])->name('reupload');
        Route::post('/{crm}/retry-failed', [CrmCampaignController::class, 'retryFailed'])->name('retry-failed');
        Route::get('/{crm}/export', [CrmCampaignController::class, 'export'])->name('export');
        Route::delete('/{crm}', [CrmCampaignController::class, 'destroy'])->name('destroy');
        Route::get('/{crm}/leads/{lead}', [CrmCampaignController::class, 'leadShow'])->name('leads.show');
        Route::get('/{crm}/leads/{lead}/status', [CrmCampaignController::class, 'leadStatus'])->name('leads.status');
        Route::post('/{crm}/leads/{lead}/retry', [CrmCampaignController::class, 'retryLead'])->name('leads.retry');
    });

    Route::get('business-research', [BusinessResearchController::class, 'index'])->name('business-research.index');
    Route::post('business-research', [BusinessResearchController::class, 'store'])->name('business-research.store');
    Route::get('business-research/{businessResearch}', [BusinessResearchController::class, 'show'])->name('business-research.show');
    Route::get('business-research/{businessResearch}/status', [BusinessResearchController::class, 'status'])->name('business-research.status');
    Route::post('business-research/{businessResearch}/retry', [BusinessResearchController::class, 'retry'])->name('business-research.retry');
    Route::delete('business-research/{businessResearch}', [BusinessResearchController::class, 'destroy'])->name('business-research.destroy');

    Route::prefix('workflows')->name('workflows.')->group(function () {
        Route::get('/', [WorkflowController::class, 'index'])->name('index');
        Route::get('/create', [WorkflowController::class, 'create'])->name('create');
        Route::post('/', [WorkflowController::class, 'store'])->name('store');
        Route::get('/{workflow}', [WorkflowController::class, 'show'])->name('show');
        Route::post('/{workflow}/map', [WorkflowController::class, 'map'])->name('map');
        Route::post('/{workflow}/run', [WorkflowController::class, 'run'])->name('run');
        Route::post('/{workflow}/approve-leads', [WorkflowController::class, 'bulkApproveLeads'])->name('approve-leads');
        Route::post('/{workflow}/activate', [WorkflowController::class, 'activate'])->name('activate');
        Route::post('/{workflow}/pause', [WorkflowController::class, 'pause'])->name('pause');
        Route::post('/{workflow}/resume', [WorkflowController::class, 'resume'])->name('resume');
        Route::post('/{workflow}/retry-failed', [WorkflowController::class, 'retryFailed'])->name('retry-failed');
        Route::post('/{workflow}/enrich', [WorkflowController::class, 'enrich'])->name('enrich');
        Route::post('/{workflow}/distribute', [WorkflowController::class, 'distribute'])->name('distribute');
        Route::post('/{workflow}/assign-leads', [WorkflowController::class, 'assignLeads'])->name('assign-leads');
        Route::delete('/{workflow}', [WorkflowController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('campaigns')->name('campaigns.')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::get('/{campaign}', [CampaignController::class, 'show'])->name('show');
        Route::post('/{campaign}/enrich', [CampaignController::class, 'enrich'])->name('enrich');
        Route::post('/{campaign}/distribute', [CampaignController::class, 'distribute'])->name('distribute');
        Route::post('/{campaign}/assign-team-lead', [CampaignController::class, 'assignTeamLead'])->name('assign-team-lead');
    });

    Route::post('leads/{lead}/approve', [WorkflowController::class, 'approveLead'])->name('leads.approve');
    Route::post('leads/{lead}/reject', [WorkflowController::class, 'rejectLead'])->name('leads.reject');
    Route::get('leads/{lead}', [WorkflowController::class, 'leadShow'])->name('leads.show');
    Route::post('leads/{lead}', [WorkflowController::class, 'leadUpdate'])->name('leads.update');
    Route::delete('leads/{lead}', [WorkflowController::class, 'leadDestroy'])->name('leads.destroy');
    Route::post('leads/{lead}/verify-email', [WorkflowController::class, 'verifyLeadEmail'])->name('leads.verify-email');
    Route::post('leads/{lead}/analyze-email', [WorkflowController::class, 'analyzeLeadEmail'])->name('leads.analyze-email');
    Route::post('leads/{lead}/check-domain', [WorkflowController::class, 'checkLeadDomain'])->name('leads.check-domain');
    Route::post('leads/{lead}/setter-status', [PipelineController::class, 'updateSetterStatus'])->name('leads.setter-status');
    Route::post('leads/{lead}/closer-status', [PipelineController::class, 'updateCloserStatus'])->name('leads.closer-status');

    Route::prefix('workspaces')->name('workspaces.')->group(function () {
        Route::get('/', [WorkflowController::class, 'workspaceIndex'])->name('index');
        Route::post('/', [WorkflowController::class, 'workspaceStore'])->name('store');
        Route::post('/switch/{workspace}', [WorkflowController::class, 'workspaceSwitch'])->name('switch');
    });

    Route::prefix('workspaces')->name('workspaces.')->middleware([\App\Http\Middleware\EnsureSuperAdmin::class])->group(function () {
        Route::post('/{workspace}/members', [WorkspaceMemberController::class, 'store'])->name('members.store');
        Route::patch('/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])->name('members.update');
        Route::patch('/{workspace}/members/{member}/role', [WorkspaceMemberController::class, 'updateRole'])->name('members.role');
        Route::post('/{workspace}/members/{member}/reset-password', [WorkspaceMemberController::class, 'resetPassword'])->name('members.reset-password');
        Route::post('/{workspace}/members/{member}/suspend', [WorkspaceMemberController::class, 'suspend'])->name('members.suspend');
        Route::post('/{workspace}/members/{member}/reactivate', [WorkspaceMemberController::class, 'reactivate'])->name('members.reactivate');
        Route::delete('/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->name('members.destroy');
    });

    Route::patch('/workspaces/{workspace}/members/{member}/modules', [WorkspaceMemberController::class, 'updateModules'])
        ->middleware([\App\Http\Middleware\EnsureCanAssignModulePermissions::class])
        ->name('workspaces.members.modules');

    Route::get('push/vapid-public-key', [PushNotificationController::class, 'vapidPublicKey'])->name('push.vapid');
    Route::post('push/subscribe', [PushNotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::get('push/latest', [PushNotificationController::class, 'latest'])->name('push.latest');
    Route::post('push/test', [PushNotificationController::class, 'sendTestNotification'])->name('push.test');

    Route::get('sync', [WorkspaceSyncController::class, 'poll'])->name('sync.poll');
    Route::get('sync/stream', [WorkspaceSyncController::class, 'stream'])->name('sync.stream');
    Route::get('server-monitoring', [ServerMonitoringController::class, 'index'])->name('server.monitoring');

    Route::prefix('communications')->name('communications.')->group(function () {
        $registerMorpheusHub = require __DIR__.'/morpheus-communications.php';

        Route::get('/', [CommunicationsHubController::class, 'index'])->name('index');
        Route::get('/dialer/call-logs', [CommunicationsHubController::class, 'dialerCallLogs'])->name('dialer.call-logs');
        Route::get('/dialer/notes', [CommunicationsHubController::class, 'dialerPhoneNoteShow'])->name('dialer.notes.show');
        Route::put('/dialer/notes/phone', [CommunicationsHubController::class, 'dialerPhoneNoteSave'])->name('dialer.notes.phone.save');
        Route::put('/dialer/notes/call', [CommunicationsHubController::class, 'dialerCallNoteSave'])->name('dialer.notes.call.save');
        Route::post('/dialer/call-logs/recording/sync', [CommunicationsHubController::class, 'dialerSyncCallRecording'])->name('dialer.recording.sync');
        Route::get('/dialer/imported-leads', [CommunicationsHubController::class, 'dialerImportedLeads'])->name('dialer.imported-leads');
        Route::post('/dialer/disposition', [CommunicationsHubController::class, 'dialerDispositionSave'])->name('dialer.disposition');
        Route::get('/contacts/{contactKey}', [CommunicationsHubController::class, 'showContact'])->name('contacts.show')->where('contactKey', '.*');
        Route::get('/zoom/export/logs', [CommunicationsHubController::class, 'exportLogs'])->name('zoom.export.logs');
        Route::get('/zoom/recordings/{recordingId}/media', [CommunicationsHubController::class, 'recordingMedia'])->name('zoom.recordings.media');
        Route::get('/zoom/voicemails/{fileId}/media', [CommunicationsHubController::class, 'voicemailMedia'])->name('zoom.voicemails.media');
        Route::post('/zoom/refresh', [CommunicationsHubController::class, 'refreshCache'])
            ->middleware('communications.admin')
            ->name('zoom.refresh');
        Route::post('/zoom/sms/send', [CommunicationsHubController::class, 'sendSms'])->name('zoom.sms.send');
        Route::post('/zoom/chat/send', [CommunicationsHubController::class, 'sendChat'])->name('zoom.chat.send');
        $registerMorpheusHub();
    });
});

// Protected Portal Routes
Route::prefix('portal')->name('portal.')->middleware([\App\Http\Middleware\MarketerPortalMiddleware::class])->group(function () {
    Route::get('/dashboard', [PipelineController::class, 'portalDashboard'])->name('dashboard');
    Route::get('/dashboard/metrics', [PipelineController::class, 'portalMetrics'])->name('dashboard.metrics');
    Route::get('/dashboard/live', [PipelineController::class, 'portalLive'])->name('dashboard.live');

    Route::get('/setter', [PipelineController::class, 'setterDashboard'])->name('setter.dashboard');
    Route::get('/setter-team', [PipelineController::class, 'setterTeamDashboard'])->name('setter-team.dashboard');
    Route::post('/setter-team/assign-leads', [PipelineController::class, 'assignSetterLeads'])->name('setter-team.assign-leads');
    Route::get('/closer-team', [PipelineController::class, 'closerTeamDashboard'])->name('closer-team.dashboard');
    Route::get('/closer-team/queue', [PipelineController::class, 'closerTeamQueue'])->name('closer-team.queue');
    Route::get('/closer', [PipelineController::class, 'closerDashboard'])->name('closer.dashboard');
    Route::post('/leads/{lead}/assign-closer', [PipelineController::class, 'assignCloser'])->name('leads.assign-closer');
    Route::post('/leads/{lead}/setter-status', [PipelineController::class, 'updateSetterStatus'])->name('leads.setter-status');
    Route::post('/leads/{lead}/closer-status', [PipelineController::class, 'updateCloserStatus'])->name('leads.closer-status');

    Route::get('/performance', [SalesOpsController::class, 'sdrPerformance'])->name('performance');
    Route::get('/pipeline', [SalesOpsController::class, 'aePipeline'])->name('pipeline');

    Route::post('/workspaces/switch/{workspace}', [WorkflowController::class, 'workspaceSwitch'])->name('workspaces.switch');

    Route::get('sync', [WorkspaceSyncController::class, 'poll'])->name('sync.poll');
    Route::get('sync/stream', [WorkspaceSyncController::class, 'stream'])->name('sync.stream');

    Route::get('/leads/{lead}', [WorkflowController::class, 'leadShow'])->name('leads.show');
    Route::post('/leads/{lead}', [WorkflowController::class, 'leadUpdate'])->name('leads.update');
    Route::delete('/leads/{lead}', [WorkflowController::class, 'leadDestroy'])->name('leads.destroy');
    Route::post('/leads/{lead}/verify-email', [WorkflowController::class, 'verifyLeadEmail'])->name('leads.verify-email');
    Route::post('/leads/{lead}/analyze-email', [WorkflowController::class, 'analyzeLeadEmail'])->name('leads.analyze-email');
    Route::post('/leads/{lead}/check-domain', [WorkflowController::class, 'checkLeadDomain'])->name('leads.check-domain');

    Route::get('push/vapid-public-key', [PushNotificationController::class, 'vapidPublicKey'])->name('push.vapid');
    Route::post('push/subscribe', [PushNotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::get('push/latest', [PushNotificationController::class, 'latest'])->name('push.latest');
    Route::post('push/test', [PushNotificationController::class, 'sendTestNotification'])->name('push.test');

    // Portal Validator Toolkit
    Route::resource('lists', EmailListController::class)->except(['edit', 'update']);
    Route::get('lists/{list}/progress', [EmailListController::class, 'progress'])->name('lists.progress');
    Route::get('lists/{list}/export', [EmailListController::class, 'export'])->name('lists.export');
    Route::post('lists/{list}/pause', [EmailListController::class, 'pause'])->name('lists.pause');
    Route::post('lists/{list}/resume', [EmailListController::class, 'resume'])->name('lists.resume');

    Route::get('deliverability', [DeliverabilityController::class, 'index'])->name('deliverability.index');
    Route::post('deliverability', [DeliverabilityController::class, 'store'])->name('deliverability.store');
    Route::get('deliverability/{deliverability}/status', [DeliverabilityController::class, 'status'])->name('deliverability.status');
    Route::get('deliverability/{deliverability}', [DeliverabilityController::class, 'show'])->name('deliverability.show');
    Route::post('deliverability/quick-check', [DeliverabilityController::class, 'quickCheck'])->name('deliverability.quick-check');
    Route::post('deliverability/inbox', [DeliverabilityController::class, 'createInbox'])->name('deliverability.inbox');
    Route::get('deliverability/inbox/{inbox}/status', [DeliverabilityController::class, 'inboxStatus'])->name('deliverability.inbox.status');

    Route::get('content-analyzer', [ContentAnalyzerController::class, 'index'])->name('content.index');
    Route::post('content-analyzer', [ContentAnalyzerController::class, 'analyze'])->name('content.analyze');
    Route::get('content-analyzer/{contentAnalysis}', [ContentAnalyzerController::class, 'show'])->name('content.show');

    Route::get('reputation', [ReputationController::class, 'index'])->name('reputation.index');
    Route::post('reputation/log', [ReputationController::class, 'storeLog'])->name('reputation.log');
    Route::post('reputation/warmup', [ReputationController::class, 'warmupCalculator'])->name('reputation.warmup');
    Route::post('reputation/compliance', [ReputationController::class, 'complianceCheck'])->name('reputation.compliance');

    Route::prefix('communications')->name('communications.')->group(function () {
        $registerMorpheusHub = require __DIR__.'/morpheus-communications.php';

        Route::get('/', [CommunicationsHubController::class, 'index'])->name('index');
        Route::get('/dialer/call-logs', [CommunicationsHubController::class, 'dialerCallLogs'])->name('dialer.call-logs');
        Route::get('/dialer/notes', [CommunicationsHubController::class, 'dialerPhoneNoteShow'])->name('dialer.notes.show');
        Route::put('/dialer/notes/phone', [CommunicationsHubController::class, 'dialerPhoneNoteSave'])->name('dialer.notes.phone.save');
        Route::put('/dialer/notes/call', [CommunicationsHubController::class, 'dialerCallNoteSave'])->name('dialer.notes.call.save');
        Route::post('/dialer/call-logs/recording/sync', [CommunicationsHubController::class, 'dialerSyncCallRecording'])->name('dialer.recording.sync');
        Route::get('/dialer/imported-leads', [CommunicationsHubController::class, 'dialerImportedLeads'])->name('dialer.imported-leads');
        Route::post('/dialer/disposition', [CommunicationsHubController::class, 'dialerDispositionSave'])->name('dialer.disposition');
        Route::get('/contacts/{contactKey}', [CommunicationsHubController::class, 'showContact'])->name('contacts.show')->where('contactKey', '.*');
        Route::get('/zoom/export/logs', [CommunicationsHubController::class, 'exportLogs'])->name('zoom.export.logs');
        Route::get('/zoom/recordings/{recordingId}/media', [CommunicationsHubController::class, 'recordingMedia'])->name('zoom.recordings.media');
        Route::get('/zoom/voicemails/{fileId}/media', [CommunicationsHubController::class, 'voicemailMedia'])->name('zoom.voicemails.media');
        Route::post('/zoom/refresh', [CommunicationsHubController::class, 'refreshCache'])
            ->middleware('communications.admin')
            ->name('zoom.refresh');
        Route::post('/zoom/sms/send', [CommunicationsHubController::class, 'sendSms'])->name('zoom.sms.send');
        Route::post('/zoom/chat/send', [CommunicationsHubController::class, 'sendChat'])->name('zoom.chat.send');
        $registerMorpheusHub();
    });
});
