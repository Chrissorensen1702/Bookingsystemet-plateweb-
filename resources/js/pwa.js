const serviceWorkerUrl = document
  .querySelector('meta[name="pwa-sw-url"]')
  ?.getAttribute('content');

const resetRequested = new URLSearchParams(window.location.search).get('pwa-reset') === '1';
const disableServiceWorker = document
  .querySelector('meta[name="pwa-disable-sw"]')
  ?.getAttribute('content') === '1';

const resetServiceWorkers = async () => {
  const registrations = await navigator.serviceWorker.getRegistrations();
  await Promise.all(registrations.map((registration) => registration.unregister()));

  if ('caches' in window) {
    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.map((cacheKey) => caches.delete(cacheKey)));
  }

  return registrations.length > 0;
};

if ('serviceWorker' in navigator && (resetRequested || disableServiceWorker)) {
  window.addEventListener('load', () => {
    Promise.resolve()
      .then(resetServiceWorkers)
      .then((hadRegistrations) => {
        if (resetRequested) {
          const resetUrl = new URL(window.location.href);
          resetUrl.searchParams.delete('pwa-reset');
          window.location.replace(resetUrl.toString());
          return;
        }

        const reloadKey = 'platebook-login-sw-reset';

        if (disableServiceWorker && hadRegistrations && !window.sessionStorage.getItem(reloadKey)) {
          window.sessionStorage.setItem(reloadKey, '1');
          window.location.reload();
          return;
        }

        window.sessionStorage.removeItem(reloadKey);
      });
  });
} else if ('serviceWorker' in navigator && serviceWorkerUrl) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(serviceWorkerUrl, { updateViaCache: 'none' })
      .then((registration) => {
        let hasReloadedForNewWorker = false;

        const activateWaitingWorker = () => {
          if (registration.waiting) {
            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
          }
        };

        if (registration.waiting) {
          activateWaitingWorker();
        }

        registration.addEventListener('updatefound', () => {
          const installingWorker = registration.installing;

          if (!installingWorker) {
            return;
          }

          installingWorker.addEventListener('statechange', () => {
            if (
              installingWorker.state === 'installed' &&
              navigator.serviceWorker.controller
            ) {
              activateWaitingWorker();
            }
          });
        });

        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (hasReloadedForNewWorker) {
            return;
          }

          hasReloadedForNewWorker = true;
          window.location.reload();
        });

        registration.update().catch(() => {
          // Ignore update checks that fail intermittently.
        });
      })
      .catch(() => {
        // Silent fail in local/dev environments without HTTPS or proper scope.
      });
  });
}
