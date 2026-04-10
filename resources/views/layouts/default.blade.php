<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  @include('layouts.partials.pwa-meta')
  <title>{{ trim($__env->yieldContent('title')) !== '' ? trim($__env->yieldContent('title')).' | Bookingsystem' : 'Bookingsystem' }}</title>
  @vite(['resources/css/app-dashboard.css', 'resources/js/app.js', 'resources/js/pwa.js'])
</head>

<body class="@yield('body-class')">
<header class="header">
@yield('header')
</header>

<main class="main-content">
@yield('main-content')
</main>

</body>
</html>
