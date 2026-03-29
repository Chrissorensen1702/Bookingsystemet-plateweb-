const serviceWorkerUrl = document
  .querySelector('meta[name="pwa-sw-url"]')
  ?.getAttribute('content');

if ('serviceWorker' in navigator && serviceWorkerUrl) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(serviceWorkerUrl).catch(() => {
      // Silent fail in local/dev environments without HTTPS or proper scope.
    });
  });
}
