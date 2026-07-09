import { hideLoadingOverlay } from './form-loading.js';
import { showToast } from './toast.js';
import {
    cancelPendingWebphoneConnect,
    ensureWebphoneReady,
    getWebphone,
} from './communications-webphone.js';
import { initCommunicationsPhoneNotes } from './communications-phone-notes.js';
const STORAGE_KEY = 'communications.dialer_extension';
const DIAL_COOLDOWN_MS = 12000;
let lastDialAt = 0;
let dialInFlight = false;

function stripCarrierPrefix(value) {
    const raw = String(value || '').trim();
    if (raw.includes('#')) {
        return raw.slice(raw.lastIndexOf('#') + 1).trim();
    }

    return raw;
}

function isValidPstnDestination(value) {
    const normalized = normalizePhone(value);
    const digits = normalized.replace(/\D/g, '');

    return digits.length >= 10;
}

function normalizePhone(value) {
    const digits = String(stripCarrierPrefix(value)).replace(/[^\d+]/g, '');
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

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function resolveExtensionSelect(form) {
    if (!form) {
        return document.getElementById('dial-caller-id-global');
    }

    const inFormSelect = form.querySelector('select[name="from_extension"]');
    if (inFormSelect) {
        return inFormSelect;
    }

    if (form.id) {
        const linkedSelect = document.querySelector(`select[name="from_extension"][form="${form.id}"]`);
        if (linkedSelect) {
            return linkedSelect;
        }
    }

    return document.getElementById('dial-caller-id-global');
}

function getDialerFormNumberInput(form) {
    return form?.querySelector('[name="destination"]') || null;
}

function handleDialerNumberChange(form) {
    if (!form) {
        return;
    }

    const numberInput = getDialerFormNumberInput(form);
    const callerSelect = resolveExtensionSelect(form);
    const dialBtn = form.querySelector('button[type="submit"]');
    const backspace = form.querySelector('[data-dial-backspace]');

    refreshDialButton(numberInput, callerSelect, dialBtn);
    refreshBackspaceVisibility(numberInput, backspace);
}

function updateDialerRouteSummary(callerSelect) {
    const summary = document.querySelector('[data-dialer-from-did]');
    if (!summary) {
        return;
    }

    const selected = callerSelect?.selectedOptions?.[0];
    const raw = selected?.dataset?.outboundDid || '';
    const digits = String(raw).replace(/\D/g, '');

    if (digits.length >= 10) {
        summary.textContent = digits.length === 10 ? `+1${digits}` : `+${digits}`;

        return;
    }

    summary.textContent = raw || 'Not configured';
}

async function logDirectOutbound(form, destination) {
    const formData = new FormData(form);
    const fromExtension = formData.get('from_extension');

    try {
        await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                destination,
                from_extension: fromExtension,
                webphone_direct: true,
            }),
        });
    } catch {
        // History logging is best-effort for browser-originated calls.
    }
}

async function originateViaWebphone(form, dialBtn) {
    if (dialInFlight) {
        showToast('A call is already being placed. Please wait.', 'warning');

        return false;
    }

    const sinceLastDial = Date.now() - lastDialAt;
    if (sinceLastDial < DIAL_COOLDOWN_MS) {
        const waitSec = Math.ceil((DIAL_COOLDOWN_MS - sinceLastDial) / 1000);
        showToast(`Wait ${waitSec}s before placing another call on this line.`, 'warning');

        return false;
    }

    const destination = normalizePhone(new FormData(form).get('destination'));

    if (!destination) {
        showToast('Enter a valid phone number first.', 'error');

        return false;
    }

    if (!isValidPstnDestination(destination)) {
        showToast('Enter a full phone number with at least 10 digits (e.g. +12722001232).', 'error');

        return false;
    }

    hideLoadingOverlay();

    const phone = getWebphone();
    dialInFlight = true;
    lastDialAt = Date.now();

    try {
        await phone.dial(destination);
        await logDirectOutbound(form, destination);
        showToast(`Calling ${destination} from your browser line — the destination phone will ring.`, 'success');

        return true;
    } catch (error) {
        showToast(error.message || 'Could not place the call from your browser line.', 'error');

        return false;
    } finally {
        dialInFlight = false;
        hideLoadingOverlay();
        if (dialBtn) {
            dialBtn.removeAttribute('disabled');
            dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            dialBtn.removeAttribute('aria-disabled');
        }
    }
}

async function originateViaJson(form, dialBtn) {
    if (dialInFlight) {
        showToast('A call is already being placed. Please wait.', 'warning');

        return false;
    }

    const sinceLastDial = Date.now() - lastDialAt;
    if (sinceLastDial < DIAL_COOLDOWN_MS) {
        const waitSec = Math.ceil((DIAL_COOLDOWN_MS - sinceLastDial) / 1000);
        showToast(`Wait ${waitSec}s before placing another call on this line.`, 'warning');

        return false;
    }

    const formData = new FormData(form);
    const destination = normalizePhone(formData.get('destination'));

    if (!destination) {
        showToast('Enter a valid phone number first.', 'error');

        return false;
    }

    if (!isValidPstnDestination(destination)) {
        showToast('Enter a full phone number with at least 10 digits (e.g. +12722001232).', 'error');

        return false;
    }

    formData.set('destination', destination);
    hideLoadingOverlay();

    const phone = getWebphone();
    phone.setCustomerFirstOutbound(false);
    phone.setCallContext('outbound', destination);
    phone.clickToCallActive = true;
    phone.awaitingDestinationBridge = true;
    phone.markClickToCallPending();

    try {
        await phone.assertTransportForOriginate();
    } catch (error) {
        showToast(error?.message || 'Connect your Phone line (WebSocket) before calling.', 'error');
        phone.clickToCallActive = false;
        phone.awaitingDestinationBridge = false;
        if (dialBtn) {
            dialBtn.removeAttribute('disabled');
            dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            dialBtn.removeAttribute('aria-disabled');
        }

        return false;
    }

    formData.set('webphone_transport_connected', '1');
    dialInFlight = true;
    lastDialAt = Date.now();

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: formData,
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok === false) {
            const message = data.extension_busy
                ? (data.error || 'Your extension is busy. Click Connect line again, or try extension 1001.')
                : (data.error || 'Could not place the call. Check your extension and try again.');

            showToast(message, 'error');
            phone.clickToCallActive = false;
            phone.awaitingDestinationBridge = false;

            return false;
        }

        if (data.call_uuid) {
            phone.setMorpheusCallUuid(data.call_uuid);
        }

        if (data.line_reset) {
            showToast('Line was reset to clear a busy extension — reconnecting…', 'warning');
            await phone.connect(phone.currentExtension || undefined).catch(() => {});
            phone.markClickToCallPending();
        }

        const dialTarget =
            data.to && String(data.to).length >= 10
                ? `+${String(data.to).replace(/\D/g, '')}`
                : destination;

        phone.setCustomerFirstOutbound(Boolean(data.customer_first));
        phone.showClickToCallRinging(dialTarget, { customerFirst: Boolean(data.customer_first) });

        const fromExt = data.from ? String(data.from) : formData.get('from_extension');
        if (fromExt) {
            phone.syncSelectedExtension();
        }

        const successMessage =
            data.outcome === 'connected'
                ? 'Call connected.'
                : data.outcome === 'no_answer'
                  ? 'Call placed but your line did not answer in time.'
                  : data.customer_first
                    ? 'Your phone is ringing — answer within 90 seconds. Keep Connect line on.'
                    : 'Connecting your line… the destination will ring once your browser phone answers.';

        showToast(data.warning || successMessage, data.warning ? 'warning' : 'success');

        return true;
    } catch (error) {
        phone.clickToCallActive = false;
        phone.awaitingDestinationBridge = false;
        showToast(error.message || 'Could not place the call.', 'error');

        return false;
    } finally {
        dialInFlight = false;
        hideLoadingOverlay();
        if (dialBtn) {
            dialBtn.removeAttribute('disabled');
            dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            dialBtn.removeAttribute('aria-disabled');
        }
    }
}

function refreshDialButton(numberInput, callerSelect, dialBtn) {
    if (!numberInput || !dialBtn || dialBtn.dataset.serverDisabled === '1') {
        return;
    }

    const hasNumber = isValidPstnDestination(numberInput.value);
    const extensionSelect = callerSelect || document.getElementById('dial-caller-id-global');
    const hasExtension = !extensionSelect || extensionSelect.value !== '';

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

function initDialerOverlayCancel() {
    if (document.documentElement.dataset.dialerOverlayCancelInit === '1') {
        return;
    }

    document.documentElement.dataset.dialerOverlayCancelInit = '1';
    document.addEventListener('app:loading-overlay-cancel', (event) => {
        const form = event.detail?.form;
        if (!(form instanceof HTMLFormElement) || !form.matches('.ghl-dialer-originate-form')) {
            return;
        }

        form.dataset.dialerConnectCancelled = '1';
        form.dataset.dialerConnectPending = '0';
        form.dataset.webphoneChecked = '';
        cancelPendingWebphoneConnect();
    });
}

function syncHiddenExtensionFields() {
    const global = document.getElementById('dial-caller-id-global');
    const value = global?.value || document.querySelector('[name="from_extension"]')?.value || '';

    document.querySelectorAll('[data-dial-extension-sync]').forEach((field) => {
        field.value = value;
    });
}

function refreshBackspaceVisibility(numberInput, backspace) {
    if (!numberInput || !backspace) {
        return;
    }

    const show = (numberInput.value || '').trim().length > 0;
    backspace.classList.toggle('is-hidden', !show);
}

function parseLineOption(select, value) {
    const option = Array.from(select.options).find((entry) => entry.value === value);
    if (!option || !value) {
        return null;
    }

    const raw = (option.textContent || '').trim();
    const parts = raw.split('·').map((part) => part.trim()).filter(Boolean);
    const did =
        option.dataset.outboundDid ||
        option.getAttribute('data-outbound-did') ||
        parts[1] ||
        '';

    return {
        extension: value,
        did,
    };
}

function syncLineDropdownFromSelect(select) {
    const wrapper = select?.closest('[data-line-dropdown]');
    if (!wrapper) {
        return;
    }

    const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
    const content = wrapper.querySelector('.ghl-line-dropdown__trigger-content');
    const selected = parseLineOption(select, select.value);

    wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
        const isSelected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', isSelected);
        button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });

    if (!content) {
        return;
    }

    if (!selected) {
        content.innerHTML = '<span class="ghl-line-dropdown__placeholder">Select line</span>';
        return;
    }

    content.innerHTML = `
        <span class="ghl-line-dropdown__ext-badge">Ext ${selected.extension}</span>
        ${selected.did ? `<span class="ghl-line-dropdown__did">${selected.did}</span>` : ''}
    `;
}

function closeLineDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
    const menu = wrapper.querySelector('.ghl-line-dropdown__menu');
    const search = wrapper.querySelector('.ghl-line-dropdown__search');

    wrapper.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (menu) {
        menu.hidden = true;
    }
    if (search) {
        search.value = '';
        wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
            button.classList.remove('is-hidden');
        });
    }
}

function closeAllLineDropdowns(except = null) {
    document.querySelectorAll('[data-line-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeLineDropdown(wrapper);
        }
    });
}

function filterLineDropdown(wrapper, query) {
    const needle = (query || '').trim().toLowerCase();

    wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
        const haystack = button.dataset.search || button.textContent || '';
        button.classList.toggle('is-hidden', needle !== '' && !haystack.toLowerCase().includes(needle));
    });
}

function initLineDropdowns(root = document) {
    const scope = root === document ? document : root;

    scope.querySelectorAll('[data-line-dropdown]').forEach((wrapper) => {
        if (wrapper.dataset.lineDropdownBound === '1') {
            syncLineDropdownFromSelect(wrapper.querySelector('.ghl-line-dropdown__native'));
            return;
        }

        wrapper.dataset.lineDropdownBound = '1';

        const select = wrapper.querySelector('.ghl-line-dropdown__native');
        const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
        const menu = wrapper.querySelector('.ghl-line-dropdown__menu');
        const search = wrapper.querySelector('.ghl-line-dropdown__search');

        if (!select || !trigger || !menu) {
            return;
        }

        syncLineDropdownFromSelect(select);

        select.addEventListener('change', () => {
            syncLineDropdownFromSelect(select);
        });

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !wrapper.classList.contains('is-open');
            closeAllLineDropdowns(willOpen ? wrapper : null);

            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;

            if (willOpen && search) {
                search.focus();
            }
        });

        if (search) {
            search.addEventListener('input', () => {
                filterLineDropdown(wrapper, search.value);
            });

            search.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeLineDropdown(wrapper);
                    trigger.focus();
                }
            });
        }

        menu.querySelectorAll('[data-line-option]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                select.value = button.dataset.value || select.value;
                syncLineDropdownFromSelect(select);
                closeLineDropdown(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    if (document.body.dataset.lineDropdownGlobalBound === '1') {
        return;
    }

    document.body.dataset.lineDropdownGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-line-dropdown]')) {
            return;
        }

        closeAllLineDropdowns();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllLineDropdowns();
        }
    });
}

function initGlobalExtensionSync(root = document) {
    const global = root.getElementById?.('dial-caller-id-global') || document.getElementById('dial-caller-id-global');
    if (!global || global.dataset.globalExtInit === '1') {
        return;
    }

    global.dataset.globalExtInit = '1';

    const savedCaller = localStorage.getItem(STORAGE_KEY);
    if (savedCaller) {
        const match = Array.from(global.options).find((option) => option.value === savedCaller);
        if (match) {
            global.value = savedCaller;
        }
    }

    syncLineDropdownFromSelect(global);

    global.addEventListener('change', () => {
        localStorage.setItem(STORAGE_KEY, global.value || '');
        syncHiddenExtensionFields();
        updateDialerRouteSummary(global);
        getWebphone().syncSelectedExtension();
        document.querySelectorAll('.ghl-dialer-originate-form').forEach((form) => {
            const numberInput = form.querySelector('[name="destination"]');
            const dialBtn = form.querySelector('button[type="submit"]');
            const backspace = form.querySelector('[data-dial-backspace]');
            refreshDialButton(numberInput, global, dialBtn);
            refreshBackspaceVisibility(numberInput, backspace);
        });
    });

    syncHiddenExtensionFields();
    updateDialerRouteSummary(global);
}

function attachDialerForm(form) {
    if (!form || form.dataset.dialerInit === '1') {
        return;
    }

    form.dataset.dialerInit = '1';

    const numberInput = getDialerFormNumberInput(form);
    const callerSelect = resolveExtensionSelect(form);
    const dialBtn = form.querySelector('button[type="submit"]');

    if (!numberInput || !dialBtn) {
        return;
    }

    if (dialBtn.hasAttribute('disabled') && dialBtn.disabled) {
        dialBtn.dataset.serverDisabled = '1';
    }

    if (callerSelect?.tagName === 'SELECT') {
        const savedCaller = localStorage.getItem(STORAGE_KEY);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }

        callerSelect.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEY, callerSelect.value || '');
            handleDialerNumberChange(form);
            getWebphone().syncSelectedExtension();
            updateDialerRouteSummary(callerSelect);
            syncHiddenExtensionFields();
        });
    }

    updateDialerRouteSummary(callerSelect);

    const handleNumberChange = () => {
        handleDialerNumberChange(form);
    };

    numberInput.addEventListener('input', handleNumberChange);
    numberInput.addEventListener('change', handleNumberChange);

    form.addEventListener('submit', async (event) => {
        syncHiddenExtensionFields();
        numberInput.value = normalizePhone(numberInput.value) || numberInput.value;

        if (form.dataset.webphoneChecked === '1') {
            form.dataset.webphoneChecked = '';
            form.dataset.dialerConnectPending = '0';
            form.dataset.dialerConnectCancelled = '0';

            return;
        }

        if (document.querySelector('[data-webphone-panel]')) {
            event.preventDefault();
            hideLoadingOverlay();

            if (form.dataset.dialerConnectPending === '1') {
                return;
            }

            form.dataset.dialerConnectPending = '1';
            form.dataset.dialerConnectCancelled = '0';

            dialBtn.setAttribute('disabled', 'disabled');
            dialBtn.classList.add('opacity-50', 'cursor-not-allowed');
            dialBtn.setAttribute('aria-disabled', 'true');

            const ready = await ensureWebphoneReady({ silent: true });
            const wasCancelled = form.dataset.dialerConnectCancelled === '1';
            form.dataset.dialerConnectPending = '0';
            form.dataset.dialerConnectCancelled = '0';

            if (wasCancelled) {
                hideLoadingOverlay();
                dialBtn.removeAttribute('disabled');
                dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                dialBtn.removeAttribute('aria-disabled');

                return;
            }

            if (form.dataset.originateJson === '1') {
                const destination = normalizePhone(new FormData(form).get('destination'));
                const phone = getWebphone();

                if (!ready || !phone.canDirectDial()) {
                    showToast(
                        'Connect your browser line first — pick your extension, click Connect line in the Phone panel, then try again.',
                        'error',
                    );
                    dialBtn.removeAttribute('disabled');
                    dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    dialBtn.removeAttribute('aria-disabled');

                    return;
                }

                // PSTN must use Morpheus click-to-call API (applies trunk routing + tech prefix).
                // Browser INVITE to sip:number@apexone.morpheus.cx returns 404 NO_ROUTE_DESTINATION.
                if (isValidPstnDestination(destination)) {
                    await originateViaJson(form, dialBtn);

                    return;
                }

                const dialMode = phone.config?.dial_mode || 'api';
                const preferSip = dialMode === 'sip' && form.dataset.dialViaSip !== '0';

                if (dialMode === 'api') {
                    await originateViaJson(form, dialBtn);

                    return;
                }

                if (preferSip) {
                    const sipOk = await originateViaWebphone(form, dialBtn);
                    if (sipOk) {
                        return;
                    }

                    const rejectDetail = phone.lastInviteReject || 'INVITE rejected';
                    showToast(
                        `Browser SIP dial failed (${rejectDetail}). Open DevTools → Network → Socket → wss://apexone.morpheus.cx:7443 and check the INVITE response. Server click-to-call cannot ring ext ${phone.currentExtension || 'your line'} while Connect line is active.`,
                        'error',
                    );

                    return;
                }

                await originateViaJson(form, dialBtn);

                return;
            }

            hideLoadingOverlay();
            form.dataset.webphoneChecked = '1';
            form.requestSubmit();
        }
    });

    handleNumberChange();
}

export function initCommunicationsDialer(root = document) {
    initLineDropdowns(root);
    initGlobalExtensionSync(root);
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

function getActiveDialerForm() {
    return document.querySelector('.ghl-comm-phone-mode .ghl-dialer-originate-form')
        || document.querySelector('.ghl-comm-dial-rail .ghl-dialer-originate-form')
        || document.querySelector('.ghl-dialer-originate-form');
}

function initDialerKeypadDelegation() {
    if (document.documentElement.dataset.dialerKeypadDelegated === '1') {
        return;
    }

    document.documentElement.dataset.dialerKeypadDelegated = '1';

    document.addEventListener('click', (event) => {
        const keyBtn = event.target.closest('[data-dial-key]');
        if (keyBtn) {
            const form = keyBtn.closest('.ghl-dialer-originate-form');
            const numberInput = getDialerFormNumberInput(form);
            if (!form || !numberInput || numberInput.disabled) {
                return;
            }

            event.preventDefault();
            numberInput.value += keyBtn.getAttribute('data-dial-key') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleDialerNumberChange(form);

            return;
        }

        const backspaceBtn = event.target.closest('[data-dial-backspace]');
        if (backspaceBtn && !backspaceBtn.classList.contains('is-hidden')) {
            const form = backspaceBtn.closest('.ghl-dialer-originate-form');
            const numberInput = getDialerFormNumberInput(form);
            if (!form || !numberInput || numberInput.disabled) {
                return;
            }

            event.preventDefault();
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleDialerNumberChange(form);

            return;
        }

        const redialBtn = event.target.closest('[data-dial-number]');
        if (!redialBtn) {
            return;
        }

        const form = getActiveDialerForm();
        const numberInput = getDialerFormNumberInput(form);
        if (!form || !numberInput || numberInput.disabled) {
            return;
        }

        event.preventDefault();
        numberInput.value = redialBtn.getAttribute('data-dial-number') || '';
        numberInput.dispatchEvent(new Event('input', { bubbles: true }));
        handleDialerNumberChange(form);
        numberInput.focus();
    });
}

export function bootCommunicationsDialer() {
    initDialerOverlayCancel();
    initDialerKeypadDelegation();
    initCommunicationsDialer();
    initDialerSubmitFeedback();
    initPhoneLogRecording();
    initPhonePanelSwitch();
    initCallLogsInfiniteScroll();
    initCommunicationsPhoneNotes();
}

function setPhoneWorkspaceView(workspace, view) {
    if (!workspace) {
        return;
    }

    const nextView = view === 'logs' ? 'logs' : 'dialer';
    workspace.dataset.phoneView = nextView;

    const switcher = workspace.querySelector('[data-phone-panel-switch]');
    switcher?.querySelectorAll('[data-phone-panel-view]').forEach((btn) => {
        const active = btn.dataset.phonePanelView === nextView;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
}

function initPhonePanelSwitch(root = document) {
    const scope = root === document ? document : root;
    const workspaces = scope.querySelectorAll('[data-phone-workspace]');
    if (!workspaces.length) {
        return;
    }

    workspaces.forEach((workspace) => {
        if (workspace.dataset.phonePanelSwitchInit === '1') {
            return;
        }

        workspace.dataset.phonePanelSwitchInit = '1';

        const switcher = workspace.querySelector('[data-phone-panel-switch]');
        if (!switcher || switcher.dataset.phonePanelSwitchBound === '1') {
            return;
        }

        switcher.dataset.phonePanelSwitchBound = '1';

        switcher.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-phone-panel-view]');
            if (!btn) {
                return;
            }

            event.preventDefault();
            setPhoneWorkspaceView(workspace, btn.dataset.phonePanelView);
            initCallLogsInfiniteScroll(workspace);
        });

        if (!workspace.dataset.phoneView) {
            setPhoneWorkspaceView(workspace, 'dialer');
        }
    });
}

function initPhoneLogRecording(root = document) {
    const scope = root === document ? document : root;
    const dialPane = scope.querySelector('[data-phone-dial-pane]');
    const recordingPane = scope.querySelector('[data-phone-recording-pane]');
    if (!dialPane || !recordingPane) {
        return;
    }

    if (dialPane.dataset.phoneLogRecordingInit === '1') {
        return;
    }
    dialPane.dataset.phoneLogRecordingInit = '1';

    const metaEl = recordingPane.querySelector('[data-phone-recording-meta]');
    const audioEl = recordingPane.querySelector('[data-phone-recording-audio]');
    const emptyEl = recordingPane.querySelector('[data-phone-recording-empty]');
    const actionsEl = recordingPane.querySelector('[data-phone-recording-actions]');
    const downloadEl = recordingPane.querySelector('[data-phone-recording-download]');
    const backBtn = recordingPane.querySelector('[data-phone-back-dialer]');
    let objectUrl = null;
    let activeRow = null;

    const revokeObjectUrl = () => {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    };

    const showDialer = () => {
        recordingPane.classList.add('hidden');
        recordingPane.hidden = true;
        dialPane.classList.remove('hidden');
        if (audioEl) {
            audioEl.pause();
            audioEl.removeAttribute('src');
            audioEl.classList.add('hidden');
        }
        revokeObjectUrl();
        activeRow?.classList.remove('is-active');
        activeRow = null;
    };

    const showRecording = async (row) => {
        activeRow?.classList.remove('is-active');
        activeRow = row;
        row.classList.add('is-active');

        const direction = row.dataset.logDirection || 'call';
        const phone = row.dataset.logPhone || '—';
        const extension = row.dataset.logExtension || '';
        const result = row.dataset.logResult || '—';
        const time = row.dataset.logTime || '—';
        let hasRecording = row.dataset.hasRecording === '1';
        let playUrl = row.dataset.playUrl || '';
        let downloadUrl = row.dataset.downloadUrl || '';
        let recordingStatus = row.dataset.recordingStatus || 'none';
        const callLogRef = row.dataset.logCallRef || '';

        if (metaEl) {
            metaEl.innerHTML = `
                <p class="ghl-phone-recording-meta__line"><strong>${direction.charAt(0).toUpperCase() + direction.slice(1)}</strong>${extension ? ` · Ext ${extension}` : ''}</p>
                <p class="ghl-phone-recording-meta__number">${phone}</p>
                <p class="ghl-phone-recording-meta__sub">${time} · ${result}</p>
            `;
        }

        setPhoneWorkspaceView(dialPane.closest('[data-phone-workspace]'), 'dialer');

        dialPane.classList.add('hidden');
        recordingPane.classList.remove('hidden');
        recordingPane.hidden = false;

        const setEmptyMessage = (message) => {
            if (emptyEl) {
                emptyEl.textContent = message;
            }
        };

        const updateRecordingUi = () => {
            const showPlayer = hasRecording && playUrl;
            emptyEl?.classList.toggle('hidden', showPlayer);
            actionsEl?.classList.toggle('hidden', !showPlayer);
            audioEl?.classList.toggle('hidden', !showPlayer);

            if (!showPlayer) {
                if (recordingStatus === 'pending' || recordingStatus === 'none') {
                    setEmptyMessage('Saving call recording… Morpheus may take a minute to finalize analog recordings.');
                } else if (recordingStatus === 'unavailable') {
                    setEmptyMessage('No recording is available for this call.');
                } else {
                    setEmptyMessage('No recording is available for this call yet.');
                }
            }
        };

        updateRecordingUi();

        const syncUrl = dialPane.closest('[data-phone-workspace]')?.dataset.recordingSyncUrl
            || scope.querySelector('[data-recording-sync-url]')?.dataset.recordingSyncUrl
            || document.querySelector('[data-recording-sync-url]')?.getAttribute('data-recording-sync-url');

        if ((!hasRecording || !playUrl) && callLogRef && syncUrl) {
            try {
                const syncResponse = await fetch(syncUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ call_log_ref: callLogRef }),
                });

                if (syncResponse.ok) {
                    const payload = await syncResponse.json();
                    hasRecording = Boolean(payload.has_recording);
                    playUrl = payload.play_url || '';
                    downloadUrl = payload.download_url || '';
                    row.dataset.hasRecording = hasRecording ? '1' : '0';
                    row.dataset.playUrl = playUrl;
                    row.dataset.downloadUrl = downloadUrl;
                    row.dataset.recordingStatus = payload.recording_status || recordingStatus;
                    recordingStatus = row.dataset.recordingStatus;
                    updateRecordingUi();
                }
            } catch {
                // Keep empty-state messaging.
            }
        }

        if (!hasRecording || !playUrl || !audioEl) {
            return;
        }

        revokeObjectUrl();
        audioEl.pause();
        audioEl.removeAttribute('src');

        try {
            const response = await fetch(playUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'audio/*,*/*' },
            });

            if (!response.ok) {
                emptyEl?.classList.remove('hidden');
                audioEl.classList.add('hidden');
                actionsEl?.classList.add('hidden');
                return;
            }

            const blob = await response.blob();
            if (!blob.size) {
                emptyEl?.classList.remove('hidden');
                audioEl.classList.add('hidden');
                actionsEl?.classList.add('hidden');
                return;
            }

            objectUrl = URL.createObjectURL(blob);
            audioEl.src = objectUrl;
            audioEl.classList.remove('hidden');
            await audioEl.play().catch(() => {});
        } catch {
            emptyEl?.classList.remove('hidden');
            audioEl.classList.add('hidden');
            actionsEl?.classList.add('hidden');
        }

        if (downloadEl && (row.dataset.downloadUrl || downloadUrl)) {
            downloadEl.href = row.dataset.downloadUrl || downloadUrl;
        }
    };

    scope.querySelectorAll('[data-call-logs-list]').forEach((list) => {
        list.addEventListener('click', (event) => {
            const row = event.target.closest('[data-phone-log-row]');
            if (!row || !list.contains(row)) {
                return;
            }
            if (event.target.closest('[data-dial-number]')) {
                return;
            }
            if (event.target.closest('[data-log-notes-toggle], [data-log-notes-panel], [data-log-notes-save]')) {
                return;
            }
            showRecording(row);
        });

        list.addEventListener('keydown', (event) => {
            const row = event.target.closest('[data-phone-log-row]');
            if (!row || !list.contains(row)) {
                return;
            }
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (event.target.closest('[data-dial-number]')) {
                    return;
                }
                if (event.target.closest('[data-log-notes-toggle], [data-log-notes-panel], [data-log-notes-save]')) {
                    return;
                }
                showRecording(row);
            }
        });
    });

    backBtn?.addEventListener('click', showDialer);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function buildCallLogRow(log) {
    const direction = escapeHtml(log.direction || 'call');
    const directionLabel = direction.charAt(0).toUpperCase() + direction.slice(1);
    const extension = log.extension ? `<span class="ghl-dialer-recent-ext">Ext ${escapeHtml(log.extension)}</span>` : '';
    const phone = escapeHtml(log.phone || '—');
    const result = escapeHtml(log.result || '—');
    const timeAgo = escapeHtml(log.time_ago || '—');
    const durationLabel = escapeHtml(log.duration_label || '0s');
    const callLogRef = escapeHtml(log.call_log_ref || log.id || '');
    const callNote = escapeHtml(log.call_note || '');
    const phoneNote = escapeHtml(log.phone_note || '');
    const hasNotes = Boolean(log.call_note && String(log.call_note).trim());
    const displayNote = escapeHtml(String(log.call_note || '').trim());
    const hasRecording = Boolean(log.has_recording);
    const recordingStatus = escapeHtml(log.recording_status || (hasRecording ? 'ready' : 'none'));
    const notePreview = displayNote
        ? `<span class="ghl-dialer-recent-note-preview" data-log-note-preview>${displayNote}</span>`
        : '';
    const notesBtn = log.phone
        ? `<button type="button" class="ghl-dialer-recent-notes-btn ${hasNotes ? 'has-notes' : ''}"
            data-log-notes-toggle aria-expanded="false" title="Notes" aria-label="Toggle notes">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
        </button>`
        : '';
    const callBtn = log.phone
        ? `<button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-dial-number="${escapeHtml(log.phone)}">Call</button>`
        : '';
    const notesPanel = log.phone
        ? `<div class="ghl-dialer-recent-notes hidden" data-log-notes-panel>
            <textarea class="ghl-dialer-recent-notes-input" data-log-notes-input rows="4"
                placeholder="Notes for this call (${escapeHtml(log.time_label || '—')})…" maxlength="5000">${displayNote}</textarea>
            <div class="ghl-dialer-recent-notes-actions">
                <span class="ghl-dialer-recent-notes-status" data-log-notes-status aria-live="polite"></span>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-log-notes-save>Save</button>
            </div>
        </div>`
        : '';

    return `
        <div class="ghl-dialer-recent-row" data-phone-log-row tabindex="0"
            data-log-direction="${direction}"
            data-log-phone="${escapeHtml(log.phone || '')}"
            data-log-extension="${escapeHtml(log.extension || '')}"
            data-log-result="${result}"
            data-log-time="${escapeHtml(log.time_label || '—')}"
            data-log-call-ref="${callLogRef}"
            data-log-call-note="${callNote}"
            data-log-phone-note="${phoneNote}"
            data-has-notes="${hasNotes ? '1' : '0'}"
            data-has-recording="${hasRecording ? '1' : '0'}"
            data-recording-status="${recordingStatus}"
            data-play-url="${escapeHtml(log.play_url || '')}"
            data-download-url="${escapeHtml(log.download_url || '')}">
            <div class="ghl-dialer-recent-main">
                <div class="ghl-dialer-recent-head">
                    <span class="ghl-dialer-recent-dir">${directionLabel}</span>
                    ${extension}
                </div>
                <span class="ghl-dialer-recent-number">${phone}</span>
                <span class="ghl-dialer-recent-meta">${timeAgo} · ${durationLabel} · ${result}</span>
                ${notePreview}
            </div>
            <div class="ghl-dialer-recent-actions">
                ${notesBtn}
                ${callBtn}
            </div>
            ${notesPanel}
        </div>
    `;
}

function appendCallLogRows(itemsContainer, logs) {
    if (!itemsContainer || !Array.isArray(logs) || logs.length === 0) {
        return;
    }

    itemsContainer.querySelector('[data-call-logs-empty]')?.remove();

    const fragment = document.createDocumentFragment();
    const wrapper = document.createElement('div');
    wrapper.innerHTML = logs.map((log) => buildCallLogRow(log)).join('');
    wrapper.childNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) {
            fragment.appendChild(node);
        }
    });
    itemsContainer.appendChild(fragment);
}

function initCallLogsInfiniteScroll(root = document) {
    const scope = root === document ? document : root;
    const lists = scope.querySelectorAll('[data-call-logs-list]');

    lists.forEach((list) => {
        if (list.dataset.callLogsInit === '1') {
            return;
        }

        list.dataset.callLogsInit = '1';

        const itemsContainer = list.querySelector('[data-call-logs-items]');
        const loadingEl = list.querySelector('[data-call-logs-loading]');
        const sentinel = list.querySelector('[data-call-logs-sentinel]');
        const apiUrl = list.dataset.callLogsUrl || '';
        let offset = Number.parseInt(list.dataset.callLogsOffset || '0', 10) || 0;
        let hasMore = list.dataset.callLogsHasMore === '1';
        let loading = false;

        const loadMore = async () => {
            if (!hasMore || loading || !apiUrl || !itemsContainer) {
                return;
            }

            loading = true;
            loadingEl?.classList.remove('hidden');

            try {
                const url = new URL(apiUrl, window.location.origin);
                url.searchParams.set('offset', String(offset));

                const response = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Call logs request failed (${response.status})`);
                }

                const payload = await response.json();
                appendCallLogRows(itemsContainer, payload.logs || []);
                offset = Number.parseInt(payload.next_offset ?? offset, 10) || offset;
                hasMore = Boolean(payload.has_more);
                list.dataset.callLogsOffset = String(offset);
                list.dataset.callLogsHasMore = hasMore ? '1' : '0';
            } catch (error) {
                console.warn('[communications-dialer] call logs scroll failed', error);
                hasMore = false;
                list.dataset.callLogsHasMore = '0';
            } finally {
                loading = false;
                loadingEl?.classList.add('hidden');
            }
        };

        const maybeLoadMore = () => {
            if (!hasMore || loading) {
                return;
            }

            const threshold = 72;
            const distanceFromBottom = list.scrollHeight - list.scrollTop - list.clientHeight;
            if (distanceFromBottom <= threshold) {
                void loadMore();
            }
        };

        list.addEventListener('scroll', maybeLoadMore, { passive: true });

        if (sentinel && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        void loadMore();
                    }
                },
                {
                    root: list,
                    rootMargin: '120px',
                    threshold: 0,
                },
            );
            observer.observe(sentinel);
        }
    });
}

// Backwards compatibility for any inline callers still present in cached pages.
window.initGhlDialer = function initGhlDialer(config) {
    const numberInput = document.getElementById(config.numberInputId);
    const form = numberInput?.closest('.ghl-dialer-originate-form');
    if (form) {
        attachDialerForm(form);
    }
};
