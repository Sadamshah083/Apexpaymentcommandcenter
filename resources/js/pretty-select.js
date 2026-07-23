const SKIP_SELECTORS = [
    '.pretty-select__native',
    '.um-role-dropdown-native',
    '.import-campaign-dropdown__native',
    '[data-pretty-select-skip]',
    '[aria-hidden="true"]',
].join(', ');

function syncPrettySelect(wrapper) {
    const select = wrapper._prettySelectEl;
    const label = wrapper.querySelector('[data-pretty-select-label]');
    if (!select || !label) {
        return;
    }

    const option = select.selectedOptions?.[0];
    label.textContent = option?.textContent?.trim() || 'Select…';
    label.title = label.textContent;

    const menu = wrapper._prettyMenuEl;
    menu?.querySelectorAll('[data-pretty-select-option]').forEach((button) => {
        const selected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
}

function clearPrettyMenuPosition(menu) {
    if (!menu) {
        return;
    }

    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.width = '';
    menu.style.minWidth = '';
    menu.style.maxWidth = '';
    menu.style.zIndex = '';
}

function measureOptionWidth(menu) {
    const probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font:inherit;padding:0.5rem 0.75rem;';
    probe.className = 'pretty-select__option';
    document.body.appendChild(probe);

    let max = 0;
    menu.querySelectorAll('[data-pretty-select-option]').forEach((button) => {
        probe.textContent = button.textContent || '';
        max = Math.max(max, probe.offsetWidth);
    });

    probe.remove();
    return max;
}

function positionPrettyMenu(wrapper) {
    const trigger = wrapper.querySelector('.pretty-select__trigger');
    const menu = wrapper._prettyMenuEl;
    if (!trigger || !menu || menu.hidden) {
        return;
    }

    if (menu.parentElement !== document.body) {
        document.body.appendChild(menu);
    }

    const gap = 6;
    const edge = 10;
    const rect = trigger.getBoundingClientRect();
    const contentWidth = measureOptionWidth(menu);
    const matchTrigger = wrapper.dataset.prettySelectWidth === 'trigger';
    const preferred = matchTrigger ? rect.width : Math.max(rect.width, Math.min(contentWidth + 16, 420));
    const width = Math.min(Math.max(preferred, 180), window.innerWidth - edge * 2);

    menu.style.position = 'fixed';
    menu.style.zIndex = '12500';
    menu.style.width = `${Math.round(width)}px`;
    menu.style.minWidth = `${Math.round(Math.min(width, rect.width))}px`;
    menu.style.maxWidth = `calc(100vw - ${edge * 2}px)`;
    menu.style.right = 'auto';
    menu.style.overflowX = 'hidden';

    let left = rect.left;
    if (left + width > window.innerWidth - edge) {
        left = Math.max(edge, window.innerWidth - edge - width);
    }
    if (left < edge) {
        left = edge;
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(rect.bottom + gap)}px`;

    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        if (menuRect.bottom > window.innerHeight - edge) {
            const upTop = rect.top - menuRect.height - gap;
            if (upTop >= edge) {
                menu.style.top = `${Math.round(upTop)}px`;
            }
        }
        menu.scrollLeft = 0;
    });
}

function closePrettySelect(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.pretty-select__trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper._prettyMenuEl;
    if (menu) {
        menu.hidden = true;
        clearPrettyMenuPosition(menu);
        if (menu.parentElement === document.body) {
            wrapper.appendChild(menu);
        }
    }
}

function closeAllPrettySelects(except = null) {
    document.querySelectorAll('.pretty-select.is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closePrettySelect(wrapper);
        }
    });
}

function rebuildMenuOptions(wrapper) {
    const select = wrapper._prettySelectEl;
    const menu = wrapper._prettyMenuEl;
    if (!select || !menu) {
        return;
    }

    menu.replaceChildren();

    Array.from(select.options).forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pretty-select__option';
        button.setAttribute('role', 'option');
        button.dataset.prettySelectOption = '1';
        button.dataset.value = option.value;
        const text = option.textContent?.trim() || option.value || 'Skip';
        button.textContent = text;
        button.title = text;
        button.disabled = option.disabled;
        menu.appendChild(button);
    });

    menu.querySelectorAll('[data-pretty-select-option]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (button.disabled) {
                return;
            }

            const nextValue = button.dataset.value ?? '';
            select.value = nextValue;
            Array.from(select.options).forEach((option) => {
                option.selected = option.value === nextValue;
            });

            syncPrettySelect(wrapper);
            closePrettySelect(wrapper);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    syncPrettySelect(wrapper);
}

function shouldSkipSelect(select) {
    if (!(select instanceof HTMLSelectElement)) {
        return true;
    }

    if (select.dataset.prettySelectBound === '1') {
        return true;
    }

    if (select.matches(SKIP_SELECTORS)) {
        return true;
    }

    if (select.closest('.um-role-dropdown, .um-module-picker, .import-campaign-dropdown, .ghl-line-dropdown')) {
        return true;
    }

    if (select.multiple) {
        return true;
    }

    return false;
}

function buildPrettySelect(select) {
    if (shouldSkipSelect(select)) {
        return;
    }

    select.dataset.prettySelectBound = '1';

    const sizeClass = select.classList.contains('app-input-sm') || select.classList.contains('um-select-sm')
        ? 'pretty-select--sm'
        : '';

    const wrapper = document.createElement('div');
    wrapper.className = `pretty-select ${sizeClass}`.trim();
    wrapper.dataset.prettySelect = '1';
    if (select.dataset.prettySelectWidth) {
        wrapper.dataset.prettySelectWidth = select.dataset.prettySelectWidth;
    }

    select.classList.add('pretty-select__native');
    select.parentNode?.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    wrapper._prettySelectEl = select;

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'pretty-select__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    if (select.disabled) {
        trigger.disabled = true;
    }
    trigger.innerHTML = `
        <span class="pretty-select__value" data-pretty-select-label></span>
        <svg class="pretty-select__chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    `;
    wrapper.appendChild(trigger);

    const menu = document.createElement('div');
    menu.className = 'pretty-select__menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    wrapper.appendChild(menu);
    wrapper._prettyMenuEl = menu;

    rebuildMenuOptions(wrapper);

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (select.disabled || trigger.disabled) {
            return;
        }

        const willOpen = !wrapper.classList.contains('is-open');
        closeAllPrettySelects(willOpen ? wrapper : null);
        wrapper.classList.toggle('is-open', willOpen);
        trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        menu.hidden = !willOpen;

        if (willOpen) {
            positionPrettyMenu(wrapper);
        } else {
            closePrettySelect(wrapper);
        }
    });

    select.addEventListener('change', () => syncPrettySelect(wrapper));

    const observer = new MutationObserver(() => {
        rebuildMenuOptions(wrapper);
        if (wrapper.classList.contains('is-open')) {
            positionPrettyMenu(wrapper);
        }
    });
    observer.observe(select, { childList: true, subtree: true, characterData: true });
    wrapper._prettyObserver = observer;
}

export function refreshPrettySelect(select) {
    const wrapper = select?.closest?.('.pretty-select');
    if (!wrapper) {
        return;
    }
    rebuildMenuOptions(wrapper);
    syncPrettySelect(wrapper);
}

export function initPrettySelects(root = document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    scope.querySelectorAll('select.app-input, select.um-select, select.um-input, select.js-pretty-select, select[data-pretty-select]').forEach(buildPrettySelect);

    if (document.body.dataset.prettySelectGlobalBound === '1') {
        return;
    }

    document.body.dataset.prettySelectGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('.pretty-select, .pretty-select__menu')) {
            return;
        }
        closeAllPrettySelects();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllPrettySelects();
        }
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('.pretty-select.is-open').forEach(positionPrettyMenu);
    }, true);

    window.addEventListener('resize', () => {
        document.querySelectorAll('.pretty-select.is-open').forEach(positionPrettyMenu);
    });
}

if (typeof window !== 'undefined') {
    window.initPrettySelects = initPrettySelects;
    window.refreshPrettySelect = refreshPrettySelect;
}
