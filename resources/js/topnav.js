function closeTopnavDropdowns(except = null) {
    document.querySelectorAll('.app-topnav-dropdown[open]').forEach((dropdown) => {
        if (dropdown !== except) {
            dropdown.open = false;
        }
    });
}

export function initTopnav() {
    if (document.body.dataset.topnavBound === '1') {
        closeTopnavDropdowns();
        return;
    }

    document.body.dataset.topnavBound = '1';

    const dropdowns = document.querySelectorAll('.app-topnav-dropdown');
    if (dropdowns.length === 0) {
        return;
    }

    dropdowns.forEach((dropdown) => {
        dropdown.addEventListener('toggle', () => {
            if (dropdown.open) {
                closeTopnavDropdowns(dropdown);
            }
        });
    });

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (!dropdown.open) {
                return;
            }

            if (!dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTopnavDropdowns();
        }
    });
}
