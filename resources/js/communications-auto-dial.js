import { placeOutboundCall } from './communications-dialer.js';
import { getWebphone } from './communications-webphone.js';
import { showToast, dismissAllToasts } from './toast.js';

const STORAGE_MODE_KEY = 'communications.dial_mode';
const STORAGE_PAUSED_KEY = 'communications.auto_dial_paused';
const AUTO_DIAL_DELAY_MS = 10000;

let state = {
    mode: 'manual',
    paused: false,
    queue: [],
    currentLead: null,
    callStartedAt: null,
    pendingDisposition: null,
    selectedDisposition: null,
    sessionActive: false,
};

let autoDialDelayTimer = null;
let autoDialCountdownTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function hubRoot() {
    return document.querySelector('[data-auto-dial-hub]');
}

function syncAutoDialControls() {
    const running = state.mode === 'auto' && state.sessionActive && !state.paused;
    const startBtns = document.querySelectorAll('[data-auto-dial-start]');
    const stopBtns = document.querySelectorAll('[data-auto-dial-stop]');
    const statusEls = document.querySelectorAll('[data-auto-dial-status]');

    startBtns.forEach((btn) => {
        btn.classList.toggle('hidden', running);
        btn.classList.toggle('is-running', false);
        btn.disabled = false;
        btn.textContent = 'Start auto dial';
    });

    stopBtns.forEach((btn) => {
        btn.classList.toggle('hidden', !running);
        btn.disabled = false;
    });

    statusEls.forEach((el) => {
        if (running) {
            const remaining = state.queue.length + (state.currentLead ? 1 : 0);
            el.textContent = remaining > 0
                ? `Auto dial running · ${remaining} lead${remaining === 1 ? '' : 's'} left`
                : 'Auto dial running';
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (state.mode === 'auto' && state.paused) {
            el.textContent = 'Auto dial paused';
            el.classList.remove('hidden', 'is-active');
        } else {
            el.textContent = '';
            el.classList.add('hidden');
            el.classList.remove('is-active');
        }
    });
}

function stopAutoDialSession({ toast = true } = {}) {
    state.sessionActive = false;
    state.paused = false;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    clearAutoDialDelay();
    hubRoot()?.querySelectorAll('[data-dialer-lead-row].is-dialing').forEach((row) => {
        row.classList.remove('is-dialing');
    });
    syncAutoDialControls();
    if (toast) {
        showToast('Auto dial stopped.', 'info');
    }
}

function clearAutoDialDelay() {
    if (autoDialDelayTimer) {
        clearTimeout(autoDialDelayTimer);
        autoDialDelayTimer = null;
    }

    if (autoDialCountdownTimer) {
        clearInterval(autoDialCountdownTimer);
        autoDialCountdownTimer = null;
    }

    const countdown = document.querySelector('[data-auto-dial-countdown]');
    countdown?.classList.add('hidden');
}

function showAutoDialCountdown(seconds, message) {
    const countdown = document.querySelector('[data-auto-dial-countdown]');
    const text = countdown?.querySelector('[data-auto-dial-countdown-text]');
    if (!countdown || !text) {
        return;
    }

    countdown.classList.remove('hidden');
    text.textContent = `${message} in ${seconds}s…`;
}

function scheduleAutoDial(action, message = 'Next call') {
    clearAutoDialDelay();

    if (state.mode !== 'auto' || state.paused) {
        return Promise.resolve(false);
    }

    let remaining = Math.ceil(AUTO_DIAL_DELAY_MS / 1000);
    showAutoDialCountdown(remaining, message);

    return new Promise((resolve) => {
        autoDialCountdownTimer = window.setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(autoDialCountdownTimer);
                autoDialCountdownTimer = null;

                return;
            }

            showAutoDialCountdown(remaining, message);
        }, 1000);

        autoDialDelayTimer = window.setTimeout(async () => {
            autoDialDelayTimer = null;
            clearAutoDialDelay();

            if (state.mode !== 'auto' || state.paused) {
                resolve(false);

                return;
            }

            const result = await action();
            resolve(result);
        }, AUTO_DIAL_DELAY_MS);
    });
}

function switchToLeadsTab() {
    const workspace = hubRoot();
    if (!workspace) {
        return;
    }

    const leadsBtn = workspace.querySelector('[data-phone-panel-view="leads"]');
    leadsBtn?.click();
}

function switchToCallLogsTab() {
    const workspace = hubRoot();
    if (!workspace) {
        return;
    }

    const logsBtn = workspace.querySelector('[data-phone-panel-view="logs"]');
    logsBtn?.click();
}

function dispositionUrl() {
    return document.querySelector('[data-call-summary-modal]')?.dataset.dispositionUrl || '';
}

function formatDuration(seconds) {
    const total = Math.max(0, Number.parseInt(String(seconds || 0), 10) || 0);
    const mins = Math.floor(total / 60);
    const secs = total % 60;

    return `${String(mins).padStart(2, '0')} Min ${String(secs).padStart(2, '0')} Sec`;
}

function leadLabel(lead) {
    const name = String(lead?.name || lead?.lead_name || '').trim();
    const phone = String(lead?.phone_display || lead?.phone || '').trim();

    if (name && phone) {
        return `${name} · ${phone}`;
    }

    if (phone) {
        return phone;
    }

    return name;
}

function buildLeadRow(lead) {
    const name = String(lead.name || '').trim();
    const contact = String(lead.contact || '').trim();
    const phone = String(lead.phone_display || lead.phone || '—');
    const campaign = String(lead.campaign || '').trim();
    const showCampaign = campaign !== '' && !['default', 'result', 'n/a', 'none', '-'].includes(campaign.toLowerCase());
    const initial = (name || contact || 'L').charAt(0).toUpperCase();

    return `
        <div class="ghl-dialer-lead-row" data-dialer-lead-row tabindex="0"
            data-lead-id="${lead.id || ''}"
            data-lead-phone="${lead.phone || ''}"
            data-lead-name="${name.replace(/"/g, '&quot;')}"
            data-lead-contact="${contact.replace(/"/g, '&quot;')}">
            <div class="ghl-dialer-lead-avatar" aria-hidden="true">${initial}</div>
            <div class="ghl-dialer-lead-main">
                ${name ? `<span class="ghl-dialer-lead-name">${name}</span>` : ''}
                ${contact ? `<span class="ghl-dialer-lead-contact">${contact}</span>` : ''}
                <span class="ghl-dialer-lead-number">${phone}</span>
                ${showCampaign ? `<span class="ghl-dialer-lead-meta">${campaign}</span>` : ''}
            </div>
            <div class="ghl-dialer-lead-actions">
                <button type="button" class="ghl-dialer-lead-call-btn" data-dial-lead-call title="Call this lead">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    <span>Call</span>
                </button>
            </div>
        </div>
    `;
}

function setDialMode(mode) {
    state.mode = mode === 'auto' ? 'auto' : 'manual';
    localStorage.setItem(STORAGE_MODE_KEY, state.mode);

    document.querySelectorAll('[data-dial-mode-switch] [data-dial-mode]').forEach((btn) => {
        const active = btn.dataset.dialMode === state.mode;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    const hub = hubRoot();
    hub?.classList.toggle('ch-dial-workspace--auto-mode', state.mode === 'auto');

    if (state.mode !== 'auto') {
        state.sessionActive = false;
        clearAutoDialDelay();
    }

    syncAutoDialControls();
}

function showSummaryModal(context) {
    const modal = document.querySelector('[data-call-summary-modal]');
    if (!modal) {
        return;
    }

    dismissAllToasts();
    getWebphone()?.restoreDialerAfterCall?.();

    // Keep the popup above topbar icons / floating call UI (those use z-index 10k+).
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    const activeNotes = window.__apexActiveCallNotes?.read?.() || {};
    state.pendingDisposition = {
        ...context,
        inCallNotes: String(activeNotes.notes || context.inCallNotes || '').trim(),
        comment: String(activeNotes.comment || context.comment || '').trim(),
    };
    state.selectedDisposition = null;

    const leadLine = modal.querySelector('[data-call-summary-lead]');
    const durationEl = modal.querySelector('[data-call-summary-duration]');
    const resultEl = modal.querySelector('[data-call-summary-result]');
    const noteEl = modal.querySelector('[data-call-summary-note]');
    const customDispositionEl = modal.querySelector('[data-call-summary-custom-disposition]');
    const nextLabel = modal.querySelector('[data-call-summary-next-label]');
    const pauseBtn = modal.querySelector('[data-call-summary-pause]');

    if (leadLine) {
        leadLine.textContent = leadLabel(context.lead) || leadLabel({ phone: context.phone }) || context.phone || 'Call ended';
    }
    if (durationEl) {
        durationEl.textContent = formatDuration(context.durationSec);
    }
    if (resultEl) {
        resultEl.textContent = context.result || 'Completed';
    }
    if (noteEl) {
        noteEl.value = activeNotes.comment || context.comment || '';
    }
    if (customDispositionEl) {
        customDispositionEl.value = '';
    }
    if (nextLabel) {
        nextLabel.textContent = state.mode === 'auto' ? 'Next' : 'Save';
    }
    if (pauseBtn) {
        pauseBtn.classList.toggle('hidden', state.mode !== 'auto');
    }
    modal.classList.toggle('ch-call-summary--manual', state.mode !== 'auto');

    modal.querySelectorAll('[data-disposition-value]').forEach((btn) => {
        btn.classList.remove('is-selected');
    });

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ch-call-summary-open');

    // Focus first disposition so agents can pick an outcome immediately.
    window.setTimeout(() => {
        modal.querySelector('[data-disposition-value]')?.focus?.();
    }, 40);
}

function hideSummaryModal({ force = false } = {}) {
    const modal = document.querySelector('[data-call-summary-modal]');
    if (!modal) {
        return;
    }

    if (!force && state.pendingDisposition && !modal.classList.contains('hidden')) {
        showToast('Select or write a disposition to continue.', 'warning');
        return;
    }

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ch-call-summary-open');
    state.pendingDisposition = null;
    state.selectedDisposition = null;
}

async function saveDisposition(disposition) {
    const context = state.pendingDisposition;
    if (!context || !disposition) {
        return null;
    }

    const url = dispositionUrl();
    if (!url) {
        return null;
    }

    const note = document.querySelector('[data-call-summary-note]')?.value?.trim() || '';
    const active = window.__apexActiveCallNotes?.read?.() || {};
    const inCallNotes = String(active.notes || context?.inCallNotes || '').trim();

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                disposition,
                note: note || null,
                in_call_notes: inCallNotes || null,
                call_uuid: context.callUuid || null,
                phone: context.phone || context.lead?.phone || null,
                lead_id: context.lead?.id || null,
                duration_sec: context.durationSec || 0,
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'Could not save disposition.');
        }

        return payload;
    } catch (error) {
        console.warn('[auto-dial] disposition save failed', error);
        showToast(error.message || 'Could not save disposition. Please try again.', 'error');

        return null;
    }
}

function resolveSummaryDisposition(modal) {
    const custom = modal.querySelector('[data-call-summary-custom-disposition]')?.value?.trim() || '';
    if (custom) {
        return custom;
    }

    return String(state.selectedDisposition || '').trim();
}

function removeLeadFromQueue(leadId, phone) {
    const root = hubRoot();
    if (!root) {
        return;
    }

    root.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const rowId = Number.parseInt(row.dataset.leadId || '0', 10) || null;
        const rowPhone = row.dataset.leadPhone || '';
        const matchId = leadId && rowId === Number(leadId);
        const matchPhone = phone && rowPhone && rowPhone === phone;
        if (matchId || matchPhone) {
            row.remove();
        }
    });

    state.queue = state.queue.filter((lead) => {
        if (leadId && Number(lead.id) === Number(leadId)) {
            return false;
        }
        if (phone && lead.phone === phone) {
            return false;
        }

        return true;
    });

    const items = root.querySelector('[data-imported-leads-items]');
    if (items && !items.querySelector('[data-dialer-lead-row]')) {
        items.innerHTML = '<p class="ghl-dialer-recent-empty" data-imported-leads-empty>No imported leads with phone numbers yet.</p>';
    }
}

function prependCallLog(callLog) {
    if (!callLog) {
        return;
    }

    const list = document.querySelector('[data-call-logs-list]');
    const items = list?.querySelector('[data-call-logs-items]');
    if (!items) {
        return;
    }

    items.querySelector('[data-call-logs-empty]')?.remove();

    if (typeof window.buildCallLogRow === 'function') {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = window.buildCallLogRow(callLog);
        const row = wrapper.firstElementChild;
        if (row) {
            items.prepend(row);
        }

        return;
    }

    const statusLike = ['', '—', '-', 'completed', 'initiated', 'connected', 'answered', 'no-answer', 'no answer', 'busy', 'failed', 'missed', 'unknown'];
    let disposition = String(callLog.disposition || '').trim();
    if (disposition && statusLike.includes(disposition.toLowerCase())) {
        disposition = '';
    }
    const note = String(callLog.call_note || '').trim();
    const inCallNotes = String(callLog.in_call_notes || '').trim();
    const escape = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    const fields = [];
    if (inCallNotes) {
        fields.push(`<p class="ghl-dialer-recent-field"><span class="ghl-dialer-recent-field__label">Notes:</span> <span class="ghl-dialer-recent-field__value">${escape(inCallNotes)}</span></p>`);
    }
    if (note) {
        fields.push(`<p class="ghl-dialer-recent-field"><span class="ghl-dialer-recent-field__label">Comment:</span> <span class="ghl-dialer-recent-field__value">${escape(note)}</span></p>`);
    }
    if (disposition) {
        fields.push(`<p class="ghl-dialer-recent-field ghl-dialer-recent-field--disposition"><span class="ghl-dialer-recent-field__label">Disposition:</span> <span class="ghl-dialer-recent-field__value">${escape(disposition)}</span></p>`);
    }
    const fieldsHtml = fields.length ? `<div class="ghl-dialer-recent-fields">${fields.join('')}</div>` : '';
    const phone = callLog.phone_display || callLog.phone || '—';
    const leadName = callLog.lead_name || '';
    const row = document.createElement('div');
    row.className = 'ghl-dialer-recent-row';
    row.innerHTML = `
        <div class="ghl-dialer-recent-main">
            <div class="ghl-dialer-recent-head">
                <span class="ghl-dialer-recent-dir">Outbound</span>
            </div>
            <div class="ghl-dialer-recent-contact">
                ${leadName ? `<span class="ghl-dialer-recent-name">${escape(leadName)}</span>` : ''}
                <span class="ghl-dialer-recent-number">${escape(phone)}</span>
            </div>
            <span class="ghl-dialer-recent-meta">${escape(callLog.time_ago || 'just now')} · ${escape(callLog.duration_label || '0s')}</span>
            ${fieldsHtml}
        </div>
    `;
    items.prepend(row);
}

async function dialLead(lead) {
    if (!lead?.phone) {
        return false;
    }

    state.currentLead = lead;
    state.callStartedAt = Date.now();

    const form = document.querySelector('.ghl-dialer-originate-form');
    if (form) {
        form.dataset.dialLeadId = String(lead.id || '');
        form.dataset.dialLeadName = String(lead.name || '');
        form.dataset.dialLeadPhone = String(lead.phone || '');
    }

    return placeOutboundCall(lead.phone, lead);
}

async function dialNextInQueue({ withDelay = false, delayMessage = 'Next call' } = {}) {
    if (state.mode !== 'auto' || state.paused) {
        return false;
    }

    const placeNext = async () => {
        if (state.queue.length === 0) {
            const list = hubRoot()?.querySelector('[data-imported-leads-list]');
            if (list && list.dataset.importedLeadsHasMore !== '0') {
                await fetchImportedLeads(list, false);
            }
        }

        // Always take from the front of the queue (built last→first).
        const next = state.queue.shift();
        if (!next) {
            state.sessionActive = false;
            syncAutoDialControls();
            showToast('No more callable imported leads in the queue.', 'info');

            return false;
        }

        markLeadRowActive(next);

        const dialed = await dialLead(next);
        syncAutoDialControls();

        return dialed;
    };

    if (withDelay) {
        return scheduleAutoDial(placeNext, delayMessage);
    }

    return placeNext();
}

function markLeadRowActive(lead) {
    const root = hubRoot();
    root?.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const match = (lead.id && Number(row.dataset.leadId) === Number(lead.id))
            || (lead.phone && row.dataset.leadPhone === lead.phone);
        row.classList.toggle('is-dialing', Boolean(match));
    });
}

/**
 * Build dial queue from the Imported leads list.
 * Starts from the TOP row and works downward so the first lead is dialed first.
 */
function loadQueueFromDom(root = hubRoot(), { rebuild = true } = {}) {
    const rows = root?.querySelectorAll('[data-dialer-lead-row]') || [];
    const skipIds = new Set();
    const skipPhones = new Set();

    if (state.currentLead?.id) {
        skipIds.add(Number(state.currentLead.id));
    }
    if (state.currentLead?.phone) {
        skipPhones.add(String(state.currentLead.phone));
    }
    if (state.pendingDisposition?.lead?.id) {
        skipIds.add(Number(state.pendingDisposition.lead.id));
    }
    if (state.pendingDisposition?.phone || state.pendingDisposition?.lead?.phone) {
        skipPhones.add(String(state.pendingDisposition.phone || state.pendingDisposition.lead.phone));
    }

    const leads = Array.from(rows).map((row) => ({
        id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
        name: row.dataset.leadName || '',
        contact: row.dataset.leadContact || '',
        phone: row.dataset.leadPhone || '',
        phone_display: row.querySelector('.ghl-dialer-lead-number')?.textContent?.trim() || row.dataset.leadPhone || '',
    })).filter((lead) => {
        if (!lead.phone) {
            return false;
        }
        if (lead.id && skipIds.has(Number(lead.id))) {
            return false;
        }
        if (skipPhones.has(String(lead.phone))) {
            return false;
        }

        return true;
    });

    // Top of list first, then next rows downward.
    if (rebuild || !state.sessionActive) {
        state.queue = leads;

        return;
    }

    const existing = new Set(
        state.queue.map((lead) => String(lead.id || lead.phone)),
    );
    leads.forEach((lead) => {
        const key = String(lead.id || lead.phone);
        if (!existing.has(key)) {
            state.queue.push(lead);
            existing.add(key);
        }
    });
}

async function fetchImportedLeads(list, reset = false) {
    const url = list?.dataset.importedLeadsUrl;
    if (!url) {
        return;
    }

    const items = list.querySelector('[data-imported-leads-items]');
    const loading = list.querySelector('[data-imported-leads-loading]');
    const offset = reset ? 0 : Number.parseInt(list.dataset.importedLeadsOffset || '0', 10) || 0;
    const hasMore = list.dataset.importedLeadsHasMore !== '0';

    if (!reset && !hasMore) {
        return;
    }

    const pool = list.closest('[data-phone-leads-pane]')?.querySelector('[data-dialer-leads-pool]')?.value || 'callable';
    const campaign = list.closest('[data-phone-leads-pane]')?.querySelector('[data-dialer-leads-campaign]')?.value || '';
    const params = new URLSearchParams({ offset: String(offset), per_page: '25', pool });
    if (campaign) {
        params.set('campaign_id', campaign);
    }

    loading?.classList.remove('hidden');

    try {
        const response = await fetch(`${url}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok) {
            throw new Error(payload.message || 'Could not load imported leads.');
        }

        if (reset) {
            items.innerHTML = '';
        }

        const leads = payload.leads || [];
        if (leads.length === 0 && offset === 0) {
            items.innerHTML = '<p class="ghl-dialer-recent-empty" data-imported-leads-empty>No imported leads with phone numbers yet.</p>';
        } else {
            items.querySelector('[data-imported-leads-empty]')?.remove();
            const wrapper = document.createElement('div');
            wrapper.innerHTML = leads.map((lead) => buildLeadRow(lead)).join('');
            wrapper.childNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    items.appendChild(node);
                }
            });
        }

        list.dataset.importedLeadsOffset = String(payload.next_offset ?? offset + leads.length);
        list.dataset.importedLeadsHasMore = payload.has_more ? '1' : '0';
        loadQueueFromDom(hubRoot(), { rebuild: reset || !state.sessionActive });
    } catch (error) {
        console.warn('[auto-dial] imported leads fetch failed', error);
        showToast(error.message || 'Could not load imported leads.', 'error');
    } finally {
        loading?.classList.add('hidden');
    }
}

function syncLeadsSelect(wrapper) {
    const select = wrapper.querySelector('.ghl-leads-select__native');
    const valueEl = wrapper.querySelector('[data-leads-select-value]');
    const menu = wrapper.querySelector('.ghl-leads-select__menu');
    if (!select || !valueEl || !menu) {
        return;
    }

    const selected = select.options[select.selectedIndex];
    valueEl.textContent = selected?.textContent?.trim() || 'Select';

    menu.innerHTML = '';
    Array.from(select.options).forEach((option) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `ghl-leads-select__option${option.selected ? ' is-selected' : ''}`;
        btn.setAttribute('role', 'option');
        btn.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        btn.dataset.value = option.value;
        btn.textContent = option.textContent?.trim() || option.value;
        menu.appendChild(btn);
    });
}

function closeLeadsSelect(wrapper) {
    if (!wrapper) {
        return;
    }
    const trigger = wrapper.querySelector('.ghl-leads-select__trigger');
    const menu = wrapper.querySelector('.ghl-leads-select__menu');
    wrapper.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (menu) {
        menu.hidden = true;
    }
}

function closeAllLeadsSelects(except = null) {
    document.querySelectorAll('[data-leads-select].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeLeadsSelect(wrapper);
        }
    });
}

function initLeadsSelects(root = document) {
    const scope = root === document ? document : root;

    scope.querySelectorAll('[data-leads-select]').forEach((wrapper) => {
        if (wrapper.dataset.leadsSelectBound === '1') {
            syncLeadsSelect(wrapper);
            return;
        }

        wrapper.dataset.leadsSelectBound = '1';
        const select = wrapper.querySelector('.ghl-leads-select__native');
        const trigger = wrapper.querySelector('.ghl-leads-select__trigger');
        const menu = wrapper.querySelector('.ghl-leads-select__menu');
        if (!select || !trigger || !menu) {
            return;
        }

        syncLeadsSelect(wrapper);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !wrapper.classList.contains('is-open');
            closeAllLeadsSelects(wrapper);
            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;
        });

        menu.addEventListener('click', (event) => {
            const option = event.target.closest('[data-value]');
            if (!option || !menu.contains(option)) {
                return;
            }
            event.preventDefault();
            select.value = option.dataset.value ?? '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncLeadsSelect(wrapper);
            closeLeadsSelect(wrapper);
        });

        select.addEventListener('change', () => syncLeadsSelect(wrapper));
    });

    if (document.documentElement.dataset.leadsSelectDocBound !== '1') {
        document.documentElement.dataset.leadsSelectDocBound = '1';
        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-leads-select]')) {
                closeAllLeadsSelects();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAllLeadsSelects();
            }
        });
    }
}

function initImportedLeadsList(root = document) {
    const list = (root === document ? document : root).querySelector('[data-imported-leads-list]');
    if (!list || list.dataset.importedLeadsInit === '1') {
        return;
    }

    list.dataset.importedLeadsInit = '1';
    loadQueueFromDom(hubRoot());
    initLeadsSelects(list.closest('[data-phone-leads-pane]') || root);

    const pane = list.closest('[data-phone-leads-pane]');
    pane?.querySelector('[data-dialer-leads-pool]')?.addEventListener('change', () => {
        list.dataset.importedLeadsOffset = '0';
        list.dataset.importedLeadsHasMore = '1';
        void fetchImportedLeads(list, true);
    });
    pane?.querySelector('[data-dialer-leads-campaign]')?.addEventListener('change', () => {
        list.dataset.importedLeadsOffset = '0';
        list.dataset.importedLeadsHasMore = '1';
        void fetchImportedLeads(list, true);
    });

    const sentinel = list.querySelector('[data-imported-leads-sentinel]');
    if (sentinel && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                void fetchImportedLeads(list, false);
            }
        }, { root: list, rootMargin: '120px' });
        observer.observe(sentinel);
    }
}

function initCallSummaryModal() {
    const modal = document.querySelector('[data-call-summary-modal]');
    if (!modal || modal.dataset.summaryInit === '1') {
        return;
    }

    modal.dataset.summaryInit = '1';

    // Disposition is required — backdrop / X cannot dismiss the popup.
    modal.querySelectorAll('[data-call-summary-close]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            showToast('Select or write a disposition to continue.', 'warning');
            modal.querySelector('[data-disposition-value]')?.focus?.();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!state.pendingDisposition || modal.classList.contains('hidden')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        showToast('Select or write a disposition to continue.', 'warning');
    }, true);

    modal.querySelectorAll('[data-disposition-value]').forEach((btn) => {
        btn.addEventListener('click', () => {
            modal.querySelectorAll('[data-disposition-value]').forEach((el) => el.classList.remove('is-selected'));
            btn.classList.add('is-selected');
            state.selectedDisposition = btn.dataset.dispositionValue || '';
            const customEl = modal.querySelector('[data-call-summary-custom-disposition]');
            if (customEl) {
                customEl.value = '';
            }
        });
    });

    modal.querySelector('[data-call-summary-custom-disposition]')?.addEventListener('input', (event) => {
        const value = String(event.target.value || '').trim();
        if (!value) {
            return;
        }
        modal.querySelectorAll('[data-disposition-value]').forEach((el) => el.classList.remove('is-selected'));
        state.selectedDisposition = null;
    });

    modal.querySelector('[data-call-summary-redial]')?.addEventListener('click', async () => {
        const disposition = resolveSummaryDisposition(modal);
        if (!disposition) {
            showToast('Select or write a disposition before redialing.', 'warning');
            return;
        }

        const context = state.pendingDisposition;
        const payload = await saveDisposition(disposition);
        if (!payload) {
            return;
        }

        removeLeadFromQueue(context?.lead?.id || payload?.lead?.id, context?.phone || context?.lead?.phone);
        prependCallLog(payload.call_log);
        window.__apexActiveCallNotes?.clear?.();
        hideSummaryModal({ force: true });

        if (context?.lead) {
            await dialLead(context.lead);
        } else if (context?.phone) {
            await placeOutboundCall(context.phone, context.lead || {});
        }
    });

    modal.querySelector('[data-call-summary-pause]')?.addEventListener('click', () => {
        state.paused = !state.paused;
        localStorage.setItem(STORAGE_PAUSED_KEY, state.paused ? '1' : '0');
        const btn = modal.querySelector('[data-call-summary-pause]');
        btn?.classList.toggle('is-paused', state.paused);
        if (state.paused) {
            clearAutoDialDelay();
        }
        showToast(state.paused ? 'Auto dial paused.' : 'Auto dial resumed.', 'info');
        syncAutoDialControls();
    });

    modal.querySelector('[data-call-summary-next]')?.addEventListener('click', async () => {
        const disposition = resolveSummaryDisposition(modal);
        if (!disposition) {
            showToast('Select or write a disposition first.', 'warning');

            return;
        }

        const context = state.pendingDisposition;
        const payload = await saveDisposition(disposition);
        if (!payload) {
            return;
        }

        removeLeadFromQueue(context?.lead?.id || payload?.lead?.id, context?.phone || context?.lead?.phone);
        prependCallLog(payload.call_log);
        window.__apexActiveCallNotes?.clear?.();
        hideSummaryModal({ force: true });
        switchToCallLogsTab();

        if (state.mode === 'auto' && !state.paused) {
            await dialNextInQueue({ withDelay: true, delayMessage: 'Next call' });
        } else {
            showToast(`Disposition saved: ${disposition}`, 'success');
        }
    });
}

function resolveEndedCallContext(detail = {}) {
    const phone =
        detail.phone
        || state.currentLead?.phone
        || document.querySelector('.ghl-dialer-form--phone [name="destination"]')?.value
        || document.querySelector('[name="destination"]')?.value
        || '';
    const callUuid = detail.callUuid || '';
    const connected = detail.connected === true;
    const durationSec = Number.isFinite(detail.durationSec)
        ? Math.max(0, Number(detail.durationSec))
        : (state.callStartedAt
            ? Math.max(0, Math.round((Date.now() - state.callStartedAt) / 1000))
            : 0);
    const lead = resolveLeadContext(phone) || state.currentLead || null;
    const result = String(detail.result || '').trim()
        || (connected ? 'Connected' : 'No answer');

    return {
        phone: String(phone || '').trim(),
        callUuid,
        durationSec: connected ? durationSec : 0,
        lead,
        result,
        connected,
    };
}

function initDialModeSwitch() {
    const saved = localStorage.getItem(STORAGE_MODE_KEY);
    setDialMode(saved === 'auto' ? 'auto' : 'manual');
    state.paused = localStorage.getItem(STORAGE_PAUSED_KEY) === '1';

    document.querySelectorAll('[data-dial-mode-switch]').forEach((switcher) => {
        if (switcher.dataset.dialModeInit === '1') {
            return;
        }

        switcher.dataset.dialModeInit = '1';
        switcher.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-dial-mode]');
            if (!btn) {
                return;
            }

            setDialMode(btn.dataset.dialMode);
            if (state.mode === 'auto') {
                switchToLeadsTab();
                loadQueueFromDom(hubRoot());
                if (state.queue.length === 0) {
                    showToast('Loading imported leads for auto dial…', 'info');
                }
            } else {
                clearAutoDialDelay();
            }
        });
    });
}

function resolveLeadContext(phone) {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const leadId = Number.parseInt(form?.dataset.dialLeadId || '0', 10) || null;
    const leadName = form?.dataset.dialLeadName || '';
    const leadPhone = form?.dataset.dialLeadPhone || phone || '';

    if (state.currentLead && (state.currentLead.phone === phone || !phone)) {
        return state.currentLead;
    }

    if (leadId || leadName || leadPhone) {
        return {
            id: leadId,
            name: leadName,
            phone: leadPhone || phone,
        };
    }

    const row = Array.from(document.querySelectorAll('[data-dialer-lead-row]'))
        .find((el) => el.dataset.leadPhone === phone);

    if (row) {
        return {
            id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
            name: row.dataset.leadName || '',
            contact: row.dataset.leadContact || '',
            phone: row.dataset.leadPhone || phone,
        };
    }

    return phone ? { phone, name: '' } : null;
}

function initAutoDialListeners() {
    if (document.documentElement.dataset.autoDialListenersInit === '1') {
        return;
    }

    document.documentElement.dataset.autoDialListenersInit = '1';

    document.addEventListener('click', async (event) => {
        const callBtn = event.target.closest('[data-dial-lead-call]');
        if (!callBtn) {
            return;
        }

        const row = callBtn.closest('[data-dialer-lead-row]');
        if (!row) {
            return;
        }

        event.preventDefault();
        const lead = {
            id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
            name: row.dataset.leadName || '',
            contact: row.dataset.leadContact || '',
            phone: row.dataset.leadPhone || '',
            phone_display: row.querySelector('.ghl-dialer-lead-number')?.textContent?.trim() || '',
        };

        await dialLead(lead);
    });

    window.addEventListener('comm:call-active', (event) => {
        state.callStartedAt = Date.now();
        const phone = event.detail?.phone || '';
        if (phone && !state.currentLead) {
            state.currentLead = resolveLeadContext(phone);
        }
    });

    window.addEventListener('comm:call-ended', (event) => {
        const context = resolveEndedCallContext(event.detail || {});
        state.callStartedAt = null;
        formClearLeadContext();

        // Always require a disposition after every hangup (manual or auto).
        showSummaryModal(context);
    });
}

function formClearLeadContext() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    if (!form) {
        return false;
    }

    delete form.dataset.dialLeadId;
    delete form.dataset.dialLeadName;
    delete form.dataset.dialLeadPhone;
    state.currentLead = null;

    return true;
}

export function initAutoDialHub(root = document) {
    const hasHub = Boolean(root.querySelector('[data-auto-dial-hub]'));
    const hasSummary = Boolean(root.querySelector('[data-call-summary-modal]'));
    if (!hasHub && !hasSummary) {
        return;
    }

    initCallSummaryModal();
    initAutoDialListeners();

    if (!hasHub) {
        return;
    }

    initDialModeSwitch();
    initImportedLeadsList(root);

    document.querySelectorAll('[data-auto-dial-start]').forEach((btn) => {
        if (btn.dataset.autoDialStartBound === '1') {
            return;
        }
        btn.dataset.autoDialStartBound = '1';
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = 'Starting…';
            setDialMode('auto');
            const started = await startAutoDialSession();
            if (!started) {
                btn.disabled = false;
                btn.textContent = 'Start auto dial';
            }
            syncAutoDialControls();
        });
    });

    document.querySelectorAll('[data-auto-dial-stop]').forEach((btn) => {
        if (btn.dataset.autoDialStopBound === '1') {
            return;
        }
        btn.dataset.autoDialStopBound = '1';
        btn.addEventListener('click', () => {
            stopAutoDialSession();
        });
    });

    syncAutoDialControls();
}

window.initImportedLeadsList = initImportedLeadsList;

export async function startAutoDialSession() {
    if (state.mode !== 'auto') {
        setDialMode('auto');
    }

    state.paused = false;
    state.sessionActive = true;
    state.currentLead = null;
    state.pendingDisposition = null;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    setDialMode('auto');
    switchToLeadsTab();

    loadQueueFromDom(hubRoot(), { rebuild: true });
    if (state.queue.length === 0) {
        const list = hubRoot()?.querySelector('[data-imported-leads-list]');
        if (list) {
            await fetchImportedLeads(list, true);
            loadQueueFromDom(hubRoot(), { rebuild: true });
        }
    }

    if (state.queue.length === 0) {
        state.sessionActive = false;
        syncAutoDialControls();
        showToast('No callable imported leads available for auto dial.', 'info');

        return false;
    }

    syncAutoDialControls();
    return dialNextInQueue({ withDelay: true, delayMessage: 'Starting call' });
}
