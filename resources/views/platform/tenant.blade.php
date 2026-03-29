<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>{{ $tenant->name }} | Platform</title>
  @vite(['resources/css/app-platform.css', 'resources/js/pwa.js'])
</head>
<body class="platform-body">
  <main class="platform-shell">
    <header class="platform-topbar">
      <a href="{{ route('platform.dashboard') }}" class="platform-brand">
        <img src="{{ asset('images/logo/header.svg') }}" alt="PlæBooking">
        <span>
          <strong>{{ $tenant->name }}</strong>
          <span>Platformstyring af butik</span>
        </span>
      </a>

      <div class="platform-top-actions">
        <a href="{{ route('platform.dashboard') }}" class="platform-link-button">Tilbage til valg</a>
        <form method="POST" action="{{ route('platform.logout') }}">
          @csrf
          <button type="submit" class="platform-button">Log ud</button>
        </form>
      </div>
    </header>

    @if (session('status'))
      <p class="platform-alert platform-alert-success" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
      <p class="platform-alert" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="platform-grid">
      <article class="platform-card">
        <p class="platform-eyebrow">Virksomhed</p>
        <h1>Butiksoplysninger</h1>
        <p class="platform-muted">
          Styr navn, slug og status for butikken. Slug bruges til tenant-linking i bookingflow.
        </p>

        <form method="POST" action="{{ route('platform.tenants.update', $tenant) }}" class="platform-form">
          @csrf
          @method('PATCH')

          <label class="platform-field">
            <span>Navn</span>
            <input type="text" name="name" value="{{ old('name', $tenant->name) }}" required>
          </label>

          <div class="platform-form-grid">
            <label class="platform-field">
              <span>Slug</span>
              <input type="text" name="slug" value="{{ old('slug', $tenant->slug) }}" required>
            </label>

            <label class="platform-field">
              <span>Tidszone</span>
              <input type="text" name="timezone" value="{{ old('timezone', $tenant->timezone) }}" required>
            </label>
          </div>

          <label class="platform-field">
            <span>Abonnement</span>
            <select name="plan_id" required>
              @foreach ($plans as $plan)
                <option
                  value="{{ $plan->id }}"
                  @selected((int) old('plan_id', $tenant->plan_id) === (int) $plan->id)
                >
                  {{ $plan->name }}{{ $plan->requires_powered_by ? ' · Powered by påkrævet' : '' }}
                </option>
              @endforeach
            </select>
          </label>

          <div class="platform-branding-block">
            <h3>Offentlig booking branding</h3>
            <p class="platform-muted">
              Her kan du personliggore den offentlige bookingside. Logo uploades af owner i bookingsystemets indstillinger.
            </p>

            <div class="platform-form-grid">
              <label class="platform-field">
                <span>Brandnavn (offentlig side)</span>
                <input
                  type="text"
                  name="public_brand_name"
                  value="{{ old('public_brand_name', $tenant->public_brand_name) }}"
                  placeholder="Fx Test Virksomhed"
                >
              </label>

              <label class="platform-field">
                <span>Logo-kilde</span>
                <input type="text" value="Kun upload via tenant-indstillinger" disabled>
              </label>
            </div>

            <div class="platform-form-grid">
              <label class="platform-field">
                <span>Logo alt-tekst</span>
                <input
                  type="text"
                  name="public_logo_alt"
                  value="{{ old('public_logo_alt', $tenant->public_logo_alt) }}"
                  placeholder="Fx Test Virksomhed logo"
                >
              </label>

              <label class="platform-field-check">
                <input type="hidden" name="show_powered_by" value="0">
                <input type="checkbox" name="show_powered_by" value="1" @checked((bool) old('show_powered_by', $tenant->show_powered_by))>
                <span>Vis Powered by PlateBooking</span>
              </label>
            </div>

            <div class="platform-form-grid">
              <label class="platform-field">
                <span>Primær farve (HEX)</span>
                <input
                  type="text"
                  name="public_primary_color"
                  value="{{ old('public_primary_color', $tenant->public_primary_color) }}"
                  placeholder="#5C80BC"
                >
              </label>

              <label class="platform-field">
                <span>Accent farve (HEX)</span>
                <input
                  type="text"
                  name="public_accent_color"
                  value="{{ old('public_accent_color', $tenant->public_accent_color) }}"
                  placeholder="#E8C547"
                >
              </label>
            </div>

            @if ($selectedPublicLogoPreviewUrl)
              <div class="platform-branding-preview">
                <span>Valgt logo preview</span>
                <img src="{{ $selectedPublicLogoPreviewUrl }}" alt="{{ $tenant->public_logo_alt ?: ($tenant->public_brand_name ?: $tenant->name) }}">
              </div>
            @endif
          </div>

          <label class="platform-field-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $tenant->is_active))>
            <span>Aktiv virksomhed</span>
          </label>

          <button type="submit" class="platform-button platform-button-primary">Gem virksomhedsdata</button>
        </form>

        <div class="platform-pills">
          <span>Plan: {{ $plans->firstWhere('id', (int) $tenant->plan_id)?->name ?? 'Ikke valgt' }}</span>
          <span>{{ $tenant->users_count }} brugere</span>
          <span>{{ $tenant->locations_count }} lokationer</span>
          <span>{{ $tenant->services_count }} ydelser</span>
          <span>{{ $tenant->bookings_count }} bookinger</span>
        </div>

        <div class="platform-location-actions">
          <a href="{{ route('public-booking.create', ['tenant' => $tenant->slug]) }}" class="platform-link-button">Åbn offentlig booking</a>
        </div>
      </article>

      <article class="platform-card">
        <p class="platform-eyebrow">Ejeradgang</p>
        <h2>Opret ejer-bruger</h2>
        <p class="platform-muted">
          Brug denne formular til at oprette en ny ejer direkte i den valgte butik.
        </p>

        <form method="POST" action="{{ route('platform.tenants.owners.store', $tenant) }}" class="platform-form">
          @csrf

          <div class="platform-form-grid">
            <label class="platform-field">
              <span>Navn</span>
              <input type="text" name="name" required>
            </label>

            <label class="platform-field">
              <span>E-mail</span>
              <input type="email" name="email" required>
            </label>
          </div>

          <div class="platform-form-grid">
            <label class="platform-field">
              <span>Initialer</span>
              <input type="text" name="initials" maxlength="6" placeholder="Fx AB">
            </label>

            <label class="platform-field-check">
              <input type="hidden" name="is_bookable" value="0">
              <input type="checkbox" name="is_bookable" value="1">
              <span>Bookbar i kalender</span>
            </label>
          </div>

          <div class="platform-form-grid">
            <label class="platform-field">
              <span>Adgangskode</span>
              <input type="password" name="password" minlength="12" required>
            </label>

            <label class="platform-field">
              <span>Bekræft adgangskode</span>
              <input type="password" name="password_confirmation" minlength="12" required>
            </label>
          </div>

          <button type="submit" class="platform-button platform-button-primary">Opret ejer</button>
        </form>

        <section class="platform-section-list">
          <h3>Eksisterende brugere</h3>
          @forelse ($tenantUsers as $user)
            <article class="platform-tenant-item">
              <div class="platform-tenant-head">
                <div>
                  <strong>{{ $user->name }}</strong>
                  <p class="platform-muted">{{ $user->email }}</p>
                </div>
                <span class="platform-tenant-status is-active">{{ $user->roleLabel() }}</span>
              </div>
              <div class="platform-pills">
                <span>{{ $user->is_bookable ? 'Bookbar' : 'Ikke bookbar' }}</span>
                <span>Oprettet {{ optional($user->created_at)->format('d.m.Y') }}</span>
              </div>
            </article>
          @empty
            <article class="platform-tenant-item">
              <strong>Ingen brugere endnu</strong>
              <p class="platform-muted">Opret en ejer-bruger for at komme i gang.</p>
            </article>
          @endforelse
        </section>
      </article>
    </section>

    <section class="platform-card">
      <p class="platform-eyebrow">Lokationer</p>
      <h2>Administrer lokationer</h2>
      <p class="platform-muted">
        Opret, rediger og slet lokationer. Nye lokationer kobles automatisk med eksisterende ydelser og bookbare brugere.
      </p>

      <form method="POST" action="{{ route('platform.tenants.locations.store', $tenant) }}" class="platform-form">
        @csrf
        <div class="platform-form-grid">
          <label class="platform-field">
            <span>Navn</span>
            <input type="text" name="name" required>
          </label>
          <label class="platform-field">
            <span>Slug (valgfri)</span>
            <input type="text" name="slug" placeholder="fx odense">
          </label>
        </div>

        <div class="platform-form-grid">
          <label class="platform-field">
            <span>Tidszone</span>
            <input type="text" name="timezone" value="{{ $tenant->timezone }}" required>
          </label>
          <label class="platform-field-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" checked>
            <span>Aktiv lokation</span>
          </label>
        </div>

        <div class="platform-form-grid">
          <label class="platform-field">
            <span>Adresse linje 1</span>
            <input type="text" name="address_line_1">
          </label>
          <label class="platform-field">
            <span>Adresse linje 2</span>
            <input type="text" name="address_line_2">
          </label>
        </div>

        <div class="platform-form-grid">
          <label class="platform-field">
            <span>Postnummer</span>
            <input type="text" name="postal_code">
          </label>
          <label class="platform-field">
            <span>By</span>
            <input type="text" name="city">
          </label>
        </div>

        <button type="submit" class="platform-button platform-button-primary">Opret lokation</button>
      </form>

      <div class="platform-section-list">
        @foreach ($locations as $location)
          <article class="platform-location-item">
            <form method="POST" action="{{ route('platform.tenants.locations.update', [$tenant, $location]) }}" class="platform-form">
              @csrf
              @method('PATCH')

              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>Navn</span>
                  <input type="text" name="name" value="{{ $location->name }}" required>
                </label>
                <label class="platform-field">
                  <span>Slug</span>
                  <input type="text" name="slug" value="{{ $location->slug }}" required>
                </label>
              </div>

              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>Tidszone</span>
                  <input type="text" name="timezone" value="{{ $location->timezone }}" required>
                </label>
                <label class="platform-field-check">
                  <input type="hidden" name="is_active" value="0">
                  <input type="checkbox" name="is_active" value="1" @checked($location->is_active)>
                  <span>Aktiv</span>
                </label>
              </div>

              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>Adresse linje 1</span>
                  <input type="text" name="address_line_1" value="{{ $location->address_line_1 }}">
                </label>
                <label class="platform-field">
                  <span>Adresse linje 2</span>
                  <input type="text" name="address_line_2" value="{{ $location->address_line_2 }}">
                </label>
              </div>

              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>Postnummer</span>
                  <input type="text" name="postal_code" value="{{ $location->postal_code }}">
                </label>
                <label class="platform-field">
                  <span>By</span>
                  <input type="text" name="city" value="{{ $location->city }}">
                </label>
              </div>

              <div class="platform-pills">
                <span>{{ $location->users_count }} brugere</span>
                <span>{{ $location->services_count }} ydelser</span>
                <span>{{ $location->bookings_count }} bookinger</span>
              </div>

              <div class="platform-location-actions">
                <button type="submit" class="platform-button">Gem lokation</button>
              </div>
            </form>

            <form method="POST" action="{{ route('platform.tenants.locations.destroy', [$tenant, $location]) }}">
              @csrf
              @method('DELETE')
              <button
                type="submit"
                class="platform-button platform-button-danger"
                onclick="return confirm('Er du sikker på, at lokationen skal slettes?')"
              >
                Slet lokation
              </button>
            </form>
          </article>
        @endforeach
      </div>
    </section>
  </main>
</body>
</html>
