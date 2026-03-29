const page = document.querySelector('[data-users-page]');

if (page) {
  const dialog = page.querySelector('[data-users-modal]');
  const openButtons = page.querySelectorAll('[data-user-trigger]');
  const passwordToggles = page.querySelectorAll('[data-password-toggle]');
  const searchInput = page.querySelector('[data-users-search]');
  const userItems = page.querySelectorAll('[data-users-item]');
  const searchEmpty = page.querySelector('[data-users-search-empty]');
  const permissionToggles = page.querySelectorAll('.users-permissions-toggle input[type="checkbox"]');

  const setPasswordVisibility = (toggleButton, visible) => {
    const targetId = toggleButton.getAttribute('data-password-target');

    if (!targetId) {
      return;
    }

    const input = page.ownerDocument.getElementById(targetId);

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    input.type = visible ? 'text' : 'password';
    toggleButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
    toggleButton.textContent = visible ? 'Skjul' : 'Vis';
    toggleButton.setAttribute('aria-label', visible ? 'Skjul adgangskode' : 'Vis adgangskode');
  };

  const resetPasswordVisibility = (scope = page) => {
    scope.querySelectorAll('[data-password-toggle]').forEach((toggleButton) => {
      setPasswordVisibility(toggleButton, false);
    });
  };

  passwordToggles.forEach((toggleButton) => {
    setPasswordVisibility(toggleButton, false);

    toggleButton.addEventListener('click', () => {
      const isVisible = toggleButton.getAttribute('aria-pressed') === 'true';
      setPasswordVisibility(toggleButton, !isVisible);
    });
  });

  const applyUserSearch = () => {
    if (!(searchInput instanceof HTMLInputElement) || userItems.length === 0) {
      return;
    }

    const term = searchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    userItems.forEach((item) => {
      const haystack = (item.getAttribute('data-user-search') || '').toLowerCase();
      const isMatch = term === '' || haystack.includes(term);
      item.hidden = !isMatch;

      if (isMatch) {
        visibleCount += 1;
      }
    });

    if (searchEmpty) {
      searchEmpty.hidden = visibleCount > 0;
    }
  };

  searchInput?.addEventListener('input', applyUserSearch);
  applyUserSearch();

  const syncPermissionToggle = (checkbox) => {
    const wrapper = checkbox.closest('.users-permissions-toggle');

    if (!(wrapper instanceof HTMLElement)) {
      return;
    }

    const state = wrapper.querySelector('[data-permission-toggle-state]');
    const isDenied = checkbox.checked;

    if (state instanceof HTMLElement) {
      state.textContent = isDenied ? 'Fra' : 'Til';
    }

    wrapper.classList.toggle('is-off', isDenied);
  };

  permissionToggles.forEach((checkbox) => {
    syncPermissionToggle(checkbox);
    checkbox.addEventListener('change', () => syncPermissionToggle(checkbox));
  });

  if (dialog instanceof HTMLDialogElement && openButtons.length > 0) {
    const editForm = dialog.querySelector('[data-users-edit-form]');
    const deleteForm = dialog.querySelector('[data-users-delete-form]');
    const title = dialog.querySelector('[data-users-modal-title]');
    const subtitle = dialog.querySelector('[data-users-modal-subtitle]');
    const roleBadge = dialog.querySelector('[data-users-modal-role]');
    const modalAvatar = dialog.querySelector('[data-users-modal-avatar]');
    const userIdField = dialog.querySelector('[data-users-modal-user-id]');
    const nameField = dialog.querySelector('[data-users-field-name]');
    const emailField = dialog.querySelector('[data-users-field-email]');
    const initialsField = dialog.querySelector('[data-users-field-initials]');
    const roleField = dialog.querySelector('[data-users-field-role]');
    const competencyScopeField = dialog.querySelector('[data-users-field-competency-scope]');
    const bookableField = dialog.querySelector('[data-users-field-bookable]');
    const photoField = dialog.querySelector('[data-users-field-photo]');
    const removePhotoField = dialog.querySelector('[data-users-field-remove-photo]');
    const locationFields = Array.from(dialog.querySelectorAll('[data-users-field-location]'));
    const editPasswordField = dialog.querySelector('input[name="password"]');
    const editPasswordConfirmationField = dialog.querySelector('input[name="password_confirmation"]');
    const closeButtons = dialog.querySelectorAll('[data-users-modal-close]');
    let activeUserPhotoUrl = '';
    let activeUserInitials = '--';
    let previewObjectUrl = null;

    const clearPreviewObjectUrl = () => {
      if (typeof previewObjectUrl === 'string' && previewObjectUrl !== '') {
        URL.revokeObjectURL(previewObjectUrl);
      }

      previewObjectUrl = null;
    };

    const renderModalAvatar = (photoUrl, initials) => {
      if (!(modalAvatar instanceof HTMLElement)) {
        return;
      }

      modalAvatar.replaceChildren();
      modalAvatar.classList.remove('has-photo');

      if (typeof photoUrl === 'string' && photoUrl.trim() !== '') {
        const image = document.createElement('img');
        image.src = photoUrl;
        image.alt = '';
        modalAvatar.appendChild(image);
        modalAvatar.classList.add('has-photo');
        return;
      }

      modalAvatar.textContent = (initials || '--').toUpperCase();
    };

    const syncModalAvatar = () => {
      const isRemovalActive = removePhotoField instanceof HTMLInputElement && removePhotoField.checked;
      const sourceUrl = !isRemovalActive
        ? (previewObjectUrl || activeUserPhotoUrl || '')
        : '';

      renderModalAvatar(sourceUrl, activeUserInitials);
    };

    const applyUser = (button, preserveExisting = false) => {
      const {
        userId,
        userName,
        userEmail,
        userInitials,
        userPhotoUrl,
        userRole,
        userRoleLabel,
        userBookable,
        userCompetencyScope,
        userLocationIds,
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
        title.textContent = userName;
      }

      if (subtitle) {
        subtitle.textContent = userEmail;
      }

      if (roleBadge) {
        roleBadge.textContent = userRoleLabel;
        roleBadge.className = `users-role users-role-${userRole}`;
      }

      if (userIdField) {
        userIdField.value = userId;
      }

      activeUserPhotoUrl = userPhotoUrl || '';
      activeUserInitials = (userInitials || '--').trim().toUpperCase() || '--';
      clearPreviewObjectUrl();

      if (!preserveExisting) {
        if (editPasswordField instanceof HTMLInputElement) {
          editPasswordField.value = '';
        }

        if (editPasswordConfirmationField instanceof HTMLInputElement) {
          editPasswordConfirmationField.value = '';
        }

        if (nameField) {
          nameField.value = userName;
        }

        if (emailField) {
          emailField.value = userEmail;
        }

        if (initialsField) {
          initialsField.value = userInitials || '';
        }

        if (roleField) {
          roleField.value = userRole;
        }

        if (bookableField) {
          bookableField.checked = userBookable === '1';
        }

        if (competencyScopeField instanceof HTMLSelectElement) {
          competencyScopeField.value = userCompetencyScope || 'global';
        }

        if (photoField instanceof HTMLInputElement) {
          photoField.value = '';
        }

        if (removePhotoField instanceof HTMLInputElement) {
          removePhotoField.checked = false;
        }

        if (locationFields.length > 0) {
          const selectedLocationIds = (userLocationIds || '')
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');

          locationFields.forEach((field) => {
            if (field instanceof HTMLInputElement) {
              field.checked = selectedLocationIds.includes(field.value);
            }
          });
        }
      }

      syncModalAvatar();
    };

    if (photoField instanceof HTMLInputElement) {
      photoField.addEventListener('change', () => {
        clearPreviewObjectUrl();

        const selectedFile = photoField.files && photoField.files.length > 0
          ? photoField.files[0]
          : null;

        if (selectedFile) {
          previewObjectUrl = URL.createObjectURL(selectedFile);

          if (removePhotoField instanceof HTMLInputElement) {
            removePhotoField.checked = false;
          }
        }

        syncModalAvatar();
      });
    }

    if (removePhotoField instanceof HTMLInputElement) {
      removePhotoField.addEventListener('change', () => {
        if (removePhotoField.checked && photoField instanceof HTMLInputElement) {
          photoField.value = '';
          clearPreviewObjectUrl();
        }

        syncModalAvatar();
      });
    }

    openButtons.forEach((button) => {
      button.addEventListener('click', () => {
        applyUser(button);
        resetPasswordVisibility(dialog);
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

    dialog.addEventListener('close', () => {
      resetPasswordVisibility(dialog);
      clearPreviewObjectUrl();
    });

    const openUserId = page.dataset.openUserId;

    if (openUserId) {
      const button = page.querySelector(`[data-user-trigger][data-user-id="${openUserId}"]`);

      if (button) {
        applyUser(button, page.dataset.preserveInput === '1');
        dialog.showModal();
      }
    }
  }

  const workhoursModal = page.querySelector('[data-workhours-modal]');
  const workhoursOpenButtons = page.querySelectorAll('[data-workhours-add], [data-workhours-shift]');

  if (workhoursModal instanceof HTMLDialogElement) {
    const workhoursForm = workhoursModal.querySelector('[data-workhours-form]');
    const workhoursDeleteForm = workhoursModal.querySelector('[data-workhours-delete-form]');
    const workhoursTitle = workhoursModal.querySelector('[data-workhours-modal-title]');
    const workhoursCloseButtons = workhoursModal.querySelectorAll('[data-workhours-modal-close]');
    const shiftIdField = workhoursModal.querySelector('[data-workhours-field-shift-id]');
    const modeField = workhoursModal.querySelector('[data-workhours-field-mode]');
    const locationField = workhoursModal.querySelector('[data-workhours-field-location]');
    const returnDateField = workhoursModal.querySelector('[data-workhours-field-return-date]');
    const userField = workhoursModal.querySelector('[data-workhours-field-user]');
    const dateField = workhoursModal.querySelector('[data-workhours-field-date]');
    const startField = workhoursModal.querySelector('[data-workhours-field-start]');
    const endField = workhoursModal.querySelector('[data-workhours-field-end]');
    const breakStartField = workhoursModal.querySelector('[data-workhours-field-break-start]');
    const breakEndField = workhoursModal.querySelector('[data-workhours-field-break-end]');
    const notesField = workhoursModal.querySelector('[data-workhours-field-notes]');
    const deleteLocationField = workhoursModal.querySelector('[data-workhours-delete-location]');
    const deleteDateField = workhoursModal.querySelector('[data-workhours-delete-date]');
    const publishSingleForm = workhoursModal.querySelector('[data-workhours-publish-single-form]');
    const publishSingleLocationField = workhoursModal.querySelector('[data-workhours-publish-single-location]');
    const publishSingleDateField = workhoursModal.querySelector('[data-workhours-publish-single-date]');
    const deleteActionTemplate = workhoursDeleteForm?.dataset.actionTemplate || '';
    const publishSingleActionTemplate = publishSingleForm?.dataset.actionTemplate || '';

    const setFieldValue = (field, value) => {
      if (
        field instanceof HTMLInputElement
        || field instanceof HTMLTextAreaElement
        || field instanceof HTMLSelectElement
      ) {
        field.value = String(value ?? '');
      }
    };

    const setDeleteState = (shiftId) => {
      if (!(workhoursDeleteForm instanceof HTMLFormElement)) {
        return;
      }

      const normalizedShiftId = String(shiftId || '').trim();
      const hasShift = normalizedShiftId !== '';
      workhoursDeleteForm.hidden = !hasShift;
      if (publishSingleForm instanceof HTMLFormElement) {
        publishSingleForm.hidden = !hasShift;
      }

      if (!hasShift || deleteActionTemplate === '') {
        workhoursDeleteForm.setAttribute('action', '#');
      } else {
        workhoursDeleteForm.setAttribute(
          'action',
          deleteActionTemplate.replace('__SHIFT_ID__', encodeURIComponent(normalizedShiftId)),
        );
      }

      if (!(publishSingleForm instanceof HTMLFormElement)) {
        return;
      }

      if (!hasShift || publishSingleActionTemplate === '') {
        publishSingleForm.setAttribute('action', '#');
        return;
      }

      publishSingleForm.setAttribute(
        'action',
        publishSingleActionTemplate.replace('__SHIFT_ID__', encodeURIComponent(normalizedShiftId)),
      );
    };

    const syncReturnDate = (dateValue) => {
      const normalizedDate = String(dateValue || '').trim();

      if (returnDateField instanceof HTMLInputElement && normalizedDate !== '') {
        returnDateField.value = normalizedDate;
      }

      if (deleteDateField instanceof HTMLInputElement && normalizedDate !== '') {
        deleteDateField.value = normalizedDate;
      }

      if (publishSingleDateField instanceof HTMLInputElement && normalizedDate !== '') {
        publishSingleDateField.value = normalizedDate;
      }
    };

    const openCreateModal = (button) => {
      if (workhoursTitle instanceof HTMLElement) {
        workhoursTitle.textContent = 'Opret bookbarhed';
      }

      const buttonUserId = String(button?.dataset.userId || '').trim();
      const buttonShiftDate = String(button?.dataset.shiftDate || '').trim();
      const fallbackDate =
        dateField instanceof HTMLInputElement
          ? dateField.value
          : '';
      const selectedDate = buttonShiftDate !== '' ? buttonShiftDate : fallbackDate;

      setFieldValue(shiftIdField, '');
      setFieldValue(modeField, 'create');
      setFieldValue(userField, buttonUserId);
      setFieldValue(dateField, selectedDate);
      setFieldValue(startField, '09:00');
      setFieldValue(endField, '17:00');
      setFieldValue(breakStartField, '');
      setFieldValue(breakEndField, '');
      setFieldValue(notesField, '');
      syncReturnDate(selectedDate);
      setDeleteState('');
      workhoursModal.showModal();
    };

    const openEditModal = (button) => {
      if (workhoursTitle instanceof HTMLElement) {
        workhoursTitle.textContent = 'Rediger bookbarhed';
      }

      const {
        shiftId,
        userId,
        shiftDate,
        startsAt,
        endsAt,
        breakStartsAt,
        breakEndsAt,
        notes,
      } = button.dataset;

      setFieldValue(shiftIdField, shiftId || '');
      setFieldValue(modeField, 'update');
      setFieldValue(userField, userId || '');
      setFieldValue(dateField, shiftDate || '');
      setFieldValue(startField, startsAt || '09:00');
      setFieldValue(endField, endsAt || '17:00');
      setFieldValue(breakStartField, breakStartsAt || '');
      setFieldValue(breakEndField, breakEndsAt || '');
      setFieldValue(notesField, notes || '');
      syncReturnDate(shiftDate || '');
      setDeleteState(shiftId || '');
      workhoursModal.showModal();
    };

    if (locationField instanceof HTMLInputElement && deleteLocationField instanceof HTMLInputElement) {
      deleteLocationField.value = locationField.value;
    }

    if (locationField instanceof HTMLInputElement && publishSingleLocationField instanceof HTMLInputElement) {
      publishSingleLocationField.value = locationField.value;
    }

    workhoursOpenButtons.forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      button.addEventListener('click', () => {
        if (button.hasAttribute('data-workhours-shift')) {
          openEditModal(button);
          return;
        }

        openCreateModal(button);
      });
    });

    if (dateField instanceof HTMLInputElement) {
      dateField.addEventListener('change', () => {
        syncReturnDate(dateField.value);
      });
    }

    workhoursCloseButtons.forEach((button) => {
      button.addEventListener('click', () => workhoursModal.close());
    });

    workhoursModal.addEventListener('click', (event) => {
      const rect = workhoursModal.getBoundingClientRect();
      const inside =
        rect.top <= event.clientY &&
        event.clientY <= rect.top + rect.height &&
        rect.left <= event.clientX &&
        event.clientX <= rect.left + rect.width;

      if (!inside) {
        workhoursModal.close();
      }
    });

    if (workhoursForm instanceof HTMLFormElement) {
      workhoursForm.addEventListener('submit', () => {
        if (locationField instanceof HTMLInputElement && deleteLocationField instanceof HTMLInputElement) {
          deleteLocationField.value = locationField.value;
        }

        if (locationField instanceof HTMLInputElement && publishSingleLocationField instanceof HTMLInputElement) {
          publishSingleLocationField.value = locationField.value;
        }
      });
    }
  }
}
