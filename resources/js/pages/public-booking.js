const form = document.querySelector('.public-booking-form[data-time-options-url]');
const dateField = document.querySelector('[data-public-booking-date]');
const timeField = document.querySelector('[data-public-booking-time]');
const locationField = document.querySelector('[data-public-booking-location-id]');
const serviceField = document.querySelector('[data-public-booking-service]');
const categoryField = document.querySelector('[data-public-booking-category]');
const categoryCards = Array.from(document.querySelectorAll('[data-service-category-card]'));
const categoryCurrent = document.querySelector('[data-service-category-current]');
const serviceGroups = Array.from(document.querySelectorAll('[data-service-group]'));
const serviceCards = Array.from(document.querySelectorAll('[data-service-card]'));

if (
  form &&
  dateField instanceof HTMLInputElement &&
  timeField instanceof HTMLSelectElement &&
  locationField instanceof HTMLInputElement &&
  serviceField instanceof HTMLInputElement &&
  categoryField instanceof HTMLInputElement
) {
  const requiresCategories = String(form.dataset.requireCategories || '1') === '1';
  let activeRequest = null;
  let currentStep = 1;

  const stepPanels = Array.from(form.querySelectorAll('[data-step-panel]'));
  const stepIndicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
  const prevButtons = Array.from(form.querySelectorAll('[data-step-prev]'));
  const stepErrors = Array.from(form.querySelectorAll('[data-step-error]'));

  const staffField = form.querySelector('[name="staff_user_id"]');
  const staffFieldWrapper = form.querySelector('[data-public-booking-staff-field]');
  const staffAutoNote = form.querySelector('[data-public-booking-staff-auto-note]');
  const nameField = form.querySelector('[name="name"]');
  const emailField = form.querySelector('[name="email"]');
  const phoneField = form.querySelector('[name="phone"]');
  const defaultStaffPlaceholder = 'Vælg medarbejder';
  const selectServiceFirstPlaceholder = 'Vælg ydelse først';
  const autoAssignedStaffPlaceholder = 'Tildeles automatisk';
  const noStaffForServicePlaceholder = 'Ingen medarbejdere for denne ydelse';
  let serviceRequiresStaffSelection =
    String(form.dataset.selectedServiceRequiresStaffSelection || '1') === '1';
  const staffFieldInitiallyDisabled =
    staffField instanceof HTMLSelectElement
      ? staffField.disabled
      : true;
  const allStaffOptions =
    staffField instanceof HTMLSelectElement
      ? Array.from(staffField.options)
        .filter((option) => option.value.trim() !== '')
        .map((option) => ({
          id: option.value.trim(),
          name: option.textContent ? option.textContent.trim() : option.value.trim(),
          serviceIds: String(option.dataset.serviceIds || '')
            .split(',')
            .map((id) => id.trim())
            .filter((id) => id !== ''),
        }))
      : [];

  const normalizeBoolean = (value, fallback = true) => {
    if (typeof value === 'boolean') {
      return value;
    }

    const normalized = String(value ?? '').trim().toLowerCase();

    if (['1', 'true', 'yes', 'on'].includes(normalized)) {
      return true;
    }

    if (['0', 'false', 'no', 'off'].includes(normalized)) {
      return false;
    }

    return fallback;
  };
  const normalizeCategoryValue = (value) => String(value || '').trim();
  const resolveServiceRequiresStaffSelection = () => {
    const selectedServiceId = serviceField.value.trim();

    if (selectedServiceId === '') {
      return true;
    }

    const selectedServiceCard = serviceCards.find((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return false;
      }

      return String(card.dataset.serviceCard || '').trim() === selectedServiceId;
    });

    if (!(selectedServiceCard instanceof HTMLButtonElement)) {
      return serviceRequiresStaffSelection;
    }

    return normalizeBoolean(selectedServiceCard.dataset.serviceRequiresStaffSelection, true);
  };
  const syncStaffFieldState = () => {
    if (!(staffField instanceof HTMLSelectElement)) {
      return;
    }

    const selectedServiceId = serviceField.value.trim();
    const mustSelectStaff = selectedServiceId !== '' && serviceRequiresStaffSelection;

    if (staffFieldWrapper instanceof HTMLElement) {
      staffFieldWrapper.hidden = !mustSelectStaff;
    }

    if (staffAutoNote instanceof HTMLElement) {
      staffAutoNote.hidden = mustSelectStaff || selectedServiceId === '';
    }

    staffField.required = mustSelectStaff;

    if (staffFieldInitiallyDisabled) {
      staffField.disabled = true;
      return;
    }

    if (!mustSelectStaff) {
      staffField.value = '';
      staffField.disabled = true;
      return;
    }

    const hasStaffOptions = Array.from(staffField.options)
      .some((option) => option.value.trim() !== '');

    staffField.disabled = selectedServiceId === '' || !hasStaffOptions;
  };

  const clearStepError = (step) => {
    const target = stepErrors[step - 1];

    if (!(target instanceof HTMLElement)) {
      return;
    }

    target.textContent = '';
    target.classList.remove('has-error');
  };

  const setStepError = (step, message) => {
    const target = stepErrors[step - 1];

    if (!(target instanceof HTMLElement)) {
      return;
    }

    target.textContent = message;
    target.classList.add('has-error');
  };

  const updateStepUi = () => {
    stepPanels.forEach((panel, index) => {
      if (!(panel instanceof HTMLElement)) {
        return;
      }

      panel.classList.toggle('is-active', index + 1 === currentStep);
    });

    stepIndicators.forEach((indicator, index) => {
      if (!(indicator instanceof HTMLElement)) {
        return;
      }

      const step = index + 1;
      indicator.classList.toggle('is-active', step === currentStep);
      indicator.classList.toggle('is-complete', step < currentStep);
    });
  };

  const goToStep = (step) => {
    const normalizedStep = Math.max(1, Math.min(stepPanels.length, step));
    currentStep = normalizedStep;
    updateStepUi();
  };

  const syncServiceCards = () => {
    const selectedServiceId = serviceField.value.trim();

    serviceCards.forEach((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return;
      }

      const cardServiceId = String(card.dataset.serviceCard || '').trim();
      const isActive = selectedServiceId !== '' && selectedServiceId === cardServiceId;
      card.classList.toggle('is-active', isActive);
      card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  };

  const setCategory = (categoryValue, autoAdvance = true) => {
    const normalizedCategory = normalizeCategoryValue(categoryValue);
    categoryField.value = normalizedCategory;

    if (!requiresCategories) {
      if (categoryCurrent instanceof HTMLElement) {
        categoryCurrent.textContent = 'Alle aktive online-ydelser';
      }

      let selectedServiceIsVisible = false;

      serviceGroups.forEach((group) => {
        if (!(group instanceof HTMLElement)) {
          return;
        }

        group.hidden = false;
      });

      serviceCards.forEach((card) => {
        if (!(card instanceof HTMLButtonElement)) {
          return;
        }

        card.hidden = false;

        if (String(card.dataset.serviceCard || '').trim() === serviceField.value.trim()) {
          selectedServiceIsVisible = true;
        }
      });

      if (!selectedServiceIsVisible) {
        serviceField.value = '';
        timeField.value = '';
      }

      syncServiceCards();
      serviceRequiresStaffSelection = resolveServiceRequiresStaffSelection();
      syncStaffOptionsFromServiceSelection();
      return;
    }

    let activeCategoryName = '';

    categoryCards.forEach((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return;
      }

      const cardCategory = normalizeCategoryValue(card.dataset.serviceCategoryCard);
      const isActive = normalizedCategory !== '' && cardCategory === normalizedCategory;

      card.classList.toggle('is-active', isActive);
      card.setAttribute('aria-pressed', isActive ? 'true' : 'false');

      if (isActive) {
        activeCategoryName = String(card.dataset.serviceCategoryName || '').trim();
      }
    });

    if (categoryCurrent instanceof HTMLElement) {
      categoryCurrent.textContent = normalizedCategory !== ''
        ? `Kategori: ${activeCategoryName || normalizedCategory}`
        : 'Vælg kategori først';
    }

    let selectedServiceIsVisible = false;

    serviceGroups.forEach((group) => {
      if (!(group instanceof HTMLElement)) {
        return;
      }

      const groupCategory = normalizeCategoryValue(group.dataset.serviceGroup);
      group.hidden = normalizedCategory === '' || groupCategory !== normalizedCategory;
    });

    serviceCards.forEach((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return;
      }

      const cardCategory = normalizeCategoryValue(card.dataset.serviceCategory);
      const isVisible = normalizedCategory !== '' && cardCategory === normalizedCategory;
      card.hidden = !isVisible;

      if (isVisible && String(card.dataset.serviceCard || '').trim() === serviceField.value.trim()) {
        selectedServiceIsVisible = true;
      }
    });

    if (!selectedServiceIsVisible) {
      serviceField.value = '';
      timeField.value = '';
    }

    syncServiceCards();
    serviceRequiresStaffSelection = resolveServiceRequiresStaffSelection();
    syncStaffOptionsFromServiceSelection();

    if (autoAdvance && currentStep === 1 && normalizedCategory !== '') {
      clearStepError(1);
      goToStep(2);
    }
  };

  const renderTimeOptions = (timeOptions, previousValue) => {
    while (timeField.options.length > 0) {
      timeField.remove(0);
    }

    timeField.add(new Option('Vælg tidspunkt', ''));

    timeOptions.forEach((timeOption) => {
      timeField.add(new Option(timeOption, timeOption));
    });

    if (previousValue && timeOptions.includes(previousValue)) {
      timeField.value = previousValue;
    }
  };

  const renderStaffOptions = (staffMembers, previousValue = '') => {
    if (!(staffField instanceof HTMLSelectElement)) {
      return;
    }

    const selectedServiceId = serviceField.value.trim();
    const normalizedStaffMembers = Array.isArray(staffMembers)
      ? staffMembers
        .map((staffMember) => ({
          id: String(staffMember?.id ?? '').trim(),
          name: String(staffMember?.name ?? '').trim(),
        }))
        .filter((staffMember) => staffMember.id !== '' && staffMember.name !== '')
      : [];

    while (staffField.options.length > 0) {
      staffField.remove(0);
    }

    const placeholderLabel = selectedServiceId === ''
      ? selectServiceFirstPlaceholder
      : !serviceRequiresStaffSelection
        ? autoAssignedStaffPlaceholder
      : normalizedStaffMembers.length > 0
        ? defaultStaffPlaceholder
        : noStaffForServicePlaceholder;
    staffField.add(new Option(placeholderLabel, ''));

    normalizedStaffMembers.forEach((staffMember) => {
      staffField.add(new Option(staffMember.name, staffMember.id));
    });

    if (previousValue !== '' && normalizedStaffMembers.some((staffMember) => staffMember.id === previousValue)) {
      staffField.value = previousValue;
    } else {
      staffField.value = '';
    }

    syncStaffFieldState();
  };

  const syncStaffOptionsFromServiceSelection = () => {
    if (!(staffField instanceof HTMLSelectElement)) {
      return;
    }

    const selectedServiceId = serviceField.value.trim();
    const currentStaffId = staffField.value.trim();
    const filteredStaffMembers = selectedServiceId === ''
      ? []
      : allStaffOptions
        .filter((staffMember) => staffMember.serviceIds.includes(selectedServiceId))
        .map((staffMember) => ({
          id: staffMember.id,
          name: staffMember.name,
        }));

    const preservedStaffId = serviceRequiresStaffSelection
      ? currentStaffId
      : '';
    renderStaffOptions(filteredStaffMembers, preservedStaffId);
  };

  const isStep3Complete = () => {
    const hasStaff = !serviceRequiresStaffSelection || (
      staffField instanceof HTMLSelectElement &&
      staffField.value.trim() !== ''
    );
    const hasDate = dateField.value.trim() !== '';
    const hasTime = timeField.value.trim() !== '';

    return hasStaff && hasDate && hasTime;
  };

  const autoAdvanceFromStep3 = () => {
    if (currentStep !== 3) {
      return;
    }

    if (isStep3Complete()) {
      clearStepError(3);
      goToStep(4);
    }
  };

  const refreshTimeOptions = async () => {
    const locationId = locationField.value.trim();
    const bookingDate = dateField.value.trim();
    const serviceId = serviceField.value.trim();
    serviceRequiresStaffSelection = resolveServiceRequiresStaffSelection();
    const selectedStaffUserId =
      staffField instanceof HTMLSelectElement
        ? staffField.value.trim()
        : '';
    const staffUserId = serviceRequiresStaffSelection
      ? selectedStaffUserId
      : '';

    if (!locationId || !bookingDate || !serviceId) {
      renderTimeOptions([], '');
      syncStaffOptionsFromServiceSelection();
      return;
    }

    if (activeRequest) {
      activeRequest.abort();
    }

    activeRequest = new AbortController();
    const currentValue = timeField.value;

    try {
      const url = new URL(form.dataset.timeOptionsUrl || '', window.location.origin);
      url.searchParams.set('location_id', locationId);
      url.searchParams.set('booking_date', bookingDate);
      url.searchParams.set('service_id', serviceId);

      if (staffUserId !== '') {
        url.searchParams.set('staff_user_id', staffUserId);
      } else {
        url.searchParams.delete('staff_user_id');
      }

      const response = await fetch(url.toString(), {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        signal: activeRequest.signal,
      });

      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      const staffMembers = Array.isArray(payload.staff_members) ? payload.staff_members : [];
      const timeOptions = Array.isArray(payload.time_options) ? payload.time_options : [];
      serviceRequiresStaffSelection = normalizeBoolean(
        payload?.service_requires_staff_selection,
        serviceRequiresStaffSelection,
      );
      const preservedStaffId = serviceRequiresStaffSelection
        ? selectedStaffUserId
        : '';
      renderStaffOptions(staffMembers, preservedStaffId);
      renderTimeOptions(timeOptions, currentValue);
      autoAdvanceFromStep3();
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return;
      }
    } finally {
      activeRequest = null;
    }
  };

  const validateStep = (step) => {
    clearStepError(step);

    if (step === 1) {
      if (!requiresCategories) {
        return true;
      }

      if (normalizeCategoryValue(categoryField.value) === '') {
        setStepError(step, 'Vælg en kategori for at fortsætte.');
        return false;
      }

      return true;
    }

    if (step === 2) {
      const selectedServiceId = serviceField.value.trim();

      if (selectedServiceId === '') {
        setStepError(step, 'Vælg en ydelse for at fortsætte.');
        return false;
      }

      const selectedServiceCard = serviceCards.find((card) => {
        if (!(card instanceof HTMLButtonElement)) {
          return false;
        }

        return String(card.dataset.serviceCard || '').trim() === selectedServiceId;
      });

      if (!(selectedServiceCard instanceof HTMLButtonElement)) {
        setStepError(step, 'Den valgte ydelse er ugyldig.');
        return false;
      }

      if (requiresCategories) {
        const selectedCategory = normalizeCategoryValue(categoryField.value);
        const serviceCategory = normalizeCategoryValue(selectedServiceCard.dataset.serviceCategory);

        if (selectedCategory === '' || selectedCategory !== serviceCategory) {
          setStepError(step, 'Vælg først en kategori og derefter en ydelse i kategorien.');
          return false;
        }
      }

      return true;
    }

    if (step === 3) {
      if (
        serviceRequiresStaffSelection &&
        (!(staffField instanceof HTMLSelectElement) || staffField.value.trim() === '')
      ) {
        setStepError(step, 'Vælg en medarbejder.');
        staffField?.focus();
        return false;
      }

      if (dateField.value.trim() === '') {
        setStepError(step, 'Vælg en dato.');
        dateField.focus();
        return false;
      }

      if (timeField.value.trim() === '') {
        setStepError(step, 'Vælg et tidspunkt.');
        timeField.focus();
        return false;
      }

      return true;
    }

    if (step === 4) {
      if (nameField instanceof HTMLInputElement && nameField.value.trim() === '') {
        setStepError(step, 'Skriv dit navn.');
        nameField.focus();
        return false;
      }

      const emailValue = emailField instanceof HTMLInputElement ? emailField.value.trim() : '';
      const phoneValue = phoneField instanceof HTMLInputElement ? phoneField.value.trim() : '';

      if (emailValue === '') {
        setStepError(step, 'Udfyld din e-mail.');
        emailField?.focus();
        return false;
      }

      if (phoneValue === '') {
        setStepError(step, 'Udfyld dit telefonnummer.');
        phoneField?.focus();
        return false;
      }
    }

    return true;
  };

  form.classList.add('is-wizard');

  const initialStepRaw = Number.parseInt(form.dataset.initialStep || '1', 10);
  if (Number.isFinite(initialStepRaw)) {
    currentStep = Math.max(1, Math.min(stepPanels.length, initialStepRaw));
  }

  updateStepUi();
  setCategory(categoryField.value, false);
  serviceRequiresStaffSelection = resolveServiceRequiresStaffSelection();
  syncStaffFieldState();

  if (!requiresCategories && currentStep === 1) {
    goToStep(2);
  }

  if (requiresCategories && categoryCards.length > 0) {
    categoryCards.forEach((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return;
      }

      card.addEventListener('click', () => {
        if (card.disabled) {
          return;
        }

        const cardCategory = normalizeCategoryValue(card.dataset.serviceCategoryCard);
        setCategory(cardCategory, true);
      });
    });
  }

  if (dateField.value.trim() !== '' && serviceField.value.trim() !== '') {
    void refreshTimeOptions();
  } else {
    syncStaffOptionsFromServiceSelection();
  }

  dateField.addEventListener('change', () => {
    clearStepError(3);
    void refreshTimeOptions();
  });

  if (staffField instanceof HTMLSelectElement) {
    staffField.addEventListener('change', () => {
      clearStepError(3);
      void refreshTimeOptions();
      autoAdvanceFromStep3();
    });
  }

  timeField.addEventListener('change', () => {
    clearStepError(3);
    autoAdvanceFromStep3();
  });

  prevButtons.forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    button.addEventListener('click', () => {
      const stepPanel = button.closest('[data-step-panel]');
      const step = Number.parseInt(stepPanel?.getAttribute('data-step-panel') || '1', 10);

      if (!Number.isFinite(step)) {
        return;
      }

      goToStep(step - 1);
    });
  });

  if (serviceCards.length > 0) {
    serviceCards.forEach((card) => {
      if (!(card instanceof HTMLButtonElement)) {
        return;
      }

      card.addEventListener('click', () => {
        if (card.disabled || card.hidden) {
          return;
        }

        const cardServiceId = String(card.dataset.serviceCard || '').trim();

        if (cardServiceId === '') {
          return;
        }

        serviceField.value = cardServiceId;
        serviceField.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    serviceField.addEventListener('change', () => {
      serviceRequiresStaffSelection = resolveServiceRequiresStaffSelection();
      syncServiceCards();
      syncStaffOptionsFromServiceSelection();
      clearStepError(2);
      void refreshTimeOptions();

      if (currentStep === 2 && serviceField.value.trim() !== '') {
        goToStep(3);
        autoAdvanceFromStep3();
      }
    });
  }

  syncServiceCards();

  form.addEventListener('submit', (event) => {
    if (requiresCategories && !validateStep(1)) {
      event.preventDefault();
      goToStep(1);
      return;
    }

    if (!validateStep(2)) {
      event.preventDefault();
      goToStep(2);
      return;
    }

    if (!validateStep(3)) {
      event.preventDefault();
      goToStep(3);
      return;
    }

    if (!validateStep(4)) {
      event.preventDefault();
      goToStep(4);
      return;
    }

    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
    }
  });
}
