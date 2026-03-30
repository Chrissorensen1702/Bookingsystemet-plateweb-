const passkeyManager = document.querySelector('[data-passkey-manager]');

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

const normalizeCreationOptions = (publicKey) => {
  const normalized = { ...publicKey };
  normalized.challenge = base64UrlToBuffer(publicKey.challenge);
  normalized.user = {
    ...publicKey.user,
    id: base64UrlToBuffer(publicKey.user.id),
  };

  if (Array.isArray(publicKey.excludeCredentials)) {
    normalized.excludeCredentials = publicKey.excludeCredentials.map((credential) => ({
      ...credential,
      id: base64UrlToBuffer(credential.id),
    }));
  }

  return normalized;
};

const serializeAttestation = (credential) => ({
  id: credential.id,
  type: credential.type,
  rawId: bufferToBase64(credential.rawId),
  response: {
    clientDataJSON: bufferToBase64(credential.response.clientDataJSON).replace(/=/g, ''),
    attestationObject: bufferToBase64(credential.response.attestationObject),
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
  && typeof navigator.credentials?.create === 'function'
  && window.isSecureContext
);

if (passkeyManager instanceof HTMLElement) {
  const createButton = passkeyManager.querySelector('[data-passkey-create]');
  const nameField = passkeyManager.querySelector('[data-passkey-name]');
  const feedback = passkeyManager.querySelector('[data-passkey-feedback]');

  if (createButton instanceof HTMLButtonElement) {
    const csrf = passkeyManager.dataset.passkeyCsrf || '';
    const optionsUrl = passkeyManager.dataset.passkeyOptionsUrl || '';
    const storeUrl = passkeyManager.dataset.passkeyStoreUrl || '';
    const initialLabel = createButton.textContent || 'Tilføj passkey';

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
      createButton.disabled = isLoading;
      createButton.textContent = isLoading ? 'Opretter…' : initialLabel;
    };

    if (!hasPasskeySupport()) {
      createButton.disabled = true;
      createButton.title = 'Passkeys kræver HTTPS og en browser med WebAuthn-understøttelse.';
    }

    createButton.addEventListener('click', async () => {
      clearFeedback();

      if (!hasPasskeySupport()) {
        setFeedback('Passkeys kræver HTTPS og en browser med WebAuthn-understøttelse.');
        return;
      }

      if (csrf === '' || optionsUrl === '' || storeUrl === '') {
        setFeedback('Passkey-oprettelse er ikke konfigureret korrekt.');
        return;
      }

      const keyNameInput = nameField instanceof HTMLInputElement ? nameField.value.trim() : '';
      const fallbackName = `Enhed ${new Date().toLocaleString('da-DK')}`;
      const keyName = keyNameInput || fallbackName;

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
          body: JSON.stringify({}),
        });

        const optionsPayload = await parseJsonResponse(optionsResponse);

        if (!optionsResponse.ok) {
          throw new Error(firstErrorMessage(optionsPayload, 'Kunne ikke starte passkey-oprettelse.'));
        }

        const publicKeyOptions = optionsPayload?.publicKey;

        if (!publicKeyOptions || typeof publicKeyOptions !== 'object') {
          throw new Error('Ugyldigt svar fra serveren ved passkey-oprettelse.');
        }

        const credential = await navigator.credentials.create({
          publicKey: normalizeCreationOptions(publicKeyOptions),
        });

        if (!credential) {
          throw new Error('Ingen passkey blev oprettet.');
        }

        const payload = serializeAttestation(credential);
        payload.name = keyName;

        const storeResponse = await fetch(storeUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify(payload),
        });

        const storePayload = await parseJsonResponse(storeResponse);

        if (!storeResponse.ok) {
          throw new Error(firstErrorMessage(storePayload, 'Kunne ikke gemme passkey.'));
        }

        setFeedback('Passkey er oprettet. Siden opdateres…', 'success');
        window.setTimeout(() => window.location.reload(), 500);
      } catch (error) {
        if (error instanceof DOMException && error.name === 'NotAllowedError') {
          setFeedback('Passkey-oprettelse blev afbrudt eller afvist på enheden.');
        } else {
          setFeedback(error instanceof Error ? error.message : 'Der opstod en ukendt fejl.');
        }
      } finally {
        setLoadingState(false);
      }
    });
  }
}
