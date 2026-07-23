<?php

use App\Http\Controllers\MorpheusHubController;
use Illuminate\Support\Facades\Route;

return function (): void {
    Route::prefix('morpheus')->name('morpheus.')->group(function () {
        Route::get('/session/ping', [MorpheusHubController::class, 'sessionPing'])->name('session.ping');
        Route::get('/webphone/config', [MorpheusHubController::class, 'webphoneConfig'])->name('webphone.config');
        Route::post('/webphone/prepare', [MorpheusHubController::class, 'prepareWebphone'])->name('webphone.prepare');

        // Agent + admin: live call operations (dial, hold, transfer, disposition, etc.)
        Route::prefix('calls')->name('calls.')->group(function () {
            Route::post('/originate', [MorpheusHubController::class, 'originateCall'])->name('originate');
            Route::post('/release-extension', [MorpheusHubController::class, 'releaseExtensionCalls'])->name('release-extension');
            Route::post('/webhook', [MorpheusHubController::class, 'receiveCallWebhook'])->name('webhook');
            Route::get('/{uuid}/events', [MorpheusHubController::class, 'streamCallEvents'])->name('events');
            Route::get('/{uuid}', [MorpheusHubController::class, 'callStatus'])->name('status');
            Route::post('/{uuid}/destination-connected', [MorpheusHubController::class, 'markDestinationConnected'])->name('destination-connected');
            Route::post('/{uuid}/ended', [MorpheusHubController::class, 'markCallEnded'])->name('ended');
            Route::post('/{uuid}/transfer', [MorpheusHubController::class, 'transferCall'])->name('transfer');
            Route::post('/{uuid}/hangup', [MorpheusHubController::class, 'hangupCall'])->name('hangup');
            Route::post('/{uuid}/record', [MorpheusHubController::class, 'recordCall'])->name('record');
            Route::post('/{uuid}/hold', [MorpheusHubController::class, 'holdCall'])->name('hold');
            Route::post('/{uuid}/unhold', [MorpheusHubController::class, 'unholdCall'])->name('unhold');
            Route::post('/{uuid}/park', [MorpheusHubController::class, 'parkCall'])->name('park');
            Route::post('/{uuid}/unpark', [MorpheusHubController::class, 'unparkCall'])->name('unpark');
            Route::post('/{uuid}/unbridge', [MorpheusHubController::class, 'unbridgeCall'])->name('unbridge');
            Route::post('/{uuid}/bridge', [MorpheusHubController::class, 'bridgeCall'])->name('bridge');
            Route::post('/{uuid}/join-conference', [MorpheusHubController::class, 'joinConferenceCall'])->name('join-conference');
            Route::post('/{uuid}/transfer-to-queue', [MorpheusHubController::class, 'transferCallToQueue'])->name('transfer-to-queue');
            Route::post('/{uuid}/transfer-to-agent', [MorpheusHubController::class, 'transferCallToAgent'])->name('transfer-to-agent');
            Route::post('/{uuid}/disposition', [MorpheusHubController::class, 'dispositionCall'])->name('disposition');
        });

        // Admin only: Morpheus configuration & CRUD
        Route::middleware('communications.admin')->group(function () {
            Route::post('/queues', [MorpheusHubController::class, 'storeQueue'])->name('queues.store');
            Route::patch('/queues/{id}', [MorpheusHubController::class, 'updateQueue'])->name('queues.update');
            Route::delete('/queues/{id}', [MorpheusHubController::class, 'destroyQueue'])->name('queues.destroy');

            Route::post('/conferences', [MorpheusHubController::class, 'storeConference'])->name('conferences.store');
            Route::patch('/conferences/{id}', [MorpheusHubController::class, 'updateConference'])->name('conferences.update');
            Route::delete('/conferences/{id}', [MorpheusHubController::class, 'destroyConference'])->name('conferences.destroy');
            Route::post('/conferences/{id}/kick-all', [MorpheusHubController::class, 'kickAllConferenceMembers'])->name('conferences.kick-all');
            Route::post('/conferences/{id}/members/{member}/{action}', [MorpheusHubController::class, 'conferenceMemberAction'])->name('conferences.member-action');

            Route::post('/leads', [MorpheusHubController::class, 'storeLead'])->name('leads.store');
            Route::patch('/leads/{id}', [MorpheusHubController::class, 'updateLead'])->name('leads.update');
            Route::delete('/leads/{id}', [MorpheusHubController::class, 'destroyLead'])->name('leads.destroy');

            Route::post('/campaigns', [MorpheusHubController::class, 'storeCampaign'])->name('campaigns.store');
            Route::patch('/campaigns/{id}', [MorpheusHubController::class, 'updateCampaign'])->name('campaigns.update');
            Route::delete('/campaigns/{id}', [MorpheusHubController::class, 'destroyCampaign'])->name('campaigns.destroy');

            Route::post('/lists', [MorpheusHubController::class, 'storeList'])->name('lists.store');
            Route::patch('/lists/{id}', [MorpheusHubController::class, 'updateList'])->name('lists.update');
            Route::delete('/lists/{id}', [MorpheusHubController::class, 'destroyList'])->name('lists.destroy');

            Route::post('/users', [MorpheusHubController::class, 'storeUser'])->name('users.store');
            Route::patch('/users/{id}', [MorpheusHubController::class, 'updateUser'])->name('users.update');
            Route::delete('/users/{id}', [MorpheusHubController::class, 'destroyUser'])->name('users.destroy');

            Route::post('/extensions', [MorpheusHubController::class, 'storeExtension'])->name('extensions.store');
            Route::patch('/extensions/{id}', [MorpheusHubController::class, 'updateExtension'])->name('extensions.update');
            Route::delete('/extensions/{id}', [MorpheusHubController::class, 'destroyExtension'])->name('extensions.destroy');

            Route::post('/agents/{user}/provision', [MorpheusHubController::class, 'provisionAgent'])->name('agents.provision');
            Route::patch('/agents/{user}', [MorpheusHubController::class, 'updateAgent'])->name('agents.update');
            Route::delete('/agents/{user}', [MorpheusHubController::class, 'deprovisionAgent'])->name('agents.deprovision');
        });
    });
};
