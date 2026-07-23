const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];
const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

function pad2(n) {
    return String(n).padStart(2, '0');
}

function toIso(date) {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function parseIso(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }
    const [y, m, d] = value.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) {
        return null;
    }
    return date;
}

function formatDisplay(value) {
    const date = parseIso(value);
    if (!date) {
        return 'Select date';
    }
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function closeAllPrettyDates(except = null) {
    document.querySelectorAll('.pretty-date.is-open').forEach((wrapper) => {
        if (except && wrapper === except) {
            return;
        }
        closePrettyDate(wrapper);
    });
}

function clearCalendarPosition(cal) {
    if (!cal) {
        return;
    }
    cal.style.position = '';
    cal.style.top = '';
    cal.style.left = '';
    cal.style.right = '';
    cal.style.width = '';
    cal.style.zIndex = '';
}

function positionCalendar(wrapper) {
    const trigger = wrapper.querySelector('.pretty-date__trigger');
    const cal = wrapper._prettyDateCal;
    if (!trigger || !cal || cal.hidden) {
        return;
    }

    if (cal.parentElement !== document.body) {
        document.body.appendChild(cal);
    }

    const gap = 8;
    const edge = 10;
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(292, window.innerWidth - edge * 2);

    cal.style.position = 'fixed';
    cal.style.zIndex = '12600';
    cal.style.width = `${Math.round(width)}px`;

    let left = rect.left;
    if (left + width > window.innerWidth - edge) {
        left = window.innerWidth - edge - width;
    }
    left = Math.max(edge, left);

    const below = rect.bottom + gap;
    const spaceBelow = window.innerHeight - below;
    const spaceAbove = rect.top - gap;
    const preferBelow = spaceBelow >= 320 || spaceBelow >= spaceAbove;

    cal.style.left = `${Math.round(left)}px`;
    if (preferBelow) {
        cal.style.top = `${Math.round(below)}px`;
    } else {
        const height = cal.offsetHeight || 320;
        cal.style.top = `${Math.round(Math.max(edge, rect.top - gap - height))}px`;
    }
}

function renderCalendar(wrapper) {
    const input = wrapper._prettyDateInput;
    const cal = wrapper._prettyDateCal;
    if (!input || !cal) {
        return;
    }

    const selected = parseIso(input.value);
    let view = wrapper._prettyDateView;
    if (!(view instanceof Date)) {
        view = selected ? new Date(selected.getFullYear(), selected.getMonth(), 1) : new Date();
        view = new Date(view.getFullYear(), view.getMonth(), 1);
        wrapper._prettyDateView = view;
    }

    const year = view.getFullYear();
    const month = view.getMonth();
    const firstDow = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrev = new Date(year, month, 0).getDate();
    const todayIso = toIso(new Date());
    const selectedIso = selected ? toIso(selected) : '';

    const title = cal.querySelector('[data-pretty-date-title]');
    if (title) {
        title.textContent = `${MONTHS[month]} ${year}`;
    }

    const grid = cal.querySelector('[data-pretty-date-grid]');
    if (!grid) {
        return;
    }

    const cells = [];
    for (let i = 0; i < 42; i += 1) {
        let dayNum;
        let cellDate;
        let muted = false;

        if (i < firstDow) {
            dayNum = daysInPrev - firstDow + i + 1;
            cellDate = new Date(year, month - 1, dayNum);
            muted = true;
        } else if (i - firstDow + 1 > daysInMonth) {
            dayNum = i - firstDow + 1 - daysInMonth;
            cellDate = new Date(year, month + 1, dayNum);
            muted = true;
        } else {
            dayNum = i - firstDow + 1;
            cellDate = new Date(year, month, dayNum);
        }

        const iso = toIso(cellDate);
        const classes = ['pretty-date__day'];
        if (muted) {
            classes.push('is-muted');
        }
        if (iso === todayIso) {
            classes.push('is-today');
        }
        if (iso === selectedIso) {
            classes.push('is-selected');
        }

        cells.push(
            `<button type="button" class="${classes.join(' ')}" data-pretty-date-day data-value="${iso}" aria-label="${iso}">${dayNum}</button>`,
        );
    }

    grid.innerHTML = cells.join('');
}

function syncPrettyDate(wrapper) {
    const input = wrapper._prettyDateInput;
    const label = wrapper.querySelector('[data-pretty-date-label]');
    if (!input || !label) {
        return;
    }
    label.textContent = formatDisplay(input.value);
    label.classList.toggle('is-placeholder', !input.value);
}

function closePrettyDate(wrapper) {
    wrapper.classList.remove('is-open');
    const trigger = wrapper.querySelector('.pretty-date__trigger');
    trigger?.setAttribute('aria-expanded', 'false');
    const cal = wrapper._prettyDateCal;
    if (cal) {
        cal.hidden = true;
        clearCalendarPosition(cal);
    }
}

function openPrettyDate(wrapper) {
    closeAllPrettyDates(wrapper);
    const input = wrapper._prettyDateInput;
    const selected = parseIso(input?.value);
    wrapper._prettyDateView = selected
        ? new Date(selected.getFullYear(), selected.getMonth(), 1)
        : new Date(new Date().getFullYear(), new Date().getMonth(), 1);

    renderCalendar(wrapper);
    wrapper.classList.add('is-open');
    const trigger = wrapper.querySelector('.pretty-date__trigger');
    trigger?.setAttribute('aria-expanded', 'true');
    const cal = wrapper._prettyDateCal;
    if (cal) {
        cal.hidden = false;
        positionCalendar(wrapper);
    }
}

function buildPrettyDate(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== 'date') {
        return;
    }
    if (input.dataset.prettyDateBound === '1' || input.closest('.pretty-date')) {
        return;
    }
    if (input.matches('[data-pretty-date-skip]')) {
        return;
    }

    input.dataset.prettyDateBound = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'pretty-date';
    if (input.classList.contains('app-input')) {
        wrapper.classList.add('pretty-date--app');
    }

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'pretty-date__trigger';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.disabled = input.disabled;

    const label = document.createElement('span');
    label.className = 'pretty-date__value';
    label.dataset.prettyDateLabel = '';

    const icon = document.createElement('span');
    icon.className = 'pretty-date__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="16" rx="2.5" stroke-width="1.75"/><path stroke-linecap="round" stroke-width="1.75" d="M8 3v4M16 3v4M3 10h18"/></svg>';

    trigger.append(label, icon);

    const cal = document.createElement('div');
    cal.className = 'pretty-date__calendar';
    cal.hidden = true;
    cal.setAttribute('role', 'dialog');
    cal.setAttribute('aria-label', 'Choose date');
    cal.innerHTML = `
        <div class="pretty-date__header">
            <button type="button" class="pretty-date__nav" data-pretty-date-prev aria-label="Previous month">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <p class="pretty-date__title" data-pretty-date-title></p>
            <button type="button" class="pretty-date__nav" data-pretty-date-next aria-label="Next month">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
        <div class="pretty-date__weekdays">${WEEKDAYS.map((d) => `<span>${d}</span>`).join('')}</div>
        <div class="pretty-date__grid" data-pretty-date-grid></div>
        <div class="pretty-date__footer">
            ${input.required ? '<span></span>' : '<button type="button" class="pretty-date__link" data-pretty-date-clear>Clear</button>'}
            <button type="button" class="pretty-date__link is-accent" data-pretty-date-today>Today</button>
        </div>
    `;

    input.classList.add('pretty-date__native');
    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(input, trigger);
    document.body.appendChild(cal);

    wrapper._prettyDateInput = input;
    wrapper._prettyDateCal = cal;
    cal._prettyDateWrapper = wrapper;

    syncPrettyDate(wrapper);

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (input.disabled || trigger.disabled) {
            return;
        }
        if (wrapper.classList.contains('is-open')) {
            closePrettyDate(wrapper);
        } else {
            openPrettyDate(wrapper);
        }
    });

    cal.addEventListener('click', (event) => {
        event.stopPropagation();
        const dayBtn = event.target.closest('[data-pretty-date-day]');
        if (dayBtn) {
            input.value = dayBtn.dataset.value || '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            syncPrettyDate(wrapper);
            closePrettyDate(wrapper);
            return;
        }

        if (event.target.closest('[data-pretty-date-prev]')) {
            const view = wrapper._prettyDateView || new Date();
            wrapper._prettyDateView = new Date(view.getFullYear(), view.getMonth() - 1, 1);
            renderCalendar(wrapper);
            positionCalendar(wrapper);
            return;
        }

        if (event.target.closest('[data-pretty-date-next]')) {
            const view = wrapper._prettyDateView || new Date();
            wrapper._prettyDateView = new Date(view.getFullYear(), view.getMonth() + 1, 1);
            renderCalendar(wrapper);
            positionCalendar(wrapper);
            return;
        }

        if (event.target.closest('[data-pretty-date-today]')) {
            input.value = toIso(new Date());
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            syncPrettyDate(wrapper);
            closePrettyDate(wrapper);
            return;
        }

        if (event.target.closest('[data-pretty-date-clear]')) {
            if (input.required) {
                return;
            }
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            syncPrettyDate(wrapper);
            closePrettyDate(wrapper);
        }
    });

    input.addEventListener('change', () => syncPrettyDate(wrapper));
}

export function initPrettyDates(root = document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    scope.querySelectorAll('input[type="date"].js-pretty-date, input[type="date"][data-pretty-date]').forEach(buildPrettyDate);

    if (document.body.dataset.prettyDateGlobalBound === '1') {
        return;
    }

    document.body.dataset.prettyDateGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('.pretty-date, .pretty-date__calendar')) {
            return;
        }
        closeAllPrettyDates();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllPrettyDates();
        }
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('.pretty-date.is-open').forEach(positionCalendar);
    }, true);

    window.addEventListener('resize', () => {
        document.querySelectorAll('.pretty-date.is-open').forEach(positionCalendar);
    });
}

if (typeof window !== 'undefined') {
    window.initPrettyDates = initPrettyDates;
}
