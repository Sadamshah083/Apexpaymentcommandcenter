<?php

use App\Http\Controllers\BusinessResearchController;
use App\Http\Controllers\CommunicationsHubController;
use App\Http\Controllers\ContentAnalyzerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\EmailListController;
use App\Http\Controllers\ReputationController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\WorkspaceAuthController;
use App\Http\Controllers\WorkspaceMemberController;
use App\Http\Controllers\WorkspaceSyncController;
use Illuminate\Support\Facades\Route;

// Redirect root to portal login
Route::get('/', function () {
    return redirect()->route('portal.login');
});

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
Route::prefix('admin')->name('admin.')->middleware([\App\Http\Middleware\AdminPortalMiddleware::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('lists', EmailListController::class)->except(['edit', 'update']);
    Route::get('lists/{list}/progress', [EmailListController::class, 'progress'])->name('lists.progress');
    Route::get('lists/{list}/export', [EmailListController::class, 'export'])->name('lists.export');
    Route::post('lists/{list}/pause', [EmailListController::class, 'pause'])->name('lists.pause');
    Route::post('lists/{list}/resume', [EmailListController::class, 'resume'])->name('lists.resume');

    Route::get('deliverability', [DeliverabilityController::class, 'index'])->name('deliverability.index');
    Route::post('deliverability', [DeliverabilityController::class, 'store'])->name('deliverability.store');
    Route::get('deliverability/{deliverability}', [DeliverabilityController::class, 'show'])->name('deliverability.show');
    Route::post('deliverability/quick-check', [DeliverabilityController::class, 'quickCheck'])->name('deliverability.quick-check');
    Route::post('deliverability/inbox', [DeliverabilityController::class, 'createInbox'])->name('deliverability.inbox');

    Route::get('content-analyzer', [ContentAnalyzerController::class, 'index'])->name('content.index');
    Route::post('content-analyzer', [ContentAnalyzerController::class, 'analyze'])->name('content.analyze');
    Route::get('content-analyzer/{contentAnalysis}', [ContentAnalyzerController::class, 'show'])->name('content.show');

    Route::get('reputation', [ReputationController::class, 'index'])->name('reputation.index');
    Route::post('reputation/log', [ReputationController::class, 'storeLog'])->name('reputation.log');
    Route::post('reputation/warmup', [ReputationController::class, 'warmupCalculator'])->name('reputation.warmup');

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
        Route::post('/{workflow}/pause', [WorkflowController::class, 'pause'])->name('pause');
        Route::post('/{workflow}/resume', [WorkflowController::class, 'resume'])->name('resume');
        Route::delete('/{workflow}', [WorkflowController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('workspaces')->name('workspaces.')->group(function () {
        Route::get('/', [WorkflowController::class, 'workspaceIndex'])->name('index');
        Route::post('/', [WorkflowController::class, 'workspaceStore'])->name('store');
        Route::post('/switch/{workspace}', [WorkflowController::class, 'workspaceSwitch'])->name('switch');
        Route::post('/{workspace}/members', [WorkspaceMemberController::class, 'store'])->name('members.store');
        Route::patch('/{workspace}/members/{member}/role', [WorkspaceMemberController::class, 'updateRole'])->name('members.role');
        Route::post('/{workspace}/members/{member}/suspend', [WorkspaceMemberController::class, 'suspend'])->name('members.suspend');
        Route::post('/{workspace}/members/{member}/reactivate', [WorkspaceMemberController::class, 'reactivate'])->name('members.reactivate');
        Route::delete('/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->name('members.destroy');
    });

    Route::get('push/vapid-public-key', [PushNotificationController::class, 'vapidPublicKey'])->name('push.vapid');
    Route::post('push/subscribe', [PushNotificationController::class, 'subscribe'])->name('push.subscribe');
    Route::get('push/latest', [PushNotificationController::class, 'latest'])->name('push.latest');
    Route::post('push/test', [PushNotificationController::class, 'sendTestNotification'])->name('push.test');

    Route::get('sync', [WorkspaceSyncController::class, 'poll'])->name('sync.poll');

    Route::prefix('communications')->name('communications.')->group(function () {
        Route::get('/', [CommunicationsHubController::class, 'index'])->name('index');
        Route::get('/contacts/{contactKey}', [CommunicationsHubController::class, 'showContact'])->name('contacts.show')->where('contactKey', '.*');
        Route::get('/zoom/export/logs', [CommunicationsHubController::class, 'exportLogs'])->name('zoom.export.logs');
        Route::get('/zoom/recordings/{recordingId}/media', [CommunicationsHubController::class, 'recordingMedia'])->name('zoom.recordings.media');
        Route::get('/zoom/voicemails/{fileId}/media', [CommunicationsHubController::class, 'voicemailMedia'])->name('zoom.voicemails.media');
        Route::post('/zoom/refresh', [CommunicationsHubController::class, 'refreshCache'])->name('zoom.refresh');
        Route::post('/zoom/sms/send', [CommunicationsHubController::class, 'sendSms'])->name('zoom.sms.send');
    });
});

// Protected Portal Routes
Route::prefix('portal')->name('portal.')->middleware([\App\Http\Middleware\MarketerPortalMiddleware::class])->group(function () {
    Route::get('/dashboard', [WorkflowController::class, 'index'])->name('dashboard');
    Route::post('/workspaces/switch/{workspace}', [WorkflowController::class, 'workspaceSwitch'])->name('workspaces.switch');

    Route::get('sync', [WorkspaceSyncController::class, 'poll'])->name('sync.poll');

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
    Route::get('deliverability/{deliverability}', [DeliverabilityController::class, 'show'])->name('deliverability.show');
    Route::post('deliverability/quick-check', [DeliverabilityController::class, 'quickCheck'])->name('deliverability.quick-check');
    Route::post('deliverability/inbox', [DeliverabilityController::class, 'createInbox'])->name('deliverability.inbox');

    Route::get('content-analyzer', [ContentAnalyzerController::class, 'index'])->name('content.index');
    Route::post('content-analyzer', [ContentAnalyzerController::class, 'analyze'])->name('content.analyze');
    Route::get('content-analyzer/{contentAnalysis}', [ContentAnalyzerController::class, 'show'])->name('content.show');

    Route::get('reputation', [ReputationController::class, 'index'])->name('reputation.index');
    Route::post('reputation/log', [ReputationController::class, 'storeLog'])->name('reputation.log');
    Route::post('reputation/warmup', [ReputationController::class, 'warmupCalculator'])->name('reputation.warmup');

    Route::prefix('communications')->name('communications.')->group(function () {
        Route::get('/', [CommunicationsHubController::class, 'index'])->name('index');
        Route::get('/contacts/{contactKey}', [CommunicationsHubController::class, 'showContact'])->name('contacts.show')->where('contactKey', '.*');
        Route::get('/zoom/export/logs', [CommunicationsHubController::class, 'exportLogs'])->name('zoom.export.logs');
        Route::get('/zoom/recordings/{recordingId}/media', [CommunicationsHubController::class, 'recordingMedia'])->name('zoom.recordings.media');
        Route::get('/zoom/voicemails/{fileId}/media', [CommunicationsHubController::class, 'voicemailMedia'])->name('zoom.voicemails.media');
        Route::post('/zoom/refresh', [CommunicationsHubController::class, 'refreshCache'])->name('zoom.refresh');
        Route::post('/zoom/sms/send', [CommunicationsHubController::class, 'sendSms'])->name('zoom.sms.send');
    });
});
