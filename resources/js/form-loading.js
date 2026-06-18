import { showToast } from './toast.js';

const LOTTIE_SRC = 'https://assets2.lottiefiles.com/packages/lf20_usmfx6bp.json';

let overlayEl = null;

function getOverlay() {
    if (overlayEl) {
        return overlayEl;
    }

    overlayEl = document.createElement('div');
    overlayEl.id = 'app-loading-overlay';
    overlayEl.className = 'app-loading-overlay';
    overlayEl.setAttribute('role', 'alertdialog');
    overlayEl.setAttribute('aria-modal', 'true');
    overlayEl.setAttribute('aria-labelledby', 'app-loading-title');
    overlayEl.setAttribute('aria-busy', 'true');
    overlayEl.hidden = true;
    overlayEl.innerHTML = `
        <div class="app-loading-card">
            <div class="app-loading-animation" id="app-loading-lottie">
                <div class="app-loading-spinner" aria-hidden="true"></div>
            </div>
            <p id="app-loading-title" class="app-loading-title">Please wait</p>
            <p id="app-loading-message" class="app-loading-message">Processing…</p>
        </div>
    `;

    document.body.appendChild(overlayEl);

    return overlayEl;
}

async function mountLottie(container) {
    if (container.querySelector('dotlottie-player')) {
        return;
    }

    const spinner = container.querySelector('.app-loading-spinner');
    if (!spinner) {
        return;
    }

    try {
        if (!customElements.get('dotlottie-player')) {
            await import('https://unpkg.com/@dotlottie/player-component@2.7.12/dist/dotlottie-player.mjs');
        }

        const player = document.createElement('dotlottie-player');
        player.setAttribute('src', LOTTIE_SRC);
        player.setAttribute('background', 'transparent');
        player.setAttribute('speed', '1');
        player.setAttribute('loop', '');
        player.setAttribute('autoplay', '');
        player.className = 'app-loading-lottie-player';

        spinner.replaceWith(player);
    } catch {
        // CSS spinner fallback remains visible.
    }
}

export function showLoadingOverlay(message, title = 'Please wait') {
    const overlay = getOverlay();
    const titleEl = overlay.querySelector('#app-loading-title');
    const messageEl = overlay.querySelector('#app-loading-message');

    if (titleEl) {
        titleEl.textContent = title;
    }

    if (messageEl) {
        messageEl.textContent = message;
    }

    overlay.hidden = false;
    document.body.classList.add('app-loading-open');

    const lottieHost = overlay.querySelector('#app-loading-lottie');
    if (lottieHost) {
        mountLottie(lottieHost);
    }
}

function setSubmitButtonState(button, loadingText) {
    if (!button || button.dataset.loadingOriginalHtml) {
        return;
    }

    button.dataset.loadingOriginalHtml = button.innerHTML;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.classList.add('is-loading');

    if (loadingText) {
        button.textContent = loadingText;
    }
}

export function attachFormLoading(form) {
    if (!form || form.dataset.formLoadingAttached === '1') {
        return;
    }

    form.dataset.formLoadingAttached = '1';

    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }

        if (!form.checkValidity()) {
            return;
        }

        form.dataset.submitting = '1';

        const message = form.dataset.loadingMessage || 'Processing…';
        const title = form.dataset.loadingTitle || 'Please wait';
        const buttonText = form.dataset.loadingButtonText || 'Processing…';
        const submitButton = form.querySelector('button[type="submit"]');

        setSubmitButtonState(submitButton, buttonText);
        showLoadingOverlay(message, title);
        showToast(message, 'info', { title, duration: 120000 });
    });
}

export function initFormLoading() {
    document.querySelectorAll('form[data-form-loading]').forEach(attachFormLoading);
}
