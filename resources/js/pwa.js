const serviceWorkerUrl = document
  .querySelector('meta[name="pwa-sw-url"]')
  ?.getAttribute('content');

const resetRequested = new URLSearchParams(window.location.search).get('pwa-reset') === '1';
const disableServiceWorker = document
  .querySelector('meta[name="pwa-disable-sw"]')
  ?.getAttribute('content') === '1';
const csrfTokenUrl = new URL('/csrf-token', window.location.origin).toString();
const nativeSubmit = HTMLFormElement.prototype.submit;
const authStatePollDelays = [250, 400, 700, 1000, 1400, 1800];

const resetServiceWorkers = async () => {
  const registrations = await navigator.serviceWorker.getRegistrations();
  await Promise.all(registrations.map((registration) => registration.unregister()));

  if ('caches' in window) {
    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.map((cacheKey) => caches.delete(cacheKey)));
  }

  return registrations.length > 0;
};

const getCsrfToken = async () => {
  const response = await fetch(csrfTokenUrl, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (!response.ok) {
    throw new Error('Could not refresh CSRF token.');
  }

  const payload = await response.json().catch(() => null);
  const token = payload && typeof payload.token === 'string' ? payload.token.trim() : '';

  if (token === '') {
    throw new Error('Missing CSRF token in refresh response.');
  }

  return token;
};

const syncFormToken = (form, token) => {
  let input = form.querySelector('input[name="_token"]');

  if (!(input instanceof HTMLInputElement)) {
    input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_token';
    form.prepend(input);
  }

  input.value = token;

  if (form.dataset.passkeyCsrf !== undefined) {
    form.dataset.passkeyCsrf = token;
  }

  return token;
};

const refreshFormToken = async (form) => {
  const token = await getCsrfToken();

  return syncFormToken(form, token);
};

const isSameOriginPostForm = (form) => {
  if (!(form instanceof HTMLFormElement)) {
    return false;
  }

  if ((form.method || 'GET').toUpperCase() !== 'POST') {
    return false;
  }

  const action = form.getAttribute('action') || window.location.href;
  const actionUrl = new URL(action, window.location.href);

  return actionUrl.origin === window.location.origin;
};

const prefersNativeResubmit = (form) => form.dataset.csrfSubmitMode === 'native';

const authStateGoalReached = (goal, authenticated) => (
  (goal === 'authenticated' && authenticated)
  || (goal === 'guest' && !authenticated)
);

const startAuthStateRedirectFallback = (form) => {
  const authStateUrl = form.dataset.authStateUrl || '';
  const authStateGoal = form.dataset.authStateGoal || '';

  if (authStateUrl === '' || authStateGoal === '') {
    return;
  }

  const pollUrl = new URL(authStateUrl, window.location.origin);
  const guard = form.dataset.authStateGuard || '';

  if (guard !== '') {
    pollUrl.searchParams.set('guard', guard);
  }

  let cancelled = false;
  const stopPolling = () => {
    cancelled = true;
  };

  window.addEventListener('pagehide', stopPolling, { once: true });

  const poll = async (attempt = 0) => {
    if (cancelled) {
      return;
    }

    try {
      const response = await fetch(pollUrl.toString(), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        cache: 'no-store',
      });

      if (response.ok) {
        const payload = await response.json().catch(() => null);
        const authenticated = payload?.authenticated === true;

        if (authStateGoalReached(authStateGoal, authenticated)) {
          const redirect = typeof payload?.redirect === 'string' && payload.redirect.trim() !== ''
            ? payload.redirect
            : (form.dataset.authStateRedirect || window.location.href);

          window.location.assign(redirect);
          return;
        }
      }
    } catch {
      // Ignore intermittent auth-state polling failures.
    }

    if (attempt >= authStatePollDelays.length - 1) {
      return;
    }

    window.setTimeout(() => {
      void poll(attempt + 1);
    }, authStatePollDelays[attempt]);
  };

  window.setTimeout(() => {
    void poll();
  }, authStatePollDelays[0]);
};

window.PlateBookCsrf = {
  refreshForm: refreshFormToken,
};

document.addEventListener('submit', (event) => {
  const form = event.target;

  if (!(form instanceof HTMLFormElement) || event.defaultPrevented || !isSameOriginPostForm(form)) {
    return;
  }

  if (form.dataset.csrfFresh === '1') {
    delete form.dataset.csrfFresh;
    return;
  }

  if (form.dataset.csrfRefreshing === '1') {
    event.preventDefault();
    return;
  }

  event.preventDefault();
  form.dataset.csrfRefreshing = '1';
  const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;

  refreshFormToken(form)
    .catch(() => {
      // Fall back to the current token if the refresh request fails.
    })
    .finally(() => {
      delete form.dataset.csrfRefreshing;

      if (prefersNativeResubmit(form)) {
        startAuthStateRedirectFallback(form);
        nativeSubmit.call(form);
        return;
      }

      if (typeof form.requestSubmit === 'function') {
        form.dataset.csrfFresh = '1';

        if (submitter && 'form' in submitter && submitter.form === form) {
          form.requestSubmit(submitter);
          return;
        }

        form.requestSubmit();
        return;
      }

      nativeSubmit.call(form);
    });
});

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
