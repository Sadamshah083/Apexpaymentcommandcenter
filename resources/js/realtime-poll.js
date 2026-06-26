/**
 * Visibility-aware JSON polling for long-running job progress UIs.
 * @returns {function} stop — call to cancel polling
 */
export function startProgressPoll(url, onData, options = {}) {
    const activeMs = options.activeMs ?? 2000;
    const hiddenMs = options.hiddenMs ?? 8000;
    const errorMs = options.errorMs ?? 4000;

    let timer = null;
    let stopped = false;
    let inflight = null;

    function schedule(ms) {
        if (stopped) {
            return;
        }
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(tick, ms);
    }

    async function tick() {
        if (stopped) {
            return;
        }

        if (inflight) {
            inflight.abort();
        }
        inflight = new AbortController();

        try {
            const response = await fetch(url, {
                signal: inflight.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                schedule(errorMs);
                return;
            }

            const data = await response.json();
            const shouldContinue = onData(data);

            if (shouldContinue === false || stopped) {
                return;
            }

            schedule(document.hidden ? hiddenMs : activeMs);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            schedule(errorMs);
        }
    }

    function onVisibilityChange() {
        if (!document.hidden && !stopped) {
            schedule(0);
        }
    }

    document.addEventListener('visibilitychange', onVisibilityChange);
    schedule(0);

    return function stop() {
        stopped = true;
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
        inflight?.abort();
        inflight = null;
        document.removeEventListener('visibilitychange', onVisibilityChange);
    };
}
