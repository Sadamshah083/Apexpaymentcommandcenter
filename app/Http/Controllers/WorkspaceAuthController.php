<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\MemberJwtService;
use App\Support\AdminModules;
use Illuminate\Http\RedirectResponse;
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

                return $this->redirectAfterAdminLogin($user);
            }

            // Agent session on admin login page — clear so they can sign in as admin if needed.
            Auth::logout();
            request()->session()->forget('member_jwt');
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return response()
            ->view('auth.login_admin')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $login = trim($credentials['email']);
        $user = $this->findUserByLogin($login);

        if ($user && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user, false);
            $request->session()->regenerate();

            if (! $user->ensureAdminPortalWorkspace()) {
                Auth::logout();

                return back()->withErrors([
                    'email' => 'Access denied. Agent accounts must use the agent sign-in page.',
                ]);
            }

            $this->issueMemberJwt($user);

            return $this->redirectAfterAdminLogin($user)->with('success', 'Logged into Admin Portal.');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('email'));
    }

    public function showRegister()
    {
        if (Auth::check()) {
            return $this->redirectAfterAdminLogin(Auth::user());
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

        return $this->redirectAfterAdminLogin($user)->with('success', 'Workspace and admin account created.');
    }

    protected function redirectAfterAdminLogin(User $user): RedirectResponse
    {
        if ($user->canAccessAdminModule('communications', $user->current_workspace_id)) {
            $defaultUrl = route('admin.communications.index', AdminModules::communicationsDialerParams());
        } else {
            $landing = AdminModules::defaultLandingForUser($user);
            $defaultUrl = route($landing['route'], $landing['params']);
        }

        return redirect()->intended($defaultUrl);
    }

    protected function redirectAfterPortalLogin(User $user): RedirectResponse
    {
        return redirect()->intended(
            route('portal.communications.index', AdminModules::communicationsDialerParams())
        );
    }

    public function adminLogout(Request $request)
    {
        Auth::logout();
        $request->session()->forget('member_jwt');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    // Portal (Marketer) Portal
    public function showPortalLogin()
    {
        if (Auth::check()) {
            $user = Auth::user();
            $activeWorkspace = $user->firstPortalWorkspace() ?? $user->firstActiveWorkspace();
            if ($activeWorkspace && $user->canAccessPortal($activeWorkspace->id)) {
                if ((int) ($user->current_workspace_id ?? 0) !== (int) $activeWorkspace->id) {
                    $user->update(['current_workspace_id' => $activeWorkspace->id]);
                }

                return $this->redirectAfterPortalLogin($user);
            }

            // Admin or inactive session on agent login — clear so agent login works cleanly.
            Auth::logout();
            request()->session()->forget('member_jwt');
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return response()
            ->view('auth.login_portal')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function portalLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $login = trim($credentials['email']);
        $user = $this->findUserByLogin($login);

        if ($user && Hash::check($credentials['password'], $user->password)) {
            $activeWorkspace = $user->firstPortalWorkspace() ?? $user->firstActiveWorkspace();

            if (! $activeWorkspace) {
                $suspended = $user->hasAnySuspendedMembership();

                return back()->withErrors([
                    'email' => $suspended
                        ? 'Your account has been suspended. Contact your workspace administrator to regain access.'
                        : 'Access denied. This account is not active in any workspace.',
                ])->withInput($request->only('email'));
            }

            Auth::login($user, false);
            $request->session()->regenerate();

            if ((int) ($user->current_workspace_id ?? 0) !== (int) $activeWorkspace->id) {
                $user->update(['current_workspace_id' => $activeWorkspace->id]);
            }

            if ($user->canAccessPortal($activeWorkspace->id)) {
                $this->issueMemberJwt($user, (int) $activeWorkspace->id);

                return $this->redirectAfterPortalLogin($user)->with('success', 'Signed in to '.config('app.name').'.');
            }

            Auth::logout();

            if ($user->isAdminOfAnyWorkspace()) {
                return back()->withErrors([
                    'email' => 'This is an admin account. Sign in at the admin portal instead: '.route('admin.login'),
                ])->withInput($request->only('email'));
            }

            return back()->withErrors([
                'email' => 'Access denied. This account is not active in any workspace.',
            ])->withInput($request->only('email'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->withInput($request->only('email'));
    }

    /**
     * Resolve a user by email (preferred) or legacy username.
     */
    protected function findUserByLogin(string $login): ?User
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $needle = strtolower($login);

        // Email logins: one indexed lookup. Username: single OR query.
        if (str_contains($login, '@')) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [$needle])
                ->first();
        }

        return User::query()
            ->where(function ($query) use ($needle) {
                $query->whereRaw('LOWER(email) = ?', [$needle])
                    ->orWhereRaw('LOWER(name) = ?', [$needle]);
            })
            ->first();
    }

    public function portalLogout(Request $request)
    {
        Auth::logout();
        $request->session()->forget('member_jwt');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    protected function issueMemberJwt(User $user, ?int $workspaceId = null): void
    {
        try {
            $token = app(MemberJwtService::class)->issue($user, $workspaceId);
            session([
                'member_jwt' => $token,
                'member_jwt_expires_at' => now()->addHours(app(MemberJwtService::class)->ttlHours())->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // Login must not fail if JWT secret is missing in a legacy env.
        }
    }

    public function showAcceptInvite(string $token)
    {
        return redirect()->route('portal.login')->with(
            'info',
            'Email invitations are no longer used. Log in with the email and password your workspace owner created for you.'
        );
    }

    public function acceptInvite(Request $request, string $token)
    {
        return redirect()->route('portal.login')->with(
            'info',
            'Email invitations are no longer used. Log in with the email and password your workspace owner created for you.'
        );
    }
}
