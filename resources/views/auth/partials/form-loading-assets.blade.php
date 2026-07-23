<style>
    button[type="submit"]:disabled {
        opacity: 0.65;
        cursor: not-allowed;
        transform: none !important;
        pointer-events: none;
    }

    body.app-loading-open {
        overflow: hidden;
    }

    .app-loading-overlay {
        position: fixed !important;
        inset: 0 !important;
        z-index: 11000 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 1.5rem !important;
        background: rgba(240, 253, 244, 0.9) !important;
    }

    .app-loading-overlay[hidden] {
        display: none !important;
    }

    .app-loading-card {
        position: relative !important;
        width: auto !important;
        max-width: 18rem !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        text-align: center !important;
    }

    .app-loading-close {
        display: none !important;
    }

    .app-loading-animation {
        min-height: 0 !important;
        margin-bottom: 1rem !important;
    }

    .app-loading-spinner {
        width: 2.75rem !important;
        height: 2.75rem !important;
        border: 3px solid #bbf7d0 !important;
        border-top-color: #16a34a !important;
        border-radius: 9999px !important;
        animation: app-loading-spin 0.8s linear infinite !important;
    }

    .app-loading-title {
        margin: 0 !important;
        font-size: 1.05rem !important;
        font-weight: 800 !important;
        color: #14532d !important;
    }

    .app-loading-message {
        margin: 0.4rem 0 0 !important;
        font-size: 0.875rem !important;
        line-height: 1.45 !important;
        color: #3f6212 !important;
    }

    @keyframes app-loading-spin {
        to { transform: rotate(360deg); }
    }
</style>
{{-- Auth pages: tiny JS only — never pull app.css (~300KB) on login. --}}
@vite(['resources/js/auth.js'])
<script>
(() => {
    // Prefetch post-login destination while credentials are checked.
    document.querySelectorAll('form[data-form-loading][data-login-prefetch]').forEach((form) => {
        form.addEventListener('submit', () => {
            const href = form.getAttribute('data-login-prefetch');
            if (!href) return;
            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.as = 'document';
            link.href = href;
            document.head.appendChild(link);
        }, { once: true });
    });
})();
</script>
