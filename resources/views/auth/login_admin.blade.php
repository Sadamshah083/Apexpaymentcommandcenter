<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ config('app.name') }} admin sign in.">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin sign in - {{ config('app.name') }}</title>
    <style>
        :root {
            --font-sans: 'Segoe UI Variable Text', 'Segoe UI Variable', 'Segoe UI', system-ui, -apple-system,
                BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
            --bg: #f0fdf4;
            --bg-accent: #dcfce7;
            --surface: rgba(255, 255, 255, 0.92);
            --ink: #052e16;
            --muted: #4d7c0f;
            --line: #bbf7d0;
            --brand: #22c55e;
            --brand-hover: #16a34a;
            --brand-text: #ffffff;
            --focus: rgba(34, 197, 94, 0.22);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font-sans);
            color: var(--ink);
            background:
                radial-gradient(900px 420px at 15% 0%, rgba(134, 239, 172, 0.42), transparent 55%),
                radial-gradient(700px 380px at 100% 10%, rgba(187, 247, 208, 0.58), transparent 50%),
                linear-gradient(rgba(22, 101, 52, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(22, 101, 52, 0.035) 1px, transparent 1px),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-accent) 100%);
            background-size: auto, auto, 56px 56px, 56px 56px, auto;
            display: grid;
            place-items: center;
            padding: 2rem;
            -webkit-font-smoothing: antialiased;
        }

        .shell {
            width: min(100%, 28rem);
        }

        .brand {
            text-align: center;
            margin-bottom: 1.1rem;
        }

        .brand h1 {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0.55rem 1.05rem;
            font-family: var(--font-sans);
            font-size: 1.08rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.3;
            color: #064e3b;
            background: rgba(255, 255, 255, 0.58);
            border: 1px solid rgba(187, 247, 208, 0.9);
            border-radius: 999px;
            box-shadow: 0 12px 30px rgba(20, 83, 45, 0.08);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 1.25rem;
            padding: 1.75rem;
            box-shadow: 0 22px 55px rgba(20, 83, 45, 0.12), 0 1px 2px rgba(20, 83, 45, 0.05);
        }

        .card h2 {
            margin: 0;
            font-size: 1.45rem;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -0.035em;
            color: var(--ink);
        }

        .card .sub {
            margin: 0.45rem 0 1.35rem;
            color: var(--muted);
            font-size: 0.875rem;
            line-height: 1.45;
        }

        .field { margin-top: 1rem; }

        label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #166534;
        }

        input {
            width: 100%;
            border: 1px solid var(--line);
            background: #f7fee7;
            border-radius: 0.85rem;
            padding: 0.82rem 0.95rem;
            font: inherit;
            font-size: 0.9375rem;
            color: var(--ink);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--brand);
            background: #fff;
            box-shadow: 0 0 0 3px var(--focus);
        }

        button[type="submit"] {
            width: 100%;
            margin-top: 1.35rem;
            border: 0;
            border-radius: 0.9rem;
            padding: 0.9rem 1rem;
            font: inherit;
            font-size: 0.9375rem;
            font-weight: 800;
            color: var(--brand-text);
            cursor: pointer;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            box-shadow: 0 14px 28px rgba(22, 163, 74, 0.24);
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        button[type="submit"]:hover {
            background: linear-gradient(135deg, #34d399, var(--brand-hover));
            box-shadow: 0 18px 34px rgba(22, 163, 74, 0.3);
            transform: translateY(-1px);
        }

        .errors {
            margin-bottom: 0.85rem;
            padding: 0.75rem 0.9rem;
            border-radius: 0.5rem;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            font-size: 0.875rem;
        }

        .copy {
            margin-top: 1rem;
            text-align: center;
            color: #15803d;
            font-size: 0.75rem;
        }

        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }

            .card {
                padding: 1.35rem;
                border-radius: 1rem;
            }

            .brand h1 {
                font-size: 1rem;
                padding-inline: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="brand">
            <h1>{{ config('app.name') }}</h1>
        </div>

        @if ($errors->any())
            <div class="errors">
                @foreach ($errors->all() as $error)
                    <p style="margin:0">{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="card">
            <h2>Admin Login</h2>
            <p class="sub">Sign in with your admin email. Admins can also open the agent portal with the same credentials.</p>

            <form method="POST" action="{{ route('admin.login') }}" data-admin-login data-form-loading
                data-turbo="false"
                data-login-prefetch="{{ route('admin.communications.index', \App\Support\AdminModules::communicationsDialerParams()) }}"
                data-loading-title="Signing in" data-loading-message="Verifying your admin credentials…"
                data-loading-button-text="Signing in…">
                @csrf
                <div class="field" style="margin-top:0">
                    <label for="admin-email">Email</label>
                    <input id="admin-email" type="text" name="email" required autocomplete="username"
                        inputmode="email" placeholder="admin@company.com" value="{{ old('email') }}">
                </div>
                <div class="field">
                    <label for="admin-password">Password</label>
                    <input id="admin-password" type="password" name="password" required autocomplete="current-password"
                        placeholder="••••••••">
                </div>
                <button type="submit">Sign in</button>
            </form>
        </div>

        <p class="copy">© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
    @include('auth.partials.form-loading-assets')
</body>
</html>
