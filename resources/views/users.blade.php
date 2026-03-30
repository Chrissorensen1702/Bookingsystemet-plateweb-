@extends('layouts.default')

@section('title', 'Brugere')

@section('body-class', 'booking-home-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $selectedUserId = (int) old('modal_user_id', session('open_user_modal', 0));
    $selectedUser = $selectedUserId ? $users->firstWhere('id', $selectedUserId) : null;
    $showCreateErrors = $errors->any() && old('form_scope', 'create') === 'create';
    $showEditErrors = $errors->any() && old('form_scope') === 'edit' && $selectedUser;
    $defaultLocationIds = $locationOptions->pluck('id')->map(static fn (int $id): string => (string) $id)->all();
    $createSelectedLocationIds = collect(old('location_ids', $defaultLocationIds))
      ->map(static fn (mixed $id): string => (string) $id)
      ->values()
      ->all();
    $selectedUserLocationIds = $selectedUser?->locations
      ? $selectedUser->locations->pluck('id')->map(static fn (int $id): string => (string) $id)->all()
      : [];
    $editSelectedLocationIds = collect(old('location_ids', $selectedUserLocationIds))
      ->map(static fn (mixed $id): string => (string) $id)
      ->values()
      ->all();
    $workShiftsEnabled = (bool) ($workShiftsEnabled ?? true);
    $allowedUsersViews = ['create', 'manage', 'permissions', 'competencies', 'activity'];

    if ($workShiftsEnabled) {
      array_splice($allowedUsersViews, 4, 0, ['workhours']);
    }
    $requestedUsersView = (string) request()->query('users_view', '');
    $activeUsersView = in_array($requestedUsersView, $allowedUsersViews, true)
      ? $requestedUsersView
      : ($selectedUser ? 'manage' : 'create');
    $usersViewUrl = static function (string $view): string {
      return route('users.index', array_merge(request()->query(), ['users_view' => $view]));
    };
    $permissionDefinitions = is_array($permissionDefinitions ?? null) ? $permissionDefinitions : [];
    $permissionRoleOptions = is_array($permissionRoleOptions ?? null) ? $permissionRoleOptions : [];
    $permissionMatrix = is_array($permissionMatrix ?? null) ? $permissionMatrix : [];
    $competencyServices = $competencyServices ?? collect();
    $competencyUsers = $competencyUsers ?? $users;
    $locationCompetencyServiceIdsByUser = is_array($locationCompetencyServiceIdsByUser ?? null)
      ? $locationCompetencyServiceIdsByUser
      : [];
    $selectedCompetencyLocationId = (int) ($selectedCompetencyLocationId ?? ($locationOptions->first()->id ?? 0));
    $selectedCompetencyUserId = (int) ($selectedCompetencyUserId ?? max(0, (int) request()->query('competency_user_id', 0)));
    $selectedCompetencyUser = $selectedCompetencyUser ?? (
      $selectedCompetencyUserId > 0 ? $competencyUsers->firstWhere('id', $selectedCompetencyUserId) : $competencyUsers->first()
    );
    $selectedCompetencyUserId = (int) ($selectedCompetencyUser?->id ?? 0);
    $selectedCompetencyServiceIds = collect($selectedCompetencyServiceIds ?? [])
      ->map(static fn (int $id): int => $id)
      ->unique()
      ->values()
      ->all();
    $selectedWorkhoursLocationId = (int) ($selectedWorkhoursLocationId ?? ($locationOptions->first()->id ?? 0));
    $workhoursDateInput = (string) ($workhoursDateInput ?? now()->format('Y-m-d'));
    $workhoursWeekStart = $workhoursWeekStart ?? \Carbon\CarbonImmutable::now((string) config('app.timezone', 'UTC'))
      ->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $workhoursUsers = $workhoursUsers ?? collect();
    $workhoursShiftsByUserAndDate = is_array($workhoursShiftsByUserAndDate ?? null)
      ? $workhoursShiftsByUserAndDate
      : [];
    $workhoursShiftCount = max(0, (int) ($workhoursShiftCount ?? 0));
    $isWorkhoursWeekPublic = (bool) ($isWorkhoursWeekPublic ?? false);
    $publishFromDateInput = (string) old('publish_from_date', $workhoursWeekStart->toDateString());
    $publishToDateInput = (string) old('publish_to_date', $workhoursWeekStart->addDays(6)->toDateString());
    $publishFromDateLabel = null;
    $publishToDateLabel = null;

    try {
      $publishFromDateLabel = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $publishFromDateInput)->format('d/m/Y');
    } catch (\Throwable) {
      $publishFromDateLabel = $publishFromDateInput;
    }

    try {
      $publishToDateLabel = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $publishToDateInput)->format('d/m/Y');
    } catch (\Throwable) {
      $publishToDateLabel = $publishToDateInput;
    }
    $createCompetencyScope = (string) old('competency_scope', \App\Models\User::COMPETENCY_SCOPE_LOCATION);
    $selectedUserCompetencyScope = $selectedUser?->competencyScopeValue() ?? \App\Models\User::COMPETENCY_SCOPE_GLOBAL;
    $editCompetencyScope = (string) old('competency_scope', $selectedUserCompetencyScope);
    $canManageRolePermissions = (bool) ($canManageRolePermissions ?? false);
  @endphp

  <section
    class="users-page is-view-mode"
    data-users-page
    data-open-user-id="{{ $selectedUser?->id ?? '' }}"
    data-preserve-input="{{ $showEditErrors ? '1' : '0' }}"
  >
    <nav class="users-page-nav" aria-label="Brugersider" data-users-view-nav>
      <a
        href="{{ $usersViewUrl('create') }}"
        class="users-page-nav-link users-page-nav-link-create{{ $activeUsersView === 'create' ? ' is-active' : '' }}"
      >
        <img src="{{ asset('images/icon-pack/lucide/icons/user-plus.svg') }}" alt="" class="users-page-nav-icon">
        Opret medarbejder
      </a>
      <a
        href="{{ $usersViewUrl('manage') }}"
        class="users-page-nav-link users-page-nav-link-manage{{ $activeUsersView === 'manage' ? ' is-active' : '' }}"
      >
        <img src="{{ asset('images/icon-pack/lucide/icons/users.svg') }}" alt="" class="users-page-nav-icon">
        Administrer medarbejdere
      </a>
      <a
        href="{{ $usersViewUrl('permissions') }}"
        class="users-page-nav-link users-page-nav-link-permissions{{ $activeUsersView === 'permissions' ? ' is-active' : '' }}"
      >
        <img src="{{ asset('images/icon-pack/lucide/icons/shield.svg') }}" alt="" class="users-page-nav-icon">
        Adgangsniveau
      </a>
      <a
        href="{{ $usersViewUrl('competencies') }}"
        class="users-page-nav-link users-page-nav-link-competencies{{ $activeUsersView === 'competencies' ? ' is-active' : '' }}"
      >
        <img src="{{ asset('images/icon-pack/lucide/icons/list-checks.svg') }}" alt="" class="users-page-nav-icon">
        Kompetencer
      </a>
      @if ($workShiftsEnabled)
        <a
          href="{{ $usersViewUrl('workhours') }}"
          class="users-page-nav-link users-page-nav-link-workhours{{ $activeUsersView === 'workhours' ? ' is-active' : '' }}"
        >
          <img src="{{ asset('images/icon-pack/lucide/icons/clock-3.svg') }}" alt="" class="users-page-nav-icon">
          Bookbarhed
        </a>
      @else
        <span
          class="users-page-nav-link users-page-nav-link-workhours is-locked has-badge"
          role="link"
          aria-disabled="true"
          title="Bookbarhed er slået fra i indstillinger"
        >
          <img src="{{ asset('images/icon-pack/lucide/icons/clock-3.svg') }}" alt="" class="users-page-nav-icon">
          Bookbarhed
          <span class="users-page-nav-badge">Slået fra</span>
        </span>
      @endif
      <a
        href="{{ $usersViewUrl('activity') }}"
        class="users-page-nav-link users-page-nav-link-activity{{ $activeUsersView === 'activity' ? ' is-active' : '' }}"
      >
        <img src="{{ asset('images/icon-pack/lucide/icons/activity.svg') }}" alt="" class="users-page-nav-icon">
        Aktivitets log
      </a>
    </nav>

    <div class="users-layout">
      <div class="users-card" id="users-create-section" data-users-panel="create" @if($activeUsersView !== 'create') hidden @endif>
        <div class="users-section-head">
          <div>
            <p class="users-eyebrow">Brugerstyring</p>
            <h1>Opret medarbejdere</h1>
          </div>
          <p class="users-text">
            Roller med brugeradgang kan oprette medarbejdere efter deres eget niveau.
          </p>
        </div>

        @if (session('status'))
          <div class="users-alert users-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($showCreateErrors)
          <div class="users-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <form class="users-form" method="POST" action="{{ route('users.store') }}" enctype="multipart/form-data">
          @csrf
          <input type="hidden" name="form_scope" value="create">

          <section class="users-form-section" aria-labelledby="users-create-details-heading">
            <div class="users-form-section-head">
              <h2 id="users-create-details-heading">Basisoplysninger</h2>
              <p>Navn, login og profilbillede for den nye medarbejder.</p>
            </div>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Navn</span>
                <input type="text" name="name" value="{{ old('name') }}" required>
              </label>

              <label class="users-field">
                <span>E-mail</span>
                <input type="email" name="email" value="{{ old('email') }}" required>
              </label>
            </div>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Rolle</span>
                <select name="role" required>
                  @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', \App\Models\User::ROLE_STAFF) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </label>

              <label class="users-field">
                <span>Initialer</span>
                <input type="text" name="initials" value="{{ old('initials') }}" maxlength="6" placeholder="Fx EM">
              </label>
            </div>

            <label class="users-field">
              <span>Profilbillede (valgfrit)</span>
              <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              <small class="users-field-help">Tilladte filer: JPG, PNG eller WEBP (max 3 MB).</small>
            </label>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Adgangskode</span>
                <div class="users-password-wrap">
                  <input id="create-password" type="password" name="password" minlength="12" required>
                  <button
                    type="button"
                    class="users-password-toggle"
                    data-password-toggle
                    data-password-target="create-password"
                    aria-pressed="false"
                    aria-label="Vis adgangskode"
                  >
                    Vis
                  </button>
                </div>
              </label>

              <label class="users-field">
                <span>Bekræft adgangskode</span>
                <div class="users-password-wrap">
                  <input id="create-password-confirmation" type="password" name="password_confirmation" minlength="12" required>
                  <button
                    type="button"
                    class="users-password-toggle"
                    data-password-toggle
                    data-password-target="create-password-confirmation"
                    aria-pressed="false"
                    aria-label="Vis adgangskode"
                  >
                    Vis
                  </button>
                </div>
              </label>
            </div>
          </section>

          <section
            class="users-form-section{{ $activeUsersView === 'permissions' ? ' is-focused' : '' }}"
            id="users-permissions-section"
            data-users-permissions-anchor
            aria-labelledby="users-create-permissions-heading"
          >
            <div class="users-form-section-head">
              <h2 id="users-create-permissions-heading">Booking og lokation</h2>
              <p>Definér rolle, bookbar status og hvilke afdelinger brugeren tilknyttes.</p>
            </div>

            <div class="users-field users-field-check">
              <span>Bookbar i kalender</span>
              <label class="users-check">
                <input type="hidden" name="is_bookable" value="0">
                <input type="checkbox" name="is_bookable" value="1" @checked((bool) old('is_bookable', true))>
                <span>Kan vælges af kunder ved booking</span>
              </label>
            </div>

            <label class="users-field">
              <span>Kompetenceområde</span>
              <select name="competency_scope" required>
                <option value="{{ \App\Models\User::COMPETENCY_SCOPE_GLOBAL }}" @selected($createCompetencyScope === \App\Models\User::COMPETENCY_SCOPE_GLOBAL)>
                  Samme på alle lokationer
                </option>
                <option value="{{ \App\Models\User::COMPETENCY_SCOPE_LOCATION }}" @selected($createCompetencyScope === \App\Models\User::COMPETENCY_SCOPE_LOCATION)>
                  Lokationsspecifik
                </option>
              </select>
              <small class="users-field-help">Lokationsspecifik betyder at kompetencer sættes pr. afdeling i kompetencefanen.</small>
            </label>

            <div class="users-field users-field-locations">
              <span>Lokationer for medarbejderen</span>
              @if ($locationOptions->isNotEmpty())
                <div class="users-location-list">
                  @foreach ($locationOptions as $locationOption)
                    <label class="users-location-option">
                      <input
                        type="checkbox"
                        name="location_ids[]"
                        value="{{ $locationOption->id }}"
                        @checked(in_array((string) $locationOption->id, $createSelectedLocationIds, true))
                      >
                      <span>{{ $locationOption->name }}</span>
                    </label>
                  @endforeach
                </div>
              @else
                <p class="users-location-note">Ingen aktive lokationer fundet for din adgang.</p>
              @endif
              <p class="users-location-note">Bookbare medarbejdere skal have mindst en lokation valgt.</p>
            </div>
          </section>

          <button type="submit" class="users-button">Opret bruger</button>
        </form>
      </div>

      <div class="users-card" id="users-manage-section" data-users-panel="manage" @if($activeUsersView !== 'manage') hidden @endif>
        <div class="users-section-head compact">
          <div class="users-section-head-copy">
            <p class="users-eyebrow">Oversigt</p>
            <h2>Vælg en medarbejder</h2>
            <p class="users-text">
              Klik pa en bruger for at ændre oplysninger, rolle eller slette adgangen i en samlet editor.
            </p>
          </div>

          <label class="users-search-field users-search-field-inline">
            <span>Søg medarbejder</span>
            <input type="search" data-users-search placeholder="Søg pa navn, e-mail eller rolle">
          </label>
        </div>

        @if (session('status'))
          <div class="users-alert users-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($errors->has('users_toggle'))
          <div class="users-alert" role="alert">
            {{ $errors->first('users_toggle') }}
          </div>
        @endif

        <div class="users-list-container">
          <div class="users-list" data-users-list>
            @forelse ($users as $user)
              @php
                $photoUrl = $user->profilePhotoUrl();
              @endphp
              <article
                class="users-list-item{{ $user->is_active ? '' : ' is-inactive' }}"
                data-users-item
                data-user-search="{{ mb_strtolower($user->name . ' ' . $user->email . ' ' . $user->roleLabel()) }}"
              >
                <div class="users-item-container">
                  <div class="users-summary">
                    <div class="users-avatar" aria-hidden="true">
                      @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="">
                      @else
                        {{ $user->bookingInitials() }}
                      @endif
                    </div>

                    <div class="users-summary-copy">
                      <strong>{{ $user->name }}</strong>
                      <span>{{ $user->email }}</span>
                    </div>
                  </div>

                  <div class="users-row-actions">
                    <form method="POST" action="{{ route('users.toggle-active', $user) }}" class="users-active-form">
                      @csrf
                      @method('PATCH')
                      <input type="hidden" name="is_active" value="0">
                      <label class="users-active-toggle">
                        <input
                          type="checkbox"
                          name="is_active"
                          value="1"
                          @checked((bool) $user->is_active)
                          onchange="this.form.submit()"
                        >
                        <span>{{ $user->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
                      </label>
                    </form>

                    <span class="users-role users-role-{{ $user->roleValue() }}">{{ $user->roleLabel() }}</span>

                    <button
                      type="button"
                      class="users-button users-button-ghost"
                      data-user-trigger
                      data-user-id="{{ $user->id }}"
                      data-user-name="{{ $user->name }}"
                      data-user-email="{{ $user->email }}"
                      data-user-initials="{{ $user->initials ?? '' }}"
                      data-user-photo-url="{{ $photoUrl ?? '' }}"
                      data-user-role="{{ $user->roleValue() }}"
                      data-user-role-label="{{ $user->roleLabel() }}"
                      data-user-bookable="{{ $user->is_bookable ? '1' : '0' }}"
                      data-user-competency-scope="{{ $user->competencyScopeValue() }}"
                      data-user-location-ids="{{ $user->locations->pluck('id')->implode(',') }}"
                      data-update-action="{{ route('users.update', $user) }}"
                      data-delete-action="{{ route('users.destroy', $user) }}"
                    >
                      Rediger
                    </button>
                  </div>
                </div>
              </article>
            @empty
              <article class="users-empty">
                <strong>Ingen brugere endnu</strong>
                <span>Opret den første medarbejder i boksen til venstre.</span>
              </article>
            @endforelse

            @if ($users->isNotEmpty())
              <article class="users-empty users-empty-search" data-users-search-empty hidden>
                <strong>Ingen resultater</strong>
                <span>Prøv en anden søgning.</span>
              </article>
            @endif
          </div>
        </div>
      </div>

      <div class="users-card" id="users-role-permissions-section" data-users-panel="permissions" @if($activeUsersView !== 'permissions') hidden @endif>
        <div class="users-section-head">
          <div>
            <p class="users-eyebrow">Adgangsniveau</p>
            <h2>Adgangsrettigheder pr. rolle</h2>
          </div>
          <p class="users-text">
            Her styrer du hvilke roller der har adgang til de enkelte områder i systemet.
          </p>
        </div>

        @if (session('status'))
          <div class="users-alert users-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($errors->has('permissions') || $errors->has('permissions_update'))
          <div class="users-alert" role="alert">
            {{ $errors->first('permissions') ?: $errors->first('permissions_update') }}
          </div>
        @endif

        @if (! $canManageRolePermissions)
          <div class="users-permissions-lock" role="status">
            <strong>Ingen adgang</strong>
            <p>Din rolle har ikke adgang til at redigere rolle-rettigheder.</p>
          </div>
        @else
          <form class="users-permissions-form" method="POST" action="{{ route('users.permissions.update') }}">
            @csrf
            @method('PATCH')

            <div class="users-permissions-table-wrap">
              <table class="users-permissions-table">
                <thead>
                  <tr>
                    <th scope="col">Område</th>
                    @foreach ($permissionRoleOptions as $roleValue => $roleLabel)
                      <th scope="col">{{ $roleLabel }}</th>
                    @endforeach
                  </tr>
                </thead>
                <tbody>
                  @foreach ($permissionDefinitions as $permissionKey => $meta)
                    <tr>
                      <th scope="row">
                        <div class="users-permissions-cell-copy">
                          <strong>{{ $meta['label'] ?? $permissionKey }}</strong>
                          @if (filled($meta['description'] ?? null))
                            <span>{{ $meta['description'] }}</span>
                          @endif
                        </div>
                      </th>

                      @foreach ($permissionRoleOptions as $roleValue => $roleLabel)
                        @php
                          $checkboxName = 'permissions[' . $roleValue . '][' . $permissionKey . ']';
                          $isAllowed = (bool) ($permissionMatrix[$roleValue][$permissionKey] ?? false);
                        @endphp
                        <td>
                          <label class="users-permissions-toggle{{ ! $isAllowed ? ' is-off' : '' }}">
                            <input type="hidden" name="{{ $checkboxName }}" value="1">
                            <input
                              type="checkbox"
                              name="{{ $checkboxName }}"
                              value="0"
                              @checked(! $isAllowed)
                            >
                            <span data-permission-toggle-state>{{ $isAllowed ? 'Til' : 'Fra' }}</span>
                          </label>
                        </td>
                      @endforeach
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <div class="users-permissions-actions">
              <button type="submit" class="users-button">Gem rettigheder</button>
            </div>
          </form>
        @endif
      </div>

      <div class="users-card" id="users-competencies-section" data-users-panel="competencies" @if($activeUsersView !== 'competencies') hidden @endif>
        <div class="users-section-head">
          <div>
            <p class="users-eyebrow">Kompetencer</p>
            <h2>Ydelser pr. medarbejder</h2>
          </div>
          <p class="users-text">
            Her definerer du hvilke ydelser hver medarbejder må udføre. Listen opdateres automatisk ud fra de ydelser, der er oprettet i systemet.
          </p>
        </div>

        @if (session('status'))
          <div class="users-alert users-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if (
          $errors->has('competencies') ||
          $errors->has('competency_user_id') ||
          $errors->has('service_ids') ||
          $errors->has('service_ids.*')
        )
          <div class="users-alert" role="alert">
            {{
              $errors->first('competencies') ?:
              $errors->first('competency_user_id') ?:
              $errors->first('service_ids') ?:
              $errors->first('service_ids.*')
            }}
          </div>
        @endif

        @if ($competencyServices->isEmpty())
          <article class="users-empty">
            <strong>Ingen ydelser oprettet endnu</strong>
            <span>Opret ydelser under "Ydelser" for at kunne tilknytte kompetencer til medarbejdere.</span>
          </article>
        @else
          <form method="GET" action="{{ route('users.index') }}" class="users-competency-location-form">
            <input type="hidden" name="users_view" value="competencies">
            @if ($selectedCompetencyUserId > 0)
              <input type="hidden" name="competency_user_id" value="{{ $selectedCompetencyUserId }}">
            @endif
            <label class="users-field users-field-compact">
              <span>Lokation</span>
              <select name="competency_location_id" onchange="this.form.submit()">
                @foreach ($locationOptions as $locationOption)
                  <option value="{{ $locationOption->id }}" @selected($selectedCompetencyLocationId === (int) $locationOption->id)>
                    {{ $locationOption->name }}
                  </option>
                @endforeach
              </select>
            </label>
          </form>

          @if ($competencyUsers->isEmpty())
            <article class="users-empty">
              <strong>Ingen medarbejdere i denne visning</strong>
              <span>Vælg en anden lokation, eller tildel brugeren lokationer først.</span>
            </article>
          @else
            <form method="POST" action="{{ route('users.competencies.bulk-update') }}" class="users-competencies-bulk-form">
              @csrf
              @method('PATCH')
              <input type="hidden" name="competency_location_id" value="{{ $selectedCompetencyLocationId }}">
              <input type="hidden" name="competency_user_id" value="{{ $selectedCompetencyUserId }}">

              <div class="users-competency-editor">
                <aside class="users-competency-users-panel">
                  <h3>Medarbejdere</h3>
                  <p>Klik på en medarbejder for at redigere deres kompetencer.</p>

                  <div class="users-competency-users-list">
                    @foreach ($competencyUsers as $competencyUser)
                      @php
                        $userLink = route('users.index', [
                          'users_view' => 'competencies',
                          'competency_location_id' => $selectedCompetencyLocationId,
                          'competency_user_id' => (int) $competencyUser->id,
                        ]);
                        $isCompetencyUserActive = (int) $competencyUser->id === $selectedCompetencyUserId;
                      @endphp
                      <a href="{{ $userLink }}" class="users-competency-user-item{{ $isCompetencyUserActive ? ' is-active' : '' }}">
                        <div class="users-competency-user-copy">
                          <strong>{{ $competencyUser->name }}</strong>
                          <span>{{ $competencyUser->roleLabel() }} · {{ $competencyUser->competencyScopeLabel() }}</span>
                        </div>
                        <span class="users-competency-user-state">{{ $isCompetencyUserActive ? 'Valgt' : 'Vælg' }}</span>
                      </a>
                    @endforeach
                  </div>
                </aside>

                <section class="users-competency-services-panel">
                  @if (! $selectedCompetencyUser)
                    <article class="users-empty">
                      <strong>Vælg en medarbejder</strong>
                      <span>Vælg en medarbejder i venstre side for at åbne deres ydelser.</span>
                    </article>
                  @else
                    <div class="users-competency-services-head">
                      <div>
                        <h3>{{ $selectedCompetencyUser->name }}</h3>
                        <p>Vælg de ydelser medarbejderen må udføre på den valgte lokation.</p>
                        @if (! $selectedCompetencyUser->usesLocationCompetencies())
                          <p class="users-location-note">Denne medarbejder er sat til globale kompetencer, så ændringer gælder på alle lokationer.</p>
                        @endif
                      </div>
                      <button type="submit" class="users-button">Gem kompetencer</button>
                    </div>

                    <div class="users-competency-services-grid">
                      @foreach ($competencyServices as $serviceOption)
                        <label class="users-competency-service-item">
                          <input
                            type="checkbox"
                            name="service_ids[]"
                            value="{{ $serviceOption->id }}"
                            @checked(in_array((int) $serviceOption->id, $selectedCompetencyServiceIds, true))
                          >
                          <div class="users-competency-service-copy">
                            <strong>{{ $serviceOption->name }}</strong>
                            <span>{{ $serviceOption->is_online_bookable ? 'Online booking: Til' : 'Online booking: Fra' }}</span>
                          </div>
                        </label>
                      @endforeach
                    </div>
                  @endif
                </section>
              </div>
            </form>
          @endif
        @endif
      </div>

      @if ($workShiftsEnabled)
      <div class="users-card" id="users-workhours-section" data-users-panel="workhours" @if($activeUsersView !== 'workhours') hidden @endif>
        @php
          $shiftWeekDays = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
          $shiftWeekDaysWithDates = collect($shiftWeekDays)
            ->values()
            ->map(static function (string $dayLabel, int $offset) use ($workhoursWeekStart): array {
              return [
                'label' => $dayLabel,
                'date' => $workhoursWeekStart->addDays($offset),
              ];
            });
          $shiftUsers = $workhoursUsers;
        @endphp

        <div class="users-workhours-tools-wrap">
          <form method="GET" action="{{ route('users.index') }}" class="users-workhours-tools">
            <input type="hidden" name="users_view" value="workhours">

            <label class="users-field users-field-compact">
              <span>Afdeling</span>
              <select name="workhours_location_id" onchange="this.form.submit()">
                @foreach ($locationOptions as $locationOption)
                  <option value="{{ $locationOption->id }}" @selected($selectedWorkhoursLocationId === (int) $locationOption->id)>
                    {{ $locationOption->name }}
                  </option>
                @endforeach
              </select>
            </label>

            <div class="users-workhours-week-nav" aria-label="Ugenavigation">
              <button type="submit" name="week_nav" value="prev" class="users-button users-button-ghost">Forrige uge</button>
              <input type="date" name="workhours_date" value="{{ $workhoursDateInput }}" onchange="this.form.submit()">
              <button type="submit" name="week_nav" value="next" class="users-button users-button-ghost">Næste uge</button>
            </div>
          </form>

          <form method="POST" action="{{ route('users.work-shifts.publish') }}" class="users-workhours-publish-form">
            @csrf
            <input type="hidden" name="workhours_location_id" value="{{ $selectedWorkhoursLocationId }}">
            <input type="hidden" name="workhours_date" value="{{ $workhoursDateInput }}">
            <div class="users-workhours-publish-range">
              <label class="users-workhours-publish-label">
                <span>Fra</span>
                <input type="date" name="publish_from_date" value="{{ $publishFromDateInput }}">
              </label>
              <label class="users-workhours-publish-label">
                <span>Til</span>
                <input type="date" name="publish_to_date" value="{{ $publishToDateInput }}">
              </label>
            </div>
            <div class="users-workhours-publish-row">
              @if ($isWorkhoursWeekPublic)
                <span class="users-workhours-public-state is-public">
                  Bookbarhed offentliggjort fra {{ $publishFromDateLabel }} til {{ $publishToDateLabel }}
                </span>
              @else
                <span class="users-workhours-public-state">
                  Ikke offentliggjort endnu
                </span>
              @endif
              <button type="submit" class="users-button users-button-public" @disabled($workhoursShiftCount <= 0)>OFFENTLIGGØR</button>
            </div>
          </form>
        </div>

        <div class="users-workhours-layout is-grid-only">
          <aside class="users-workhours-templates">
            <div class="users-workhours-panel-head">
              <h3>Skabeloner</h3>
              <p>Skabeloner tilføjes i næste version.</p>
            </div>
          </aside>

          <section class="users-workhours-calendar">
            <div class="users-workhours-grid-wrap">
              <div class="users-workhours-grid">
                <div class="users-workhours-grid-cell users-workhours-grid-cell-head users-workhours-grid-cell-staff">Medarbejder</div>
                @foreach ($shiftWeekDaysWithDates as $dayData)
                  <div class="users-workhours-grid-cell users-workhours-grid-cell-head users-workhours-grid-cell-day">
                    <strong class="users-workhours-day-label">{{ $dayData['label'] }}</strong>
                    <span class="users-workhours-day-date">{{ $dayData['date']->format('d/m') }}</span>
                  </div>
                @endforeach

                @forelse ($shiftUsers as $shiftUser)
                  <div class="users-workhours-grid-cell users-workhours-grid-cell-staff">
                    <strong>{{ $shiftUser->name }}</strong>
                    <span>{{ $shiftUser->roleLabel() }}</span>
                  </div>

                  @foreach ($shiftWeekDaysWithDates as $dayData)
                    @php
                      $dayDate = $dayData['date']->format('Y-m-d');
                      $shiftLookupKey = (int) $shiftUser->id . '|' . $dayDate;
                      $workShifts = collect($workhoursShiftsByUserAndDate[$shiftLookupKey] ?? []);
                    @endphp
                    <div class="users-workhours-grid-cell">
                      @foreach ($workShifts as $workShift)
                        @php
                          $workShiftTimeLabel = substr((string) $workShift->starts_at, 0, 5) . '-' . substr((string) $workShift->ends_at, 0, 5);
                          $workShiftBreakLabel = $workShift->break_starts_at && $workShift->break_ends_at
                            ? 'Pause ' . substr((string) $workShift->break_starts_at, 0, 5) . '-' . substr((string) $workShift->break_ends_at, 0, 5)
                            : null;
                        @endphp
                        <button
                          type="button"
                          class="users-workhours-shift-chip{{ $workShift->is_public ? ' is-public' : '' }}"
                          data-workhours-shift
                          data-shift-id="{{ $workShift->id }}"
                          data-user-id="{{ $workShift->user_id }}"
                          data-shift-date="{{ $dayDate }}"
                          data-starts-at="{{ substr((string) $workShift->starts_at, 0, 5) }}"
                          data-ends-at="{{ substr((string) $workShift->ends_at, 0, 5) }}"
                          data-break-starts-at="{{ $workShift->break_starts_at ? substr((string) $workShift->break_starts_at, 0, 5) : '' }}"
                          data-break-ends-at="{{ $workShift->break_ends_at ? substr((string) $workShift->break_ends_at, 0, 5) : '' }}"
                          data-notes="{{ $workShift->notes ?? '' }}"
                          data-is-public="{{ $workShift->is_public ? '1' : '0' }}"
                          title="Rediger bookbarhed"
                        >
                          {{ $workShiftTimeLabel }}
                        </button>
                        @if ($workShiftBreakLabel)
                          <span class="users-workhours-shift-meta">{{ $workShiftBreakLabel }}</span>
                        @endif
                      @endforeach
                      <button
                        type="button"
                        class="users-workhours-add-chip"
                        data-workhours-add
                        data-user-id="{{ $shiftUser->id }}"
                        data-shift-date="{{ $dayDate }}"
                        title="Opret bookbarhed"
                      >
                        +
                      </button>
                    </div>
                  @endforeach
                @empty
                  <div class="users-empty">
                    <strong>Ingen bookbare medarbejdere</strong>
                    <span>Tilknyt en bookbar medarbejder til afdelingen for at oprette vagter.</span>
                  </div>
                @endforelse
              </div>
            </div>
          </section>
        </div>

        <dialog class="users-modal users-workhours-modal" data-workhours-modal>
          <div class="users-modal-card">
            <div class="users-modal-head">
              <div>
                <p class="users-eyebrow">Bookbarhed</p>
                <h2 data-workhours-modal-title>Opret bookbarhed</h2>
                <p class="users-text">Sæt bookbar tid og eventuel pause.</p>
              </div>

              <button type="button" class="users-modal-close" data-workhours-modal-close aria-label="Luk">
                Luk
              </button>
            </div>

            <form
              class="users-modal-form users-workhours-form"
              method="POST"
              action="{{ route('users.work-shifts.store') }}"
              data-workhours-form
            >
              @csrf
              <input type="hidden" name="form_scope" value="work_shift">
              <input type="hidden" name="shift_mode" value="{{ old('shift_mode', 'create') }}" data-workhours-field-mode>
              <input type="hidden" name="shift_id" value="{{ old('shift_id') }}" data-workhours-field-shift-id>
              <input type="hidden" name="workhours_location_id" value="{{ old('workhours_location_id', $selectedWorkhoursLocationId) }}" data-workhours-field-location>
              <input type="hidden" name="workhours_date" value="{{ old('workhours_date', $workhoursDateInput) }}" data-workhours-field-return-date>

              <div class="users-grid users-grid-two">
                <label class="users-field">
                  <span>Medarbejder</span>
                  <select name="user_id" data-workhours-field-user required>
                    <option value="">Vælg medarbejder</option>
                    @foreach ($shiftUsers as $shiftUser)
                      <option value="{{ $shiftUser->id }}" @selected((int) old('user_id') === (int) $shiftUser->id)>{{ $shiftUser->name }}</option>
                    @endforeach
                  </select>
                </label>

                <label class="users-field">
                  <span>Dato</span>
                  <input type="date" name="shift_date" value="{{ old('shift_date', $workhoursDateInput) }}" data-workhours-field-date required>
                </label>
              </div>

              <div class="users-grid users-grid-two">
                <label class="users-field">
                  <span>Bookbar tid fra</span>
                  <input type="time" name="starts_at" value="{{ old('starts_at', '09:00') }}" data-workhours-field-start required>
                </label>

                <label class="users-field">
                  <span>Bookbar tid til</span>
                  <input type="time" name="ends_at" value="{{ old('ends_at', '17:00') }}" data-workhours-field-end required>
                </label>
              </div>

              <div class="users-grid users-grid-two">
                <label class="users-field">
                  <span>Pausetid fra (ikke bookbar)</span>
                  <input type="time" name="break_starts_at" value="{{ old('break_starts_at') }}" data-workhours-field-break-start>
                </label>

                <label class="users-field">
                  <span>Pausetid til</span>
                  <input type="time" name="break_ends_at" value="{{ old('break_ends_at') }}" data-workhours-field-break-end>
                </label>
              </div>

              <label class="users-field">
                <span>Notat (valgfri)</span>
                <input type="text" name="notes" maxlength="500" value="{{ old('notes') }}" data-workhours-field-notes placeholder="Fx intern mødetid eller opgave">
              </label>

              <div class="users-modal-actions">
                <button type="submit" class="users-button users-button-secondary">Gem bookbarhed</button>
                <button type="button" class="users-button users-button-muted" data-workhours-modal-close>Annuller</button>
              </div>
            </form>

            <form
              method="POST"
              action="{{ old('shift_id') ? route('users.work-shifts.destroy', ['workShift' => (int) old('shift_id')]) : '#' }}"
              class="users-delete-form users-workhours-delete-form"
              data-workhours-delete-form
              data-action-template="{{ route('users.work-shifts.destroy', ['workShift' => '__SHIFT_ID__']) }}"
              @if (! old('shift_id')) hidden @endif
            >
              @csrf
              @method('DELETE')
              <input type="hidden" name="workhours_location_id" value="{{ old('workhours_location_id', $selectedWorkhoursLocationId) }}" data-workhours-delete-location>
              <input type="hidden" name="workhours_date" value="{{ old('workhours_date', $workhoursDateInput) }}" data-workhours-delete-date>
              <div class="users-delete-row">
                <div class="users-delete-copy">
                  <strong>Slet</strong>
                  <span>Fjerner bookbarheden helt fra den valgte dag.</span>
                </div>
                <div class="users-delete-actions">
                  <button
                    type="submit"
                    class="users-button users-button-public users-button-public-shift"
                    form="users-workhours-publish-single-form"
                  >
                    Offentligør
                  </button>
                  <button type="submit" class="users-button users-button-danger">Slet</button>
                </div>
              </div>
            </form>
            <form
              method="POST"
              action="{{ old('shift_id') ? route('users.work-shifts.publish-single', ['workShift' => (int) old('shift_id')]) : '#' }}"
              class="users-workhours-publish-single-form"
              id="users-workhours-publish-single-form"
              data-workhours-publish-single-form
              data-action-template="{{ route('users.work-shifts.publish-single', ['workShift' => '__SHIFT_ID__']) }}"
              @if (! old('shift_id')) hidden @endif
            >
              @csrf
              <input type="hidden" name="workhours_location_id" value="{{ old('workhours_location_id', $selectedWorkhoursLocationId) }}" data-workhours-publish-single-location>
              <input type="hidden" name="workhours_date" value="{{ old('workhours_date', $workhoursDateInput) }}" data-workhours-publish-single-date>
            </form>
          </div>
        </dialog>
      </div>
      @endif

      <div class="users-card" id="users-activity-section" data-users-panel="activity" @if($activeUsersView !== 'activity') hidden @endif>
        <div class="users-section-head">
          <div>
            <p class="users-eyebrow">Aktivitet</p>
            <h2>Status og historik</h2>
          </div>
          <p class="users-text">
            Et simpelt overblik over medarbejderstatus. Her kan du hurtigt se hvem der er aktive og klar til booking.
          </p>
        </div>

        <div class="users-module-grid">
          @foreach ($users as $user)
            <article class="users-module-item">
              <div class="users-module-item-head">
                <strong>{{ $user->name }}</strong>
                <span class="users-module-tag{{ $user->is_active ? '' : ' is-inactive' }}">
                  {{ $user->is_active ? 'Aktiv' : 'Inaktiv' }}
                </span>
              </div>
              <p>Rolle: {{ $user->roleLabel() }}</p>
              <small>Bookbar: {{ $user->is_bookable ? 'Ja' : 'Nej' }}</small>
            </article>
          @endforeach
        </div>
      </div>
    </div>

    @if ($users->isNotEmpty())
      <dialog class="users-modal" data-users-modal>
        <div class="users-modal-card">
          <div class="users-modal-head">
            <div>
              <p class="users-eyebrow">Medarbejdereditor</p>
              <h2 data-users-modal-title>{{ $selectedUser?->name ?? 'Rediger bruger' }}</h2>
              <p class="users-text" data-users-modal-subtitle>{{ $selectedUser?->email ?? 'Vælg en bruger fra listen for at redigere adgang og oplysninger.' }}</p>
            </div>

            <button type="button" class="users-modal-close" data-users-modal-close aria-label="Luk">
              Luk
            </button>
          </div>

          @if ($showEditErrors)
            <div class="users-alert" role="alert">
              {{ $errors->first() }}
            </div>
          @endif

          <div class="users-modal-role-wrap">
            <span class="users-role users-role-{{ $selectedUser?->roleValue() ?? \App\Models\User::ROLE_STAFF }}" data-users-modal-role>
              {{ $selectedUser?->roleLabel() ?? 'Ansat' }}
            </span>
          </div>

          <form
            class="users-modal-form"
            method="POST"
            action="{{ $selectedUser ? route('users.update', $selectedUser) : '#' }}"
            enctype="multipart/form-data"
            data-users-edit-form
          >
            @csrf
            @method('PATCH')
            <input type="hidden" name="form_scope" value="edit">
            <input type="hidden" name="modal_user_id" value="{{ $selectedUser?->id ?? '' }}" data-users-modal-user-id>

            @php
              $selectedUserPhotoUrl = $selectedUser?->profilePhotoUrl();
            @endphp

            <div class="users-avatar-editor">
              <div class="users-avatar users-avatar-large" data-users-modal-avatar>
                @if ($selectedUserPhotoUrl)
                  <img src="{{ $selectedUserPhotoUrl }}" alt="">
                @else
                  {{ $selectedUser?->bookingInitials() ?? '--' }}
                @endif
              </div>

              <div class="users-avatar-editor-fields">
                <label class="users-field">
                  <span>Nyt profilbillede</span>
                  <input
                    type="file"
                    name="profile_photo"
                    data-users-field-photo
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                  >
                  <small class="users-field-help">Tilladte filer: JPG, PNG eller WEBP (max 3 MB).</small>
                </label>

                <label class="users-check users-check-compact">
                  <input
                    type="checkbox"
                    name="remove_profile_photo"
                    value="1"
                    data-users-field-remove-photo
                    @checked((bool) old('remove_profile_photo', false))
                  >
                  <span>Fjern nuværende profilbillede</span>
                </label>
              </div>
            </div>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Navn</span>
                <input
                  type="text"
                  name="name"
                  value="{{ $showEditErrors ? old('name') : ($selectedUser?->name ?? '') }}"
                  data-users-field-name
                  required
                >
              </label>

              <label class="users-field">
                <span>E-mail</span>
                <input
                  type="email"
                  name="email"
                  value="{{ $showEditErrors ? old('email') : ($selectedUser?->email ?? '') }}"
                  data-users-field-email
                  required
                >
              </label>
            </div>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Rolle</span>
                <select name="role" data-users-field-role required>
                  @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(($showEditErrors ? old('role') : ($selectedUser?->roleValue() ?? \App\Models\User::ROLE_STAFF)) === $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </label>

              <label class="users-field">
                <span>Initialer</span>
                <input
                  type="text"
                  name="initials"
                  maxlength="6"
                  value="{{ $showEditErrors ? old('initials') : ($selectedUser?->initials ?? '') }}"
                  data-users-field-initials
                  placeholder="Fx EM"
                >
              </label>
            </div>

            <div class="users-grid users-grid-two">
              <label class="users-field">
                <span>Ny adgangskode</span>
                <div class="users-password-wrap">
                  <input id="edit-password" type="password" name="password" minlength="12" placeholder="Kun hvis den skal skiftes">
                  <button
                    type="button"
                    class="users-password-toggle"
                    data-password-toggle
                    data-password-target="edit-password"
                    aria-pressed="false"
                    aria-label="Vis adgangskode"
                  >
                    Vis
                  </button>
                </div>
              </label>

              <label class="users-field">
                <span>Bekræft ny adgangskode</span>
                <div class="users-password-wrap">
                  <input id="edit-password-confirmation" type="password" name="password_confirmation" minlength="12" placeholder="Gentag kun ved nyt password">
                  <button
                    type="button"
                    class="users-password-toggle"
                    data-password-toggle
                    data-password-target="edit-password-confirmation"
                    aria-pressed="false"
                    aria-label="Vis adgangskode"
                  >
                    Vis
                  </button>
                </div>
              </label>
            </div>

            <div class="users-grid users-grid-two">
              <div class="users-field users-field-check">
                <span>Bookbar i kalender</span>
                <label class="users-check">
                  <input type="hidden" name="is_bookable" value="0">
                  <input
                    type="checkbox"
                    name="is_bookable"
                    value="1"
                    data-users-field-bookable
                    @checked((bool) old('is_bookable', $selectedUser?->is_bookable ?? true))
                  >
                  <span>Kan vælges af kunder ved booking</span>
                </label>
              </div>

              <label class="users-field">
                <span>Kompetenceområde</span>
                <select name="competency_scope" data-users-field-competency-scope required>
                  <option value="{{ \App\Models\User::COMPETENCY_SCOPE_GLOBAL }}" @selected($editCompetencyScope === \App\Models\User::COMPETENCY_SCOPE_GLOBAL)>
                    Samme på alle lokationer
                  </option>
                  <option value="{{ \App\Models\User::COMPETENCY_SCOPE_LOCATION }}" @selected($editCompetencyScope === \App\Models\User::COMPETENCY_SCOPE_LOCATION)>
                    Lokationsspecifik
                  </option>
                </select>
                <small class="users-field-help">Lokationsspecifik sættes i kompetencefanen for den valgte afdeling.</small>
              </label>
            </div>

            <div class="users-field users-field-note">
              <span>Adgang</span>
              <p>Ejer administrerer alle roller. Lokationschef administrerer leder og ansat. Leder administrerer ansat.</p>
            </div>

            <div class="users-field users-field-locations">
              <span>Lokationer for medarbejderen</span>
              @if ($locationOptions->isNotEmpty())
                <div class="users-location-list">
                  @foreach ($locationOptions as $locationOption)
                    <label class="users-location-option">
                      <input
                        type="checkbox"
                        name="location_ids[]"
                        value="{{ $locationOption->id }}"
                        data-users-field-location
                        @checked(in_array((string) $locationOption->id, $editSelectedLocationIds, true))
                      >
                      <span>{{ $locationOption->name }}</span>
                    </label>
                  @endforeach
                </div>
              @else
                <p class="users-location-note">Ingen aktive lokationer fundet for din adgang.</p>
              @endif
              <p class="users-location-note">Bookbare medarbejdere skal have mindst en lokation valgt.</p>
            </div>

            <div class="users-modal-actions">
              <button type="submit" class="users-button users-button-secondary">Gem ændringer</button>
              <button type="button" class="users-button users-button-muted" data-users-modal-close">Annuller</button>
            </div>
          </form>

          <form
            method="POST"
            action="{{ $selectedUser ? route('users.destroy', $selectedUser) : '#' }}"
            class="users-delete-form"
            data-users-delete-form
          >
            @csrf
            @method('DELETE')
            <div class="users-delete-row">
              <div class="users-delete-copy">
                <strong>Slet bruger</strong>
                <span>Brug kun denne handling, hvis adgangen skal fjernes helt fra systemet.</span>
              </div>
              <button type="submit" class="users-button users-button-danger">Slet bruger</button>
            </div>
          </form>
        </div>
      </dialog>
    @endif
  </section>
@endsection
