const page = document.querySelector('[data-availability-page]');

if (page) {
  const locationField = page.querySelector('[data-availability-location]');
  const locationForm = locationField?.closest('form');
  const createDialog = page.querySelector('[data-availability-create-modal]');
  const createOpenButton = page.querySelector('[data-availability-create-open]');
  const createCloseButtons = page.querySelectorAll('[data-availability-create-close]');

  locationField?.addEventListener('change', () => {
    locationForm?.requestSubmit();
  });

  if (createDialog instanceof HTMLDialogElement) {
    createOpenButton?.addEventListener('click', () => {
      if (!createDialog.open) {
        createDialog.showModal();
      }
    });

    createCloseButtons.forEach((button) => {
      button.addEventListener('click', () => createDialog.close());
    });

    createDialog.addEventListener('click', (event) => {
      const rect = createDialog.getBoundingClientRect();
      const inside =
        rect.top <= event.clientY &&
        event.clientY <= rect.top + rect.height &&
        rect.left <= event.clientX &&
        event.clientX <= rect.left + rect.width;

      if (!inside) {
        createDialog.close();
      }
    });

    if (page.dataset.openCreateModal === '1' && !createDialog.open) {
      createDialog.showModal();
    }
  }

  const bindOverrideTypeForm = (formElement) => {
    if (!(formElement instanceof HTMLElement)) {
      return;
    }

    const overrideTypeField = formElement.querySelector('[data-override-type]');
    const overrideTimeFields = formElement.querySelectorAll('[data-override-time] input[type="time"]');

    if (!(overrideTypeField instanceof HTMLSelectElement) || overrideTimeFields.length === 0) {
      return;
    }

    const syncOverrideTimeFields = () => {
      const isOpenType = overrideTypeField.value === 'open';

      overrideTimeFields.forEach((input) => {
        input.disabled = !isOpenType;
        input.required = isOpenType;
      });
    };

    overrideTypeField.addEventListener('change', syncOverrideTimeFields);
    syncOverrideTimeFields();
  };

  page.querySelectorAll('[data-override-form]').forEach((formElement) => {
    bindOverrideTypeForm(formElement);
  });
}
