import { hideLoadingOverlay } from './form-loading.js';
import { showToast } from './toast.js';
import { ensureWebphoneReady, markDialerClickToCallPending } from './communications-webphone.js';
const STORAGE_KEY = 'communications.dialer_extension';

function normalizePhone(value) {
    const digits = String(value || '').replace(/[^\d+]/g, '');
    if (!digits) {
        return '';
    }
    if (digits.startsWith('+')) {
        return digits;
    }
    const numeric = digits.replace(/^0+/, '');
    if (numeric.length === 10) {
        return `+1${numeric}`;
    }
    if (numeric.length <= 6) {
        return numeric;
    }

    return `+${numeric}`;
}

function refreshDialButton(numberInput, callerSelect, dialBtn) {
    if (!numberInput || !dialBtn || dialBtn.dataset.serverDisabled === '1') {
        return;
    }

    const hasNumber = normalizePhone(numberInput.value) !== '';
    const hasExtension = !callerSelect || callerSelect.value !== '';

    if (!hasNumber || !hasExtension) {
        dialBtn.setAttribute('disabled', 'disabled');
        dialBtn.classList.add('opacity-50', 'cursor-not-allowed');
        dialBtn.setAttribute('aria-disabled', 'true');

        return;
    }

    dialBtn.removeAttribute('disabled');
    dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    dialBtn.removeAttribute('aria-disabled');
}

function attachDialerForm(form) {
    if (!form || form.dataset.dialerInit === '1') {
        return;
    }

    form.dataset.dialerInit = '1';

    const numberInput = form.querySelector('[name="destination"]');
    const callerSelect = form.querySelector('[name="from_extension"]');
    const dialBtn = form.querySelector('button[type="submit"]');
    const backspace = form.querySelector('[data-dial-backspace]');
    const keypadRoot = form.querySelector('.ghl-dialer-keypad') || form;

    if (!numberInput || !dialBtn) {
        return;
    }

    if (dialBtn.hasAttribute('disabled') && dialBtn.disabled) {
        dialBtn.dataset.serverDisabled = '1';
    }

    if (callerSelect) {
        const savedCaller = localStorage.getItem(STORAGE_KEY);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }

        callerSelect.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEY, callerSelect.value || '');
            refreshDialButton(numberInput, callerSelect, dialBtn);
        });
    }

    const handleNumberChange = () => refreshDialButton(numberInput, callerSelect, dialBtn);

    numberInput.addEventListener('input', handleNumberChange);
    numberInput.addEventListener('change', handleNumberChange);

    keypadRoot.querySelectorAll('[data-dial-key]').forEach((button) => {
        button.addEventListener('click', () => {
            numberInput.value += button.getAttribute('data-dial-key') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    });

    document.querySelectorAll('[data-dial-number]').forEach((button) => {
        button.addEventListener('click', () => {
            numberInput.value = button.getAttribute('data-dial-number') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    });

    if (backspace) {
        backspace.addEventListener('click', () => {
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    }

    form.addEventListener('submit', async (event) => {
        numberInput.value = normalizePhone(numberInput.value) || numberInput.value;

        if (form.dataset.webphoneChecked === '1') {
            form.dataset.webphoneChecked = '';

            return;
        }

        if (document.querySelector('[data-webphone-panel]')) {
            event.preventDefault();

            const ready = await ensureWebphoneReady();
            if (!ready) {
                return;
            }

            markDialerClickToCallPending();
            form.dataset.webphoneChecked = '1';
            form.requestSubmit();
        }
    });

    handleNumberChange();
}

export function initCommunicationsDialer(root = document) {
    root.querySelectorAll('.ghl-dialer-originate-form').forEach(attachDialerForm);
}

export function resetDialerButtonsForCache() {
    document.querySelectorAll('.ghl-dialer-originate-form').forEach((form) => {
        delete form.dataset.dialerInit;

        const button = form.querySelector('button[type="submit"]');
        if (!button || button.dataset.serverDisabled === '1') {
            return;
        }

        button.removeAttribute('disabled');
        button.classList.remove('opacity-50', 'cursor-not-allowed');
        button.removeAttribute('aria-disabled');
    });
}

function initDialerSubmitFeedback() {
    if (document.documentElement.dataset.dialerSubmitFeedbackInit === '1') {
        return;
    }

    document.documentElement.dataset.dialerSubmitFeedbackInit = '1';

    document.addEventListener('turbo:submit-end', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.ghl-dialer-originate-form')) {
            return;
        }

        hideLoadingOverlay();

        if (event.detail.success) {
            return;
        }

        showToast('Could not place the call. Check your extension and try again.', 'error');
    });
}

export function bootCommunicationsDialer() {
    initCommunicationsDialer();
    initDialerSubmitFeedback();
}

// Backwards compatibility for any inline callers still present in cached pages.
window.initGhlDialer = function initGhlDialer(config) {
    const numberInput = document.getElementById(config.numberInputId);
    const form = numberInput?.closest('.ghl-dialer-originate-form');
    if (form) {
        attachDialerForm(form);
    }
};
