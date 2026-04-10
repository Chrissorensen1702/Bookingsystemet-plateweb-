const forms = document.querySelectorAll('[data-passkey-login]');

const base64UrlToBuffer = (value) => {
  const normalized = String(value || '')
    .replace(/-/g, '+')
    .replace(/_/g, '/')
    .padEnd(Math.ceil(String(value || '').length / 4) * 4, '=');

  const binary = window.atob(normalized);
  const bytes = new Uint8Array(binary.length);

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }

  return bytes.buffer;
};

const bufferToBase64 = (value) => {
  const bytes = value instanceof ArrayBuffer ? new Uint8Array(value) : new Uint8Array(value.buffer);
  let binary = '';

  for (let index = 0; index < bytes.byteLength; index += 1) {
    binary += String.fromCharCode(bytes[index]);
  }

  return window.btoa(binary);
};

const normalizeAssertionOptions = (publicKey) => {
  const normalized = { ...publicKey };
  normalized.challenge = base64UrlToBuffer(publicKey.challenge);

  if (Array.isArray(publicKey.allowCredentials)) {
    normalized.allowCredentials = publicKey.allowCredentials.map((credential) => ({
      ...credential,
      id: base64UrlToBuffer(credential.id),
    }));
  }

  return normalized;
};

const serializeAssertion = (credential) => ({
  id: credential.id,
  type: credential.type,
  rawId: bufferToBase64(credential.rawId),
  response: {
    authenticatorData: bufferToBase64(credential.response.authenticatorData).replace(/=/g, ''),
    clientDataJSON: bufferToBase64(credential.response.clientDataJSON).replace(/=/g, ''),
    signature: bufferToBase64(credential.response.signature),
    userHandle: credential.response.userHandle ? bufferToBase64(credential.response.userHandle) : null,
  },
});

const firstErrorMessage = (payload, fallback) => {
  if (!payload || typeof payload !== 'object') {
    return fallback;
  }

  const validationErrors = payload.errors && typeof payload.errors === 'object'
    ? Object.values(payload.errors)
    : [];

  if (validationErrors.length > 0 && Array.isArray(validationErrors[0]) && validationErrors[0][0]) {
    return String(validationErrors[0][0]);
  }

  if (typeof payload.message === 'string' && payload.message.trim() !== '') {
    return payload.message;
  }

  return fallback;
};

const parseJsonResponse = async (response) => {
  try {
    return await response.json();
  } catch {
    return null;
  }
};

const hasPasskeySupport = () => (
  Boolean(window.PublicKeyCredential)
  && typeof navigator.credentials?.get === 'function'
  && window.isSecureContext
);

for (const form of forms) {
  const button = form.querySelector('[data-passkey-login-button]');
  const feedback = form.querySelector('[data-passkey-feedback]');
  const emailField = form.querySelector('input[name="email"]');

  if (!(button instanceof HTMLButtonElement) || !(emailField instanceof HTMLInputElement)) {
    continue;
  }

  const csrf = form.dataset.passkeyCsrf || '';
  const optionsUrl = form.dataset.passkeyOptionsUrl || '';
  const authUrl = form.dataset.passkeyAuthUrl || '';
  const redirectUrl = form.dataset.passkeyRedirectUrl || '/';
  const nativeApp = form.dataset.nativeApp === '1';
  const initialLabel = button.textContent || 'Log ind med passkey';
  const csrfInput = form.querySelector('input[name="_token"]');

  const setFeedback = (message, type = 'error') => {
    if (!feedback) {
      return;
    }

    feedback.textContent = message;
    feedback.hidden = false;
    feedback.classList.toggle('is-success', type === 'success');
  };

  const clearFeedback = () => {
    if (!feedback) {
      return;
    }

    feedback.hidden = true;
    feedback.textContent = '';
    feedback.classList.remove('is-success');
  };

  const setLoadingState = (isLoading) => {
    button.disabled = isLoading;
    button.textContent = isLoading ? 'Starter passkey…' : initialLabel;
  };

  if (!hasPasskeySupport()) {
    button.disabled = true;
    button.title = 'Passkey kræver en sikker forbindelse (HTTPS) og en understøttet browser.';
    continue;
  }

  button.addEventListener('click', async () => {
    clearFeedback();

    const email = emailField.value.trim().toLowerCase();

    if (email === '') {
      setFeedback('Skriv din e-mail først, og prøv derefter passkey-login.');
      emailField.focus();
      return;
    }

    const csrf = await (async () => {
      const csrfHelper = window.PlateBookCsrf;

      if (csrfHelper && typeof csrfHelper.refreshForm === 'function') {
        try {
          return await csrfHelper.refreshForm(form);
        } catch {
          // Fall back to the current token embedded in the page.
        }
      }

      if (csrfInput instanceof HTMLInputElement && csrfInput.value.trim() !== '') {
        return csrfInput.value.trim();
      }

      return form.dataset.passkeyCsrf || '';
    })();

    if (csrf === '' || optionsUrl === '' || authUrl === '') {
      setFeedback('Passkey-login er ikke konfigureret korrekt.');
      return;
    }

    try {
      setLoadingState(true);

      const optionsResponse = await fetch(optionsUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          email,
          is_active: '1',
        }),
      });

      const optionsPayload = await parseJsonResponse(optionsResponse);

      if (!optionsResponse.ok) {
        throw new Error(firstErrorMessage(optionsPayload, 'Kunne ikke starte passkey-login.'));
      }

      const publicKeyOptions = optionsPayload?.publicKey;

      if (!publicKeyOptions || typeof publicKeyOptions !== 'object') {
        throw new Error('Ugyldigt svar fra serveren ved passkey-login.');
      }

      const credential = await navigator.credentials.get({
        publicKey: normalizeAssertionOptions(publicKeyOptions),
      });

      if (!credential) {
        throw new Error('Ingen passkey blev valgt.');
      }

      const payload = serializeAssertion(credential);
      payload.email = email;
      payload.is_active = '1';
      payload.remember = nativeApp ? '1' : '';

      if (nativeApp) {
        payload.native_app = '1';
      }

      const authResponse = await fetch(authUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      });

      const authPayload = await parseJsonResponse(authResponse);

      if (!authResponse.ok) {
        throw new Error(firstErrorMessage(authPayload, 'Passkey-login fejlede.'));
      }

      setFeedback('Login godkendt. Sender dig videre…', 'success');
      window.location.href = authPayload?.callback || redirectUrl;
    } catch (error) {
      if (error instanceof DOMException && error.name === 'NotAllowedError') {
        setFeedback('Passkey-login blev afbrudt eller afvist på enheden.');
      } else {
        setFeedback(error instanceof Error ? error.message : 'Der opstod en ukendt fejl.');
      }
    } finally {
      setLoadingState(false);
    }
  });
}
