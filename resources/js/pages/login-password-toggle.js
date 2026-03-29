const toggles = document.querySelectorAll('[data-password-toggle]');

const setPasswordVisibility = (toggleButton, visible) => {
  const targetId = toggleButton.getAttribute('data-password-target');
  const input = targetId ? document.getElementById(targetId) : null;

  if (!input) {
    return;
  }

  input.type = visible ? 'text' : 'password';
  toggleButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
  toggleButton.textContent = visible ? 'Skjul' : 'Vis';
  toggleButton.setAttribute(
    'aria-label',
    visible ? 'Skjul adgangskode' : 'Vis adgangskode'
  );
};

toggles.forEach((toggleButton) => {
  setPasswordVisibility(toggleButton, false);

  toggleButton.addEventListener('click', () => {
    const visible = toggleButton.getAttribute('aria-pressed') === 'true';
    setPasswordVisibility(toggleButton, !visible);
  });
});
