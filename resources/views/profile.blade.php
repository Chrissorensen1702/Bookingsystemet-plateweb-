@extends('layouts.default')

@section('body-class', 'profile-page-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $profilePhotoUrl = $user->profilePhotoUrl();
    $statusValue = session('status');
    $statusMessage = match ($statusValue) {
      'webauthn-destroyed' => 'Passkey er slettet.',
      'webauthn-updated' => 'Passkey er opdateret.',
      default => is_string($statusValue) ? $statusValue : null,
    };
  @endphp

  <section class="profile-page">
    <div class="profile-card">
      <div class="profile-head">
        <p class="profile-eyebrow">Min profil</p>
        <h1>Personlige oplysninger</h1>
        <p>Opdater navn, e-mail, mobil og adgangskode for din egen bruger.</p>
      </div>

      @if ($statusMessage)
        <p class="profile-alert profile-alert-success" role="status">{{ $statusMessage }}</p>
      @endif

      @if ($errors->any())
        <p class="profile-alert profile-alert-error" role="alert">{{ $errors->first() }}</p>
      @endif

      <form method="POST" action="{{ route('profile.update') }}" class="profile-form" enctype="multipart/form-data">
        @csrf
        @method('PATCH')

        <div class="profile-avatar-panel">
          <div class="profile-avatar{{ $profilePhotoUrl ? ' has-photo' : '' }}" aria-hidden="true">
            @if ($profilePhotoUrl)
              <img src="{{ $profilePhotoUrl }}" alt="">
            @else
              {{ $user->bookingInitials() }}
            @endif
          </div>

          <div class="profile-avatar-fields">
            <label class="profile-field">
              <span>Profilbillede</span>
              <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              <small class="profile-field-help">Tilladte filer: JPG, PNG eller WEBP (max 3 MB).</small>
            </label>

            @if ($profilePhotoUrl)
              <label class="profile-check">
                <input type="checkbox" name="remove_profile_photo" value="1" @checked((bool) old('remove_profile_photo', false))>
                <span>Fjern nuværende profilbillede</span>
              </label>
            @endif
          </div>
        </div>

        <div class="profile-grid">
          <label class="profile-field">
            <span>Navn</span>
            <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
          </label>

          <label class="profile-field">
            <span>E-mail</span>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
          </label>
        </div>

        <label class="profile-field">
          <span>Mobil</span>
          <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="+45 12 34 56 78">
        </label>

        <div class="profile-divider"></div>

        <div class="profile-grid">
          <label class="profile-field">
            <span>Nuværende adgangskode</span>
            <input type="password" name="current_password" autocomplete="current-password" placeholder="Udfyld kun ved kodeændring">
          </label>

          <label class="profile-field">
            <span>Ny adgangskode</span>
            <input type="password" name="password" autocomplete="new-password" placeholder="Min. 12 tegn">
          </label>
        </div>

        <label class="profile-field">
          <span>Gentag ny adgangskode</span>
          <input type="password" name="password_confirmation" autocomplete="new-password">
        </label>

        <div class="profile-actions">
          <button type="submit">Gem ændringer</button>
        </div>
      </form>

      <div
        class="profile-passkey-panel"
        data-passkey-manager
        data-passkey-csrf="{{ csrf_token() }}"
        data-passkey-options-url="{{ route('webauthn.store.options') }}"
        data-passkey-store-url="{{ route('webauthn.store') }}"
      >
        <div class="profile-passkey-head">
          <h2>Passkey-login</h2>
          <p>Aktiver biometrisk login (Face ID, Touch ID eller Windows Hello) på dine egne enheder.</p>
        </div>

        <div class="profile-passkey-create">
          <label class="profile-field">
            <span>Navn på enhed (valgfri)</span>
            <input
              type="text"
              data-passkey-name
              maxlength="255"
              placeholder="Fx iPhone 15"
            >
          </label>
          <button type="button" class="profile-passkey-create-button" data-passkey-create>
            Tilføj passkey
          </button>
        </div>

        <p class="profile-passkey-feedback" data-passkey-feedback hidden></p>

        @if ($passkeys->isEmpty())
          <p class="profile-passkey-empty">Ingen passkeys oprettet endnu.</p>
        @else
          <div class="profile-passkey-list">
            @foreach ($passkeys as $passkey)
              <article class="profile-passkey-item">
                <div class="profile-passkey-meta">
                  <strong>{{ $passkey->name ?: 'Passkey' }}</strong>
                  <small>
                    Tilføjet {{ optional($passkey->created_at)?->format('d/m/Y H:i') ?? 'ukendt tidspunkt' }}
                  </small>
                </div>

                <form method="POST" action="{{ route('webauthn.destroy', $passkey->id) }}" class="profile-passkey-delete-form">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="profile-passkey-delete">Slet</button>
                </form>
              </article>
            @endforeach
          </div>
        @endif
      </div>
    </div>
  </section>
@endsection
