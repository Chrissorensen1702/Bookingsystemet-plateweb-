<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('layouts.partials.pwa-meta')
  <title>homepage</title>
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
