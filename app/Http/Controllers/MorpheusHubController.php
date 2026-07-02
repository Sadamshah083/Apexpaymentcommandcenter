<?php

namespace App\Http\Controllers;

use App\Services\Communications\CommunicationsDataService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Http\Request;

class MorpheusHubController extends Controller
{
    public function __construct(
        protected ZoomApiService $morpheus,
        protected MorpheusHubService $hub,
        protected CommunicationsDataService $data,
    ) {}

    // -------------------------------------------------------------------------
    // Call actions
    // -------------------------------------------------------------------------

    public function originateCall(Request $request)
    {
        $validated = $request->validate([
            'destination' => ['required', 'string', 'max:32'],
            'from_extension' => ['required', 'string', 'max:32'],
            'fallback' => ['nullable', 'in:sip,tel,none'],
        ]);

        if (! $this->morpheus->isConfigured()) {
            return back()->with('error', 'Morpheus CX is not configured.');
        }

        $clickToCall = app(\App\Services\Communications\ZoomClickToCallService::class);
        $destination = $clickToCall->normalizePhone($validated['destination']);
        $fromExtension = preg_replace('/\D/', '', $validated['from_extension']) ?: $validated['from_extension'];
        $fallback = $validated['fallback'] ?? 'sip';
        $dialMethod = (string) config('integrations.morpheus.dial_method', 'api');

        if ($dialMethod === 'sip') {
            return $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip')
                ?? $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel')
                ?? back()->withInput()->with('error', 'Could not build a dial URL for this number.');
        }

        if ($dialMethod === 'tel') {
            return $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel')
                ?? back()->withInput()->with('error', 'Could not build a tel: link for this number.');
        }

        $result = $this->morpheus->originateCall($fromExtension, $destination);

        if ($result['ok'] ?? false) {
            $this->data->bustCache();
            $this->hub->bustCache();

            if ($request->wantsJson()) {
                return response()->json(array_merge(['ok' => true], $result));
            }

            return redirect()
                ->route($this->redirectRoutePrefix($request).'communications.index', ['channel' => 'calls'])
                ->with('success', 'Outbound call initiated. Answer your extension/softphone if it rings first.');
        }

        if ($fallback === 'sip') {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'sip');
            if ($launched) {
                return $launched;
            }
        }

        if ($fallback === 'tel') {
            $launched = $this->launchOutboundDial($request, $clickToCall, $destination, $fromExtension, 'tel');
            if ($launched) {
                return $launched;
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'Could not place outbound call.',
                'attempted' => $result['attempted'] ?? [],
            ], 422);
        }

        return back()
            ->withInput()
            ->with('error', $result['error'] ?? 'Could not place outbound call.');
    }

    public function transferCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['destination' => ['required', 'string', 'max:64']]);

        return $this->callAction($request, fn () => $this->morpheus->transferCall($uuid, $validated['destination']), 'Call transferred.');
    }

    public function hangupCall(Request $request, string $uuid)
    {
        return $this->callAction($request, fn () => $this->morpheus->hangup($uuid), 'Call ended.');
    }

    public function holdCall(Request $request, string $uuid)
    {
        return $this->callAction($request, fn () => $this->morpheus->hold($uuid), 'Call placed on hold.');
    }

    public function unholdCall(Request $request, string $uuid)
    {
        return $this->callAction($request, fn () => $this->morpheus->unhold($uuid), 'Call removed from hold.');
    }

    public function parkCall(Request $request, string $uuid)
    {
        return $this->callAction($request, fn () => $this->morpheus->park($uuid), 'Call parked.');
    }

    public function unparkCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['destination' => ['required', 'string', 'max:64']]);

        return $this->callAction($request, fn () => $this->morpheus->unpark($uuid, $validated['destination']), 'Call unparked.');
    }

    public function unbridgeCall(Request $request, string $uuid)
    {
        return $this->callAction($request, fn () => $this->morpheus->unbridge($uuid), 'Call unbridged.');
    }

    public function bridgeCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['other_uuid' => ['required', 'string', 'uuid']]);

        return $this->callAction($request, fn () => $this->morpheus->bridge($uuid, $validated['other_uuid']), 'Calls bridged.');
    }

    public function joinConferenceCall(Request $request, string $uuid)
    {
        $validated = $request->validate(['conference' => ['required', 'string', 'max:128']]);

        return $this->callAction($request, fn () => $this->morpheus->joinConference($uuid, $validated['conference']), 'Call joined conference.');
    }

    public function transferCallToQueue(Request $request, string $uuid)
    {
        $validated = $request->validate(['queue_id' => ['required', 'string']]);

        return $this->callAction($request, fn () => $this->morpheus->transferToQueue($uuid, $validated['queue_id']), 'Call transferred to queue.');
    }

    public function transferCallToAgent(Request $request, string $uuid)
    {
        $validated = $request->validate(['agent_user_id' => ['required', 'string', 'uuid']]);

        return $this->callAction($request, fn () => $this->morpheus->transferToAgent($uuid, $validated['agent_user_id']), 'Call transferred to agent.');
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

        return $this->callAction($request, fn () => $this->morpheus->dispositionCall($uuid, $payload), 'Disposition recorded.');
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

        return $this->mutateAction(fn () => $this->morpheus->createExtension($validated), 'Extension created.');
    }

    public function updateExtension(Request $request, string $id)
    {
        $validated = $request->validate([
            'caller_id_name' => ['nullable', 'string', 'max:128'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:active,disabled'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
        ]);

        return $this->mutateAction(fn () => $this->morpheus->updateExtension($id, array_filter($validated, fn ($v) => ! is_null($v))), 'Extension updated.');
    }

    public function destroyExtension(string $id)
    {
        return $this->mutateAction(fn () => $this->morpheus->deleteExtension($id), 'Extension deleted.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function callAction(Request $request, callable $action, string $successMessage)
    {
        if (! $this->morpheus->isConfigured()) {
            return back()->with('error', 'Morpheus CX is not configured.');
        }

        try {
            $result = $action();
            if (! ($result['ok'] ?? false)) {
                return back()->with('error', $result['error'] ?? 'Call action failed.');
            }

            $this->data->bustCache();
            $this->hub->bustCache();

            return $this->redirectBack($request, $successMessage);
        } catch (\Throwable $e) {
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
}
