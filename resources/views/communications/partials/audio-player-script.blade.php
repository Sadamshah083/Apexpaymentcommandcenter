<script>
    (() => {
        const player = document.getElementById('comm-hub-player');
        const audio = document.getElementById('comm-hub-audio');
        if (!player || !audio) return;

        let objectUrl = null;

        const revokeObjectUrl = () => {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        };

        const showPlayError = (detail = '') => {
            const suffix = detail ? ` ${detail}` : '';
            window.showToast?.(
                `Could not play audio.${suffix} Recording media is not available via Morpheus CX.`,
                'error'
            );
        };

        const playFromUrl = async (url) => {
            revokeObjectUrl();
            audio.pause();
            audio.removeAttribute('src');

            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'audio/*,*/*'
                },
            });

            if (!response.ok) {
                const message = (await response.text()).trim();
                const plain = message.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                showPlayError(plain);
                return;
            }

            const blob = await response.blob();
            if (!blob.size) {
                showPlayError('Empty audio response.');
                return;
            }

            objectUrl = URL.createObjectURL(blob);
            audio.src = objectUrl;
            player.classList.remove('hidden');
            player.setAttribute('aria-hidden', 'false');

            try {
                await audio.play();
            } catch {
                showPlayError();
            }
        };

        document.querySelectorAll('.comm-hub-play-btn').forEach((button) => {
            button.addEventListener('click', () => {
                playFromUrl(button.dataset.playUrl).catch(() => showPlayError());
            });
        });

        document.querySelector('[data-close-player]')?.addEventListener('click', () => {
            audio.pause();
            revokeObjectUrl();
            audio.removeAttribute('src');
            player.classList.add('hidden');
            player.setAttribute('aria-hidden', 'true');
        });
    })();
</script>
