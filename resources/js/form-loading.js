import { showToast } from './toast.js';

let overlayEl = null;
let activeForm = null;
const FORM_LOADING_BOUND_KEY = 'formLoadingGlobalBound';

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
            <button type="button" class="app-loading-close" id="app-loading-close" hidden aria-label="Cancel request">
                <span aria-hidden="true">&times;</span>
            </button>
            <div class="app-loading-animation" id="app-loading-lottie">
                <div class="app-loading-spinner" aria-hidden="true"></div>
            </div>
            <p id="app-loading-title" class="app-loading-title">Please wait</p>
            <p id="app-loading-message" class="app-loading-message">Processing…</p>
        </div>
    `;

    document.body.appendChild(overlayEl);
    overlayEl.querySelector('#app-loading-close')?.addEventListener('click', () => {
        const form = activeForm;
        hideLoadingOverlay();
        if (form) {
            document.dispatchEvent(new CustomEvent('app:loading-overlay-cancel', {
                detail: { form },
            }));
        }
    });

    return overlayEl;
}

export function hideLoadingOverlay() {
    const overlay = getOverlay();
    overlay.hidden = true;
    document.body.classList.remove('app-loading-open');
    overlay.querySelector('#app-loading-close')?.setAttribute('hidden', 'hidden');
    activeForm = null;

    document.querySelectorAll('form[data-form-loading][data-submitting="1"]').forEach((form) => {
        form.dataset.submitting = '0';
        const button = form.querySelector('button[type="submit"]');
        if (button?.dataset.loadingOriginalHtml) {
            button.innerHTML = button.dataset.loadingOriginalHtml;
            delete button.dataset.loadingOriginalHtml;
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.classList.remove('is-loading');
        }
    });
}

export function updateLoadingOverlay(message, title) {
    const overlay = getOverlay();
    const titleEl = overlay.querySelector('#app-loading-title');
    const messageEl = overlay.querySelector('#app-loading-message');

    if (titleEl && title) {
        titleEl.textContent = title;
    }

    if (messageEl && message) {
        messageEl.textContent = message;
    }
}

export function showLoadingOverlay(message, title = 'Please wait', options = {}) {
    const overlay = getOverlay();
    const titleEl = overlay.querySelector('#app-loading-title');
    const messageEl = overlay.querySelector('#app-loading-message');
    const closeBtn = overlay.querySelector('#app-loading-close');

    if (titleEl) {
        titleEl.textContent = title;
    }

    if (messageEl) {
        messageEl.textContent = message;
    }

    activeForm = options.form || null;
    if (closeBtn) {
        if (options.cancelable) {
            closeBtn.removeAttribute('hidden');
        } else {
            closeBtn.setAttribute('hidden', 'hidden');
        }
    }

    overlay.hidden = false;
    document.body.classList.add('app-loading-open');
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
        const cancelable = form.dataset.loadingCancelable === '1';

        setSubmitButtonState(submitButton, buttonText);
        showLoadingOverlay(message, title, { form, cancelable });
    });
}

export function initFormLoading() {
    document.querySelectorAll('form[data-form-loading]').forEach(attachFormLoading);

    if (document.documentElement.dataset[FORM_LOADING_BOUND_KEY] === '1') {
        return;
    }

    document.documentElement.dataset[FORM_LOADING_BOUND_KEY] = '1';

    document.addEventListener('turbo:submit-end', () => {
        hideLoadingOverlay();
    });
}
