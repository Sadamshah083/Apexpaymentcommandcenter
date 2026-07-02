@extends('layouts.portal')

@section('title', 'Accept Workspace Invitation')

@section('content')
    <div class="max-w-md mx-auto mt-12 bg-white rounded-2xl shadow-sm border border-slate-100 p-8">
        <h1 class="text-2xl font-bold text-slate-800">Join {{ $invitation->workspace->name }}</h1>
        <p class="text-sm text-slate-500 mt-2">
            You were invited as <strong>{{ ucfirst($invitation->role) }}</strong>.
            Set your password to activate portal access.
        </p>

        <form method="POST" action="{{ route('portal.invite.store', $invitation->token) }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Username</label>
                <input type="text" name="username" value="{{ old('username', $invitation->username) }}"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Confirm Password</label>
                <input type="password" name="password_confirmation" required
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm">
            </div>
            <button type="submit"
                class="w-full py-3 bg-warmgrey-900 hover:bg-warmgrey-800 text-white font-bold rounded-xl">
                Activate Account
            </button>
        </form>
    </div>
@endsection
