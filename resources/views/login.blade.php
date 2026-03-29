<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>Login | Bookingsystem</title>
  @vite(['resources/css/app-login.css', 'resources/js/pwa.js', 'resources/js/pages/login-password-toggle.js'])
</head>
<body class="login-page-body">
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand">
        <a href="{{ route('login') }}" class="login-brand-link">
          <img src="{{ asset('images/logo/header.svg') }}" alt="PlæBooking" class="logo-login">
        </a>
      </div>

      <div class="login-card">
        <div class="login-copy">
          <h1>Log ind i bookingsystemet</h1>
          <p class="login-text">
            Brug din arbejdsmail og adgangskode for at komme ind til kalender,
            kunder og indstillinger.
          </p>
        </div>

        @if ($errors->any())
          <div class="login-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <form class="login-form" method="POST" action="{{ route('login.store') }}">
          @csrf
          <label class="login-field">
            <span>E-mail</span>
            <input
              type="email"
              name="email"
              value="{{ old('email') }}"
              placeholder="navn@firma.dk"
              autocomplete="email"
              required
            >
          </label>

          <label class="login-field">
            <span>Adgangskode</span>
            <div class="login-password-wrap">
              <input
                id="login-password"
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
                data-password-target="login-password"
                aria-pressed="false"
                aria-label="Vis adgangskode"
              >Vis</button>
            </div>
          </label>

          <label class="login-check">
            <input type="checkbox" name="remember" value="1">
            <span>Husk mig på denne enhed</span>
          </label>

          <button type="submit" class="login-button">Log ind</button>

          <div class="login-help">
            <p>Adgang administreres internt.</p>
            <p>Kontakt systemadministratoren, hvis du mangler en bruger eller skal have nulstillet adgang.</p>
            <a href="{{ route('public-booking.create') }}" class="login-public-link">Skal du booke en tid? Gå til online booking</a>
            <a href="{{ route('platform.login') }}" class="login-public-link">Platform login (developer)</a>
          </div>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
