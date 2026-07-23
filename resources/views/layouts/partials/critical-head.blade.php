{{-- Critical first-paint styles so the shell is visible before Vite CSS arrives. --}}
<style>
    html { color-scheme: light; }
    html.theme-dark { color-scheme: dark; }
    body.app-shell {
        margin: 0;
        min-height: 100vh;
        background: #f8fafc;
        color: #0f172a;
        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }
    html.theme-dark body.app-shell {
        background: #0b1220;
        color: #e2e8f0;
    }
    .app-content-shell { flex: 1; min-width: 0; }
    .app-main { min-height: 40vh; }
</style>
<meta name="description" content="{{ config('app.name') }} — operations command center.">
<meta name="robots" content="noindex, nofollow">
