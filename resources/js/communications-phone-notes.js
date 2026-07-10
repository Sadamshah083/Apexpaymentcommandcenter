import { showToast } from './toast.js';
import { getWebphone } from './communications-webphone.js';

const SAVE_DEBOUNCE_MS = 1200;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

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
    if (numeric.length === 11 && numeric.startsWith('1')) {
        return `+${numeric}`;
    }

    return digits.startsWith('+') ? digits : `+${numeric}`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function panelUrls(root) {
    const panel = root.querySelector('[data-dialer-notes-panel]');
    if (!panel) {
        return null;
    }

    return {
        panel,
        show: panel.dataset.notesShowUrl || '',
        phoneSave: panel.dataset.notesPhoneSaveUrl || '',
        callSave: panel.dataset.notesCallSaveUrl || '',
        toggle: panel.querySelector('[data-dialer-notes-toggle]'),
        drawer: panel.querySelector('[data-dialer-notes-drawer]'),
        input: panel.querySelector('[data-dialer-notes-input]'),
        saveBtn: panel.querySelector('[data-dialer-notes-save]'),
        status: panel.querySelector('[data-dialer-notes-status]'),
        phoneLabel: panel.querySelector('[data-dialer-notes-phone-label]'),
        indicator: panel.querySelector('[data-dialer-notes-indicator]'),
    };
}

async function fetchNotes(urls, phone, callLogRef = '') {
    const url = new URL(urls.show, window.location.origin);
    url.searchParams.set('phone', phone);
    if (callLogRef) {
        url.searchParams.set('call_log_ref', callLogRef);
    }

    const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to load notes (${response.status})`);
    }

    return response.json();
}

async function savePhoneNote(urls, phone, body) {
    const response = await fetch(urls.phoneSave, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ phone, body }),
    });

    if (!response.ok) {
        throw new Error(`Failed to save phone note (${response.status})`);
    }

    return response.json();
}

async function saveCallNote(urls, payload) {
    const response = await fetch(urls.callSave, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(`Failed to save call note (${response.status})`);
    }

    return response.json();
}

async function loadCallLogNote(urls, callLogRef, phone = '') {
    if (!urls?.show || !callLogRef) {
        return '';
    }

    const url = new URL(urls.show, window.location.origin);
    if (phone) {
        url.searchParams.set('phone', phone);
    }
    url.searchParams.set('call_log_ref', callLogRef);

    const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to load call note (${response.status})`);
    }

    const payload = await response.json();

    return String(payload.call_note || '').trim();
}

async function persistCallLogNote({ callLogRef, noteText, phone = '' }) {
    const urls = activeUrlsFromScope();
    if (!urls || !callLogRef) {
        return false;
    }

    await saveCallNote(urls, {
        call_log_ref: callLogRef,
        note: noteText,
        ...(phone ? { phone } : {}),
    });

    return true;
}

function activeUrlsFromScope() {
    const workspace = document.querySelector('[data-phone-workspace]');
    return workspace ? panelUrls(workspace) : null;
}

function setStatus(el, message, tone = 'muted') {
    if (!el) {
        return;
    }

    el.textContent = message || '';
    el.dataset.tone = tone;
}

function combinedNoteText(phoneNote, callNote) {
    const phone = String(phoneNote || '').trim();
    const call = String(callNote || '').trim();
    if (phone && call && phone !== call) {
        return `${phone}\n\n---\nCall: ${call}`;
    }

    return phone || call;
}

function markRowHasNotes(row, hasNotes) {
    if (!row) {
        return;
    }

    row.dataset.hasNotes = hasNotes ? '1' : '0';
    row.querySelector('[data-log-notes-toggle]')?.classList.toggle('has-notes', hasNotes);
}

function updateRowNotePreview(row, text) {
    if (!row) {
        return;
    }

    const preview = row.querySelector('[data-log-note-preview]');
    const trimmed = String(text || '').trim();

    if (!trimmed) {
        preview?.remove();
        markRowHasNotes(row, false);

        return;
    }

    markRowHasNotes(row, true);

    if (preview) {
        preview.textContent = trimmed;

        return;
    }

    const main = row.querySelector('.ghl-dialer-recent-main');
    if (!main) {
        return;
    }

    const el = document.createElement('span');
    el.className = 'ghl-dialer-recent-note-preview';
    el.dataset.logNotePreview = '';
    el.textContent = trimmed;
    main.appendChild(el);
}

function toggleRowNotes(row, forceOpen = null) {
    if (!row) {
        return;
    }

    const panel = row.querySelector('[data-log-notes-panel]');
    const toggle = row.querySelector('[data-log-notes-toggle]');
    const input = row.querySelector('[data-log-notes-input]');
    if (!panel) {
        return;
    }

    const shouldOpen = forceOpen === null ? panel.classList.contains('hidden') : forceOpen;

    row.closest('[data-call-logs-items]')?.querySelectorAll('[data-phone-log-row].is-notes-open').forEach((other) => {
        if (other === row) {
            return;
        }

        other.classList.remove('is-notes-open');
        other.querySelector('[data-log-notes-panel]')?.classList.add('hidden');
        other.querySelector('[data-log-notes-toggle]')?.setAttribute('aria-expanded', 'false');
    });

    panel.classList.toggle('hidden', !shouldOpen);
    row.classList.toggle('is-notes-open', shouldOpen);
    toggle?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

    const scroll = row.closest('[data-call-logs-list]');
    if (scroll) {
        requestAnimationFrame(() => {
            void scroll.offsetHeight;
        });
    }

    if (!shouldOpen || !input) {
        return;
    }

    const seed = row.dataset.logCallNote || input.value || '';
    if (seed && input.value !== seed) {
        input.value = seed;
    }

    window.setTimeout(() => input.focus(), 0);
}

export function teardownPhoneNotesForTurbo() {
    delete document.documentElement.dataset.commPhoneNotesBound;
    document.querySelectorAll('[data-call-logs-list]').forEach((list) => {
        delete list.dataset.phoneNotesListBound;
    });
}

function updatePanelIndicator(urls, hasContent) {
    urls?.indicator?.classList.toggle('hidden', !hasContent);
    urls?.panel?.classList.toggle('has-content', hasContent);
}

export function initCommunicationsPhoneNotes(root = document) {
    const scope = root === document ? document : root;
    const workspaces = scope.querySelectorAll('[data-phone-workspace]');
    if (!workspaces.length) {
        return;
    }

    const state = {
        phone: '',
        callLogRef: '',
        callUuid: '',
        open: false,
        loading: false,
        saveTimer: null,
        dirty: false,
    };

    function activeUrls() {
        for (const workspace of workspaces) {
            const urls = panelUrls(workspace);
            if (urls?.show) {
                return urls;
            }
        }

        return null;
    }

    function dialerNumberInput() {
        return document.querySelector('.ghl-dialer-form--phone [name="destination"]');
    }

    function currentPhone() {
        const webphone = getWebphone?.();
        const onCall = webphone?.currentCallPeer;
        if (onCall) {
            return normalizePhone(onCall);
        }

        const input = dialerNumberInput();
        return normalizePhone(input?.value || state.phone);
    }

    function currentCallUuid() {
        const webphone = getWebphone?.();
        return webphone?.hangupCallUuid?.() || webphone?.morpheusCallUuid || webphone?.originateCallUuid || state.callUuid || '';
    }

    async function loadNotesFor(phone, callLogRef = '') {
        const urls = activeUrls();
        if (!urls || !phone) {
            return;
        }

        state.phone = phone;
        state.callLogRef = callLogRef;
        state.loading = true;

        try {
            const payload = await fetchNotes(urls, phone, callLogRef);
            const text = combinedNoteText(payload.phone_note, payload.call_note);
            if (urls.input && document.activeElement !== urls.input) {
                urls.input.value = text;
            }
            if (urls.phoneLabel) {
                urls.phoneLabel.textContent = phone;
            }
            updatePanelIndicator(urls, text.trim() !== '');
            state.dirty = false;
        } catch (error) {
            console.warn('[communications-phone-notes] load failed', error);
        } finally {
            state.loading = false;
        }
    }

    async function persistNotes({ silent = false, phone = null, noteText = null, callLogRef = null, callUuid = null } = {}) {
        const urls = activeUrls();
        if (!urls) {
            return false;
        }

        const resolvedPhone = phone || currentPhone();
        const body = noteText ?? urls.input?.value ?? '';
        if (!resolvedPhone) {
            if (!silent) {
                showToast('Enter a phone number before saving notes.', 'warning');
            }

            return false;
        }

        const uuid = callUuid || currentCallUuid();
        const logRef = callLogRef ?? state.callLogRef;

        try {
            if (uuid || logRef) {
                await saveCallNote(urls, {
                    phone: resolvedPhone,
                    note: body,
                    call_uuid: uuid || undefined,
                    call_log_ref: logRef || undefined,
                    save_phone_note: true,
                });
            } else {
                await savePhoneNote(urls, resolvedPhone, body);
            }

            state.phone = resolvedPhone;
            state.dirty = false;
            updatePanelIndicator(urls, body.trim() !== '');
            if (!silent) {
                setStatus(urls.status, 'Saved', 'success');
                showToast('Notes saved.', 'success');
            }

            return true;
        } catch (error) {
            console.warn('[communications-phone-notes] save failed', error);
            if (!silent) {
                setStatus(urls.status, 'Save failed', 'error');
                showToast('Could not save notes. Try again.', 'error');
            }

            return false;
        }
    }

    function scheduleAutoSave() {
        if (state.saveTimer) {
            window.clearTimeout(state.saveTimer);
        }

        state.saveTimer = window.setTimeout(() => {
            if (!state.dirty) {
                return;
            }

            const webphone = getWebphone?.();
            const onCall = webphone && ['dialing', 'ringing', 'in-call'].includes(webphone.state);
            if (!onCall && !state.phone) {
                return;
            }

            void persistNotes({ silent: true });
        }, SAVE_DEBOUNCE_MS);
    }

    function setDrawerOpen(open) {
        const urls = activeUrls();
        if (!urls) {
            return;
        }

        state.open = open;
        urls.drawer?.classList.toggle('hidden', !open);
        urls.panel?.classList.toggle('is-open', open);
        urls.toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            const phone = currentPhone() || state.phone;
            if (phone) {
                void loadNotesFor(phone, state.callLogRef);
            }
        }
    }

    function bindMainPanel() {
        const urls = activeUrls();
        if (!urls || urls.panel.dataset.notesBound === '1') {
            return;
        }

        urls.panel.dataset.notesBound = '1';

        urls.toggle?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            setDrawerOpen(!state.open);
        });

        urls.saveBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            void persistNotes();
        });

        urls.input?.addEventListener('input', () => {
            state.dirty = true;
            setStatus(urls.status, 'Unsaved changes');
            scheduleAutoSave();
        });

        const numberInput = dialerNumberInput();
        numberInput?.addEventListener('input', () => {
            const phone = normalizePhone(numberInput.value);
            if (!phone || state.open) {
                if (phone && state.open) {
                    void loadNotesFor(phone, '');
                }

                return;
            }

            state.phone = phone;
        });
    }

    function bindLogRows() {
        scope.querySelectorAll('[data-call-logs-list]').forEach((list) => {
            if (list.dataset.phoneNotesListBound === '1') {
                return;
            }

            list.dataset.phoneNotesListBound = '1';

            list.addEventListener('click', (event) => {
                const notesToggle = event.target.closest('[data-log-notes-toggle]');
                if (notesToggle) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    const row = notesToggle.closest('[data-phone-log-row]');
                    const phone = normalizePhone(row?.dataset.logPhone || '');
                    const callLogRef = row?.dataset.logCallRef || '';
                    const opening = !row?.classList.contains('is-notes-open');

                    toggleRowNotes(row, opening);

                    if (opening && callLogRef) {
                        const input = row.querySelector('[data-log-notes-input]');
                        const seed = row.dataset.logCallNote || '';
                        if (input && seed) {
                            input.value = seed;
                        }

                        state.callLogRef = callLogRef;
                        state.phone = phone;
                        const urls = activeUrls();
                        void loadCallLogNote(urls, callLogRef, phone).then((callNote) => {
                            const text = callNote || seed;
                            if (input) {
                                input.value = text;
                            }
                            row.dataset.logCallNote = text;
                            updateRowNotePreview(row, text);
                        }).catch(() => {
                            if (input && seed) {
                                input.value = seed;
                            }
                        });
                    }

                    return;
                }

                const saveBtn = event.target.closest('[data-log-notes-save]');
                if (saveBtn) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    const row = saveBtn.closest('[data-phone-log-row]');
                    const input = row?.querySelector('[data-log-notes-input]');
                    const status = row?.querySelector('[data-log-notes-status]');
                    const phone = normalizePhone(row?.dataset.logPhone || '');
                    const callLogRef = row?.dataset.logCallRef || '';
                    const noteText = input?.value ?? '';

                    if (!phone) {
                        return;
                    }

                    setStatus(status, 'Saving…');
                    void persistCallLogNote({ callLogRef, noteText, phone }).then((ok) => {
                        if (!ok) {
                            setStatus(status, 'Save failed', 'error');

                            return;
                        }

                        row.dataset.logCallNote = noteText;
                        updateRowNotePreview(row, noteText);
                        setStatus(status, 'Saved', 'success');
                    });

                    return;
                }
            });
        });
    }

    function bindActiveCallNotes() {
        scope.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-dialer-active-notes-toggle]');
            if (toggle) {
                event.preventDefault();
                const block = toggle.closest('[data-dialer-active-screen]')?.querySelector('[data-dialer-active-notes]');
                block?.classList.toggle('hidden');
                return;
            }

            const saveBtn = event.target.closest('[data-dialer-active-notes-save]');
            if (!saveBtn) {
                return;
            }

            event.preventDefault();
            const screen = saveBtn.closest('[data-dialer-active-screen]');
            const input = screen?.querySelector('[data-dialer-active-notes-input]');
            const status = screen?.querySelector('[data-dialer-active-notes-status]');
            const noteText = input?.value ?? '';
            const phone = currentPhone();

            setStatus(status, 'Saving…');
            void persistNotes({ phone, noteText, callUuid: currentCallUuid() }).then((ok) => {
                setStatus(status, ok ? 'Saved' : 'Save failed', ok ? 'success' : 'error');
                const urls = activeUrls();
                if (urls?.input) {
                    urls.input.value = noteText;
                }
            });
        });

        scope.querySelectorAll('[data-dialer-active-notes-input]').forEach((input) => {
            input.addEventListener('input', () => {
                state.dirty = true;
                const urls = activeUrls();
                if (urls?.input) {
                    urls.input.value = input.value;
                }
                scheduleAutoSave();
            });
        });
    }

    function bindCallLifecycle() {
        window.addEventListener('comm:call-active', (event) => {
            const detail = event.detail || {};
            const phone = normalizePhone(detail.phone || '');
            const callUuid = detail.callUuid || '';

            if (phone) {
                state.phone = phone;
                state.callUuid = callUuid;
                void loadNotesFor(phone, '');

                scope.querySelectorAll('[data-dialer-active-notes-input]').forEach((input) => {
                    if (document.activeElement !== input) {
                        const urls = activeUrls();
                        input.value = urls?.input?.value || '';
                    }
                });
            }
        });

        window.addEventListener('comm:call-ended', (event) => {
            const detail = event.detail || {};
            const phone = normalizePhone(detail.phone || state.phone);
            const noteText = activeUrls()?.input?.value
                || scope.querySelector('[data-dialer-active-notes-input]')?.value
                || '';

            if (phone && noteText.trim() !== '') {
                const endedCallRef = detail.callLogRef || detail.callUuid || state.callLogRef || '';
                if (endedCallRef) {
                    void persistCallLogNote({
                        callLogRef: endedCallRef,
                        noteText,
                        phone,
                    });
                } else {
                    void persistNotes({
                        silent: true,
                        phone,
                        noteText,
                    });
                }
            }

            const callLogRef = detail.callLogRef || detail.callUuid || state.callLogRef || '';
            const syncUrl = scope.querySelector('[data-recording-sync-url]')?.getAttribute('data-recording-sync-url')
                || document.querySelector('[data-phone-workspace]')?.dataset.recordingSyncUrl;
            if (callLogRef && syncUrl) {
                void fetch(syncUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ call_log_ref: callLogRef }),
                }).catch(() => {});
            }

            state.callUuid = '';
            state.dirty = false;
        });
    }

    if (document.documentElement.dataset.commPhoneNotesBound === '1') {
        bindLogRows();

        return;
    }

    document.documentElement.dataset.commPhoneNotesBound = '1';

    workspaces.forEach(() => {
        bindMainPanel();
    });
    bindLogRows();
    bindActiveCallNotes();
    bindCallLifecycle();

    window.commPhoneNotes = {
        openForPhone(phone, callLogRef = '') {
            const normalized = normalizePhone(phone);
            if (!normalized) {
                return;
            }

            state.callLogRef = callLogRef;
            setDrawerOpen(true);
            void loadNotesFor(normalized, callLogRef);
        },
        loadForPhone(phone, callLogRef = '') {
            return loadNotesFor(normalizePhone(phone), callLogRef);
        },
        persistCallNote() {
            return persistNotes({ silent: true });
        },
    };
}

export function buildCallLogRowNotesButton(log) {
    const hasNotes = Boolean(log.call_note && String(log.call_note).trim());
    const noteClass = hasNotes ? 'has-notes' : '';

    return `<button type="button" class="ghl-dialer-recent-notes-btn ${noteClass}"
        data-log-notes-toggle title="Notes" aria-label="Toggle notes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
        </svg>
    </button>`;
}

export function buildCallLogRowNotesPanel(log) {
    const callNote = escapeHtml(String(log.call_note || '').trim());
    const timeLabel = escapeHtml(log.time_label || '—');

    return `
        <div class="ghl-dialer-recent-notes hidden" data-log-notes-panel>
            <textarea class="ghl-dialer-recent-notes-input" data-log-notes-input rows="3"
                placeholder="Notes for this call (${timeLabel})…" maxlength="5000">${callNote}</textarea>
            <div class="ghl-dialer-recent-notes-actions">
                <span class="ghl-dialer-recent-notes-status" data-log-notes-status aria-live="polite"></span>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-log-notes-save>Save</button>
            </div>
        </div>
    `;
}
