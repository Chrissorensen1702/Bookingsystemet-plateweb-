<div class="users-section-head">
  <div>
    <p class="users-eyebrow">Aktivitet</p>
    <h2>Status og historik</h2>
  </div>
  <p class="users-text">
    Et simpelt overblik over medarbejderstatus. Her kan du hurtigt se hvem der er aktive og klar til booking.
  </p>
</div>

@if ($activityUsers->isEmpty())
  <article class="users-empty">
    <strong>Ingen medarbejdere i denne visning</strong>
    <span>Der er ingen medarbejdere at vise med din nuværende adgang.</span>
  </article>
@else
  <div class="users-module-grid">
    @foreach ($activityUsers as $activityUser)
      <article class="users-module-item">
        <div class="users-module-item-head">
          <strong>{{ $activityUser->name }}</strong>
          <span class="users-module-tag{{ $activityUser->is_active ? '' : ' is-inactive' }}">
            {{ $activityUser->is_active ? 'Aktiv' : 'Inaktiv' }}
          </span>
        </div>
        <p>Rolle: {{ $activityUser->roleLabel() }}</p>
        <small>Bookbar: {{ $activityUser->is_bookable ? 'Ja' : 'Nej' }}</small>
      </article>
    @endforeach
  </div>
@endif
