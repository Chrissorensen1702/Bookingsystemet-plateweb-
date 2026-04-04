@php
  $activityEvents = $activityEvents ?? collect();
  $activityScope = in_array((string) ($activityScope ?? 'all'), ['all', 'bookings', 'users', 'services', 'settings'], true)
    ? (string) ($activityScope ?? 'all')
    : 'all';
  $activityScopes = [
    'all' => 'Alle',
    'bookings' => 'Bookinger',
    'users' => 'Brugere',
    'services' => 'Ydelser',
    'settings' => 'Indstillinger',
  ];
  $activityScopeUrl = static function (string $scope) use ($selectedLocationId): string {
    return route('settings.index', array_merge(request()->query(), [
      'location_id' => $selectedLocationId,
      'settings_view' => 'activity',
      'activity_scope' => $scope,
    ]));
  };
@endphp

<div class="settings-section-head settings-activity-head">
  <div>
    <p class="settings-eyebrow">Aktivitet</p>
    <h1>Aktivitets log</h1>
  </div>
  <p class="settings-text">
    Et console-lignende overblik over hvem der har oprettet, aendret eller slettet vigtige data i systemet.
  </p>
</div>

<div class="settings-activity-console">
  <div class="settings-activity-console__toolbar">
    <div class="settings-activity-console__filters" role="tablist" aria-label="Filtrer aktivitet">
      @foreach ($activityScopes as $scopeKey => $scopeLabel)
        <a
          href="{{ $activityScopeUrl($scopeKey) }}"
          class="settings-activity-filter{{ $activityScope === $scopeKey ? ' is-active' : '' }}"
        >
          {{ $scopeLabel }}
        </a>
      @endforeach
    </div>

    <p class="settings-activity-console__meta">
      Viser de seneste {{ $activityEvents->count() }} haendelser.
    </p>
  </div>

  @if ($activityEvents->isEmpty())
    <article class="settings-activity-empty">
      <strong>Ingen haendelser endnu</strong>
      <span>Der er ikke logget nogen relevante handlinger i denne visning endnu.</span>
    </article>
  @else
    <div class="settings-activity-stream">
      @foreach ($activityEvents as $activityEvent)
        @php
          $metadata = is_array($activityEvent->metadata ?? null) ? $activityEvent->metadata : [];
          $contextItems = collect($metadata['context'] ?? [])
            ->filter(static fn ($item): bool => is_array($item) && filled($item['label'] ?? null) && filled($item['value'] ?? null))
            ->values();
          $changeItems = collect($metadata['changes'] ?? [])
            ->filter(static fn ($item): bool => is_array($item) && filled($item['label'] ?? null))
            ->values();
          $categoryLabel = match ($activityEvent->category) {
            'bookings' => 'Booking',
            'users' => 'Bruger',
            'services' => 'Ydelse',
            'settings' => 'Indstilling',
            default => ucfirst((string) $activityEvent->category),
          };
          $actorLabel = $activityEvent->actor?->name ?: 'System';
        @endphp

        <article class="settings-activity-entry settings-activity-entry--{{ $activityEvent->category }}">
          <div class="settings-activity-entry__rail" aria-hidden="true"></div>

          <div class="settings-activity-entry__body">
            <div class="settings-activity-entry__meta">
              <span class="settings-activity-badge settings-activity-badge--{{ $activityEvent->category }}">
                {{ $categoryLabel }}
              </span>
              <span>{{ $activityEvent->created_at?->format('d.m.Y H:i') }}</span>
              <span>{{ $actorLabel }}</span>
              @if ($activityEvent->location?->name)
                <span>{{ $activityEvent->location->name }}</span>
              @endif
            </div>

            <p class="settings-activity-entry__message">{{ $activityEvent->message }}</p>

            @if ($contextItems->isNotEmpty() || $changeItems->isNotEmpty())
              <details class="settings-activity-entry__details">
                <summary>Se detaljer</summary>

                @if ($contextItems->isNotEmpty())
                  <dl class="settings-activity-context">
                    @foreach ($contextItems as $contextItem)
                      <div class="settings-activity-context__item">
                        <dt>{{ $contextItem['label'] }}</dt>
                        <dd>{{ $contextItem['value'] }}</dd>
                      </div>
                    @endforeach
                  </dl>
                @endif

                @if ($changeItems->isNotEmpty())
                  <div class="settings-activity-changes">
                    @foreach ($changeItems as $changeItem)
                      <article class="settings-activity-change">
                        <p class="settings-activity-change__label">{{ $changeItem['label'] }}</p>
                        <div class="settings-activity-change__values">
                          <div>
                            <span>Foer</span>
                            <strong>{{ $changeItem['before'] ?? 'Tom' }}</strong>
                          </div>
                          <div>
                            <span>Efter</span>
                            <strong>{{ $changeItem['after'] ?? 'Tom' }}</strong>
                          </div>
                        </div>
                      </article>
                    @endforeach
                  </div>
                @endif
              </details>
            @endif
          </div>
        </article>
      @endforeach
    </div>
  @endif
</div>
