<script>
window.initGhlDialer = function (config) {
    const numberInput = document.getElementById(config.numberInputId);
    const callerSelect = document.getElementById(config.callerSelectId);
    const dialBtn = document.getElementById(config.dialBtnId);
    const backspace = config.backspaceId ? document.getElementById(config.backspaceId) : null;
    const keypadRoot = config.keypadRootId ? document.getElementById(config.keypadRootId) : document;

    if (!numberInput || !dialBtn) {
        return;
    }

    const storageKey = 'communications.dialer_caller_id';

    if (callerSelect) {
        const savedCaller = localStorage.getItem(storageKey);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }
    }

    function normalizePhone(value) {
        const digits = String(value || '').replace(/[^\d+]/g, '');
        if (!digits) return '';
        if (digits.startsWith('+')) return digits;
        const numeric = digits.replace(/^0+/, '');
        if (numeric.length === 10) return '+1' + numeric;
        return '+' + numeric;
    }

    function buildDialUrl() {
        const target = normalizePhone(numberInput.value);
        if (!target) return null;
        let url = 'zoomphonecall://' + target;
        const caller = callerSelect ? normalizePhone(callerSelect.value) : '';
        if (caller) url += '?callerid=' + encodeURIComponent(caller);
        return url;
    }

    function refreshDialButton() {
        const url = buildDialUrl();
        if (!url) {
            dialBtn.setAttribute('href', '#');
            dialBtn.classList.add('opacity-50', 'pointer-events-none');
            return;
        }
        dialBtn.setAttribute('href', url);
        dialBtn.classList.remove('opacity-50', 'pointer-events-none');
    }

    numberInput.addEventListener('input', refreshDialButton);

    if (callerSelect) {
        callerSelect.addEventListener('change', function () {
            localStorage.setItem(storageKey, callerSelect.value || '');
            refreshDialButton();
        });
    }

    keypadRoot.querySelectorAll('[data-dial-key]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value += button.getAttribute('data-dial-key');
            refreshDialButton();
        });
    });

    document.querySelectorAll('[data-dial-number]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value = button.getAttribute('data-dial-number') || '';
            refreshDialButton();
        });
    });

    if (backspace) {
        backspace.addEventListener('click', function () {
            numberInput.value = numberInput.value.slice(0, -1);
            refreshDialButton();
        });
    }

    refreshDialButton();
};
</script>
