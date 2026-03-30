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
  @php
    $publicLocation = $locations->firstWhere('is_active', true);
    $publicBookingUrl = $publicLocation?->slug
      ? \App\Support\RouteUrls::publicBooking((string) $tenant->slug, (string) $publicLocation->slug)
      : \App\Support\RouteUrls::publicBooking((string) $tenant->slug);
    $activeLocationsCount = $locations->where('is_active', true)->count();
    $inactiveLocationsCount = $locations->count() - $activeLocationsCount;
    $bookableUsersCount = $tenantUsers->where('is_bookable', true)->count();
    $activeUsersCount = $tenantUsers->where('is_active', true)->count();
  @endphp

  <main class="platform-shell">
    <header class="platform-topbar">
      <a href="{{ route('platform.dashboard') }}" class="platform-brand">
        <img src="{{ asset('images/logo/header.svg') }}" alt="PlateBook">
        <span>
          <strong>{{ $tenant->name }}</strong>
          <span>Platformstyring af butik</span>
        </span>
      </a>

      <div class="platform-top-actions">
        <a href="{{ route('platform.dashboard') }}" class="platform-link-button">Tilbage til oversigt</a>
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

    <section class="platform-hero platform-card">
      <div class="platform-hero-copy">
        <p class="platform-eyebrow">Virksomhedsoverblik</p>
        <h1>{{ $tenant->name }}</h1>
        <p class="platform-muted">
          Herfra styrer du subdomæne, offentlig booking, branding, ejeradgang og lokationer.
          Opsætningen er samlet, sa drift og support kan klares uden at lede efter felter.
        </p>

        <div class="platform-link-stack">
          <div class="platform-domain-preview">
            <span>Virksomheds-subdomæne</span>
            <strong>{{ $tenant->slug }}.platebook.dk</strong>
          </div>

          <div class="platform-domain-preview">
            <span>Offentlig booking</span>
            <strong>{{ $publicBookingUrl }}</strong>
          </div>
        </div>

        <div class="platform-location-actions">
          <a href="{{ $publicBookingUrl }}" class="platform-link-button">Aabn offentlig booking</a>
          <a href="{{ route('login') }}" class="platform-link-button">Medarbejder-login</a>
        </div>
      </div>

      <div class="platform-stat-grid">
        <article class="platform-stat-card">
          <span>Lokationer</span>
          <strong>{{ $locations->count() }}</strong>
          <small>{{ $activeLocationsCount }} aktive · {{ $inactiveLocationsCount }} inaktive</small>
        </article>

        <article class="platform-stat-card">
          <span>Brugere</span>
          <strong>{{ $tenantUsers->count() }}</strong>
          <small>{{ $activeUsersCount }} aktive · {{ $bookableUsersCount }} bookbare</small>
        </article>

        <article class="platform-stat-card">
          <span>Ydelser</span>
          <strong>{{ $tenant->services_count }}</strong>
          <small>Services koblet til virksomheden</small>
        </article>

        <article class="platform-stat-card">
          <span>Bookinger</span>
          <strong>{{ $tenant->bookings_count }}</strong>
          <small>Historik og aktivitet pa tenant-niveau</small>
        </article>
      </div>
    </section>

    <section class="platform-detail-grid">
      <div class="platform-main-stack">
        <article class="platform-card">
          <div class="platform-card-header">
            <div>
              <p class="platform-eyebrow">Virksomhed</p>
              <h2>Profil, routing og branding</h2>
              <p class="platform-muted">
                Rediger virksomhedens navn, subdomæne, abonnement og de vigtigste brandfelter for offentlig booking.
              </p>
            </div>

            <span class="platform-tag{{ $tenant->is_active ? '' : ' platform-tag-muted' }}">
              {{ $tenant->is_active ? 'Aktiv virksomhed' : 'Inaktiv virksomhed' }}
            </span>
          </div>

          <form method="POST" action="{{ route('platform.tenants.update', $tenant) }}" class="platform-form">
            @csrf
            @method('PATCH')

            <section class="platform-form-section">
              <div class="platform-section-headline">
                <h3>Basis og domæner</h3>
                <p class="platform-muted">Virksomheds-sluggen bliver brugt som subdomæne for den offentlige booking.</p>
              </div>

              <label class="platform-field">
                <span>Navn</span>
                <input type="text" name="name" value="{{ old('name', $tenant->name) }}" required>
              </label>

              <div class="platform-form-grid">
                <label class="platform-field">
                  <span>Virksomheds-slug (subdomæne)</span>
                  <input type="text" name="slug" value="{{ old('slug', $tenant->slug) }}" required>
                  <small class="platform-muted">Offentlig root: https://{{ old('slug', $tenant->slug) }}.platebook.dk</small>
                </label>

                <label class="platform-field">
                  <span>Tidszone</span>
                  <input type="text" name="timezone" value="{{ old('timezone', $tenant->timezone) }}" required>
                </label>
              </div>

              <label class="platform-field-check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $tenant->is_active))>
                <span>Aktiv virksomhed</span>
              </label>
            </section>

            <section class="platform-form-section">
              <div class="platform-section-headline">
                <h3>Abonnement og offentlig visning</h3>
                <p class="platform-muted">Plan og branding styrer, hvordan virksomheden præsenteres pa bookingsiden.</p>
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
                <div class="platform-section-headline">
                  <h3>Offentlig booking branding</h3>
                  <p class="platform-muted">Logo uploades af owner i bookingsystemets indstillinger. Her sætter du de overordnede brandfelter.</p>
                </div>

                <div class="platform-form-grid">
                  <label class="platform-field">
                    <span>Brandnavn (offentlig side)</span>
                    <input
                      type="text"
                      name="public_brand_name"
                      value="{{ old('public_brand_name', $tenant->public_brand_name) }}"
                      placeholder="Fx Chris Virksomhed"
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
                      placeholder="Fx Chris Virksomhed logo"
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
            </section>

            <div class="platform-location-actions">
              <button type="submit" class="platform-button platform-button-primary">Gem virksomhedsdata</button>
            </div>
          </form>
        </article>

        <article class="platform-card">
          <div class="platform-card-header">
            <div>
              <p class="platform-eyebrow">Ejeradgang</p>
              <h2>Opret og gennemga brugere</h2>
              <p class="platform-muted">Brug denne sektion, nar virksomheden skal onboardes eller nar du skal verificere ejerens adgang.</p>
            </div>

            <span class="platform-tag">{{ $tenantUsers->count() }} brugere</span>
          </div>

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

            <div class="platform-location-actions">
              <button type="submit" class="platform-button platform-button-primary">Opret ejer</button>
            </div>
          </form>

          <section class="platform-section-list">
            @forelse ($tenantUsers as $user)
              <article class="platform-tenant-item">
                <div class="platform-tenant-head">
                  <div>
                    <strong>{{ $user->name }}</strong>
                    <p class="platform-muted">{{ $user->email }}</p>
                  </div>
                  <span class="platform-tenant-status is-active">{{ $user->roleLabel() }}</span>
                </div>

                <div class="platform-meta-grid">
                  <article class="platform-meta-item">
                    <span>Status</span>
                    <strong>{{ $user->is_active ? 'Aktiv' : 'Inaktiv' }}</strong>
                  </article>

                  <article class="platform-meta-item">
                    <span>Bookbarhed</span>
                    <strong>{{ $user->is_bookable ? 'Bookbar' : 'Ikke bookbar' }}</strong>
                  </article>

                  <article class="platform-meta-item">
                    <span>Oprettet</span>
                    <strong>{{ optional($user->created_at)->format('d.m.Y') ?: 'Ukendt' }}</strong>
                  </article>
                </div>
              </article>
            @empty
              <article class="platform-empty-state">
                <strong>Ingen brugere endnu</strong>
                <p class="platform-muted">Opret en ejer-bruger for at komme i gang med virksomheden.</p>
              </article>
            @endforelse
          </section>
        </article>

        <article class="platform-card">
          <div class="platform-card-header">
            <div>
              <p class="platform-eyebrow">Lokationer</p>
              <h2>Administrer lokationer</h2>
              <p class="platform-muted">
                Hver lokation far sin egen URL-sti under virksomhedens subdomæne. Det gør det tydeligt, hvilken afdeling kunden booker hos.
              </p>
            </div>

            <span class="platform-tag">{{ $locations->count() }} lokationer</span>
          </div>

          <form method="POST" action="{{ route('platform.tenants.locations.store', $tenant) }}" class="platform-form platform-inline-panel">
            @csrf

            <div class="platform-form-grid">
              <label class="platform-field">
                <span>Navn</span>
                <input type="text" name="name" required>
              </label>

              <label class="platform-field">
                <span>Lokations-slug (URL-sti, valgfri)</span>
                <input type="text" name="slug" placeholder="fx odense">
                <small class="platform-muted">Bruges som `https://{{ $tenant->slug }}.platebook.dk/odense`.</small>
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

            <div class="platform-location-actions">
              <button type="submit" class="platform-button platform-button-primary">Opret lokation</button>
            </div>
          </form>

          <div class="platform-section-list">
            @foreach ($locations as $location)
              <article class="platform-location-item">
                <form method="POST" action="{{ route('platform.tenants.locations.update', [$tenant, $location]) }}" class="platform-form">
                  @csrf
                  @method('PATCH')

                  <div class="platform-card-header platform-card-header-tight">
                    <div>
                      <h3>{{ $location->name }}</h3>
                      <p class="platform-muted">{{ \App\Support\RouteUrls::publicBooking((string) $tenant->slug, (string) $location->slug) }}</p>
                    </div>

                    <span class="platform-tenant-status{{ $location->is_active ? ' is-active' : '' }}">
                      {{ $location->is_active ? 'Aktiv' : 'Inaktiv' }}
                    </span>
                  </div>

                  <div class="platform-form-grid">
                    <label class="platform-field">
                      <span>Navn</span>
                      <input type="text" name="name" value="{{ $location->name }}" required>
                    </label>

                    <label class="platform-field">
                      <span>Lokations-slug (URL-sti)</span>
                      <input type="text" name="slug" value="{{ $location->slug }}" required>
                      <small class="platform-muted">{{ \App\Support\RouteUrls::publicBooking((string) $tenant->slug, (string) $location->slug) }}</small>
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
                      <span>Aktiv lokation</span>
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
                    <a href="{{ \App\Support\RouteUrls::publicBooking((string) $tenant->slug, (string) $location->slug) }}" class="platform-link-button">Aabn lokation</a>
                  </div>
                </form>

                <form method="POST" action="{{ route('platform.tenants.locations.destroy', [$tenant, $location]) }}">
                  @csrf
                  @method('DELETE')
                  <button
                    type="submit"
                    class="platform-button platform-button-danger"
                    onclick="return confirm('Er du sikker pa, at lokationen skal slettes?')"
                  >
                    Slet lokation
                  </button>
                </form>
              </article>
            @endforeach
          </div>
        </article>
      </div>

      <aside class="platform-side-stack">
        <article class="platform-card platform-card-accent platform-card-compact">
          <div class="platform-card-header">
            <div>
              <p class="platform-eyebrow">Live links</p>
              <h2>Hurtig adgang</h2>
            </div>
          </div>

          <div class="platform-link-stack">
            <div class="platform-domain-preview">
              <span>Public booking</span>
              <strong>{{ $publicBookingUrl }}</strong>
            </div>

            <div class="platform-domain-preview">
              <span>Subdomæne</span>
              <strong>{{ $tenant->slug }}.platebook.dk</strong>
            </div>

            <div class="platform-domain-preview">
              <span>URL-model</span>
              <strong>{{ $tenant->slug }}.platebook.dk/{lokation}</strong>
            </div>
          </div>

          <div class="platform-location-actions">
            <a href="{{ $publicBookingUrl }}" class="platform-link-button">Aabn public flow</a>
            <a href="{{ route('platform.dashboard') }}" class="platform-link-button">Alle virksomheder</a>
          </div>
        </article>

        <article class="platform-card platform-card-compact">
          <div class="platform-card-header">
            <div>
              <p class="platform-eyebrow">Driftstatus</p>
              <h2>Nuvaerende state</h2>
            </div>
          </div>

          <div class="platform-meta-grid platform-meta-grid-single">
            <article class="platform-meta-item">
              <span>Plan</span>
              <strong>{{ $plans->firstWhere('id', (int) $tenant->plan_id)?->name ?? 'Ikke valgt' }}</strong>
            </article>

            <article class="platform-meta-item">
              <span>Tidszone</span>
              <strong>{{ $tenant->timezone }}</strong>
            </article>

            <article class="platform-meta-item">
              <span>Aktive lokationer</span>
              <strong>{{ $activeLocationsCount }} / {{ $locations->count() }}</strong>
            </article>

            <article class="platform-meta-item">
              <span>Bookbare brugere</span>
              <strong>{{ $bookableUsersCount }}</strong>
            </article>
          </div>
        </article>

        <article class="platform-danger-zone">
          <p class="platform-danger-title">Farezone</p>
          <p class="platform-muted">Sletning fjerner virksomheden permanent inkl. brugere, lokationer, ydelser, bookinger og branding.</p>
          <form
            method="POST"
            action="{{ route('platform.tenants.destroy', $tenant) }}"
            onsubmit="return confirm('Er du sikker pa, at virksomheden {{ $tenant->name }} skal slettes permanent?');"
          >
            @csrf
            @method('DELETE')
            <button type="submit" class="platform-button platform-button-danger">Slet virksomhed</button>
          </form>
        </article>
      </aside>
    </section>
  </main>
</body>
</html>
