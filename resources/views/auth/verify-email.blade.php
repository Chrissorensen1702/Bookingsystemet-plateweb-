<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('layouts.partials.pwa-meta')
  <title>Bekræft e-mail | Bookingsystem</title>
  @vite([
    'resources/css/app-login.css',
    'resources/js/pwa.js',
  ])
</head>
<body class="login-page-body">
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand">
        <a href="{{ route('login') }}" class="login-brand-link">
          <img src="{{ asset('images/logo/header.svg') }}" alt="PlateBook" class="logo-login">
        </a>
      </div>

      <div class="login-card">
        <div class="login-copy">
          <h1>Bekræft din e-mail</h1>
          <p class="login-text">
            Din bruger er oprettet, men du skal bekræfte din e-mail, før du kan bruge systemet.
            Tjek din indbakke og klik på linket i mailen.
          </p>
        </div>

        @if (session('status'))
          <div class="login-alert is-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($errors->any())
          <div class="login-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <form class="login-form" method="POST" action="{{ route('verification.send') }}" data-csrf-submit-mode="native">
          @csrf
          <button type="submit" class="login-button">Send bekræftelsesmail igen</button>
        </form>

        <form
          class="login-form platform-logout-form"
          method="POST"
          action="{{ route('logout') }}"
          data-csrf-submit-mode="native"
          data-auth-state-url="{{ route('auth.state') }}"
          data-auth-state-guard="web"
          data-auth-state-goal="guest"
          data-auth-state-redirect="{{ \App\Support\RouteUrls::loginHome() }}"
        >
          @csrf
          <button type="submit" class="login-button login-button-secondary">Log ud</button>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
