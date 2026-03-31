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
    <input type="hidden" name="settings_view" value="permissions">
    <input type="hidden" name="location_id" value="{{ $selectedLocationId }}">

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
