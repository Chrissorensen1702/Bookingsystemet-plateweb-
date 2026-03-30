@extends('layouts.default')

@section('title', 'Indstillinger')

@section('body-class', 'booking-home-body settings-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $isPoweredByLocked = (bool) ($planRequiresPoweredBy ?? false);
    $activePlanName = trim((string) ($planName ?? ''));
    $locations = $locations ?? collect();
    $selectedLocationId = (int) ($selectedLocationId ?? 0);
    $selectedLocation = $selectedLocation ?? null;
    $canManageGlobal = (bool) ($canManageGlobal ?? false);
    $isLocationManager = (bool) ($isLocationManager ?? false);
    $globalSettingsError = $errors->first('settings');
    $firstPageError = collect($errors->keys())
      ->reject(static fn (string $key): bool => $key === 'settings')
      ->map(fn (string $key): string => (string) $errors->first($key))
      ->first();
  @endphp

  <section class="settings-page">
    <div class="settings-layout">
      <div class="settings-card">
        <div class="settings-section-head">
          <div>
            <p class="settings-eyebrow">Indstillinger</p>
            <h1>Branding for offentlig booking</h1>
          </div>
          <p class="settings-text">
            Global branding styres centralt. Lokationstekster og adresser styres pr. afdeling.
          </p>
        </div>

        @if (session('status'))
          <div class="settings-alert settings-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($firstPageError)
          <div class="settings-alert" role="alert">
            {{ $firstPageError }}
          </div>
        @endif

        @if ($locations->count() > 1 && ! $isLocationManager)
          <form method="GET" class="settings-location-form">
            <label class="settings-field">
              <span>Vælg lokation (lokale indstillinger)</span>
              <select name="location_id" onchange="this.form.submit()">
                @foreach ($locations as $location)
                  <option value="{{ $location->id }}" @selected($selectedLocationId === (int) $location->id)>
                    {{ $location->name }}
                  </option>
                @endforeach
              </select>
            </label>
          </form>
        @elseif ($isLocationManager && $selectedLocation)
          <div class="settings-alert settings-alert-scope" role="status">
            Lokationschef: du redigerer lokale indstillinger for <strong>{{ $selectedLocation->name }}</strong>.
          </div>
        @endif

        <form class="settings-form settings-form-local" method="POST" action="{{ route('settings.update') }}">
          @csrf
          @method('PATCH')
          <input type="hidden" name="update_booking_intro" value="1">
          <input type="hidden" name="location_id" value="{{ $selectedLocationId }}">

          <section class="settings-block settings-block-local">
            <div class="settings-block-head">
              <span>Lokationsindstillinger</span>
            </div>

            @if ($selectedLocation)
              <p class="settings-text">Aktiv lokation: <strong>{{ $selectedLocation->name }}</strong></p>
            @endif

            <div class="settings-grid">
              <label class="settings-field">
                <span>Tekst til offentlig booking-side</span>
                <textarea
                  name="location_public_booking_intro_text"
                  rows="3"
                  maxlength="500"
                  placeholder="Vælg ydelse, tidspunkt og kontaktoplysninger. Når du opretter, ligger bookingen straks i kalenderen."
                >{{ old('location_public_booking_intro_text', (string) ($selectedLocation?->public_booking_intro_text ?? '')) }}</textarea>
                <small>Denne tekst vises kun for den valgte lokation.</small>
              </label>
            </div>

            <div class="settings-grid settings-grid-two">
              <label class="settings-field">
                <span>Adresse linje 1</span>
                <input
                  type="text"
                  name="location_address_line_1"
                  value="{{ old('location_address_line_1', (string) ($selectedLocation?->address_line_1 ?? '')) }}"
                  placeholder="Fx Storegade 12"
                >
              </label>
              <label class="settings-field">
                <span>Adresse linje 2 (valgfri)</span>
                <input
                  type="text"
                  name="location_address_line_2"
                  value="{{ old('location_address_line_2', (string) ($selectedLocation?->address_line_2 ?? '')) }}"
                  placeholder="Fx 1. sal"
                >
              </label>
            </div>

            <div class="settings-grid settings-grid-two">
              <label class="settings-field">
                <span>Postnummer</span>
                <input
                  type="text"
                  name="location_postal_code"
                  value="{{ old('location_postal_code', (string) ($selectedLocation?->postal_code ?? '')) }}"
                  placeholder="Fx 8000"
                >
              </label>
              <label class="settings-field">
                <span>By</span>
                <input
                  type="text"
                  name="location_city"
                  value="{{ old('location_city', (string) ($selectedLocation?->city ?? '')) }}"
                  placeholder="Fx Aarhus C"
                >
              </label>
            </div>

            <div class="settings-grid settings-grid-two">
              <label class="settings-field">
                <span>Mobil nr. (offentlig)</span>
                <input
                  type="text"
                  name="location_public_contact_phone"
                  value="{{ old('location_public_contact_phone', (string) ($selectedLocation?->public_contact_phone ?? '')) }}"
                  placeholder="Fx +45 12 34 56 78"
                >
              </label>
              <label class="settings-field">
                <span>E-mail (offentlig)</span>
                <input
                  type="email"
                  name="location_public_contact_email"
                  value="{{ old('location_public_contact_email', (string) ($selectedLocation?->public_contact_email ?? '')) }}"
                  placeholder="Fx booking@dinforretning.dk"
                >
              </label>
            </div>

            <div class="settings-actions">
              <button type="submit" class="settings-button">Gem lokationsindstillinger</button>
            </div>
          </section>
        </form>

        <section class="settings-block settings-block-scope{{ $canManageGlobal ? '' : ' is-locked' }}">
          <div class="settings-block-head settings-block-head-split">
            <span>Global branding (alle lokationer)</span>
            @if (! $canManageGlobal)
              <span class="settings-scope-lock">
                <img src="{{ asset('images/icon-pack/lucide/icons/lock.svg') }}" alt="">
                Kun owner
              </span>
            @endif
          </div>

          @if ($globalSettingsError)
            <div class="settings-alert" role="alert">
              {{ $globalSettingsError }}
            </div>
          @endif

          <form class="settings-form settings-form-global" method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <div class="settings-grid settings-grid-two">
              <label class="settings-field">
                <span>Brand navn</span>
                <input type="text" name="public_brand_name" value="{{ old('public_brand_name', $tenant->public_brand_name) }}" placeholder="{{ $tenant->name }}" @disabled(! $canManageGlobal)>
              </label>

              <label class="settings-field">
                <span>Logo alt tekst</span>
                <input type="text" name="public_logo_alt" value="{{ old('public_logo_alt', $tenant->public_logo_alt) }}" placeholder="Beskriv logoet kort" @disabled(! $canManageGlobal)>
              </label>
            </div>

            <div class="settings-grid settings-grid-two">
              <label class="settings-field">
                <span>Primær farve</span>
                <div class="settings-color-input">
                  <input type="color" value="{{ old('public_primary_color', $tenant->public_primary_color ?? '#5C80BC') }}" data-color-target="primary" @disabled(! $canManageGlobal)>
                  <input type="text" name="public_primary_color" value="{{ old('public_primary_color', $tenant->public_primary_color) }}" placeholder="#5C80BC" maxlength="7" data-color-input="primary" @disabled(! $canManageGlobal)>
                </div>
              </label>

              <label class="settings-field">
                <span>Accent farve</span>
                <div class="settings-color-input">
                  <input type="color" value="{{ old('public_accent_color', $tenant->public_accent_color ?? '#E8C547') }}" data-color-target="accent" @disabled(! $canManageGlobal)>
                  <input type="text" name="public_accent_color" value="{{ old('public_accent_color', $tenant->public_accent_color) }}" placeholder="#E8C547" maxlength="7" data-color-input="accent" @disabled(! $canManageGlobal)>
                </div>
              </label>
            </div>

            <div class="settings-stack">
              <section class="settings-block">
                <div class="settings-block-head">
                  <span>Upload logo</span>
                </div>

                <div class="settings-grid settings-grid-two settings-grid-tight">
                  <label class="settings-field settings-field-upload">
                    <input type="file" name="public_logo_file" accept=".svg,.png,.webp,.jpg,.jpeg" @disabled(! $canManageGlobal)>
                  </label>

                  <label class="settings-check settings-check-inline settings-check-remove">
                    <input type="hidden" name="remove_public_logo" value="0">
                    <input type="checkbox" name="remove_public_logo" value="1" @disabled(! $canManageGlobal)>
                    <span>Fjern nuværende upload-logo</span>
                  </label>
                </div>

                <div class="settings-upload-note">
                  <strong>Upload guide</strong>
                  <ul>
                    <li>Tilladte filtyper: SVG, PNG, WEBP, JPG/JPEG.</li>
                    <li>Maks filstørrelse: 4 MB.</li>
                    <li>Anbefalet: SVG (bedst) eller 600-1200 px bred PNG/WEBP med transparent baggrund.</li>
                  </ul>
                </div>
              </section>

              <section class="settings-block">
                <div class="settings-field settings-field-check">
                  <label class="settings-check">
                    <input type="hidden" name="work_shifts_enabled" value="0">
                    <input
                      type="checkbox"
                      name="work_shifts_enabled"
                      value="1"
                      @checked((bool) old('work_shifts_enabled', $tenant->work_shifts_enabled ?? true))
                      @disabled(! $canManageGlobal)
                    >
                    <span>Aktiver bookbarhed i bookingsystemet</span>
                  </label>
                  <small>
                    Når slået fra skjules arbejdstider/bookbarhed, og kunder skal ikke vælge behandler ved online booking.
                  </small>
                </div>

                <div class="settings-field settings-field-check">
                  <label class="settings-check">
                    <input type="hidden" name="require_service_categories" value="0">
                    <input
                      type="checkbox"
                      name="require_service_categories"
                      value="1"
                      @checked((bool) old('require_service_categories', $tenant->require_service_categories))
                      @disabled(! $canManageGlobal)
                    >
                    <span>Kræv kategori-valg på online booking</span>
                  </label>
                  <small>
                    Når slået fra, vises ydelser direkte i bookingflow uden kategori-step.
                  </small>
                </div>

                <div class="settings-field settings-field-check">
                  <label class="settings-check settings-check-powered{{ $isPoweredByLocked ? ' is-plan-locked' : '' }}">
                    <span class="settings-check-main">
                      <input type="hidden" name="show_powered_by" value="{{ $isPoweredByLocked ? '1' : '0' }}">
                      <input
                        type="checkbox"
                        name="show_powered_by"
                        value="1"
                        @checked($isPoweredByLocked || (bool) old('show_powered_by', $tenant->show_powered_by))
                        @disabled($isPoweredByLocked || ! $canManageGlobal)
                      >
                      <span>Vis "Powered by PlateBooking"</span>
                    </span>
                    <span class="settings-lock" aria-hidden="true">
                      <img src="{{ asset('images/icon-pack/lucide/icons/lock.svg') }}" alt="">
                    </span>
                    <small class="settings-lock-note">
                      Låst af {{ $activePlanName !== '' ? $activePlanName : 'Starter' }}-plan
                    </small>
                  </label>
                </div>
              </section>
            </div>

            <div class="settings-actions">
              <button type="submit" class="settings-button" @disabled(! $canManageGlobal)>Gem branding</button>
            </div>
          </form>

          <form method="POST" action="{{ route('settings.update') }}" class="settings-reset-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="reset_branding" value="1">
            <button type="submit" class="settings-button settings-button-ghost" @disabled(! $canManageGlobal)>Nulstil branding (ingen logo)</button>
          </form>
        </section>
      </div>

      <div class="settings-card">
        <div class="settings-section-head compact">
          <div>
            <p class="settings-eyebrow">Forhåndsvisning</p>
            <h2>Mobil booking-side</h2>
          </div>
          <p class="settings-text">
            Live visning af den offentlige bookingside for den valgte lokation.
          </p>
        </div>

        <div class="settings-preview-live">
          <div class="settings-preview-frame-shell">
            <iframe
              src="{{ $publicBookingPreviewUrl }}"
              class="settings-preview-frame"
              title="Forhåndsvisning af offentlig booking-side"
              loading="lazy"
              referrerpolicy="same-origin"
            ></iframe>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
