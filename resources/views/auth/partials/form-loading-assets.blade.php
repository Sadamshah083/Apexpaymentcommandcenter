<style>
    button[type="submit"]:disabled {
        opacity: 0.65;
        cursor: not-allowed;
        transform: none !important;
        pointer-events: none;
    }
</style>
@vite(['resources/css/app.css', 'resources/js/auth.js'])
