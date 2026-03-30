<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>Platform Login | Bookingsystem</title>
  @vite(['resources/css/app-login.css', 'resources/js/pwa.js', 'resources/js/pages/login-password-toggle.js'])
</head>
<body class="login-page-body">
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand">
        <a href="{{ route('platform.login') }}" class="login-brand-link">
          <img src="{{ asset('images/logo/header.svg') }}" alt="PlateBook" class="logo-login">
        </a>
      </div>

      <div class="login-card">
        <div class="login-copy">
          <h1>Platform adgang</h1>
          <p class="login-text">
            Developer-login er adskilt fra kundernes login og bruges til opsætning,
            support og drift pa tværs af virksomheder.
          </p>
        </div>

        @if ($errors->any())
          <div class="login-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <form
          class="login-form"
          method="POST"
          action="{{ route('platform.login.store') }}"
          data-csrf-submit-mode="native"
          data-auth-state-url="{{ route('auth.state') }}"
          data-auth-state-guard="platform"
          data-auth-state-goal="authenticated"
          data-auth-state-redirect="{{ route('platform.dashboard') }}"
        >
          @csrf

          <label class="login-field">
            <span>E-mail</span>
            <input
              type="email"
              name="email"
              value="{{ old('email') }}"
              placeholder="dev@plateweb.dk"
              autocomplete="email"
              required
            >
          </label>

          <label class="login-field">
            <span>Adgangskode</span>
            <div class="login-password-wrap">
              <input
                id="platform-login-password"
                type="password"
                name="password"
                placeholder="Indtast adgangskode"
                autocomplete="current-password"
                required
              >
              <button
                type="button"
                class="login-password-toggle"
                data-password-toggle
                data-password-target="platform-login-password"
                aria-pressed="false"
                aria-label="Vis adgangskode"
              >Vis</button>
            </div>
          </label>

          <button type="submit" class="login-button">Log ind som developer</button>

          <div class="login-help">
            <p>Dette login er kun til intern platform-adgang.</p>
            <a href="{{ route('login') }}" class="login-public-link">Ga til kunde/medarbejder-login</a>
          </div>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
