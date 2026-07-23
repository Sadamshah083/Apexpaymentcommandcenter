const TOAST_TITLES = {
    success: 'Success',
    error: 'Error',
    warning: 'Warning',
    info: 'Info',
};

const TOAST_DURATION = {
    success: 5000,
    error: 7000,
    warning: 6500,
    info: 6000,
};

const TOAST_ICONS = {
    success:
        '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>',
    error: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
    warning:
        '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
    info: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>',
};

function getContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'app-toast-container';
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'true');
    }
    document.body.appendChild(container);
    return container;
}

function toastKey(type, message, title) {
    return `${type}::${title || ''}::${String(message ?? '').trim()}`;
}

const COMM_TOAST_DURATION = {
    success: 3200,
    error: 4500,
    warning: 4000,
    info: 3500,
};

export function dismissAllToasts() {
    document.querySelectorAll('.app-toast').forEach((toast) => {
        toast.classList.remove('is-visible');
        toast.remove();
    });
}

export function usesCallSummaryFlow() {
    return Boolean(document.querySelector('[data-call-summary-modal]'));
}

export function showCommToast(message, type = 'info', options = {}) {
    if (usesCallSummaryFlow() && document.body.classList.contains('ch-call-summary-open')) {
        return () => {};
    }

    const toastType = TOAST_TITLES[type] ? type : 'info';

    return showToast(message, toastType, {
        duration: options.duration ?? COMM_TOAST_DURATION[toastType] ?? 3500,
        title: options.title ?? (toastType === 'info' ? 'Phone' : undefined),
        compact: true,
        ...options,
    });
}

export function showToast(message, type = 'success', options = {}) {
    if (options.suppressWhenSummary !== false && usesCallSummaryFlow() && document.body.classList.contains('ch-call-summary-open')) {
        return () => {};
    }

    const container = getContainer();
    const toastType = TOAST_TITLES[type] ? type : 'info';
    const duration = options.duration ?? TOAST_DURATION[toastType] ?? 5000;
    const titleText = options.title ?? TOAST_TITLES[toastType];
    const key = toastKey(toastType, message, titleText);

    // Dedupe: refresh an existing identical toast instead of stacking copies.
    const existing = [...container.querySelectorAll('.app-toast')].find((el) => el.dataset.toastKey === key);
    if (existing) {
        const dismissExisting = existing._toastDismiss;
        if (typeof dismissExisting === 'function' && options.replace !== false) {
            existing.classList.remove('is-visible');
            requestAnimationFrame(() => existing.classList.add('is-visible'));
            if (existing._toastTimer) {
                window.clearTimeout(existing._toastTimer);
            }
            existing._toastTimer = window.setTimeout(() => dismissExisting(), duration);
            return dismissExisting;
        }
        return () => {};
    }

    const toast = document.createElement('div');
    toast.className = `app-toast app-toast-${toastType}${options.compact ? ' app-toast--compact' : ''}`;
    toast.setAttribute('role', 'alert');
    toast.dataset.toastKey = key;

    const icon = document.createElement('div');
    icon.className = 'app-toast-icon';
    icon.innerHTML = TOAST_ICONS[toastType];

    const body = document.createElement('div');
    body.className = 'app-toast-body';

    const title = document.createElement('div');
    title.className = 'app-toast-title';
    title.textContent = titleText;

    const text = document.createElement('div');
    text.className = 'app-toast-message';
    text.textContent = message;

    body.appendChild(title);
    body.appendChild(text);

    if (options.action?.label && options.action?.value) {
        const actionBtn = document.createElement('button');
        actionBtn.type = 'button';
        actionBtn.className = 'app-toast-action';
        actionBtn.textContent = options.action.label;
        actionBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(options.action.value);
                showToast('Copied to clipboard', 'success', { duration: 2500 });
            } catch {
                showToast('Could not copy to clipboard', 'error', { duration: 3000 });
            }
        });
        body.appendChild(actionBtn);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'app-toast-close';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.innerHTML = '&times;';

    toast.appendChild(icon);
    toast.appendChild(body);
    toast.appendChild(closeBtn);
    container.appendChild(toast);

    const dismiss = () => {
        if (toast._toastTimer) {
            window.clearTimeout(toast._toastTimer);
            toast._toastTimer = null;
        }
        toast.classList.remove('is-visible');
        window.setTimeout(() => toast.remove(), 220);
    };

    toast._toastDismiss = dismiss;
    closeBtn.addEventListener('click', dismiss);
    toast._toastTimer = window.setTimeout(dismiss, duration);

    requestAnimationFrame(() => {
        toast.classList.add('is-visible');
    });

    return dismiss;
}

function showFlashMessages() {
    const el = document.getElementById('app-flash-messages');
    if (!el) {
        return;
    }

    const fingerprint = el.textContent?.trim();
    if (!fingerprint) {
        el.remove();
        return;
    }

    const storageKey = `toast-flash-${fingerprint}`;
    try {
        if (sessionStorage.getItem(storageKey) === '1') {
            el.remove();
            return;
        }
        sessionStorage.setItem(storageKey, '1');
    } catch {
        // Continue without dedupe if storage is unavailable.
    }

    let messages = [];
    try {
        messages = JSON.parse(fingerprint);
    } catch {
        el.remove();
        return;
    }

    el.remove();

    messages.forEach((msg, index) => {
        window.setTimeout(() => {
            showToast(msg.message, msg.type || 'info', {
                duration: msg.duration,
                action: msg.action,
                title: msg.title,
            });
        }, index * 180);
    });
}

export function initToasts() {
    showFlashMessages();
}

window.showToast = showToast;
