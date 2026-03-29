const page = document.querySelector('[data-services-page]');

const normalizeHexColor = (value, fallback = '#5C80BC') => {
  const candidate = String(value || '').trim().toUpperCase();

  if (/^#[0-9A-F]{6}$/.test(candidate)) {
    return candidate;
  }

  return fallback;
};

const isValidHexColor = (value) => /^#[0-9A-F]{6}$/.test(String(value || '').trim().toUpperCase());

if (page) {
  const dialog = page.querySelector('[data-services-modal]');
  const openButtons = page.querySelectorAll('[data-service-trigger]');
  const createColorPicker = page.querySelector('[data-services-create-color-picker]');
  const createColorInput = page.querySelector('[data-services-create-color-input]');

  const syncCreateSwatch = (source = 'input', commit = false) => {
    if (!createColorPicker || !createColorInput) {
      return;
    }

    if (source === 'picker') {
      const pickerColor = normalizeHexColor(createColorPicker.value);
      createColorPicker.value = pickerColor;
      createColorInput.value = pickerColor;
      return;
    }

    const rawInputColor = String(createColorInput.value || '').trim().toUpperCase();
    createColorInput.value = rawInputColor;

    if (isValidHexColor(rawInputColor)) {
      createColorPicker.value = rawInputColor;
      return;
    }

    if (commit) {
      const fallback = normalizeHexColor(createColorPicker.value);
      createColorInput.value = fallback;
    }
  };

  createColorPicker?.addEventListener('input', () => syncCreateSwatch('picker'));
  createColorInput?.addEventListener('input', () => syncCreateSwatch('input'));
  createColorInput?.addEventListener('blur', () => syncCreateSwatch('input', true));
  syncCreateSwatch('input');

  const createDialog = page.querySelector('[data-services-create-modal]');
  const createOpenButton = page.querySelector('[data-services-create-open]');
  const createCloseButtons = page.querySelectorAll('[data-services-create-modal-close]');
  const categoriesDialog = page.querySelector('[data-services-categories-modal]');
  const categoriesOpenButtons = page.querySelectorAll('[data-services-categories-open]');
  const categoriesCloseButtons = page.querySelectorAll('[data-services-categories-close]');

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

  if (categoriesDialog instanceof HTMLDialogElement) {
    const openCategoriesDialog = () => {
      if (dialog instanceof HTMLDialogElement && dialog.open) {
        dialog.close();
      }

      if (createDialog instanceof HTMLDialogElement && createDialog.open) {
        createDialog.close();
      }

      if (!categoriesDialog.open) {
        categoriesDialog.showModal();
      }

      const focusCategoryId = String(page.dataset.openCategoryId || '').trim();

      if (focusCategoryId !== '') {
        const focusItem = categoriesDialog.querySelector(`[data-service-category-item="${focusCategoryId}"]`);
        focusItem?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    };

    categoriesOpenButtons.forEach((button) => {
      button.addEventListener('click', openCategoriesDialog);
    });

    categoriesCloseButtons.forEach((button) => {
      button.addEventListener('click', () => categoriesDialog.close());
    });

    categoriesDialog.addEventListener('click', (event) => {
      const rect = categoriesDialog.getBoundingClientRect();
      const inside =
        rect.top <= event.clientY &&
        event.clientY <= rect.top + rect.height &&
        rect.left <= event.clientX &&
        event.clientX <= rect.left + rect.width;

      if (!inside) {
        categoriesDialog.close();
      }
    });

    if (page.dataset.openCategoryModal === '1' && !categoriesDialog.open) {
      openCategoriesDialog();
    }
  }

  const searchField = page.querySelector('[data-services-search]');
  const kindButtons = page.querySelectorAll('[data-services-kind]');
  const groups = page.querySelectorAll('[data-services-group]');
  const countLabel = page.querySelector('[data-services-count]');

  const normalizeText = (value) =>
    String(value || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();

  if (groups.length > 0) {
    let activeKind = 'all';

    const setKindState = () => {
      kindButtons.forEach((button) => {
        const isActive = (button.dataset.servicesKind || 'all') === activeKind;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
    };

    const applyFilters = () => {
      const searchTerm = normalizeText(searchField?.value || '');
      let visibleCount = 0;

      groups.forEach((group) => {
        const groupType = group.dataset.servicesGroup || '';
        const groupAllowed = activeKind === 'all' || activeKind === groupType;
        const serviceItems = group.querySelectorAll('[data-service-item]');
        const staticEmpty = group.querySelector('[data-services-empty-static]');
        const filterEmpty = group.querySelector('[data-services-empty-filter]');

        if (serviceItems.length === 0) {
          if (staticEmpty) {
            staticEmpty.hidden = !groupAllowed;
          }
          if (filterEmpty) {
            filterEmpty.hidden = true;
          }
          group.hidden = !groupAllowed;
          return;
        }

        let groupVisibleCount = 0;

        serviceItems.forEach((item) => {
          const searchBlob = normalizeText(item.dataset.serviceSearch || '');
          const matchesSearch = searchTerm === '' || searchBlob.includes(searchTerm);
          const isVisible = groupAllowed && matchesSearch;

          item.hidden = !isVisible;

          if (isVisible) {
            groupVisibleCount += 1;
            visibleCount += 1;
          }
        });

        if (staticEmpty) {
          staticEmpty.hidden = true;
        }

        if (filterEmpty) {
          filterEmpty.hidden = !groupAllowed || groupVisibleCount > 0;
        }

        group.hidden = !groupAllowed;
      });

      if (countLabel) {
        countLabel.textContent = visibleCount === 1
          ? '1 ydelse vises'
          : `${visibleCount} ydelser vises`;
      }
    };

    searchField?.addEventListener('input', applyFilters);

    kindButtons.forEach((button) => {
      button.addEventListener('click', () => {
        activeKind = button.dataset.servicesKind || 'all';
        setKindState();
        applyFilters();
      });
    });

    setKindState();
    applyFilters();
  }

  if (dialog instanceof HTMLDialogElement && openButtons.length > 0) {
    const editForm = dialog.querySelector('[data-services-edit-form]');
    const deleteForm = dialog.querySelector('[data-services-delete-form]');
    const title = dialog.querySelector('[data-services-modal-title]');
    const subtitle = dialog.querySelector('[data-services-modal-subtitle]');
    const swatch = dialog.querySelector('[data-services-modal-swatch]');
    const bookingsBadge = dialog.querySelector('[data-services-modal-bookings]');
    const deleteButton = dialog.querySelector('[data-services-delete-button]');
    const deleteText = dialog.querySelector('[data-services-delete-text]');
    const serviceIdField = dialog.querySelector('[data-services-modal-service-id]');
    const nameField = dialog.querySelector('[data-services-field-name]');
    const durationField = dialog.querySelector('[data-services-field-duration]');
    const priceField = dialog.querySelector('[data-services-field-price]');
    const categoryField = dialog.querySelector('[data-services-field-category]');
    const sortOrderField = dialog.querySelector('[data-services-field-sort-order]');
    const onlineBookableField = dialog.querySelector('[data-services-field-online-bookable]');
    const requiresStaffSelectionField = dialog.querySelector('[data-services-field-requires-staff-selection]');
    const bufferBeforeField = dialog.querySelector('[data-services-field-buffer-before]');
    const bufferAfterField = dialog.querySelector('[data-services-field-buffer-after]');
    const minNoticeField = dialog.querySelector('[data-services-field-min-notice]');
    const maxAdvanceField = dialog.querySelector('[data-services-field-max-advance]');
    const cancellationNoticeField = dialog.querySelector('[data-services-field-cancellation-notice]');
    const colorField = dialog.querySelector('[data-services-field-color]');
    const colorPickerField = dialog.querySelector('[data-services-modal-color-picker]');
    const descriptionField = dialog.querySelector('[data-services-field-description]');
    const locationCheckboxes = Array.from(dialog.querySelectorAll('[data-services-location-checkbox]'));
    const locationDurationFields = new Map(
      Array.from(dialog.querySelectorAll('[data-services-location-duration]')).map((input) => [
        String(input.getAttribute('data-services-location-duration') || ''),
        input,
      ])
    );
    const locationSortOrderFields = new Map(
      Array.from(dialog.querySelectorAll('[data-services-location-sort-order]')).map((input) => [
        String(input.getAttribute('data-services-location-sort-order') || ''),
        input,
      ])
    );
    const locationPriceFields = new Map(
      Array.from(dialog.querySelectorAll('[data-services-location-price]')).map((input) => [
        String(input.getAttribute('data-services-location-price') || ''),
        input,
      ])
    );
    const closeButtons = dialog.querySelectorAll('[data-services-modal-close]');

    const syncLocationOverrideDisabledState = () => {
      locationCheckboxes.forEach((checkbox) => {
        const locationId = String(checkbox.getAttribute('data-services-location-id') || checkbox.value || '');
        const sortOrderInput = locationSortOrderFields.get(locationId);
        const durationInput = locationDurationFields.get(locationId);
        const priceInput = locationPriceFields.get(locationId);
        const isActive = checkbox.checked;

        if (sortOrderInput instanceof HTMLInputElement) {
          sortOrderInput.disabled = !isActive;
        }

        if (durationInput instanceof HTMLInputElement) {
          durationInput.disabled = !isActive;
        }

        if (priceInput instanceof HTMLInputElement) {
          priceInput.disabled = !isActive;
        }
      });
    };

    locationCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', syncLocationOverrideDisabledState);
    });

    const syncModalColor = (source = 'input', commit = false) => {
      if (!colorField || !colorPickerField) {
        return;
      }

      if (source === 'picker') {
        const pickerColor = normalizeHexColor(colorPickerField.value);
        colorPickerField.value = pickerColor;
        colorField.value = pickerColor;

        if (swatch) {
          swatch.style.setProperty('--service-color', pickerColor);
        }

        return;
      }

      const rawInputColor = String(colorField.value || '').trim().toUpperCase();
      colorField.value = rawInputColor;

      if (isValidHexColor(rawInputColor)) {
        colorPickerField.value = rawInputColor;

        if (swatch) {
          swatch.style.setProperty('--service-color', rawInputColor);
        }

        return;
      }

      if (commit) {
        const fallback = normalizeHexColor(colorPickerField.value);
        colorField.value = fallback;

        if (swatch) {
          swatch.style.setProperty('--service-color', fallback);
        }
      }
    };

    const applyService = (button, preserveExisting = false) => {
      const {
        serviceId,
        serviceName,
        serviceDuration,
        servicePriceKr,
        serviceCategoryId,
        serviceSortOrder,
        serviceOnlineBookable,
        serviceRequiresStaffSelection,
        serviceBufferBefore,
        serviceBufferAfter,
        serviceMinNotice,
        serviceMaxAdvanceDays,
        serviceCancellationNotice,
        serviceColor,
        serviceDescription,
        serviceBookings,
        serviceActiveLocations,
        serviceLocationOverrides,
        updateAction,
        deleteAction,
      } = button.dataset;

      if (editForm) {
        editForm.setAttribute('action', updateAction);
      }

      if (deleteForm) {
        deleteForm.setAttribute('action', deleteAction);
      }

      if (title) {
        title.textContent = serviceName;
      }

      if (subtitle) {
        subtitle.textContent = `${serviceDuration} minutter · ${serviceOnlineBookable === '1' ? 'online booking aktiveret' : 'kun intern booking'} · ${serviceRequiresStaffSelection === '1' ? 'behandler valg kraeves' : 'behandler tildeles automatisk'}`;
      }

      if (serviceIdField) {
        serviceIdField.value = serviceId;
      }

      if (bookingsBadge) {
        bookingsBadge.textContent = `${serviceBookings} bookinger`;
      }

      const bookingsCount = Number(serviceBookings || 0);

      if (deleteButton) {
        deleteButton.disabled = bookingsCount > 0;
      }

      if (deleteText) {
        deleteText.textContent = bookingsCount > 0
          ? 'Denne ydelse har bookinger og kan derfor ikke slettes.'
          : 'Slet kun ydelsen hvis den ikke længere skal bruges.';
      }

      if (!preserveExisting) {
        if (nameField) {
          nameField.value = serviceName;
        }

        if (durationField) {
          durationField.value = serviceDuration;
        }

        if (priceField) {
          priceField.value = servicePriceKr || '';
        }

        if (categoryField) {
          categoryField.value = serviceCategoryId || '';
        }

        if (sortOrderField) {
          sortOrderField.value = serviceSortOrder || '1';
        }

        if (onlineBookableField instanceof HTMLInputElement) {
          onlineBookableField.checked = serviceOnlineBookable === '1';
        }

        if (requiresStaffSelectionField instanceof HTMLInputElement) {
          requiresStaffSelectionField.checked = serviceRequiresStaffSelection !== '0';
        }

        if (bufferBeforeField) {
          bufferBeforeField.value = serviceBufferBefore || '0';
        }

        if (bufferAfterField) {
          bufferAfterField.value = serviceBufferAfter || '0';
        }

        if (minNoticeField) {
          minNoticeField.value = serviceMinNotice || '0';
        }

        if (maxAdvanceField) {
          maxAdvanceField.value = serviceMaxAdvanceDays || '';
        }

        if (cancellationNoticeField) {
          cancellationNoticeField.value = serviceCancellationNotice || '24';
        }

        const normalizedServiceColor = normalizeHexColor(serviceColor);

        if (colorField) {
          colorField.value = normalizedServiceColor;
        }

        if (colorPickerField) {
          colorPickerField.value = normalizedServiceColor;
        }

        if (descriptionField) {
          descriptionField.value = serviceDescription || '';
        }

        if (locationCheckboxes.length > 0) {
          const activeLocationIds = String(serviceActiveLocations || '')
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean);
          let parsedLocationOverrides = {};

          try {
            parsedLocationOverrides = JSON.parse(String(serviceLocationOverrides || '{}'));
          } catch (error) {
            parsedLocationOverrides = {};
          }

          locationCheckboxes.forEach((checkbox) => {
            const locationId = String(checkbox.getAttribute('data-services-location-id') || checkbox.value || '');
            const locationOverride = parsedLocationOverrides[locationId] || {};
            const sortOrderInput = locationSortOrderFields.get(locationId);
            const durationInput = locationDurationFields.get(locationId);
            const priceInput = locationPriceFields.get(locationId);

            checkbox.checked = activeLocationIds.includes(locationId);

            if (sortOrderInput instanceof HTMLInputElement) {
              sortOrderInput.value = locationOverride.sort_order !== null && locationOverride.sort_order !== undefined
                ? String(locationOverride.sort_order)
                : '';
            }

            if (durationInput instanceof HTMLInputElement) {
              durationInput.value = locationOverride.duration_minutes !== null && locationOverride.duration_minutes !== undefined
                ? String(locationOverride.duration_minutes)
                : '';
            }

            if (priceInput instanceof HTMLInputElement) {
              priceInput.value = locationOverride.price_kr !== null && locationOverride.price_kr !== undefined
                ? String(locationOverride.price_kr)
                : '';
            }
          });
        }
      }

      syncModalColor();
      syncLocationOverrideDisabledState();
    };

    openButtons.forEach((button) => {
      button.addEventListener('click', () => {
        applyService(button);
        dialog.showModal();
      });
    });

    closeButtons.forEach((button) => {
      button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
      const rect = dialog.getBoundingClientRect();
      const inside =
        rect.top <= event.clientY &&
        event.clientY <= rect.top + rect.height &&
        rect.left <= event.clientX &&
        event.clientX <= rect.left + rect.width;

      if (!inside) {
        dialog.close();
      }
    });

    colorPickerField?.addEventListener('input', () => syncModalColor('picker'));
    colorField?.addEventListener('input', () => syncModalColor('input'));
    colorField?.addEventListener('blur', () => syncModalColor('input', true));

    const openServiceId = page.dataset.openServiceId;

    if (openServiceId) {
      const button = page.querySelector(`[data-service-trigger][data-service-id="${openServiceId}"]`);

      if (button) {
        applyService(button, page.dataset.preserveInput === '1');
        dialog.showModal();
      } else {
        syncModalColor('input', true);
      }
    } else {
      syncModalColor('input', true);
      syncLocationOverrideDisabledState();
    }
  }
}
