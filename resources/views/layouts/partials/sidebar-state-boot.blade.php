<script>
    (function () {
        try {
            if (window.matchMedia('(min-width: 1024px)').matches
                && localStorage.getItem('app-sidebar-collapsed') === '1') {
                document.body.classList.add('app-sidebar-collapsed');
            }
        } catch (e) {}
    })();
</script>
