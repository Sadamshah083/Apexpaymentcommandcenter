<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Communications\CommunicationsWebphoneService;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Communications\CommunicationsCallHistoryService;
use App\Services\Communications\CommunicationsDataService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MorpheusHubController extends Controller
{
    public function __construct(
        protected ZoomApiService $morpheus,
        protected MorpheusHubService $hub,
        protected CommunicationsDataService $data,
        protected CommunicationsAgentService $agents,
        protected CommunicationsCallHistoryService $callHistory,
        protected CommunicationsWebphoneService $webphone,
        protected WorkspaceContextService $workspaceContext,
    ) {}

    // -------------------------------------------------------------------------
    // Webphone (embedded SIP/WebRTC)
    // -------------------------------------------------------------------------

    public function webphoneConfig(Request $request)
    {
        $validated = $request->validate([
            'extension' => ['required', 'string', 'max:32'],
        ]);

        if (! $this->morpheus->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Morpheus is not configured.'], 503);
        }

        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $routePrefix = $this->redirectRoutePrefix($request);

        $config = $this->webphone->configFor(
            $user,
            $workspace,
            $validated['extension'],
            $routePrefix,
        );

        if ($config === null) {
            return response()->json(['ok' => false, 'error' => 'Webphone not available for this extension.'], 403);
        }

        return response()->json(['ok' => true, 'config' => $config]);
    }

    public function prepareWebphone(Request $request)
    {
        $validated = $request->validate([
            'extension' => ['required', 'string', 'max:32'],
        ]);

        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $routePrefix = $this->redirectRoutePrefix($request);

        $result = $this->webphone->prepareExtension(
            $user,
            $workspace,
            $validated['extension'],
            $routePrefix,
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        $config = $this->webphone->configFor($user, $workspace, $validated['extension'], $routePrefix);

        if ($config === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Webphone not available for this extension.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'] ?? 'Ready.',
            'warning' => $result['warning'] ?? null,
            'config' => $config,
        ]);
    }

    // -------------------------------------------------------------------------
    // Call actions
    // -------------------------------------------------------------------------

    public function originateCall(Request $request)
    {
        $validated = $request->validate([
            'destination' => ['required', 'string', 'max:32'],
            'from_extension' => ['required', 'string', 'max:32'],
        ]);

        if (! $this->morpheus->isConfigured()) {
            return back()->with('error', 'Morpheus CX is not configured.');
        }

        if ($request->boolean('webphone_direct') || $request->has('campaign_id') || $request->has('caller_id_number')) {
            $message = 'Outbound calls must use the server click-to-call API. Send only destination and from_extension.';

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $message], 422);
            }

            return back()->withInput()->with('error', $message);
        }

        $clickToCall = app(\App\Services\Communications\ZoomClickToCallService::class);
        $destination = $clickToCall->normalizePhone($validated['destination']);
        $fromExtension = preg_replace('/\D/', '', $validated['from_extension']) ?: $validated['from_extension'];
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $routePrefix = $this->redirectRoutePrefix($request);
        $dialMethod = (string) config('integrations.morpheus.dial_method', 'api');
        $apiOnly = $dialMethod === 'api';

        if (! $clickToCall->isValidPstnDestination($destination)) {
            $destinationError = 'Enter a valid phone number with at least 10 digits (e.g. +12722001232).';

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $destinationError], 422);
            }

            return back()->withInput()->with('error', $destinationError);
        }

        if (! $this->agents->userCanDialFrom($user, $workspace, $fromExtension, $routePrefix)) {
            return back()->withInput()->with('error', 'You can only place calls from your assigned extension.');
        }

        $dialOptions = $this->agents->extensionDialOptions($fromExtension);

        if (! filled($dialOptions['campaign_id'] ?? null)) {
            $campaignError = 'Morpheus campaign_id is required for outbound calls. Set MORPHEUS_DEFAULT_CAMPAIGN_ID in .env or create an active campaign in Morpheus CX.';

            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $campaignError], 422);
            }

            return back()->withInput()->with('error', $campaignError);
        }

        $missingDid = ! $this->agents->extensionHasOutboundDid($fromExtension);
        $result = ['ok' => false, 'error' => 'Could not place outbound call.'];
        $didWarning = $missingDid
            ? 'Outbound DID is not set on this extension yet — assign a caller ID in Phone Agents before calling customers.'
            : null;

        $endpointOnline = $this->agents->extensionEndpointOnline($fromExtension);

        if (! $endpointOnline && $dialMethod === 'sip' && ! $apiOnly) {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip');
            if ($launched) {
                $this->logOutboundDial($request, $fromExtension, $destination);

                if (! $request->wantsJson()) {
                    session()->flash(
                        'warning',
                        'Your Morpheus extension is not registered yet. Opening direct SIP dial — register Zoiper, Linphone, or the Morpheus web phone first.'
                    );
                }

                return $launched;
            }
        }

        if (in_array($dialMethod, ['api', 'auto'], true)) {
            if ($request->wantsJson() && $apiOnly && ! $request->boolean('webphone_transport_connected')) {
                return response()->json([
                    'ok' => false,
                    'extension_offline' => true,
                    'webphone_required' => true,
                    'error' => $this->agents->extensionOfflineDialMessage($fromExtension),
                ], 422);
            }

            if ($request->wantsJson() && ! $apiOnly && ! $endpointOnline) {
                return response()->json([
                    'ok' => false,
                    'extension_offline' => true,
                    'error' => $this->agents->extensionOfflineDialMessage($fromExtension),
                ], 422);
            }

            $this->webphone->prepareExtension($user, $workspace, $fromExtension, $routePrefix);

            if ($request->boolean('webphone_transport_connected')) {
                $this->morpheus->clearExtensionForOutboundDial($fromExtension, kickSip: true);
            }

            $result = $this->morpheus->originateCall($fromExtension, $destination, $dialOptions);

            if (! ($result['ok'] ?? false) && $request->wantsJson()) {
                return response()->json(
                    $this->morpheus->formatOriginateResponse($result, $fromExtension, $destination, $dialOptions),
                    ($result['extension_busy'] ?? false) ? 409 : 422
                );
            }

            if ($result['ok'] ?? false) {
                $this->logOutboundDial($request, $fromExtension, $destination, $result['call_uuid'] ?? null);
                $this->data->bustCache();
                $this->hub->bustCache();

                $successMessage = match ($result['outcome'] ?? 'initiated') {
                    'connected' => 'Call connected.',
                    'ringing' => ($result['customer_first'] ?? false)
                        ? 'Your phone is ringing — answer within 90 seconds. Keep Connect line on.'
                        : 'Connecting your line… the destination will ring once your browser phone answers.',
                    'no_answer' => 'Call placed but extension did not answer in time.',
                    'routing_failed' => 'Call could not reach the destination. Check the number and Morpheus trunk routing.',
                    default => ($result['customer_first'] ?? false)
                        ? 'Your phone is ringing — answer within 90 seconds. Keep Connect line on.'
                        : 'Connecting your line… the destination will ring once your browser phone answers.',
                };

                if ($request->wantsJson()) {
                    return response()->json(
                        $this->morpheus->formatOriginateResponse($result, $fromExtension, $destination, $dialOptions)
                    );
                }

                $redirect = back()->with('success', $successMessage);

                if ($didWarning) {
                    $redirect->with('warning', $didWarning);
                }

                if (filled($result['warning'] ?? null)) {
                    $redirect->with('warning', $result['warning']);
                }

                return $redirect;
            }

            if ($dialMethod === 'api') {
                if ($request->wantsJson()) {
                    return response()->json(
                        $this->morpheus->formatOriginateResponse($result, $fromExtension, $destination, $dialOptions),
                        422
                    );
                }

                return back()
                    ->withInput()
                    ->with('error', $result['error'] ?? 'Could not place outbound call.');
            }
        }

        if (($result['extension_busy'] ?? false) === true && in_array($dialMethod, ['auto', 'sip'], true) && ! $apiOnly) {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip');
            if ($launched) {
                $this->logOutboundDial($request, $fromExtension, $destination, $result['call_uuid'] ?? null);

                if (! $request->wantsJson()) {
                    session()->flash(
                        'warning',
                        ($result['error'] ?? 'Extension rejected the ring.').' Opening direct SIP dial instead.'
                    );
                }

                return $launched;
            }
        }

        if (($result['extension_offline'] ?? false) === true && in_array($dialMethod, ['auto', 'sip'], true) && ! $apiOnly) {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip');
            if ($launched) {
                $this->logOutboundDial($request, $fromExtension, $destination, $result['call_uuid'] ?? null);

                if (! $request->wantsJson()) {
                    session()->flash('warning', ($result['error'] ?? 'Extension offline.').' Opening direct SIP dial instead.');
                }

                return $launched;
            }
        }

        if (($result['extension_offline'] ?? false) === true) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => $result['error'] ?? 'Extension is not online.',
                    'attempted' => $result['attempted'] ?? [],
                ], 422);
            }

            return back()
                ->withInput()
                ->with('error', $result['error'] ?? 'Extension is not online.');
        }

        if ($dialMethod === 'sip' && ! $apiOnly) {
            $response = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip')
                ?? $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel')
                ?? back()->withInput()->with('error', 'Could not build a dial URL for this number.');

            $this->logOutboundDial($request, $fromExtension, $destination);

            return $response;
        }

        if ($dialMethod === 'tel' && ! $apiOnly) {
            $response = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel')
                ?? back()->withInput()->with('error', 'Could not build a tel: link for this number.');

            $this->logOutboundDial($request, $fromExtension, $destination);

            return $response;
        }

        if (! $apiOnly && $request->input('fallback') === 'sip') {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip');
            if ($launched) {
                $this->logOutboundDial($request, $fromExtension, $destination);

                return $launched;
            }
        }

        if (! $apiOnly && $request->input('fallback') === 'tel') {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel');
            if ($launched) {
                $this->logOutboundDial($request, $fromExtension, $destination);

                return $launched;
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => false,
                'error' => ($result['error'] ?? null) ?: 'Could not place outbound call.',
                'attempted' => $result['attempted'] ?? [],
            ], 422);
        }

        return back()
            ->withInput()
            ->with('error', ($result['error'] ?? null) ?: 'Could not place outbound call.');
    }

    public function transferCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['destination' => ['required', 'string', 'max:64']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->transferCall($uuid, $validated['destination']), 'Call transferred.');
    }

    public function hangupCall(Request $request, string $uuid)
    {
        return $this->executeCallAction($request, fn () => $this->morpheus->hangup($uuid), 'Call ended.');
    }

    public function callStatus(Request $request, string $uuid)
    {
        if (! $this->morpheus->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Morpheus is not configured.'], 503);
        }

        $destination = is_string($request->query('destination')) ? $request->query('destination') : null;

        try {
            return response()->json(
                $this->morpheus->hubCallStatus($uuid, $destination, $request->boolean('customer_first'))
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => 'Could not load call status.',
            ], 500);
        }
    }

    public function holdCall(Request $request, string $uuid)
    {
        return $this->executeCallAction($request, fn () => $this->morpheus->hold($uuid), 'Call placed on hold.');
    }

    public function unholdCall(Request $request, string $uuid)
    {
        return $this->executeCallAction($request, fn () => $this->morpheus->unhold($uuid), 'Call removed from hold.');
    }

    public function parkCall(Request $request, string $uuid)
    {
        return $this->executeCallAction($request, fn () => $this->morpheus->park($uuid), 'Call parked.');
    }

    public function unparkCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['destination' => ['required', 'string', 'max:64']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->unpark($uuid, $validated['destination']), 'Call unparked.');
    }

    public function unbridgeCall(Request $request, string $uuid)
    {
        return $this->executeCallAction($request, fn () => $this->morpheus->unbridge($uuid), 'Call unbridged.');
    }

    public function bridgeCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['other_uuid' => ['required', 'string', 'uuid']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->bridge($uuid, $validated['other_uuid']), 'Calls bridged.');
    }

    public function joinConferenceCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['conference' => ['required', 'string', 'max:128']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->joinConference($uuid, $validated['conference']), 'Call joined conference.');
    }

    public function transferCallToQueue(Request $request, string $uuid)
    {
        $validated = $request->validate(['queue_id' => ['required', 'string']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->transferToQueue($uuid, $validated['queue_id']), 'Call transferred to queue.');
    }

    public function transferCallToAgent(Request $request, string $uuid)
    {
        $validated = $request->validate(['agent_user_id' => ['required', 'string', 'uuid']]);

        return $this->executeCallAction($request, fn () => $this->morpheus->transferToAgent($uuid, $validated['agent_user_id']), 'Call transferred to agent.');
    }

    public function dispositionCall(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'disposition' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'update_lead' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'disposition' => $validated['disposition'],
            'update_lead' => $validated['update_lead'] ?? true,
        ];
        if (filled($validated['note'] ?? null)) {
            $payload['note'] = $validated['note'];
        }

        return $this->executeCallAction($request, function () use ($uuid, $payload, $request) {
            $result = $this->morpheus->dispositionCall($uuid, $payload);
            $this->recordDispositionHistory($request, $uuid, $payload['disposition'], $payload['note'] ?? null);

            return $result;
        }, 'Disposition recorded.');
    }

    // -------------------------------------------------------------------------
    // Queues
    // -------------------------------------------------------------------------

    public function storeQueue(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'strategy' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createQueue($validated), 'Queue created.');
    }

    public function updateQueue(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'strategy' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'max_wait_time' => ['nullable', 'integer', 'min:0'],
            'wrap_up_time' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateQueue($id, array_filter($validated, fn ($v) => ! is_null($v))), 'Queue updated.');
    }

    public function destroyQueue(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteQueue($id), 'Queue deleted.');
    }

    // -------------------------------------------------------------------------
    // Conferences
    // -------------------------------------------------------------------------

    public function storeConference(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'extension_num' => ['nullable', 'string', 'max:32'],
            'pin' => ['nullable', 'string', 'max:32'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'record' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createConference($validated), 'Conference room created.');
    }

    public function updateConference(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'extension_num' => ['nullable', 'string', 'max:32'],
            'pin' => ['nullable', 'string', 'max:32'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'record' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateConference($id, array_filter($validated, fn ($v) => ! is_null($v))), 'Conference room updated.');
    }

    public function destroyConference(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteConference($id), 'Conference room deleted.');
    }

    public function kickAllConferenceMembers(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->kickAllConferenceMembers($id), 'All members removed from conference.');
    }

    public function conferenceMemberAction(Request $request, string $id, string $member, string $action)
    {
        $allowed = ['mute', 'unmute', 'deaf', 'undeaf', 'kick'];
        abort_unless(in_array($action, $allowed, true), 404);

        return $this->mutateAction(fn () => $this->morpheus->conferenceMemberAction($id, $member, $action), 'Conference member action applied.');
    }

    // -------------------------------------------------------------------------
    // Leads
    // -------------------------------------------------------------------------

    public function storeLead(Request $request)
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
            'list_id' => ['required', 'string'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createLead($validated), 'Lead created.');
    }

    public function updateLead(Request $request, string $id)
    {
        $validated = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:32'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'string', 'in:clean,in_progress,suppressed,completed,callback'],
            'disposition' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateLead($id, array_filter($validated, fn ($v) => ! is_null($v))), 'Lead updated.');
    }

    public function destroyLead(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteLead($id), 'Lead deleted.');
    }

    // -------------------------------------------------------------------------
    // Campaigns
    // -------------------------------------------------------------------------

    public function storeCampaign(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'dial_mode' => ['nullable', 'string', 'in:manual,ratio,inbound,blended'],
            'status' => ['nullable', 'string', 'in:draft,active,paused,completed,archived'],
            'dial_ratio' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createCampaign($validated), 'Campaign created.');
    }

    public function updateCampaign(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'dial_mode' => ['nullable', 'string', 'in:manual,ratio,inbound,blended'],
            'status' => ['nullable', 'string', 'in:draft,active,paused,completed,archived'],
            'dial_ratio' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateCampaign($id, array_filter($validated, fn ($v) => ! is_null($v))), 'Campaign updated.');
    }

    public function destroyCampaign(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteCampaign($id), 'Campaign deleted.');
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    public function storeList(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
            'campaign_id' => ['nullable', 'string'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createLeadList($validated), 'List created.');
    }

    public function updateList(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
            'campaign_id' => ['nullable', 'string'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateLeadList($id, array_filter($validated, fn ($v) => ! is_null($v))), 'List updated.');
    }

    public function destroyList(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteLeadList($id), 'List deleted.');
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'in:admin,user'],
            'status' => ['nullable', 'string', 'in:active,inactive,locked'],
            'user_level' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->createUser($validated), 'User created.');
    }

    public function updateUser(Request $request, string $id)
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'in:admin,user'],
            'status' => ['nullable', 'string', 'in:active,inactive,locked'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'user_level' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateUser($id, array_filter($validated, fn ($v) => ! is_null($v))), 'User updated.');
    }

    public function destroyUser(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteUser($id), 'User deleted.');
    }

    // -------------------------------------------------------------------------
    // Extensions
    // -------------------------------------------------------------------------

    public function storeExtension(Request $request)
    {
        $validated = $request->validate([
            'extension_num' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'caller_id_name' => ['nullable', 'string', 'max:128'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:active,disabled'],
        ]);

        $payload = array_filter([
            ...$validated,
            'outbound_cid_name' => $validated['caller_id_name'] ?? null,
            'outbound_cid_num' => $validated['caller_id_num'] ?? null,
            'is_dialer_agent' => true,
            'override_campaign_cid' => true,
        ], fn ($v) => ! is_null($v));

        return $this->mutateAction(fn () => $this->morpheus->createExtension($payload), 'Extension created.');
    }

    public function updateExtension(Request $request, string $id)
    {
        $validated = $request->validate([
            'caller_id_name' => ['nullable', 'string', 'max:128'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:active,disabled'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
        ]);

        $payload = array_filter([
            ...$validated,
            'outbound_cid_name' => $validated['caller_id_name'] ?? null,
            'outbound_cid_num' => $validated['caller_id_num'] ?? null,
            'override_campaign_cid' => true,
        ], fn ($v) => ! is_null($v));

        return $this->mutateAction(fn () => $this->morpheus->updateExtension($id, $payload), 'Extension updated.');
    }

    public function destroyExtension(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteExtension($id), 'Extension deleted.');
    }

    // -------------------------------------------------------------------------
    // Phone agents (workspace users + Morpheus extensions)
    // -------------------------------------------------------------------------

    public function provisionAgent(Request $request, User $user)
    {
        $this->ensureCanManageAgents($request);

        $validated = $request->validate([
            'extension_num' => ['required', 'string', 'max:32'],
            'sip_password' => ['required', 'string', 'min:8', 'max:128'],
            'caller_id_name' => ['nullable', 'string', 'max:128'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'create_morpheus_user' => ['nullable', 'boolean'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $result = $this->agents->provision($workspace, $user, $validated);

        if (! ($result['ok'] ?? false)) {
            return back()->withInput()->with('error', $result['error'] ?? 'Could not provision phone line.');
        }

        $this->data->bustCache();
        $this->hub->bustCache();

        return back()
            ->with('success', $result['message'] ?? 'Phone line provisioned.')
            ->with('provisioned_agent', [
                'name' => $user->name,
                'extension_num' => $result['agent']['extension_num'] ?? $validated['extension_num'],
                'sip_password' => $result['agent']['sip_password'] ?? $validated['sip_password'],
                'sip_host' => config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host'),
            ]);
    }

    public function updateAgent(Request $request, User $user)
    {
        $this->ensureCanManageAgents($request);

        $validated = $request->validate([
            'sip_password' => ['nullable', 'string', 'min:8', 'max:128'],
            'caller_id_name' => ['nullable', 'string', 'max:128'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:active,disabled'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $result = $this->agents->update($workspace, $user, $validated);

        if (! ($result['ok'] ?? false)) {
            return back()->withInput()->with('error', $result['error'] ?? 'Could not update phone line.');
        }

        $this->data->bustCache();
        $this->hub->bustCache();

        return back()->with('success', $result['message'] ?? 'Phone line updated.');
    }

    public function deprovisionAgent(Request $request, User $user)
    {
        $this->ensureCanManageAgents($request);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $result = $this->agents->deprovision($workspace, $user);

        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'Could not remove phone line.');
        }

        $this->data->bustCache();
        $this->hub->bustCache();

        return back()->with('success', $result['message'] ?? 'Phone line removed.');
    }

    protected function ensureCanManageAgents(Request $request): void
    {
        if (! $request->is('admin*')) {
            abort(403, 'Only admins can manage phone agents.');
        }

        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function executeCallAction(Request $request, callable $action, string $successMessage)
    {
        if (! $this->morpheus->isConfigured()) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => 'Morpheus CX is not configured.'], 503);
            }

            return back()->with('error', 'Morpheus CX is not configured.');
        }

        try {
            $result = $action();
            if (! ($result['ok'] ?? false)) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'ok' => false,
                        'error' => $result['error'] ?? 'Call action failed.',
                    ], 422);
                }

                return back()->with('error', $result['error'] ?? 'Call action failed.');
            }

            $this->data->bustCache();
            $this->hub->bustCache();

            if ($request->wantsJson()) {
                return response()->json(array_merge(['ok' => true, 'message' => $successMessage], $result));
            }

            return $this->redirectBack($request, $successMessage);
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => $this->morpheus->humanizeError($e->getMessage()),
                ], 500);
            }

            return back()->with('error', $this->morpheus->humanizeError($e->getMessage()));
        }
    }

    protected function mutateAction(callable $action, string $successMessage)
    {
        if (! $this->morpheus->isConfigured()) {
            return back()->with('error', 'Morpheus CX is not configured.');
        }

        try {
            $result = $action();
            if (($result['ok'] ?? true) === false || isset($result['error'])) {
                return back()->withInput()->with('error', $result['error'] ?? 'Request failed.');
            }

            $this->data->bustCache();
            $this->hub->bustCache();

            return back()->with('success', $successMessage);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $this->morpheus->humanizeError($e->getMessage()));
        }
    }

    protected function redirectBack(Request $request, string $successMessage)
    {
        if (filled($request->input('redirect_to'))) {
            return redirect($request->input('redirect_to'))->with('success', $successMessage);
        }

        return back()->with('success', $successMessage);
    }

    protected function redirectRoutePrefix(Request $request): string
    {
        return $request->is('admin*') ? 'admin.' : 'portal.';
    }

    protected function launchOutboundDial(
        Request $request,
        \App\Services\Communications\ZoomClickToCallService $clickToCall,
        string $destination,
        string $fromExtension,
        string $method,
    ) {
        $url = $method === 'tel'
            ? $clickToCall->telUrl($destination)
            : $clickToCall->sipUrl($destination, $fromExtension);

        if (! $url) {
            return null;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'action' => $method,
                'dial_url' => $url,
            ]);
        }

        if ($method === 'sip') {
            return response()->view('communications.partials.sip-launch', [
                'sipUrl' => $url,
                'routePrefix' => $this->redirectRoutePrefix($request),
            ]);
        }

        return redirect()->away($url);
    }

    protected function logOutboundDial(Request $request, string $fromExtension, string $destination, ?string $morpheusCallUuid = null): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return;
        }

        $this->callHistory->logOutboundDial($workspace, $user, $fromExtension, $destination, $morpheusCallUuid);
        $this->data->bustCache();
    }

    protected function recordDispositionHistory(Request $request, string $uuid, string $disposition, ?string $note): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return;
        }

        $this->callHistory->recordDisposition($workspace, $uuid, $disposition, $note, $user);
        $this->data->bustCache();
    }
}
