@php
  $iconBase = asset('images/icon-pack/lucide/icons');
  $sidebarUser = auth()->user();
  $workShiftsEnabled = $sidebarUser?->workShiftsEnabled() ?? true;
@endphp

<a href="{{ route('booking-calender') }}" class="sidebar-brand">
  <img src="{{ asset('images/logo/stacked.svg') }}" alt="PlæBooking" class="logo">
</a>

<nav class="sidebar-nav" aria-label="Hovedmenu">
  <a
    href="{{ route('booking-calender') }}"
    class="nav-link{{ request()->routeIs('booking-calender') ? ' is-active' : '' }}"
    aria-label="Kalender"
    title="Kalender"
  >
    <img src="{{ $iconBase }}/calendar-days.svg" alt="" class="nav-icon" aria-hidden="true">
    <span class="sr-only">Kalender</span>
  </a>

  @if ($workShiftsEnabled)
    <a
      href="{{ route('my-shifts.index') }}"
      class="nav-link{{ request()->routeIs('my-shifts.*') ? ' is-active' : '' }}"
      aria-label="Mine vagter"
      title="Mine vagter"
    >
      <img src="{{ $iconBase }}/clock-3.svg" alt="" class="nav-icon" aria-hidden="true">
      <span class="sr-only">Mine vagter</span>
    </a>
  @else
    <a
      href="#"
      class="nav-link is-locked"
      aria-disabled="true"
      aria-label="Mine vagter (bookbarhed slået fra)"
      title="Mine vagter (bookbarhed slået fra)"
    >
      <img src="{{ $iconBase }}/clock-3.svg" alt="" class="nav-icon" aria-hidden="true">
      <span class="nav-state nav-state-locked" aria-hidden="true">
        <img src="{{ $iconBase }}/lock.svg" alt="">
      </span>
      <span class="sr-only">Mine vagter (bookbarhed slået fra)</span>
    </a>
  @endif

  @can('availability.manage')
    <a
      href="{{ route('availability.index') }}"
      class="nav-link{{ request()->routeIs('availability.*') ? ' is-active' : '' }}"
      aria-label="Tilgængelighed"
      title="Tilgængelighed"
    >
      <img src="{{ $iconBase }}/calendar-clock.svg" alt="" class="nav-icon" aria-hidden="true">
      <span class="sr-only">Tilgængelighed</span>
    </a>
  @else
    <a
      href="#"
      class="nav-link is-locked"
      aria-disabled="true"
      aria-label="Tilgængelighed (ingen adgang)"
      title="Tilgængelighed (ingen adgang)"
    >
      <img src="{{ $iconBase }}/calendar-clock.svg" alt="" class="nav-icon" aria-hidden="true">
      <span class="nav-state nav-state-locked" aria-hidden="true">
        <img src="{{ $iconBase }}/lock.svg" alt="">
      </span>
      <span class="sr-only">Tilgængelighed (ingen adgang)</span>
    </a>
  @endcan
  
  @auth
    @can('services.manage')
      <a
        href="{{ route('services.index') }}"
        class="nav-link{{ request()->routeIs('services.*') ? ' is-active' : '' }}"
        aria-label="Ydelser"
        title="Ydelser"
      >
        <img src="{{ $iconBase }}/scissors.svg" alt="" class="nav-icon" aria-hidden="true">
        <span class="sr-only">Ydelser</span>
      </a>
    @else
      <a
        href="#"
        class="nav-link is-locked"
        aria-disabled="true"
        aria-label="Ydelser (ingen adgang)"
        title="Ydelser (ingen adgang)"
      >
        <img src="{{ $iconBase }}/scissors.svg" alt="" class="nav-icon" aria-hidden="true">
        <span class="nav-state nav-state-locked" aria-hidden="true">
          <img src="{{ $iconBase }}/lock.svg" alt="">
        </span>
        <span class="sr-only">Ydelser (ingen adgang)</span>
      </a>
    @endcan
  @endauth
</nav>

@auth
  @php
    $sidebarUserPhotoUrl = $sidebarUser?->profilePhotoUrl();
  @endphp
  <div class="sidebar-bottom">
    <nav class="sidebar-admin-nav" aria-label="Administration">
      @can('settings.location.manage')
        <a
          href="{{ route('settings.index') }}"
          class="nav-link{{ request()->routeIs('settings.*') ? ' is-active' : '' }}"
          aria-label="Indstillinger"
          title="Indstillinger"
        >
          <img src="{{ $iconBase }}/settings.svg" alt="" class="nav-icon" aria-hidden="true">
          <span class="sr-only">Indstillinger</span>
        </a>
      @else
        <a
          href="#"
          class="nav-link is-locked"
          aria-disabled="true"
          aria-label="Indstillinger (ingen adgang)"
          title="Indstillinger (ingen adgang)"
        >
          <img src="{{ $iconBase }}/settings.svg" alt="" class="nav-icon" aria-hidden="true">
          <span class="nav-state nav-state-locked" aria-hidden="true">
            <img src="{{ $iconBase }}/lock.svg" alt="">
          </span>
          <span class="sr-only">Indstillinger (ingen adgang)</span>
        </a>
      @endcan

      @can('users.manage')
        <a
          href="{{ route('users.index') }}"
          class="nav-link{{ request()->routeIs('users.*') ? ' is-active' : '' }}"
          aria-label="Administrer brugere"
          title="Administrer brugere"
        >
          <img src="{{ $iconBase }}/users-round.svg" alt="" class="nav-icon" aria-hidden="true">
          <span class="sr-only">Administrer brugere</span>
        </a>
      @else
        <a
          href="#"
          class="nav-link is-locked"
          aria-disabled="true"
          aria-label="Administrer brugere (ingen adgang)"
          title="Administrer brugere (ingen adgang)"
        >
          <img src="{{ $iconBase }}/users-round.svg" alt="" class="nav-icon" aria-hidden="true">
          <span class="nav-state nav-state-locked" aria-hidden="true">
            <img src="{{ $iconBase }}/lock.svg" alt="">
          </span>
          <span class="sr-only">Administrer brugere (ingen adgang)</span>
        </a>
      @endcan
    </nav>

    <div class="sidebar-bottom-profile-actions">
      <a
        href="{{ route('profile.index') }}"
        class="sidebar-profile-link{{ request()->routeIs('profile.*') ? ' is-active' : '' }}"
        aria-label="Min profil"
        title="Min profil"
      >
        @if ($sidebarUserPhotoUrl)
          <img src="{{ $sidebarUserPhotoUrl }}" alt="">
        @else
          <span>{{ $sidebarUser?->bookingInitials() }}</span>
        @endif
      </a>

      <form method="POST" action="{{ route('logout') }}" class="sidebar-bottom-form">
        @csrf
        <button type="submit" class="nav-link nav-link-logout" aria-label="Log ud" title="Log ud">
          <img src="{{ $iconBase }}/log-out.svg" alt="" class="nav-icon" aria-hidden="true">
          <span class="sr-only">Log ud</span>
        </button>
      </form>
    </div>
  </div>
@else
  <div class="sidebar-bottom">
    <a href="{{ route('login') }}" class="nav-link nav-link-login" aria-label="Login" title="Login">
      <img src="{{ $iconBase }}/log-in.svg" alt="" class="nav-icon" aria-hidden="true">
      <span class="sr-only">Login</span>
    </a>
  </div>
@endauth
