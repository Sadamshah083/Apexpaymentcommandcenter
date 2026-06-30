<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifications\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushNotificationController extends Controller
{
    public function __construct(
        protected WebPushService $webPush,
    ) {}

    public function vapidPublicKey()
    {
        $publicKey = $this->webPush->publicKey();

        if (! $publicKey) {
            return response()->json([
                'error' => 'Web Push is not configured on this server.',
            ], 503);
        }

        return response()->json(['publicKey' => $publicKey]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (! $this->webPush->isConfigured()) {
            return response()->json(['success' => true, 'mode' => 'local']);
        }

        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $request->input('endpoint')],
            [
                'public_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
            ]
        );

        return response()->json(['success' => true]);
    }

    public function latest(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json([]);
        }

        $latestLead = $user->assignedLeads()
            ->where('status', 'completed')
            ->latest('updated_at')
            ->first();

        if ($latestLead) {
            return response()->json([
                'title' => 'New lead assigned',
                'body' => 'Lead: '.$latestLead->business_name.' ('.($latestLead->city ?: 'Unknown City').')',
                'url' => route('portal.leads.show', $latestLead->id),
            ]);
        }

        return response()->json([
            'title' => config('app.name'),
            'body' => 'Your workspace is synchronized.',
            'url' => $user->isWorkspaceAdmin($user->current_workspace_id)
                ? route('admin.dashboard')
                : route('portal.dashboard'),
        ]);
    }

    public static function sendPushNotification(int $userId, string $title, string $body, ?string $url = null): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        app(WebPushService::class)->sendToUser($user, $title, $body, $url);
    }

    public function sendTestNotification(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $dashboardUrl = $user->isWorkspaceAdmin($user->current_workspace_id)
            ? route('admin.dashboard')
            : route('portal.dashboard');

        if ($this->webPush->isConfigured()) {
            $this->webPush->sendToUser(
                $user,
                'Notifications enabled',
                'You will receive Windows alerts for leads and workspace updates.',
                $dashboardUrl
            );
        }

        return response()->json(['success' => true, 'mode' => 'local']);
    }
}
