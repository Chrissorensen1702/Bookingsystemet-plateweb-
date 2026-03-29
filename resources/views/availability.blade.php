@extends('layouts.default')

@section('body-class', 'booking-home-body availability-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $locations = $locations ?? collect();
    $weekDays = $weekDays ?? [];
    $openingHoursByDay = $openingHoursByDay ?? collect();
    $closures = $closures ?? collect();
    $dateOverrides = $dateOverrides ?? collect();
    $selectedLocationId = (int) ($selectedLocationId ?? 0);
    $showCreateErrors = $errors->any() && (string) old('availability_form_scope', '') !== '';
  @endphp

  <section
    class="availability-page"
    data-availability-page
    data-open-create-modal="{{ $showCreateErrors ? '1' : '0' }}"
  >
    <div class="availability-layout">
      <div class="availability-card availability-card-overview">
        <div class="availability-section-head compact">
          <div>
            <p class="availability-eyebrow">Tilgængelighed</p>
            <h1>Åbningstider og bookingrammer</h1>
            <p class="availability-text">
              Definer faste ugetider, ferie/lukkeperioder og dato-undtagelser pr. afdeling.
            </p>
          </div>
          <button
            type="button"
            class="availability-create-fab"
            data-availability-create-open
            aria-label="Opret ny regel"
            title="Opret ny regel"
          >
            <img src="{{ asset('images/icon-pack/lucide/icons/plus.svg') }}" alt="" class="availability-create-fab-icon" aria-hidden="true">
          </button>
        </div>

        @if (session('status'))
          <div class="availability-alert availability-alert-success" role="status">
            {{ session('status') }}
          </div>
        @endif

        @if ($errors->any())
          <div class="availability-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <div class="availability-tools">
          <form method="GET" class="availability-location-form">
            <label class="availability-field">
              <span>Afdeling</span>
              <select name="location_id" data-availability-location>
                @foreach ($locations as $location)
                  <option value="{{ $location->id }}" @selected($selectedLocationId === (int) $location->id)>
                    {{ $location->name }}
                  </option>
                @endforeach
              </select>
            </label>

            <button type="submit" class="availability-button availability-button-ghost">Skift afdeling</button>
          </form>
        </div>

        <section class="availability-section">
          <div class="availability-section-title compact">
            <h3>Ugeskema</h3>
          </div>

          <div class="availability-day-grid">
            @foreach ($weekDays as $weekday => $label)
              @php($daySlots = $openingHoursByDay->get($weekday, collect()))
              <article class="availability-day-card">
                <h4>{{ $label }}</h4>

                <div class="availability-slot-list">
                  @forelse ($daySlots as $slot)
                    <div class="availability-slot-row">
                      <span>{{ substr((string) $slot->opens_at, 0, 5) }} - {{ substr((string) $slot->closes_at, 0, 5) }}</span>
                      <form method="POST" action="{{ route('availability.opening-hours.destroy', $slot) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="availability-link-button">Slet</button>
                      </form>
                    </div>
                  @empty
                    <p class="availability-empty-line">Ingen tider oprettet</p>
                  @endforelse
                </div>
              </article>
            @endforeach
          </div>
        </section>

        <section class="availability-section">
          <div class="availability-section-title compact">
            <h3>Ferie og lukkeperioder</h3>
          </div>

          <div class="availability-list">
            @forelse ($closures as $closure)
              <article class="availability-list-item">
                <div class="availability-list-copy">
                  <strong>{{ $closure->starts_on?->format('d.m.Y') }} - {{ $closure->ends_on?->format('d.m.Y') }}</strong>
                  <span>{{ $closure->reason ?: 'Ingen note' }}</span>
                </div>
                <form method="POST" action="{{ route('availability.closures.destroy', $closure) }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="availability-link-button">Slet</button>
                </form>
              </article>
            @empty
              <article class="availability-empty">
                <strong>Ingen lukkeperioder</strong>
                <span>Tilføj ferie eller lukkedage i boksen til venstre.</span>
              </article>
            @endforelse
          </div>
        </section>

        <section class="availability-section">
          <div class="availability-section-title compact">
            <h3>Dato-undtagelser</h3>
          </div>

          <div class="availability-list">
            @forelse ($dateOverrides as $override)
              <article class="availability-override-card">
                <div class="availability-override-head">
                  <div>
                    <strong>{{ $override->override_date?->format('d.m.Y') }}</strong>
                    @if ($override->note)
                      <span>{{ $override->note }}</span>
                    @endif
                  </div>
                  <span class="availability-badge{{ $override->is_closed ? ' is-closed' : '' }}">
                    {{ $override->is_closed ? 'Lukket' : 'Særåbning' }}
                  </span>
                </div>

                @if ($override->is_closed)
                  <p class="availability-text">Afdelingen er lukket hele dagen.</p>
                @else
                  <div class="availability-slot-list">
                    @foreach ($override->slots as $slot)
                      <div class="availability-slot-row">
                        <span>{{ substr((string) $slot->opens_at, 0, 5) }} - {{ substr((string) $slot->closes_at, 0, 5) }}</span>
                        <form method="POST" action="{{ route('availability.date-overrides.slots.destroy', $slot) }}">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="availability-link-button">Slet</button>
                        </form>
                      </div>
                    @endforeach
                  </div>

                  <form method="POST" action="{{ route('availability.date-overrides.slots.store', $override) }}" class="availability-inline-form">
                    @csrf
                    <label class="availability-field">
                      <span>Fra</span>
                      <input type="time" name="opens_at" required>
                    </label>
                    <label class="availability-field">
                      <span>Til</span>
                      <input type="time" name="closes_at" required>
                    </label>
                    <button type="submit" class="availability-button availability-button-ghost">Tilføj slot</button>
                  </form>
                @endif

                <form method="POST" action="{{ route('availability.date-overrides.destroy', $override) }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="availability-link-button">Slet dato-undtagelse</button>
                </form>
              </article>
            @empty
              <article class="availability-empty">
                <strong>Ingen dato-undtagelser</strong>
                <span>Tilføj en dagsregel, hvis en specifik dato skal afvige fra ugeskemaet.</span>
              </article>
            @endforelse
          </div>
        </section>
      </div>
    </div>

    <dialog class="availability-modal" data-availability-create-modal>
      <div class="availability-modal-card">
        <div class="availability-modal-head">
          <div>
            <p class="availability-eyebrow">Tilgængelighed</p>
            <h2>Opret ny regel</h2>
            <p class="availability-text">Tilføj åbningstider, lukkeperioder og dato-undtagelser for den valgte afdeling.</p>
          </div>

          <button type="button" class="availability-modal-close" data-availability-create-close aria-label="Luk">
            Luk
          </button>
        </div>

        @if ($showCreateErrors)
          <div class="availability-alert" role="alert">
            {{ $errors->first() }}
          </div>
        @endif

        <section class="availability-section">
          <div class="availability-section-title">
            <h2>Ugentlige åbningstider</h2>
            <p>Tilføj et eller flere tidsintervaller pr. ugedag.</p>
          </div>

          <form method="POST" action="{{ route('availability.opening-hours.store') }}" class="availability-form-grid availability-form-grid-hours">
            @csrf
            <input type="hidden" name="availability_form_scope" value="opening_hours">
            <input type="hidden" name="location_id" value="{{ $selectedLocationId }}">

            <label class="availability-field">
              <span>Ugedag</span>
              <select name="weekday" required>
                @foreach ($weekDays as $value => $label)
                  <option value="{{ $value }}" @selected((int) old('weekday', 1) === (int) $value)>{{ $label }}</option>
                @endforeach
              </select>
            </label>

            <label class="availability-field">
              <span>Fra</span>
              <input type="time" name="opens_at" value="{{ old('opens_at', '07:00') }}" required>
            </label>

            <label class="availability-field">
              <span>Til</span>
              <input type="time" name="closes_at" value="{{ old('closes_at', '22:00') }}" required>
            </label>

            <button type="submit" class="availability-button">Tilføj interval</button>
          </form>
        </section>

        <section class="availability-section">
          <div class="availability-section-title">
            <h2>Ferie og lukkeperioder</h2>
            <p>Bruges til perioder hvor afdelingen ikke tager bookinger.</p>
          </div>

          <form method="POST" action="{{ route('availability.closures.store') }}" class="availability-form-grid availability-form-grid-2">
            @csrf
            <input type="hidden" name="availability_form_scope" value="closures">
            <input type="hidden" name="location_id" value="{{ $selectedLocationId }}">

            <label class="availability-field">
              <span>Fra dato</span>
              <input type="date" name="starts_on" value="{{ old('starts_on') }}" required>
            </label>

            <label class="availability-field">
              <span>Til dato</span>
              <input type="date" name="ends_on" value="{{ old('ends_on') }}" required>
            </label>

            <label class="availability-field availability-field-full">
              <span>Note (valgfri)</span>
              <input type="text" name="reason" maxlength="180" value="{{ old('reason') }}" placeholder="Fx Sommerferie">
            </label>

            <button type="submit" class="availability-button">Tilføj lukkeperiode</button>
          </form>
        </section>

        <section class="availability-section">
          <div class="availability-section-title">
            <h2>Dato-undtagelse</h2>
            <p>Lav en enkelt dag som lukket eller med særåbningstid.</p>
          </div>

          <form method="POST" action="{{ route('availability.date-overrides.store') }}" class="availability-form-grid availability-form-grid-2" data-override-form>
            @csrf
            <input type="hidden" name="availability_form_scope" value="date_override">
            <input type="hidden" name="location_id" value="{{ $selectedLocationId }}">

            <label class="availability-field">
              <span>Dato</span>
              <input type="date" name="override_date" value="{{ old('override_date') }}" required>
            </label>

            <label class="availability-field">
              <span>Type</span>
              <select name="override_type" data-override-type required>
                <option value="closed" @selected(old('override_type', 'closed') === 'closed')>Lukket hele dagen</option>
                <option value="open" @selected(old('override_type') === 'open')>Særåbningstid</option>
              </select>
            </label>

            <label class="availability-field" data-override-time>
              <span>Fra</span>
              <input type="time" name="opens_at" value="{{ old('opens_at', '07:00') }}">
            </label>

            <label class="availability-field" data-override-time>
              <span>Til</span>
              <input type="time" name="closes_at" value="{{ old('closes_at', '22:00') }}">
            </label>

            <label class="availability-field availability-field-full">
              <span>Note (valgfri)</span>
              <input type="text" name="note" maxlength="180" value="{{ old('note') }}" placeholder="Fx Åbent hus">
            </label>

            <button type="submit" class="availability-button">Tilføj dato-undtagelse</button>
          </form>
        </section>
      </div>
    </dialog>
  </section>
@endsection
