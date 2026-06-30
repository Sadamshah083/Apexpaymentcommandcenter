<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkspaceAuthController extends Controller
{
    // Admin Portal
    public function showAdminLogin()
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user->isAdminOfAnyWorkspace()) {
                $user->ensureAdminPortalWorkspace();

                return redirect()->route('admin.dashboard');
            }
        }

        return view('auth.login_admin');
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('name', $credentials['username'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user);

            if (! $user->ensureAdminPortalWorkspace()) {
                Auth::logout();

                return back()->withErrors([
                    'username' => 'Access denied. Agent accounts must use the agent sign-in page.',
                ]);
            }

            return redirect()->route('admin.dashboard')->with('success', 'Logged into Admin Portal.');
        }

        return back()->withErrors([
            'username' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('username'));
    }

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'workspace_name' => 'required|string|max:255',
            'admin_username' => 'required|string|max:255|unique:users,name',
            'admin_password' => 'required|string|min:6',
        ]);

        $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['admin_username'])) . 
                 '@' . 
                 strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['workspace_name'])) . 
                 '.local';

        if (User::where('email', $email)->exists()) {
            $email = Str::random(5) . '_' . $email;
        }

        $user = User::create([
            'name' => $data['admin_username'],
            'email' => $email,
            'password' => Hash::make($data['admin_password']),
        ]);

        $workspace = Workspace::create([
            'name' => $data['workspace_name'],
            'admin_id' => $user->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'super_admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->update(['current_workspace_id' => $workspace->id]);

        Auth::login($user);

        return redirect()->route('admin.dashboard')->with('success', 'Workspace and admin account created.');
    }

    public function adminLogout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    // Portal (Marketer) Portal
    public function showPortalLogin()
    {
        if (Auth::check()) {
            return redirect()->route('portal.dashboard');
        }
        return view('auth.login_portal');
    }

    public function portalLogin(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('name', $credentials['username'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            $activeWorkspace = $user->firstActiveWorkspace();

            if (! $activeWorkspace) {
                $suspended = $user->hasAnySuspendedMembership();

                return back()->withErrors([
                    'username' => $suspended
                        ? 'Your account has been suspended. Contact your workspace administrator to regain access.'
                        : 'Access denied. This account is not active in any workspace.',
                ])->withInput($request->only('username'));
            }

            Auth::login($user);
            $user->update(['current_workspace_id' => $activeWorkspace->id]);

            if ($user->canAccessMarketerPortal($activeWorkspace->id)) {
                return redirect()->route('portal.dashboard')->with('success', 'Signed in to '.config('app.name').'.');
            }

            Auth::logout();

            return back()->withErrors([
                'username' => 'Access denied. This account is not active in any workspace.',
            ]);
        }

        return back()->withErrors([
            'username' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('username'));
    }

    public function portalLogout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    public function showAcceptInvite(string $token)
    {
        return redirect()->route('portal.login')->with(
            'info',
            'Email invitations are no longer used. Log in with the username and password your workspace owner created for you.'
        );
    }

    public function acceptInvite(Request $request, string $token)
    {
        return redirect()->route('portal.login')->with(
            'info',
            'Email invitations are no longer used. Log in with the username and password your workspace owner created for you.'
        );
    }
}
