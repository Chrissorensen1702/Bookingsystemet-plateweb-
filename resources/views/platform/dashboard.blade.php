<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>Platform | Vælg forretning</title>
  @vite(['resources/css/app-platform.css', 'resources/js/pwa.js'])
</head>
<body class="platform-body">
  @php
    $activeTenantCount = $tenants->where('is_active', true)->count();
    $inactiveTenantCount = $tenants->count() - $activeTenantCount;
    $totalLocations = (int) $tenants->sum('locations_count');
    $totalUsers = (int) $tenants->sum('users_count');
    $totalBookings = (int) $tenants->sum('bookings_count');
  @endphp

  <main class="platform-shell">
    <header class="platform-topbar">
      <a href="{{ route('platform.dashboard') }}" class="platform-brand">
        <img src="{{ asset('images/logo/header.svg') }}" alt="PlateBook">
        <span>
          <strong>Platform dashboard</strong>
          <span>Logget ind som {{ $platformUser?->name ?? 'Developer' }} ({{ $platformUser?->roleLabel() ?? 'Developer' }})</span>
        </span>
      </a>

      <div class="platform-top-actions">
        <a href="{{ route('login') }}" class="platform-link-button">Medarbejder-login</a>
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
        <p class="platform-eyebrow">Developer platform</p>
        <h1>Faa overblik over virksomheder, domæner og bookingdrift</h1>
        <p class="platform-muted">
          Herfra opretter du nye virksomheder, følger status pa live-opsætninger og hopper direkte
          ind i den butik, der skal justeres. Fokus er hurtig drift, tydelige links og færre klik.
        </p>

        <div class="platform-link-stack">
          <div class="platform-domain-preview">
            <span>Medarbejder-login</span>
            <strong>{{ \App\Support\RouteUrls::loginHome() }}</strong>
          </div>
          <div class="platform-domain-preview">
            <span>Platform-app</span>
            <strong>{{ \App\Support\RouteUrls::platform('dashboard') }}</strong>
          </div>
        </div>
      </div>

      <div class="platform-stat-grid">
        <article class="platform-stat-card">
          <span>Virksomheder</span>
          <strong>{{ $tenants->count() }}</strong>
          <small>{{ $activeTenantCount }} aktive · {{ $inactiveTenantCount }} inaktive</small>
        </article>

        <article class="platform-stat-card">
          <span>Lokationer</span>
          <strong>{{ $totalLocations }}</strong>
          <small>Offentlige bookingsider pa tværs af tenants</small>
        </article>

        <article class="platform-stat-card">
          <span>Brugere</span>
          <strong>{{ $totalUsers }}</strong>
          <small>Ejere, medarbejdere og ledere samlet</small>
        </article>

        <article class="platform-stat-card">
          <span>Bookinger</span>
          <strong>{{ $totalBookings }}</strong>
          <small>Total bookingvolumen i systemet</small>
        </article>
      </div>
    </section>

    <section class="platform-dashboard-grid">
      <article class="platform-card platform-card-accent">
        @php
          $suggestedSubdomain = \Illuminate\Support\Str::slug((string) old('name', 'virksomhedsnavn'));
          $suggestedSubdomain = $suggestedSubdomain !== '' ? $suggestedSubdomain : 'virksomhedsnavn';
        @endphp
        <div class="platform-card-header">
          <div>
            <p class="platform-eyebrow">Ny forretning</p>
            <h2>Opret virksomhed</h2>
            <p class="platform-muted">
              Nye virksomheder far automatisk en aktiv `Hovedafdeling`, sa der er et klart udgangspunkt for
              domæner, ydelser og ejeropsætning.
            </p>
          </div>

          <span class="platform-tag">Klar til drift</span>
        </div>

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="platform-form">
          @csrf

          <label class="platform-field">
            <span>Virksomhedsnavn</span>
            <input type="text" name="name" value="{{ old('name') }}" required>
            <small class="platform-muted">Systemet laver automatisk et subdomæne ud fra navnet, fx `{{ $suggestedSubdomain }}.platebook.dk`.</small>
          </label>

          <label class="platform-field">
            <span>Tidszone</span>
            <input type="text" name="timezone" value="{{ old('timezone', config('app.timezone', 'UTC')) }}" required>
          </label>

          <label class="platform-field">
            <span>Abonnement</span>
            <select name="plan_id" required>
              @foreach ($plans as $plan)
                <option value="{{ $plan->id }}" @selected((int) old('plan_id', 1) === (int) $plan->id)>
                  {{ $plan->name }}{{ $plan->requires_powered_by ? ' · Powered by påkrævet' : '' }}
                </option>
              @endforeach
            </select>
          </label>

          <label class="platform-field-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', true))>
            <span>Aktiv virksomhed fra start</span>
          </label>

          <button type="submit" class="platform-button platform-button-primary">Opret forretning</button>
        </form>

        <div class="platform-note-list">
          <article class="platform-note-card">
            <strong>Automatisk subdomæne</strong>
            <p>Du skal ikke selv skrive slug ved oprettelse. Vi bruger virksomhedsnavnet og laver det om til et URL-venligt subdomæne.</p>
          </article>

          <article class="platform-note-card">
            <strong>Kan stadig ændres bagefter</strong>
            <p>Når virksomheden er oprettet, kan du stadig ga ind og justere subdomænet manuelt, hvis du vil have en anden URL.</p>
          </article>
        </div>
      </article>

      <article class="platform-card">
        <div class="platform-card-header">
          <div>
            <p class="platform-eyebrow">Forretninger</p>
            <h2>Workspace-oversigt</h2>
            <p class="platform-muted">
              Vælg en butik for at redigere domæner, lokationer, branding og ejeradgang. Alt samlet i et mere driftsvenligt overblik.
            </p>
          </div>

          <div class="platform-card-header-actions">
            <span class="platform-tag">{{ $activeTenantCount }} aktive</span>
            <span class="platform-tag platform-tag-muted">{{ $inactiveTenantCount }} inaktive</span>
          </div>
        </div>

        <div class="platform-tenant-list">
          @forelse ($tenants as $tenant)
            <article class="platform-tenant-item">
              <div class="platform-tenant-head">
                <div>
                  <strong>{{ $tenant->name }}</strong>
                  <p class="platform-muted">Subdomæne: {{ $tenant->slug }}.platebook.dk · {{ $tenant->timezone }}</p>
                </div>
                <span class="platform-tenant-status{{ $tenant->is_active ? ' is-active' : '' }}">
                  {{ $tenant->is_active ? 'Aktiv' : 'Inaktiv' }}
                </span>
              </div>

              <div class="platform-meta-grid">
                <article class="platform-meta-item">
                  <span>Plan</span>
                  <strong>{{ $tenant->plan?->name ?? 'Ikke valgt' }}</strong>
                </article>

                <article class="platform-meta-item">
                  <span>Public root</span>
                  <strong>{{ \App\Support\RouteUrls::publicBooking((string) $tenant->slug) }}</strong>
                </article>

                <article class="platform-meta-item">
                  <span>Oprettet</span>
                  <strong>{{ optional($tenant->created_at)->format('d.m.Y') ?: 'Ukendt' }}</strong>
                </article>
              </div>

              <div class="platform-pills">
                <span>{{ $tenant->users_count }} brugere</span>
                <span>{{ $tenant->locations_count }} lokationer</span>
                <span>{{ $tenant->services_count }} ydelser</span>
                <span>{{ $tenant->bookings_count }} bookinger</span>
              </div>

              <div class="platform-location-actions">
                <a href="{{ route('platform.tenants.show', $tenant) }}" class="platform-link-button">Aabn kontrolrum</a>
                <a href="{{ route('public-booking.tenant', ['tenantSlug' => $tenant->slug]) }}" class="platform-link-button">Offentlig booking</a>
              </div>
            </article>
          @empty
            <article class="platform-empty-state">
              <strong>Ingen virksomheder endnu</strong>
              <p class="platform-muted">Start til venstre med at oprette den første virksomhed. Den far automatisk en aktiv hovedlokation.</p>
            </article>
          @endforelse
        </div>
      </article>
    </section>
  </main>
</body>
</html>
