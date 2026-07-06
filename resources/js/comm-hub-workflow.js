/**
 * Syncs the 3-step dial workflow UI with webphone state.
 */
export function initCommHubWorkflow() {
    const workflow = document.querySelector('[data-comm-workflow]');
    if (!workflow) {
        return;
    }

    const steps = [...workflow.querySelectorAll('[data-workflow-step]')];
    const heroBadge = document.querySelector('[data-dial-hero-badge]');

    const applyStep = (activeStep) => {
        steps.forEach((el, index) => {
            const stepNum = index + 1;
            el.classList.remove('is-active', 'is-complete');
            if (stepNum < activeStep) {
                el.classList.add('is-complete');
            } else if (stepNum === activeStep) {
                el.classList.add('is-active');
            }
        });
    };

    const syncBadge = (state) => {
        if (!heroBadge) {
            return;
        }
        const live = ['registered', 'dialing', 'ringing', 'in-call', 'connecting'].includes(state);
        heroBadge.classList.toggle('ch-badge--live', live && state !== 'connecting');
        heroBadge.classList.toggle('ch-badge--offline', !live);
        heroBadge.classList.toggle('ch-badge--warn', state === 'connecting');
    };

    const onState = (state) => {
        const registered =
            state === 'registered' || state === 'dialing' || state === 'ringing' || state === 'in-call';
        const onCall = state === 'dialing' || state === 'ringing' || state === 'in-call';

        if (onCall) {
            applyStep(3);
        } else if (registered) {
            applyStep(2);
        } else {
            applyStep(1);
        }

        syncBadge(state);

        document.querySelectorAll('[data-webphone-status-text-header]').forEach((el) => {
            const main = document.querySelector('[data-webphone-status-text]');
            if (main) {
                el.textContent = main.textContent;
            }
        });
        document.querySelectorAll('[data-webphone-dot-header]').forEach((el) => {
            const main = document.querySelector('[data-webphone-dot]');
            if (main?.dataset.state) {
                el.dataset.state = main.dataset.state;
            }
        });
    };

    document.addEventListener('apex:webphone-state', (event) => {
        const { state, message } = event.detail ?? {};
        onState(state ?? 'offline');

        if (heroBadge) {
            const textEl = heroBadge.querySelector('[data-webphone-status-text]');
            const dotEl = heroBadge.querySelector('.ch-status-dot');
            if (textEl && message) {
                textEl.textContent = message;
            }
            if (dotEl) {
                dotEl.dataset.state = state ?? 'offline';
            }
        }
    });

    const panel = document.querySelector('[data-webphone-panel]');
    const initialDot = panel?.querySelector('[data-webphone-dot]');
    onState(initialDot?.dataset.state ?? 'offline');
}
