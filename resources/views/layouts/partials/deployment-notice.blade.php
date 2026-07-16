@php
    $enabled = (bool) config('deployment.notice_enabled', false);
    $version = (string) config('deployment.notice_version', '1');
    $title = (string) config('deployment.notice_title', 'Deploying new features');
    $message = (string) config('deployment.notice_message', 'Please be patient while we deploy.');
@endphp

@auth
@if ($enabled)
<div id="deployment-notice-modal"
    class="deployment-notice"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="deployment-notice-title"
    data-deployment-notice
    data-deployment-notice-version="{{ $version }}">
    <div class="deployment-notice__backdrop" data-deployment-notice-dismiss></div>
    <div class="deployment-notice__card">
        <p class="deployment-notice__eyebrow">ApexOne Payment Command Center</p>
        <h2 id="deployment-notice-title" class="deployment-notice__title">{{ $title }}</h2>
        <p class="deployment-notice__body">{{ $message }}</p>
        <button type="button" class="app-btn app-btn-success deployment-notice__btn" data-deployment-notice-dismiss>
            Got it — thank you
        </button>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('[data-deployment-notice]');
    if (!root) return;
    var version = root.getAttribute('data-deployment-notice-version') || '1';
    var key = 'apex-deployment-notice:' + version;
    try {
        if (window.localStorage.getItem(key) === '1') return;
    } catch (e) {}
    root.hidden = false;
    document.documentElement.classList.add('deployment-notice-open');
    function dismiss() {
        root.hidden = true;
        document.documentElement.classList.remove('deployment-notice-open');
        try { window.localStorage.setItem(key, '1'); } catch (e) {}
    }
    root.querySelectorAll('[data-deployment-notice-dismiss]').forEach(function (el) {
        el.addEventListener('click', dismiss);
    });
})();
</script>
@endif
@endauth
