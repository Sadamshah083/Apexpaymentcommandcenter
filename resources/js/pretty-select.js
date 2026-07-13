function syncPrettySelect(wrapper) {
    const select = wrapper.querySelector('select');
    const label = wrapper.querySelector('[data-pretty-select-label]');
    if (!select || !label) {
        return;
    }

    const option = select.selectedOptions?.[0];
    label.textContent = option?.textContent?.trim() || 'Select…';

    wrapper.querySelectorAll('[data-pretty-select-option]').forEach((button) => {
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

function positionPrettyMenu(wrapper) {
    const trigger = wrapper.querySelector('.pretty-select__trigger');
    const menu = wrapper.querySelector('.pretty-select__menu');
    if (!trigger || !menu || menu.hidden) {
        return;
    }

    const gap = 6;
    const edge = 8;
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 220), window.innerWidth - edge * 2);

    menu.style.position = 'fixed';
    menu.style.zIndex = '12150';
    menu.style.width = `${width}px`;
    menu.style.minWidth = `${Math.min(width, 200)}px`;
    menu.style.maxWidth = `calc(100vw - ${edge * 2}px)`;
    menu.style.right = 'auto';

    let left = rect.left;
    if (left + width > window.innerWidth - edge) {
        left = Math.max(edge, window.innerWidth - edge - width);
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
    });
}

function closePrettySelect(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.pretty-select__trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper.querySelector('.pretty-select__menu');
    if (menu) {
        menu.hidden = true;
        clearPrettyMenuPosition(menu);
    }
}

function closeAllPrettySelects(except = null) {
    document.querySelectorAll('.pretty-select.is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closePrettySelect(wrapper);
        }
    });
}

function buildPrettySelect(select) {
    if (!(select instanceof HTMLSelectElement) || select.dataset.prettySelectBound === '1') {
        return;
    }

    select.dataset.prettySelectBound = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'pretty-select';
    wrapper.dataset.prettySelect = '1';

    select.classList.add('pretty-select__native');
    select.parentNode?.insertBefore(wrapper, select);
    wrapper.appendChild(select);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'pretty-select__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
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

    Array.from(select.options).forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pretty-select__option';
        button.setAttribute('role', 'option');
        button.dataset.prettySelectOption = '1';
        button.dataset.value = option.value;
        button.textContent = option.textContent?.trim() || option.value || 'Skip';
        menu.appendChild(button);
    });

    wrapper.appendChild(menu);
    syncPrettySelect(wrapper);

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const willOpen = !wrapper.classList.contains('is-open');
        closeAllPrettySelects(willOpen ? wrapper : null);
        wrapper.classList.toggle('is-open', willOpen);
        trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        menu.hidden = !willOpen;

        if (willOpen) {
            positionPrettyMenu(wrapper);
        } else {
            clearPrettyMenuPosition(menu);
        }
    });

    menu.querySelectorAll('[data-pretty-select-option]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

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

    select.addEventListener('change', () => syncPrettySelect(wrapper));
}

export function initPrettySelects(root = document) {
    root.querySelectorAll('select[data-pretty-select], .js-pretty-select').forEach(buildPrettySelect);

    if (document.body.dataset.prettySelectGlobalBound === '1') {
        return;
    }

    document.body.dataset.prettySelectGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('.pretty-select')) {
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
