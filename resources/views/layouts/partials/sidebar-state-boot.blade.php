<script>
    (function () {
        try {
            if (window.matchMedia('(min-width: 1024px)').matches
                && localStorage.getItem('app-sidebar-collapsed') === '1') {
                document.body.classList.add('app-sidebar-collapsed');
            }
        } catch (e) {}

        try {
            var theme = localStorage.getItem('apex-ui-theme')
                || localStorage.getItem('communications.dialer_theme')
                || 'light';
            theme = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.dataset.theme = theme;
            document.documentElement.dataset.commTheme = theme;
            document.documentElement.classList.toggle('theme-dark', theme === 'dark');
            document.documentElement.classList.toggle('theme-light', theme === 'light');
            if (document.body) {
                document.body.classList.toggle('theme-dark', theme === 'dark');
                document.body.classList.toggle('theme-light', theme === 'light');
                document.body.classList.toggle('comm-theme-dark', theme === 'dark');
                document.body.classList.toggle('comm-theme-light', theme === 'light');
            }
        } catch (e) {}
    })();
</script>
