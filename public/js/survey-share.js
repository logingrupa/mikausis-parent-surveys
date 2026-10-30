(() => {
    const pending = new WeakSet();

    function publicUrl(value) {
        try {
            const url = new URL(value, window.location.href);
            return url.origin === window.location.origin &&
                /^\/a\/[^/]+$/.test(url.pathname) &&
                !url.search && !url.hash && !url.username && !url.password
                ? url.href
                : null;
        } catch {
            return null;
        }
    }

    async function copy(button, url) {
        try {
            if (!navigator.clipboard?.writeText) throw new Error();
            await navigator.clipboard.writeText(url);
            let status = button.parentElement.querySelector('[data-share-status]');
            if (!status) {
                status = document.createElement('span');
                status.dataset.shareStatus = '';
                status.className = 'survey-share-status';
                status.setAttribute('role', 'status');
                button.parentElement.append(status);
            }
            status.textContent = button.dataset.shareSuccess;
        } catch {
            window.prompt(button.dataset.shareCopy, url);
        }
    }

    window.MikausisSurveyShare = {
        async share(button) {
            if (!button || pending.has(button)) return;
            const url = publicUrl(button.dataset.shareUrl);
            if (!url) return;
            const data = { title: button.dataset.shareTitle, url };
            pending.add(button);
            const disabled = button.disabled;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            try {
                if (typeof navigator.share === 'function') {
                    try {
                        if (typeof navigator.canShare !== 'function' || navigator.canShare(data)) {
                            await navigator.share(data);
                            return;
                        }
                    } catch (error) {
                        if (error?.name === 'AbortError') return;
                    }
                }
                await copy(button, url);
            } finally {
                pending.delete(button);
                button.disabled = disabled;
                button.removeAttribute('aria-busy');
            }
        },
    };
})();
