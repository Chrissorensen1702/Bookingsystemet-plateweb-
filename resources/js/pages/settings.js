const bindSettingsColorInputs = () => {
  const colorPickers = document.querySelectorAll('[data-color-target]');

  colorPickers.forEach((picker) => {
    const key = picker.getAttribute('data-color-target');

    if (!key) {
      return;
    }

    const textInput = document.querySelector(`[data-color-input="${key}"]`);

    if (!(textInput instanceof HTMLInputElement)) {
      return;
    }

    picker.addEventListener('input', () => {
      textInput.value = picker.value.toUpperCase();
    });

    textInput.addEventListener('input', () => {
      const value = textInput.value.trim();

      if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
        picker.value = value;
      }
    });
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bindSettingsColorInputs);
} else {
  bindSettingsColorInputs();
}

