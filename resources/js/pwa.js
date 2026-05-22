// Register service worker for PWA support
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((reg) => {
                // Check for updates every 60 minutes
                setInterval(() => reg.update(), 60 * 60 * 1000);
            })
            .catch(() => {});
    });
}

// PWA install prompt — store the event so the page can trigger it
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window._pwaInstallPrompt = e;
    document.dispatchEvent(new CustomEvent('pwa:installable'));
});

window.addEventListener('appinstalled', () => {
    window._pwaInstallPrompt = null;
    document.dispatchEvent(new CustomEvent('pwa:installed'));
});
