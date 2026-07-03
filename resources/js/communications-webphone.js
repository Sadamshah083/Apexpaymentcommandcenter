import { Inviter, Registerer, RegistererState, SessionState, UserAgent } from 'sip.js';
import { showToast } from './toast.js';

const STORAGE_KEY = 'communications.webphone_extension';

let singleton = null;

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
            callInfo: panel.querySelector('[data-webphone-call-info]'),
            connectBtn: panel.querySelector('[data-webphone-connect]'),
            answerBtn: panel.querySelector('[data-webphone-answer]'),
            hangupBtn: panel.querySelector('[data-webphone-hangup]'),
            bridgeBtn: panel.querySelector('[data-webphone-bridge]'),
            bridgePanel: panel.querySelector('[data-webphone-bridge-panel]'),
            iframe: panel.querySelector('[data-webphone-iframe]'),
            remoteAudio: panel.querySelector('[data-webphone-remote]'),
        };

        this.ui.connectBtn?.addEventListener('click', () => {
            this.connect(selectedExtension()).catch((error) => {
                this.handleConnectFailure(error);
            });
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
    }

    handleConnectFailure(error) {
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
        showToast('Use the embedded Morpheus phone below to register your line.', 'warning');
    }

    markClickToCallPending() {
        this.pendingClickToCall = true;
        window.setTimeout(() => {
            this.pendingClickToCall = false;
        }, 45000);
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

        this.ui.statusText.textContent = message || labels[state] || state;
        this.ui.dot.dataset.state = state;

        const registered = state === 'registered' || state === 'ringing' || state === 'in-call';
        this.ui.connectBtn.textContent = registered ? 'Reconnect' : 'Connect';
        this.ui.connectBtn.classList.toggle('hidden', state === 'in-call');
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
        } else if (state === 'offline' || state === 'error') {
            this.ui.hint.textContent =
                this.lastError ||
                'Built-in web phone — click Connect and allow microphone access.';
        }
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

        this.connectPromise = this._connect(normalized).finally(() => {
            this.connectPromise = null;
        });

        return this.connectPromise;
    }

    async _connect(extension) {
        await this.disconnect(false);

        this.setState('connecting');
        this.config = await this.prepareConfig(extension);
        this.currentExtension = extension;
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

        this.registerer = new Registerer(this.userAgent);
        await this.waitForRegistration(this.registerer);

        return true;
    }

    waitForRegistration(registerer) {
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
                finish(reject, error);
            });
        });
    }

    handleInvite(invitation) {
        this.session = invitation;
        this.bindSession(invitation);

        const caller = invitation.remoteIdentity?.uri?.user || 'Unknown';
        this.ui.callInfo.textContent = `Incoming: ${caller}`;
        this.ui.callInfo.classList.remove('hidden');
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
            if (state === SessionState.Established) {
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

        if (this.ui?.callInfo) {
            this.ui.callInfo.textContent = '';
            this.ui.callInfo.classList.add('hidden');
        }

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

        return this.state === 'registered' || this.state === 'in-call';
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
