<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>Platform Login | Bookingsystem</title>
  @vite(['resources/css/app-login.css', 'resources/js/pwa.js'])
</head>
<body class="login-page-body">
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand">
        <a href="{{ route('platform.login') }}" class="login-brand-link">
          <img src="{{ asset('images/logo/header.svg') }}" alt="PlæBooking" class="logo-login">
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

        <form class="login-form" method="POST" action="{{ route('platform.login.store') }}">
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
            <input
              type="password"
              name="password"
              placeholder="Indtast adgangskode"
              autocomplete="current-password"
              required
            >
          </label>

          <label class="login-check">
            <input type="checkbox" name="remember" value="1">
            <span>Husk mig pa denne enhed</span>
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
