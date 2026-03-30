@extends('layouts.default')

@section('title', 'Ydelser')

@section('body-class', 'booking-home-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $locations = $locations ?? collect();
    $serviceCategories = $serviceCategories ?? collect();
    $requiresServiceCategories = (bool) ($requiresServiceCategories ?? true);
    $locationCount = (int) $locations->count();
    $totalLocationCount = max(1, $locationCount);
    $fixedServices = $locationCount > 0
      ? $services->filter(fn ($service) => (int) $service->active_locations_count === $locationCount)->values()
      : $services->values();
    $localServices = $locationCount > 0
      ? $services->filter(fn ($service) => (int) $service->active_locations_count !== $locationCount)->values()
      : collect();
    $defaultServiceColor = '#5C80BC';
    $createColorRaw = old('color', $defaultServiceColor);
    $createColor = is_string($createColorRaw) && preg_match('/^#[A-Fa-f0-9]{6}$/', $createColorRaw)
      ? strtoupper($createColorRaw)
      : $defaultServiceColor;
    $createPriceRaw = old('price_kr', '');
    $createPriceKr = is_string($createPriceRaw) ? trim($createPriceRaw) : '';
    $fallbackCategoryId = (string) ($serviceCategories->first()?->id ?? '');
    $createServiceCategoryId = (string) old('service_category_id', $fallbackCategoryId);
    $createSortOrder = (string) old('sort_order', '1');
    $createOnlineBookable = (bool) old('is_online_bookable', true);
    $createRequiresStaffSelection = (bool) old('requires_staff_selection', true);
    $createBufferBeforeMinutes = (string) old('buffer_before_minutes', '0');
    $createBufferAfterMinutes = (string) old('buffer_after_minutes', '0');
    $createMinNoticeMinutes = (string) old('min_notice_minutes', '0');
    $createMaxAdvanceDays = (string) old('max_advance_days', '');
    $createCancellationNoticeHours = (string) old('cancellation_notice_hours', '24');
    $createRawActiveLocationIds = old('form_scope', 'create') === 'create' && old('has_location_selection') !== null
      ? (old('active_location_ids') ?? [])
      : $locations->pluck('id')->map(fn ($id) => (string) $id)->all();
    $createActiveLocationIds = collect($createRawActiveLocationIds)
      ->map(fn ($id) => (string) $id)
      ->values()
      ->all();

    $selectedServiceId = (int) old('modal_service_id', 0);
    $selectedService = $selectedServiceId ? $services->firstWhere('id', $selectedServiceId) : null;
    $showCreateErrors = $errors->any() && old('form_scope', 'create') === 'create';
    $showEditErrors = $errors->any() && old('form_scope') === 'edit' && $selectedService;

    $selectedColorRaw = (
      $showEditErrors
        ? old('color', $selectedService?->color ?? $defaultServiceColor)
        : ($selectedService?->color ?? $defaultServiceColor)
    );
    $selectedColor = is_string($selectedColorRaw) && preg_match('/^#[A-Fa-f0-9]{6}$/', $selectedColorRaw)
      ? strtoupper($selectedColorRaw)
      : $defaultServiceColor;
    $selectedPriceRaw = $showEditErrors
      ? old('price_kr', '')
      : ($selectedService && $selectedService->price_minor !== null ? number_format($selectedService->price_minor / 100, 2, '.', '') : '');
    $selectedPriceKr = is_string($selectedPriceRaw) ? trim($selectedPriceRaw) : '';
    $selectedOnlineBookable = $showEditErrors
      ? (bool) old('is_online_bookable', false)
      : (bool) ($selectedService?->is_online_bookable ?? true);
    $selectedRequiresStaffSelection = $showEditErrors
      ? (bool) old('requires_staff_selection', true)
      : (bool) ($selectedService?->requiresStaffSelection() ?? true);
    $selectedServiceCategoryId = $showEditErrors
      ? (string) old('service_category_id', '')
      : (string) ($selectedService?->service_category_id ?? '');
    $selectedSortOrder = $showEditErrors
      ? (string) old('sort_order', '1')
      : (string) ($selectedService?->sort_order ?? 1);
    $selectedBufferBeforeMinutes = $showEditErrors
      ? (string) old('buffer_before_minutes', '0')
      : (string) ($selectedService?->buffer_before_minutes ?? 0);
    $selectedBufferAfterMinutes = $showEditErrors
      ? (string) old('buffer_after_minutes', '0')
      : (string) ($selectedService?->buffer_after_minutes ?? 0);
    $selectedMinNoticeMinutes = $showEditErrors
      ? (string) old('min_notice_minutes', '0')
      : (string) ($selectedService?->min_notice_minutes ?? 0);
    $selectedMaxAdvanceDays = $showEditErrors
      ? (string) old('max_advance_days', '')
      : (string) ($selectedService?->max_advance_days ?? '');
    $selectedCancellationNoticeHours = $showEditErrors
      ? (string) old('cancellation_notice_hours', '24')
      : (string) ($selectedService?->cancellation_notice_hours ?? 24);
    $defaultActiveLocationIds = $selectedService
      ? $selectedService->locations
        ->filter(fn ($location) => (bool) ($location->pivot?->is_active ?? false))
        ->pluck('id')
        ->all()
      : [];
    $rawActiveLocationIds = $showEditErrors
      ? (
        old('has_location_selection') !== null
          ? (old('active_location_ids') ?? [])
          : old('active_location_ids', $defaultActiveLocationIds)
      )
      : $defaultActiveLocationIds;
    $selectedActiveLocationIds = collect($rawActiveLocationIds)
      ->map(fn ($id) => (string) $id)
      ->values()
      ->all();
    $selectedLocationDurationOverrides = collect(old('location_duration_minutes', []))
      ->mapWithKeys(fn ($value, $id) => [(string) $id => is_string($value) ? trim($value) : (string) $value])
      ->all();
    $selectedLocationPriceOverrides = collect(old('location_price_kr', []))
      ->mapWithKeys(fn ($value, $id) => [(string) $id => is_string($value) ? trim($value) : (string) $value])
      ->all();
    $selectedLocationSortOrderOverrides = collect(old('location_sort_order', []))
      ->mapWithKeys(fn ($value, $id) => [(string) $id => is_string($value) ? trim($value) : (string) $value])
      ->all();
    $categoryFormScope = (string) old('form_scope', '');
    $showCategoryErrors = $errors->any() && str_starts_with($categoryFormScope, 'category_');
    $openCategoryModalId = (string) old('category_modal_id', '');
    $categoryModalError = $errors->first('category_modal');
    $categoryValidationError = $errors->first('category_name')
      ?: $errors->first('category_description')
      ?: $errors->first('category_sort_order');

    if (! $showEditErrors && $selectedService) {
      foreach ($selectedService->locations as $selectedServiceLocation) {
        $locationKey = (string) $selectedServiceLocation->id;
        $selectedLocationDurationOverrides[$locationKey] = $selectedServiceLocation->pivot?->duration_minutes !== null
          ? (string) $selectedServiceLocation->pivot->duration_minutes
          : '';
        $selectedLocationPriceOverrides[$locationKey] = $selectedServiceLocation->pivot?->price_minor !== null
          ? number_format(((int) $selectedServiceLocation->pivot->price_minor) / 100, 2, '.', '')
          : '';
        $selectedLocationSortOrderOverrides[$locationKey] = $selectedServiceLocation->pivot?->sort_order !== null
          ? (string) $selectedServiceLocation->pivot->sort_order
          : '';
      }
    }
  @endphp

  <section
    class="services-page"
    data-services-page
    data-open-service-id="{{ $selectedService?->id ?? '' }}"
    data-open-create-modal="{{ $showCreateErrors ? '1' : '0' }}"
    data-open-category-modal="{{ $showCategoryErrors ? '1' : '0' }}"
    data-open-category-id="{{ $openCategoryModalId }}"
    data-preserve-input="{{ $showEditErrors ? '1' : '0' }}"
  >
    <div class="services-layout">
      <div class="services-card services-card-overview">
        <div class="services-section-head compact">
          <div>
            <p class="services-eyebrow">Oversigt</p>
            <h1>Ydelser</h1>
          </div>
          <button
            type="button"
            class="services-create-fab"
            data-services-create-open
            aria-label="Opret ny ydelse"
            title="Opret ny ydelse"
          >
            <img src="{{ asset('images/icon-pack/lucide/icons/plus.svg') }}" alt="" class="services-create-fab-icon" aria-hidden="true">
          </button>
        </div>

        @if (session('status'))
          <div class="services-alert services-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($errors->has('service_toggle'))
          <div class="services-alert" role="alert">
            {{ $errors->first('service_toggle') }}
          </div>
        @endif

        <div class="services-tools" data-services-tools>
          <p class="services-tools-hint">
            Tip: Klik på <strong>Online</strong> og <strong>On</strong> for at slå ydelsen til eller fra.
          </p>

        </div>

        <div class="services-groups">
          <section class="services-group" data-services-group="fixed">
            <div class="services-group-head">
              <h3>Faste (alle lokationer)</h3>
              <span class="services-group-count">{{ $fixedServices->count() }}</span>
            </div>

            <div class="services-list">
              @forelse ($fixedServices as $service)
                @include('services._service-item', ['service' => $service, 'serviceGroup' => 'fixed'])
              @empty
                <article class="services-empty" data-services-empty-static>
                  <strong>Ingen faste ydelser</strong>
                  <span>Ydelser vises her, når de er aktive på alle lokationer.</span>
                </article>
              @endforelse

            </div>
          </section>

          <section class="services-group" data-services-group="local">
            <div class="services-group-head">
              <h3>Lokale (afvigelser)</h3>
              <span class="services-group-count">{{ $localServices->count() }}</span>
            </div>

            <div class="services-list">
              @forelse ($localServices as $service)
                @include('services._service-item', ['service' => $service, 'serviceGroup' => 'local'])
              @empty
                <article class="services-empty" data-services-empty-static>
                  <strong>Ingen lokale ydelser</strong>
                  <span>Når en ydelse ikke er aktiv på alle lokationer, vises den her.</span>
                </article>
              @endforelse

            </div>
          </section>
        </div>
      </div>
    </div>

    <dialog class="services-modal services-modal-create" data-services-create-modal>
      <div class="services-modal-card">
        <div class="services-modal-head">
          <div>
            <p class="services-eyebrow">Ydelser</p>
            <h2>Opret ny ydelse</h2>
            <p class="services-text">Tilføj den faste ydelse her. Efter oprettelse kan du styre lokalt, hvilke lokationer den er aktiv på.</p>
          </div>

          <button type="button" class="services-modal-close" data-services-create-modal-close aria-label="Luk">
            Luk
          </button>
        </div>

        @if ($showCreateErrors)
          <div class="services-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <form class="services-modal-form" method="POST" action="{{ route('services.store') }}">
          @csrf
          <input type="hidden" name="form_scope" value="create">
          <input type="hidden" name="has_location_selection" value="1">

          <div class="services-grid">
            <label class="services-field">
              <span>Navn</span>
              <input type="text" name="name" value="{{ old('name') }}" required>
            </label>
          </div>

          <div class="services-grid services-grid-two">
            <label class="services-field">
              <span>Varighed (minutter)</span>
              <input type="number" name="duration_minutes" min="5" step="5" value="{{ old('duration_minutes', 30) }}" required>
            </label>

            <label class="services-field">
              <span>Pris inkl. moms (kr.)</span>
              <input type="text" name="price_kr" value="{{ $createPriceKr }}" placeholder="Fx 499 eller 499,95" inputmode="decimal">
            </label>
          </div>

          <div class="services-meta-strip" role="note" aria-label="Pris og visningsinfo">
            <article class="services-meta-card services-meta-card-highlight">
              <strong>Rækkefølge</strong>
              <p>Laveste tal vises først.</p>
            </article>

            <article class="services-meta-card">
              <strong>Moms</strong>
              <p>Alle priser vises inkl. moms.</p>
              <span class="services-meta-pill">25 %</span>
            </article>
          </div>

          <div class="services-grid services-grid-two">
            <label class="services-field">
              <span>Rækkefølge visning</span>
              <input type="number" name="sort_order" min="1" step="1" value="{{ $createSortOrder }}">
            </label>

            <label class="services-field">
              <span>Kategori</span>
              <div class="services-category-field-row">
                <select name="service_category_id" data-services-create-category @if ($requiresServiceCategories) required @endif>
                  @if (! $requiresServiceCategories)
                    <option value="">Standard (automatisk)</option>
                  @endif
                  @foreach ($serviceCategories as $serviceCategory)
                    <option value="{{ $serviceCategory->id }}" @selected($createServiceCategoryId === (string) $serviceCategory->id)>
                      {{ $serviceCategory->name }}
                    </option>
                  @endforeach
                </select>

                <button
                  type="button"
                  class="services-button services-button-ghost services-button-square"
                  data-services-categories-open
                  aria-label="Administrer kategorier"
                  title="Administrer kategorier"
                >
                  +
                </button>
              </div>
            </label>
          </div>

          <div class="services-field services-field-toggle">
            <input type="hidden" name="is_online_bookable" value="0">
            <span>Online booking</span>
            <label class="services-inline-checkbox">
              <input type="checkbox" name="is_online_bookable" value="1" @checked($createOnlineBookable)>
              <span>Ydelsen kan bookes af kunder på den offentlige side</span>
            </label>
          </div>

          <div class="services-field services-field-toggle">
            <input type="hidden" name="requires_staff_selection" value="0">
            <span>Kraever behandler</span>
            <label class="services-inline-checkbox">
              <input type="checkbox" name="requires_staff_selection" value="1" @checked($createRequiresStaffSelection)>
              <span>Nar den er slaet fra, tildeles ledig behandler automatisk ved online booking</span>
            </label>
          </div>

          <section class="services-subsection">
            <p class="services-subsection-title">Planlægning</p>

            <div class="services-grid services-grid-two">
              <label class="services-field">
                <span>Buffer før (min)</span>
                <input type="number" name="buffer_before_minutes" min="0" max="240" step="5" value="{{ $createBufferBeforeMinutes }}">
              </label>

              <label class="services-field">
                <span>Buffer efter (min)</span>
                <input type="number" name="buffer_after_minutes" min="0" max="240" step="5" value="{{ $createBufferAfterMinutes }}">
              </label>
            </div>

            <div class="services-grid services-grid-two">
              <label class="services-field">
                <span>Min. varsel (min)</span>
                <input type="number" name="min_notice_minutes" min="0" max="10080" step="15" value="{{ $createMinNoticeMinutes }}">
              </label>

              <label class="services-field">
                <span>Max. dage frem</span>
                <input type="number" name="max_advance_days" min="1" max="730" step="1" value="{{ $createMaxAdvanceDays }}" placeholder="Tom = ingen grænse">
              </label>
            </div>

            <label class="services-field">
              <span>Online-aflysning deadline (timer før)</span>
              <input type="number" name="cancellation_notice_hours" min="0" max="720" step="1" value="{{ $createCancellationNoticeHours }}">
            </label>
          </section>

          <div class="services-grid">
            <label class="services-field">
              <span>Farve (HEX)</span>
              <div class="services-color-input">
                <input type="color" value="{{ $createColor }}" data-services-create-color-picker aria-label="Vælg farve">
                <input
                  type="text"
                  name="color"
                  value="{{ $createColor }}"
                  placeholder="#5C80BC"
                  maxlength="7"
                  data-services-create-color-input
                  required
                >
              </div>
            </label>
          </div>

          <label class="services-field">
            <span>Beskrivelse</span>
            <textarea name="description" rows="4" placeholder="Kort tekst om hvad ydelsen indeholder">{{ old('description') }}</textarea>
          </label>

          @if ($locations->isNotEmpty())
            <section class="services-location-config">
              <p class="services-location-title">Aktiv på lokationer</p>
              <p class="services-location-help">
                Vælg hvilke lokationer ydelsen skal oprettes på. Vælger du kun én, bliver den lokal til den lokation.
              </p>

              <div class="services-location-list">
                @foreach ($locations as $location)
                  <div class="services-location-item">
                    <label class="services-location-item-head">
                      <input
                        type="checkbox"
                        name="active_location_ids[]"
                        value="{{ $location->id }}"
                        @checked(in_array((string) $location->id, $createActiveLocationIds, true))
                      >
                      <span>{{ $location->name }}</span>
                    </label>
                  </div>
                @endforeach
              </div>
            </section>
          @endif

          <button type="submit" class="services-button">Opret ydelse</button>
        </form>
      </div>
    </dialog>

    <dialog class="services-modal services-modal-categories" data-services-categories-modal>
      <div class="services-modal-card">
        <div class="services-modal-head">
          <div>
            <p class="services-eyebrow">Ydelseskategorier</p>
            <h2>Administrer kategorier</h2>
            <p class="services-text">Opret, rediger og ryd op i kategorierne her. Ydelser vælger derefter kun fra listen.</p>
          </div>

          <button type="button" class="services-modal-close" data-services-categories-close aria-label="Luk">
            Luk
          </button>
        </div>

        @if ($showCategoryErrors && ($categoryValidationError !== '' || $categoryModalError !== ''))
          <div class="services-alert" role="alert">
            {{ $categoryValidationError !== '' ? $categoryValidationError : $categoryModalError }}
          </div>
        @endif

        @php
          $showCreateCategoryInput = old('form_scope') === 'category_create';
        @endphp

        <section class="services-categories-block">
          <p class="services-subsection-title">Opret kategori</p>

          <form class="services-modal-form" method="POST" action="{{ route('services.categories.store') }}">
            @csrf
            <input type="hidden" name="form_scope" value="category_create">

            <div class="services-grid services-grid-two">
              <label class="services-field">
                <span>Navn</span>
                <input
                  type="text"
                  name="category_name"
                  value="{{ $showCreateCategoryInput ? (string) old('category_name', '') : '' }}"
                  maxlength="120"
                  placeholder="Fx Klip"
                  required
                >
              </label>

              <label class="services-field">
                <span>Rækkefølge</span>
                <input
                  type="number"
                  name="category_sort_order"
                  min="0"
                  step="1"
                  value="{{ $showCreateCategoryInput ? (string) old('category_sort_order', '') : '' }}"
                  placeholder="Tom = sidst"
                >
              </label>
            </div>

            <label class="services-field">
              <span>Beskrivelse (valgfri)</span>
              <input
                type="text"
                name="category_description"
                maxlength="255"
                value="{{ $showCreateCategoryInput ? (string) old('category_description', '') : '' }}"
                placeholder="Kort tekst der kan bruges på den offentlige bookingside"
              >
            </label>

            <button type="submit" class="services-button">Opret kategori</button>
          </form>
        </section>

        <section class="services-categories-block">
          <p class="services-subsection-title">Eksisterende kategorier</p>

          <div class="services-categories-list">
            @forelse ($serviceCategories as $serviceCategory)
              @php
                $isCurrentCategoryError = $openCategoryModalId !== '' && $openCategoryModalId === (string) $serviceCategory->id;
                $categoryNameValue = $isCurrentCategoryError
                  ? (string) old('category_name', (string) $serviceCategory->name)
                  : (string) $serviceCategory->name;
                $categoryDescriptionValue = $isCurrentCategoryError
                  ? (string) old('category_description', (string) ($serviceCategory->description ?? ''))
                  : (string) ($serviceCategory->description ?? '');
                $categorySortOrderValue = $isCurrentCategoryError
                  ? (string) old('category_sort_order', (string) $serviceCategory->sort_order)
                  : (string) $serviceCategory->sort_order;
              @endphp
              <article
                class="services-category-item{{ $isCurrentCategoryError ? ' is-focused' : '' }}"
                data-service-category-item="{{ $serviceCategory->id }}"
              >
                <form class="services-category-form" method="POST" action="{{ route('services.categories.update', $serviceCategory) }}">
                  @csrf
                  @method('PATCH')
                  <input type="hidden" name="form_scope" value="category_update">
                  <input type="hidden" name="category_modal_id" value="{{ $serviceCategory->id }}">

                  <div class="services-grid services-grid-two">
                    <label class="services-field">
                      <span>Navn</span>
                      <input type="text" name="category_name" value="{{ $categoryNameValue }}" maxlength="120" required>
                    </label>

                    <label class="services-field">
                      <span>Rækkefølge</span>
                      <input type="number" name="category_sort_order" min="0" step="1" value="{{ $categorySortOrderValue }}">
                    </label>
                  </div>

                  <label class="services-field">
                    <span>Beskrivelse</span>
                    <input type="text" name="category_description" maxlength="255" value="{{ $categoryDescriptionValue }}">
                  </label>

                  <div class="services-category-actions">
                    <span class="services-badge services-badge-muted">
                      {{ (int) $serviceCategory->services_count }} ydelser
                    </span>
                    <button type="submit" class="services-button services-button-secondary">Gem</button>
                  </div>
                </form>

                <form method="POST" action="{{ route('services.categories.destroy', $serviceCategory) }}">
                  @csrf
                  @method('DELETE')
                  <input type="hidden" name="form_scope" value="category_delete">
                  <input type="hidden" name="category_modal_id" value="{{ $serviceCategory->id }}">
                  <button
                    type="submit"
                    class="services-button services-button-danger"
                    @disabled((int) $serviceCategory->services_count > 0)
                  >
                    Slet
                  </button>
                </form>
              </article>
            @empty
              <article class="services-empty">
                <strong>Ingen kategorier endnu</strong>
                <span>Opret den første kategori ovenfor.</span>
              </article>
            @endforelse
          </div>
        </section>
      </div>
    </dialog>

    @if ($services->isNotEmpty())
      <dialog class="services-modal" data-services-modal>
        <div class="services-modal-card">
          <div class="services-modal-head">
            <div>
              <p class="services-eyebrow">Ydelseseditor</p>
              <h2 data-services-modal-title>{{ $selectedService?->name ?? 'Rediger ydelse' }}</h2>
              <p class="services-text" data-services-modal-subtitle>
                {{ $selectedService ? $selectedService->effectiveDurationMinutes() . ' minutter · aktiv lokalt pr. lokation' : 'Vælg en ydelse fra listen for at redigere den.' }}
              </p>
            </div>

            <button type="button" class="services-modal-close" data-services-modal-close aria-label="Luk">
              Luk
            </button>
          </div>

          @if ($showEditErrors)
            <div class="services-alert" role="alert">
              {{ $errors->first() }}
            </div>
          @endif

          <div class="services-modal-swatch-wrap">
            <span
              class="services-swatch services-swatch-large"
              style="--service-color: {{ $selectedColor }};"
              data-services-modal-swatch
            ></span>
            <span class="services-badge" data-services-modal-bookings>
              {{ $selectedService?->bookings_count ?? 0 }} bookinger
            </span>
          </div>

          <form
            class="services-modal-form"
            method="POST"
            action="{{ $selectedService ? route('services.update', $selectedService) : '#' }}"
            data-services-edit-form
          >
            @csrf
            @method('PATCH')
            <input type="hidden" name="form_scope" value="edit">
            <input type="hidden" name="has_location_selection" value="1">
            <input type="hidden" name="modal_service_id" value="{{ $selectedService?->id ?? '' }}" data-services-modal-service-id>

            <div class="services-grid">
              <label class="services-field">
                <span>Navn</span>
                <input
                  type="text"
                  name="name"
                  value="{{ $showEditErrors ? old('name') : ($selectedService?->name ?? '') }}"
                  data-services-field-name
                  required
                >
              </label>
            </div>

            <div class="services-grid services-grid-two">
              <label class="services-field">
                <span>Varighed (minutter)</span>
                <input
                  type="number"
                  name="duration_minutes"
                  min="5"
                  step="5"
                  value="{{ $showEditErrors ? old('duration_minutes') : ($selectedService?->duration_minutes ?? 30) }}"
                  data-services-field-duration
                  required
                >
              </label>

              <label class="services-field">
                <span>Pris inkl. moms (kr.)</span>
                <input
                  type="text"
                  name="price_kr"
                  value="{{ $selectedPriceKr }}"
                  placeholder="Fx 499 eller 499,95"
                  inputmode="decimal"
                  data-services-field-price
                >
              </label>
            </div>

            <div class="services-meta-strip" role="note" aria-label="Pris og visningsinfo">
              <article class="services-meta-card services-meta-card-highlight">
                <strong>Rækkefølge</strong>
                <p>Laveste tal vises først.</p>
              </article>

              <article class="services-meta-card">
                <strong>Moms</strong>
                <p>Alle priser vises inkl. moms.</p>
                <span class="services-meta-pill">25 %</span>
              </article>
            </div>

            <div class="services-grid services-grid-two">
              <label class="services-field">
                <span>Rækkefølge visning</span>
                <input
                  type="number"
                  name="sort_order"
                  min="1"
                  step="1"
                  value="{{ $selectedSortOrder }}"
                  data-services-field-sort-order
                >
              </label>

              <label class="services-field">
                <span>Kategori</span>
                <div class="services-category-field-row">
                  <select name="service_category_id" data-services-field-category @if ($requiresServiceCategories) required @endif>
                    @if (! $requiresServiceCategories)
                      <option value="">Standard (automatisk)</option>
                    @endif
                    @foreach ($serviceCategories as $serviceCategory)
                      <option
                        value="{{ $serviceCategory->id }}"
                        @selected($selectedServiceCategoryId === (string) $serviceCategory->id)
                      >
                        {{ $serviceCategory->name }}
                      </option>
                    @endforeach
                  </select>

                  <button
                    type="button"
                    class="services-button services-button-ghost services-button-square"
                    data-services-categories-open
                    aria-label="Administrer kategorier"
                    title="Administrer kategorier"
                  >
                    +
                  </button>
                </div>
              </label>
            </div>

            <div class="services-field services-field-toggle">
              <input type="hidden" name="is_online_bookable" value="0">
              <span>Online booking</span>
              <label class="services-inline-checkbox">
                <input
                  type="checkbox"
                  name="is_online_bookable"
                  value="1"
                  data-services-field-online-bookable
                  @checked($selectedOnlineBookable)
                >
                <span>Ydelsen kan bookes af kunder på den offentlige side</span>
              </label>
            </div>

            <div class="services-field services-field-toggle">
              <input type="hidden" name="requires_staff_selection" value="0">
              <span>Kraever behandler</span>
              <label class="services-inline-checkbox">
                <input
                  type="checkbox"
                  name="requires_staff_selection"
                  value="1"
                  data-services-field-requires-staff-selection
                  @checked($selectedRequiresStaffSelection)
                >
                <span>Nar den er slaet fra, tildeles ledig behandler automatisk ved online booking</span>
              </label>
            </div>

            <section class="services-subsection">
              <p class="services-subsection-title">Planlægning</p>

              <div class="services-grid services-grid-two">
                <label class="services-field">
                  <span>Buffer før (min)</span>
                  <input
                    type="number"
                    name="buffer_before_minutes"
                    min="0"
                    max="240"
                    step="5"
                    value="{{ $selectedBufferBeforeMinutes }}"
                    data-services-field-buffer-before
                  >
                </label>

                <label class="services-field">
                  <span>Buffer efter (min)</span>
                  <input
                    type="number"
                    name="buffer_after_minutes"
                    min="0"
                    max="240"
                    step="5"
                    value="{{ $selectedBufferAfterMinutes }}"
                    data-services-field-buffer-after
                  >
                </label>
              </div>

              <div class="services-grid services-grid-two">
                <label class="services-field">
                  <span>Min. varsel (min)</span>
                  <input
                    type="number"
                    name="min_notice_minutes"
                    min="0"
                    max="10080"
                    step="15"
                    value="{{ $selectedMinNoticeMinutes }}"
                    data-services-field-min-notice
                  >
                </label>

                <label class="services-field">
                  <span>Max. dage frem</span>
                  <input
                    type="number"
                    name="max_advance_days"
                    min="1"
                    max="730"
                    step="1"
                    value="{{ $selectedMaxAdvanceDays }}"
                    placeholder="Tom = ingen grænse"
                    data-services-field-max-advance
                  >
                </label>
              </div>

              <label class="services-field">
                <span>Online-aflysning deadline (timer før)</span>
                <input
                  type="number"
                  name="cancellation_notice_hours"
                  min="0"
                  max="720"
                  step="1"
                  value="{{ $selectedCancellationNoticeHours }}"
                  data-services-field-cancellation-notice
                >
              </label>
            </section>

            <div class="services-grid">
              <label class="services-field">
                <span>Farve (HEX)</span>
                <div class="services-color-input">
                  <input type="color" value="{{ $selectedColor }}" data-services-modal-color-picker aria-label="Vælg farve">
                  <input
                    type="text"
                    name="color"
                    value="{{ $selectedColor }}"
                    placeholder="#5C80BC"
                    maxlength="7"
                    data-services-field-color
                    required
                  >
                </div>
              </label>
            </div>

            <label class="services-field">
              <span>Beskrivelse</span>
              <textarea name="description" rows="4" data-services-field-description>{{ $showEditErrors ? old('description') : ($selectedService?->description ?? '') }}</textarea>
            </label>

            @if ($locations->isNotEmpty())
              <section class="services-location-config">
                <p class="services-location-title">Aktiv på lokationer</p>
                <p class="services-location-help">
                  Slå ydelsen til/fra pr. lokation og tilføj lokale overrides for varighed/pris ved behov.
                </p>

                <div class="services-location-list">
                  @foreach ($locations as $location)
                    @php
                      $locationKey = (string) $location->id;
                      $locationDurationOverrideValue = $selectedLocationDurationOverrides[$locationKey] ?? '';
                      $locationPriceOverrideValue = $selectedLocationPriceOverrides[$locationKey] ?? '';
                      $locationSortOrderOverrideValue = $selectedLocationSortOrderOverrides[$locationKey] ?? '';
                    @endphp
                    <div class="services-location-item" data-services-location-item="{{ $location->id }}">
                      <label class="services-location-item-head">
                        <input
                          type="checkbox"
                          name="active_location_ids[]"
                          value="{{ $location->id }}"
                          data-services-location-checkbox
                          data-services-location-id="{{ $location->id }}"
                          @checked(in_array((string) $location->id, $selectedActiveLocationIds, true))
                        >
                        <span>{{ $location->name }}</span>
                      </label>

                      <div class="services-location-item-overrides">
                        <label class="services-field">
                          <span>Lokal rækkefølge</span>
                          <input
                            type="number"
                            name="location_sort_order[{{ $location->id }}]"
                            min="1"
                            max="9999"
                            step="1"
                            value="{{ $locationSortOrderOverrideValue }}"
                            placeholder="Tom = global"
                            data-services-location-sort-order="{{ $location->id }}"
                          >
                        </label>

                        <label class="services-field">
                          <span>Lokal varighed (min)</span>
                          <input
                            type="number"
                            name="location_duration_minutes[{{ $location->id }}]"
                            min="5"
                            max="480"
                            step="5"
                            value="{{ $locationDurationOverrideValue }}"
                            placeholder="Tom = global"
                            data-services-location-duration="{{ $location->id }}"
                          >
                        </label>

                        <label class="services-field">
                          <span>Lokal pris inkl. moms (kr.)</span>
                          <input
                            type="text"
                            name="location_price_kr[{{ $location->id }}]"
                            value="{{ $locationPriceOverrideValue }}"
                            placeholder="Tom = global"
                            inputmode="decimal"
                            data-services-location-price="{{ $location->id }}"
                          >
                        </label>
                      </div>
                    </div>
                  @endforeach
                </div>
              </section>
            @endif

            <div class="services-modal-actions">
              <button type="submit" class="services-button services-button-secondary">Gem ændringer</button>
              <button type="button" class="services-button services-button-muted" data-services-modal-close">Annuller</button>
            </div>
          </form>

          <form
            method="POST"
            action="{{ $selectedService ? route('services.destroy', $selectedService) : '#' }}"
            class="services-delete-form"
            data-services-delete-form
          >
            @csrf
            @method('DELETE')
            <div class="services-delete-row">
              <div class="services-delete-copy">
                <strong>Slet ydelse</strong>
                <span data-services-delete-text>
                  {{ ($selectedService?->bookings_count ?? 0) > 0 ? 'Denne ydelse har bookinger og kan derfor ikke slettes.' : 'Slet kun ydelsen hvis den ikke længere skal bruges.' }}
                </span>
              </div>
              <button
                type="submit"
                class="services-button services-button-danger"
                data-services-delete-button
                @disabled(($selectedService?->bookings_count ?? 0) > 0)
              >
                Slet ydelse
              </button>
            </div>
          </form>
        </div>
      </dialog>
    @endif
  </section>
@endsection
