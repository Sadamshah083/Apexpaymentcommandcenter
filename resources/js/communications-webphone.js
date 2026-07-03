import { Inviter, Registerer, RegistererState, SessionState, UserAgent } from 'sip.js';
import { showToast } from './toast.js';

const STORAGE_KEY = 'communications.webphone_extension';

let singleton = null;

function formatDuration(totalSeconds) {
    const minutes = Math.floor(totalSeconds / 60)
        .toString()
        .padStart(2, '0');
    const seconds = Math.floor(totalSeconds % 60)
        .toString()
        .padStart(2, '0');

    return `${minutes}:${seconds}`;
}

function selectedExtension() {
    const select = document.querySelector('[name="from_extension"]');
    if (select?.value) {
        return select.value;
    }

    const panel = document.querySelector('[data-webphone-panel]');

    return panel?.dataset.defaultExtension || localStorage.getItem(STORAGE_KEY) || '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function createCancelledError() {
    const error = new Error('Connection cancelled.');
    error.cancelled = true;

    return error;
}

class ApexWebphone {
    constructor() {
        this.userAgent = null;
        this.registerer = null;
        this.session = null;
        this.config = null;
        this.state = 'offline';
        this.pendingClickToCall = false;
        this.panel = null;
        this.connectPromise = null;
        this.currentExtension = '';
        this.lastError = '';
        this.callStartedAt = null;
        this.callTimer = null;
        this.connectAttempt = 0;
        this.cancelledConnectAttempt = 0;
    }

    bindPanel(panel) {
        if (panel.dataset.webphoneBound === '1' && this.panel === panel) {
            return;
        }

        panel.dataset.webphoneBound = '1';
        this.panel = panel;
        this.ui = {
            statusText: panel.querySelector('[data-webphone-status-text]'),
            dot: panel.querySelector('[data-webphone-dot]'),
            hint: panel.querySelector('[data-webphone-hint]'),
            stage: panel.querySelector('[data-webphone-stage]'),
            stageNote: panel.querySelector('[data-webphone-stage-note]'),
            extension: panel.querySelector('[data-webphone-extension]'),
            domain: panel.querySelector('[data-webphone-domain]'),
            transport: panel.querySelector('[data-webphone-transport]'),
            callInfo: panel.querySelector('[data-webphone-call-info]'),
            callCard: panel.querySelector('[data-webphone-call-card]'),
            callTitle: panel.querySelector('[data-webphone-call-title]'),
            callSubtitle: panel.querySelector('[data-webphone-call-subtitle]'),
            callTimer: panel.querySelector('[data-webphone-call-timer]'),
            connectBtn: panel.querySelector('[data-webphone-connect]'),
            disconnectBtn: panel.querySelector('[data-webphone-disconnect]'),
            answerBtn: panel.querySelector('[data-webphone-answer]'),
            hangupBtn: panel.querySelector('[data-webphone-hangup]'),
            bridgeBtn: panel.querySelector('[data-webphone-bridge]'),
            bridgePanel: panel.querySelector('[data-webphone-bridge-panel]'),
            bridgeExtension: panel.querySelector('[data-webphone-bridge-extension]'),
            iframe: panel.querySelector('[data-webphone-iframe]'),
            remoteAudio: panel.querySelector('[data-webphone-remote]'),
        };

        this.ui.connectBtn?.addEventListener('click', () => {
            this.connect(selectedExtension()).catch((error) => {
                this.handleConnectFailure(error);
            });
        });

        this.ui.disconnectBtn?.addEventListener('click', () => {
            this.disconnect().catch(() => {});
        });

        this.ui.answerBtn?.addEventListener('click', () => {
            this.answer().catch((error) => {
                showToast(error.message || 'Could not answer call.', 'error');
            });
        });

        this.ui.hangupBtn?.addEventListener('click', () => {
            this.hangup().catch(() => {});
        });

        this.ui.bridgeBtn?.addEventListener('click', () => {
            this.openBridge();
        });

        const extSelect = document.querySelector('[name="from_extension"]');
        extSelect?.addEventListener('change', () => this.syncSelectedExtension());

        this.syncSelectedExtension();
        this.setState('offline');
    }

    handleConnectFailure(error) {
        if (error?.cancelled) {
            this.lastError = '';
            this.pendingClickToCall = false;
            this.setState('offline');
            if (this.ui?.hint) {
                this.ui.hint.textContent = 'Call canceled before the line connected.';
            }
            return;
        }

        const message = error?.message || 'Could not connect phone.';
        this.lastError = message;
        this.setState('error', 'Offline');
        this.ui.hint.textContent = message;
        this.ui.bridgeBtn?.classList.remove('hidden');
        showToast(message, 'error');
    }

    openBridge() {
        const portalUrl = this.panel?.dataset.portalUrl;
        if (!portalUrl || !this.ui?.iframe) {
            return;
        }

        this.ui.iframe.src = portalUrl;
        this.ui.bridgePanel?.classList.remove('hidden');
        this.ui.bridgeBtn?.classList.add('hidden');
        this.setState('registered', 'Embedded phone');
        this.ui.hint.textContent =
            'Morpheus phone loaded below. Log in there, then dial from Quick dial above.';
        if (this.ui.stageNote) {
            this.ui.stageNote.textContent = 'Fallback phone is open and ready to use.';
        }
        showToast('Use the embedded Morpheus phone below to register your line.', 'warning');
    }

    markClickToCallPending() {
        this.pendingClickToCall = true;
        window.setTimeout(() => {
            this.pendingClickToCall = false;
        }, 45000);
    }

    cancelPendingConnect() {
        this.pendingClickToCall = false;
        this.cancelledConnectAttempt = this.connectAttempt;
        this.disconnect().catch(() => {});
    }

    syncSelectedExtension() {
        if (!this.ui) {
            return;
        }

        const extension = selectedExtension() || this.currentExtension || this.panel?.dataset.defaultExtension || '—';
        if (this.ui.extension) {
            this.ui.extension.textContent = extension;
        }
        if (this.ui.bridgeExtension) {
            this.ui.bridgeExtension.textContent = extension;
        }

        if (
            this.state === 'registered' &&
            this.currentExtension &&
            extension !== this.currentExtension &&
            this.ui.stageNote
        ) {
            this.ui.stageNote.textContent = `Ready on ${this.currentExtension}. Click Reconnect to switch to ${extension}.`;
        }
    }

    applyConfigMeta(config) {
        if (!this.ui || !config) {
            return;
        }

        if (this.ui.domain) {
            this.ui.domain.textContent = config.domain || '—';
        }
        if (this.ui.transport) {
            this.ui.transport.textContent = config.wss_url || '—';
        }
        if (this.ui.extension) {
            this.ui.extension.textContent = config.extension || this.currentExtension || '—';
        }
        if (this.ui.bridgeExtension) {
            this.ui.bridgeExtension.textContent = config.extension || this.currentExtension || '—';
        }
    }

    updateCallCard({ title = '', subtitle = '', detail = '', visible = false, timer = '00:00' } = {}) {
        if (!this.ui) {
            return;
        }

        this.ui.callCard?.classList.toggle('hidden', !visible);
        if (this.ui.callTitle) {
            this.ui.callTitle.textContent = title;
        }
        if (this.ui.callSubtitle) {
            this.ui.callSubtitle.textContent = subtitle;
        }
        if (this.ui.callTimer) {
            this.ui.callTimer.textContent = timer;
        }
        if (this.ui.callInfo) {
            this.ui.callInfo.textContent = detail;
            this.ui.callInfo.classList.toggle('hidden', !detail);
        }
    }

    startCallTimer() {
        this.stopCallTimer();
        this.callStartedAt = Date.now();
        this.ui?.callTimer?.classList.remove('hidden');
        this.callTimer = window.setInterval(() => {
            if (!this.callStartedAt || !this.ui?.callTimer) {
                return;
            }
            const seconds = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
            this.ui.callTimer.textContent = formatDuration(seconds);
        }, 1000);
    }

    stopCallTimer() {
        if (this.callTimer) {
            window.clearInterval(this.callTimer);
            this.callTimer = null;
        }
        this.callStartedAt = null;
        if (this.ui?.callTimer) {
            this.ui.callTimer.textContent = '00:00';
        }
    }

    setState(state, message = '') {
        this.state = state;

        if (!this.ui) {
            return;
        }

        const labels = {
            offline: 'Offline',
            connecting: 'Connecting…',
            registered: 'Registered',
            ringing: 'Ringing…',
            'in-call': 'On call',
            error: 'Error',
        };
        const stageNotes = {
            offline: 'Waiting to connect your line.',
            connecting: 'Preparing extension credentials and opening SIP over WSS.',
            registered: 'Line is live and ready for inbound or outbound calls.',
            ringing: 'Live invite received. Answer to join the call.',
            'in-call': 'Audio is connected. Keep this tab open while you talk.',
            error: 'Connection failed. You can retry or open the fallback phone.',
        };

        this.ui.statusText.textContent = message || labels[state] || state;
        this.ui.dot.dataset.state = state;
        if (this.ui.stage) {
            this.ui.stage.dataset.state = state;
            this.ui.stage.textContent = labels[state] || state;
        }
        if (this.ui.stageNote) {
            this.ui.stageNote.textContent = stageNotes[state] || '';
        }

        const registered = state === 'registered' || state === 'ringing' || state === 'in-call';
        this.ui.connectBtn.textContent = state === 'connecting' ? 'Connecting…' : registered ? 'Reconnect line' : 'Connect line';
        this.ui.connectBtn.disabled = state === 'connecting';
        this.ui.connectBtn.classList.toggle('hidden', state === 'in-call');
        this.ui.disconnectBtn?.classList.toggle('hidden', !registered || state === 'in-call');
        this.ui.disconnectBtn && (this.ui.disconnectBtn.disabled = state === 'connecting');
        this.ui.answerBtn.classList.toggle('hidden', state !== 'ringing');
        this.ui.hangupBtn.classList.toggle('hidden', state !== 'ringing' && state !== 'in-call');

        if (state === 'registered' && !this.ui.bridgePanel?.classList.contains('hidden')) {
            return;
        }

        if (state === 'registered') {
            this.ui.hint.textContent = 'Phone ready — place calls from Quick dial below.';
            this.ui.bridgeBtn?.classList.add('hidden');
        } else if (state === 'connecting') {
            this.ui.hint.textContent = 'Syncing SIP credentials and registering with Morpheus…';
        } else if (state === 'ringing') {
            this.ui.hint.textContent = 'Incoming call detected. Answer when ready.';
        } else if (state === 'in-call') {
            this.ui.hint.textContent = 'Call is live. Audio should be routed through your browser now.';
        } else if (state === 'offline' || state === 'error') {
            this.ui.hint.textContent =
                this.lastError ||
                'Built-in web phone — click Connect and allow microphone access.';
        }

        this.syncSelectedExtension();
    }

    async prepareConfig(extension) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        if (!panel?.dataset.prepareUrl) {
            return this.fetchConfig(extension);
        }

        const response = await fetch(panel.dataset.prepareUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ extension }),
        });

        const payload = await response.json();
        if (!response.ok || !payload.ok || !payload.config) {
            throw new Error(payload.error || 'Could not prepare phone settings.');
        }

        this.applyConfigMeta(payload.config);
        return payload.config;
    }

    async fetchConfig(extension) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        if (!panel) {
            throw new Error('Webphone panel not found.');
        }

        const url = new URL(panel.dataset.configUrl, window.location.origin);
        url.searchParams.set('extension', extension);

        const response = await fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Could not load phone settings.');
        }

        this.applyConfigMeta(payload.config);
        return payload.config;
    }

    async connect(extension) {
        const normalized = String(extension || '').replace(/\D/g, '') || String(extension || '');
        if (!normalized) {
            throw new Error('Select your extension in the From dropdown first.');
        }

        if (this.state === 'registered' && this.currentExtension === normalized) {
            return true;
        }

        if (this.connectPromise) {
            return this.connectPromise;
        }

        const attempt = ++this.connectAttempt;
        this.connectPromise = this._connect(normalized, attempt).finally(() => {
            this.connectPromise = null;
        });

        return this.connectPromise;
    }

    throwIfConnectCancelled(attempt) {
        if (this.cancelledConnectAttempt === attempt) {
            throw createCancelledError();
        }
    }

    async _connect(extension, attempt) {
        await this.disconnect(false);
        this.throwIfConnectCancelled(attempt);

        this.setState('connecting');
        this.config = await this.prepareConfig(extension);
        this.throwIfConnectCancelled(attempt);
        this.currentExtension = extension;
        this.applyConfigMeta(this.config);
        localStorage.setItem(STORAGE_KEY, extension);

        if (!window.isSecureContext && window.location.protocol === 'http:') {
            throw new Error(
                'Microphone requires HTTPS. Click "Use embedded Morpheus phone" below instead.',
            );
        }

        const domain = this.config.domain;
        const uri = UserAgent.makeURI(`sip:${extension}@${domain}`);
        if (!uri) {
            throw new Error('Invalid SIP address.');
        }

        const iceServers = (this.config.stun_servers || []).map((url) => ({ urls: url }));
        const wssUrl = this.config.wss_url.includes('/ws')
            ? this.config.wss_url
            : `${this.config.wss_url.replace(/\/$/, '')}/ws`;

        this.userAgent = new UserAgent({
            uri,
            transportOptions: {
                server: wssUrl,
            },
            authorizationUsername: this.config.auth_user,
            authorizationPassword: this.config.password,
            displayName: this.config.display_name || extension,
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: {
                    iceServers,
                },
            },
        });

        this.userAgent.delegate = {
            onInvite: (invitation) => this.handleInvite(invitation),
        };

        await this.userAgent.start();
        this.throwIfConnectCancelled(attempt);

        this.registerer = new Registerer(this.userAgent);
        await this.waitForRegistration(this.registerer, attempt);
        this.throwIfConnectCancelled(attempt);

        return true;
    }

    waitForRegistration(registerer, attempt) {
        return new Promise((resolve, reject) => {
            let settled = false;

            const finish = (fn, value) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                fn(value);
            };

            const timer = window.setTimeout(() => {
                finish(
                    reject,
                    new Error(
                        'Morpheus rejected SIP registration. Use the embedded Morpheus phone button below.',
                    ),
                );
            }, 12000);

            registerer.stateChange.addListener((state) => {
                if (this.cancelledConnectAttempt === attempt) {
                    finish(reject, createCancelledError());
                    return;
                }

                if (state === RegistererState.Registered) {
                    this.setState('registered');
                    finish(resolve);
                }

                if (state === RegistererState.Unregistered && registerer.waiting === false) {
                    finish(
                        reject,
                        new Error(
                            'Morpheus rejected SIP registration (403). Click "Use embedded Morpheus phone" below.',
                        ),
                    );
                }
            });

            registerer.register().catch((error) => {
                if (this.cancelledConnectAttempt === attempt) {
                    finish(reject, createCancelledError());
                    return;
                }
                finish(reject, error);
            });
        });
    }

    handleInvite(invitation) {
        this.session = invitation;
        this.bindSession(invitation);

        const caller = invitation.remoteIdentity?.uri?.user || 'Unknown';
        this.updateCallCard({
            title: 'Incoming call',
            subtitle: 'A live call is waiting on your line.',
            detail: `Caller: ${caller}`,
            visible: true,
        });
        this.setState('ringing');

        if (this.config?.auto_answer_click_to_call && this.pendingClickToCall) {
            this.pendingClickToCall = false;
            this.answer().catch(() => {
                showToast('Incoming call — click Answer.', 'warning');
            });

            return;
        }

        showToast('Incoming call — click Answer in the Phone panel.', 'warning');
    }

    bindSession(session) {
        session.stateChange.addListener((state) => {
            if (state === SessionState.Establishing) {
                this.updateCallCard({
                    title: 'Connecting call',
                    subtitle: 'Negotiating media with Morpheus…',
                    detail: this.ui?.callInfo?.textContent || '',
                    visible: true,
                });
            }

            if (state === SessionState.Established) {
                this.startCallTimer();
                this.updateCallCard({
                    title: 'Call live',
                    subtitle: 'Two-way audio is active.',
                    detail: this.ui?.callInfo?.textContent || '',
                    visible: true,
                    timer: '00:00',
                });
                this.setState('in-call');
                this.attachRemoteAudio(session);
            }

            if (state === SessionState.Terminated) {
                this.clearSession();
            }
        });
    }

    attachRemoteAudio(session) {
        const remoteAudio = this.ui?.remoteAudio;
        const pc = session.sessionDescriptionHandler?.peerConnection;
        if (!remoteAudio || !pc) {
            return;
        }

        pc.ontrack = (event) => {
            remoteAudio.srcObject = event.streams[0];
            remoteAudio.play().catch(() => {});
        };

        const stream = new MediaStream();
        pc.getReceivers().forEach((receiver) => {
            if (receiver.track) {
                stream.addTrack(receiver.track);
            }
        });
        if (stream.getTracks().length > 0) {
            remoteAudio.srcObject = stream;
            remoteAudio.play().catch(() => {});
        }
    }

    async answer() {
        if (!this.session) {
            return;
        }

        await this.session.accept({
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: false },
            },
        });
    }

    async hangup() {
        if (this.session) {
            const state = this.session.state;
            if (state === SessionState.Initial || state === SessionState.Establishing) {
                if (this.session instanceof Inviter) {
                    await this.session.cancel();
                } else {
                    await this.session.reject();
                }
            } else {
                await this.session.bye();
            }
        }

        this.clearSession();
    }

    clearSession() {
        this.session = null;
        this.stopCallTimer();

        this.updateCallCard({ visible: false });

        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
        }

        if (this.ui?.bridgePanel && !this.ui.bridgePanel.classList.contains('hidden')) {
            this.setState('registered', 'Embedded phone');

            return;
        }

        if (this.registerer?.state === RegistererState.Registered) {
            this.setState('registered');
        } else {
            this.setState('offline');
        }
    }

    async disconnect(resetUi = true) {
        this.session = null;
        this.stopCallTimer();

        if (this.registerer) {
            try {
                await this.registerer.unregister();
            } catch {
                // ignore
            }
            this.registerer = null;
        }

        if (this.userAgent) {
            try {
                await this.userAgent.stop();
            } catch {
                // ignore
            }
            this.userAgent = null;
        }

        if (resetUi) {
            this.setState('offline');
        }
    }

    isReady() {
        if (this.ui?.bridgePanel && !this.ui.bridgePanel.classList.contains('hidden')) {
            return true;
        }

        return this.state === 'registered' || this.state === 'in-call' || this.state === 'ringing';
    }
}

export function getWebphone() {
    if (!singleton) {
        singleton = new ApexWebphone();
    }

    return singleton;
}

export async function ensureWebphoneReady() {
    const phone = getWebphone();
    if (phone.isReady()) {
        return true;
    }

    const extension = selectedExtension();
    if (!extension) {
        showToast('Select your extension before calling.', 'error');

        return false;
    }

    try {
        await phone.connect(extension);

        return phone.isReady();
    } catch (error) {
        phone.handleConnectFailure(error);

        return false;
    }
}

export function markDialerClickToCallPending() {
    getWebphone().markClickToCallPending();
}

export function cancelPendingWebphoneConnect() {
    getWebphone().cancelPendingConnect();
}

export function bootCommunicationsWebphone() {
    const panel = document.querySelector('[data-webphone-panel]');
    if (!panel) {
        return;
    }

    const phone = getWebphone();
    phone.bindPanel(panel);

    if (!window.isSecureContext && window.location.protocol === 'http:') {
        phone.ui.hint.textContent =
            'Apex is on HTTP — use the embedded Morpheus phone (HTTPS) for audio.';
        phone.ui.bridgeBtn?.classList.remove('hidden');
    }
}
