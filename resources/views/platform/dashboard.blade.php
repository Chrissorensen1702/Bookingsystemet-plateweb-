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

    <section class="platform-grid">
      <article class="platform-card">
        <p class="platform-eyebrow">Ny forretning</p>
        <h1>Opret virksomhed</h1>
        <p class="platform-muted">
          Opret en ny butik med default lokation. Derefter kan du ga ind og konfigurere brugere, lokationer og indstillinger.
        </p>

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="platform-form">
          @csrf

          <label class="platform-field">
            <span>Virksomhedsnavn</span>
            <input type="text" name="name" value="{{ old('name') }}" required>
          </label>

          <div class="platform-form-grid">
            <label class="platform-field">
              <span>Slug (valgfri)</span>
              <input type="text" name="slug" value="{{ old('slug') }}" placeholder="fx salonnavn">
            </label>

            <label class="platform-field">
              <span>Tidszone</span>
              <input type="text" name="timezone" value="{{ old('timezone', config('app.timezone', 'UTC')) }}" required>
            </label>
          </div>

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
            <span>Aktiv virksomhed</span>
          </label>

          <button type="submit" class="platform-button platform-button-primary">Opret forretning</button>
        </form>
      </article>

      <article class="platform-card">
        <p class="platform-eyebrow">Forretninger</p>
        <h2>Vælg butik</h2>
        <p class="platform-muted">{{ $tenants->count() }} virksomheder i systemet.</p>

        <div class="platform-tenant-list">
          @forelse ($tenants as $tenant)
            <article class="platform-tenant-item">
              <div class="platform-tenant-head">
                <div>
                  <strong>{{ $tenant->name }}</strong>
                  <p class="platform-muted">Slug: {{ $tenant->slug }} · {{ $tenant->timezone }}</p>
                </div>
                <span class="platform-tenant-status{{ $tenant->is_active ? ' is-active' : '' }}">
                  {{ $tenant->is_active ? 'Aktiv' : 'Inaktiv' }}
                </span>
              </div>

              <div class="platform-pills">
                <span>Plan: {{ $tenant->plan?->name ?? 'Ikke valgt' }}</span>
                <span>{{ $tenant->users_count }} brugere</span>
                <span>{{ $tenant->locations_count }} lokationer</span>
                <span>{{ $tenant->services_count }} ydelser</span>
                <span>{{ $tenant->bookings_count }} bookinger</span>
              </div>

              <div class="platform-location-actions">
                <a href="{{ route('platform.tenants.show', $tenant) }}" class="platform-link-button">Vælg forretning</a>
                <a href="{{ route('public-booking.create', ['tenant' => $tenant->slug]) }}" class="platform-link-button">Offentlig booking</a>
              </div>
            </article>
          @empty
            <article class="platform-tenant-item">
              <strong>Ingen virksomheder endnu</strong>
              <p class="platform-muted">Start med at oprette den første virksomhed til venstre.</p>
            </article>
          @endforelse
        </div>
      </article>
    </section>
  </main>
</body>
</html>
