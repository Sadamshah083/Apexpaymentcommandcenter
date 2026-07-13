/**
 * Prefetch the import create screen so "+ Import file" feels instant.
 */
export function initFastImportNav() {
    const link = document.querySelector('[data-import-file-nav]');
    if (!link?.href) {
        return;
    }

    const url = link.href;

    const prefetch = () => {
        if (document.querySelector(`link[data-import-prefetch][href="${url}"]`)) {
            return;
        }

        const hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.href = url;
        hint.setAttribute('data-import-prefetch', '1');
        document.head.appendChild(hint);
    };

    if (document.readyState === 'complete') {
        prefetch();
    } else {
        window.addEventListener('load', prefetch, { once: true });
    }

    link.addEventListener('mouseenter', prefetch, { passive: true });
    link.addEventListener('focus', prefetch, { passive: true });
}
