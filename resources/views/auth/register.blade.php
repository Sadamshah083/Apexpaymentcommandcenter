<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create workspace - {{ config('app.name') }}</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        warmgrey: {
                            50: '#f4f4f5',
                            100: '#e4e4e7',
                            200: '#d4d4d8',
                            500: '#71717a',
                            900: '#000000',
                        },
                        cream: {
                            50: '#ffffff',
                            100: '#f4f4f5',
                            200: '#e4e4e7',
                            300: '#d4d4d8',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #e4e4e7;
            /* cream-200 */
            min-height: 100vh;
            color: #000000;
            /* warmgrey-900 */
        }

        .glass-card {
            background: #ffffff;
            /* cream-50 */
            border: 2px solid #71717a;
            /* solid grey border */
            box-shadow: 4px 4px 0px 0px rgba(0, 0, 0, 0.08);
        }

        .input-glass {
            background: #ffffff;
            border: 2px solid #71717a;
            color: #000000;
            transition: all 0.3s ease;
        }

        .input-glass:focus {
            background: #ffffff;
            border-color: #7c7a72;
            /* warmgrey-500 */
            box-shadow: 0 0 0 2px rgba(124, 122, 114, 0.15);
            outline: none;
        }
    </style>
</head>

<body class="flex items-center justify-center p-4 md:p-8">
    <div class="w-full max-w-md space-y-6">

        <!-- Header -->
        <div class="text-center space-y-2">
            <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-warmgrey-900">{{ config('app.name') }}
            </h1>
            <p class="text-warmgrey-500 text-sm">Deploy an isolated workspace for your revenue team.</p>
        </div>

        <!-- Error Alerts -->
        @if ($errors->any())
            <div class="p-4 rounded-xl bg-rose-50 border border-rose-100 text-rose-800 text-xs space-y-1">
                @foreach ($errors->all() as $error)
                    <p class="flex items-center">
                        <svg class="w-4 h-4 mr-1.5 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                            </path>
                        </svg>
                        {{ $error }}
                    </p>
                @endforeach
            </div>
        @endif

        <!-- Card: Create Workspace -->
        <div class="glass-card rounded-3xl p-8 md:p-10 transition-all duration-300">
            <div class="space-y-6">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-warmgrey-900">Create workspace</h2>
                    <p class="text-warmgrey-500 text-xs mt-1">Configure your workspace and admin login below.</p>
                </div>

                <form method="POST" action="{{ route('admin.register-workspace') }}" class="space-y-4"
                    data-form-loading data-loading-title="Creating workspace"
                    data-loading-message="Setting up your workspace and admin account…"
                    data-loading-button-text="Creating…">
                    @csrf
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-warmgrey-500">workspace
                            name</label>
                        <input type="text" name="workspace_name" required placeholder="e.g. Acme Marketing"
                            value="{{ old('workspace_name') }}" class="w-full px-4 py-3 rounded-xl input-glass text-sm">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-warmgrey-500">admin
                            username</label>
                        <input type="text" name="admin_username" required placeholder="e.g. john_doe"
                            value="{{ old('admin_username') }}" class="w-full px-4 py-3 rounded-xl input-glass text-sm">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-warmgrey-500">admin
                            password</label>
                        <input type="password" name="admin_password" required placeholder="••••••••"
                            class="w-full px-4 py-3 rounded-xl input-glass text-sm">
                    </div>

                    <button type="submit"
                        class="w-full mt-6 py-3 bg-warmgrey-900 hover:bg-warmgrey-500 text-cream-50 font-bold rounded-xl shadow-md transition-all duration-300 transform hover:-translate-y-0.5">
                        Create
                    </button>
                </form>

                <div class="pt-4 border-t border-warmgrey-100 text-center">
                    <p class="text-xs text-warmgrey-500">
                        Already have a workspace user?
                        <a href="{{ route('admin.login') }}"
                            class="text-warmgrey-900 hover:underline font-semibold transition-colors">Login here</a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Footer Info -->
        <p class="text-center text-xs text-slate-600">© {{ date('Y') }} {{ config('app.name') }}. All rights
            reserved.</p>

    </div>
    @include('auth.partials.form-loading-assets')
</body>

</html>
