@extends('layouts.default')

@section('body-class', 'booking-home-body')

@section('header')
  @include('layouts.header-public')
@endsection

@section('main-content')
  @php
    $calendarQuery = collect(request()->query())
      ->only(['date', 'location_id', 'status', 'staff_user_id', 'service_id'])
      ->filter(static fn ($value) => ! ($value === null || $value === ''))
      ->all();
    $hasCreateBookingErrors = $errors->hasAny([
      'create_location_id',
      'create_service_id',
      'create_staff_user_id',
      'create_booking_date',
      'create_booking_time',
      'create_customer_name',
      'create_customer_email',
      'create_customer_phone',
      'create_notes',
    ]);
  @endphp
  <section class="booking-table-page">
    @if (session('status'))
      <p class="booking-table-alert" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
      <p class="booking-table-alert booking-table-alert-error" role="alert">{{ $errors->first() }}</p>
    @endif

      <div class="booking-table-layout">
        <div class="booking-table-card">
          <section class="booking-table-controls">
            <div class="booking-filter-head">
              <p class="booking-detail-label">Filtre</p>
              <div class="booking-filter-head-actions">
                <button type="button" class="booking-now-follow-toggle" data-now-follow-toggle aria-pressed="true">
                  <span class="booking-now-follow-text">Auto-følg</span>
                  <span class="booking-now-follow-track" aria-hidden="true">
                    <span class="booking-now-follow-thumb"></span>
                  </span>
                  <span class="booking-now-follow-label" data-now-follow-label>Til</span>
                </button>
              </div>
            </div>

            <form method="GET" class="booking-filter-form booking-filter-form-top">
              <label class="booking-filter-field booking-filter-field-week">
                <span>Dato</span>
                <input type="date" name="date" value="{{ $selectedDateIso }}">
              </label>

              <label class="booking-filter-field booking-filter-field-location">
                <span>Afdeling</span>
                <select name="location_id">
                  @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) $filterState['location_id'] === (string) $location->id)>
                      {{ $location->name }}
                    </option>
                  @endforeach
                </select>
              </label>

              <label class="booking-filter-field booking-filter-field-advanced">
                <span>Medarbejder</span>
                <select name="staff_user_id">
                  <option value="">Alle</option>
                  @foreach ($staffMembers as $staffMember)
                    <option value="{{ $staffMember->id }}" @selected((string) $filterState['staff_user_id'] === (string) $staffMember->id)>
                      {{ $staffMember->name }}
                    </option>
                  @endforeach
                </select>
              </label>

              <label class="booking-filter-field booking-filter-field-advanced">
                <span>Ydelse</span>
                <select name="service_id">
                  <option value="">Alle</option>
                  @foreach ($services as $service)
                    <option value="{{ $service->id }}" @selected((string) $filterState['service_id'] === (string) $service->id)>
                      {{ $service->name }}
                    </option>
                  @endforeach
                </select>
              </label>

              <label class="booking-filter-field booking-filter-field-advanced">
                <span>Status</span>
                <select name="status">
                  <option value="active" @selected($filterState['status'] === 'active')>Aktive</option>
                  <option value="all" @selected($filterState['status'] === 'all')>Alle</option>
                  <option value="confirmed" @selected($filterState['status'] === 'confirmed')>Bekræftet</option>
                  <option value="completed" @selected($filterState['status'] === 'completed')>Gennemført</option>
                  <option value="canceled" @selected($filterState['status'] === 'canceled')>Annulleret</option>
                </select>
              </label>

              <div class="booking-filter-actions booking-filter-actions-advanced">
                <button type="submit">Filtrer</button>
                <a href="{{ $clearFiltersUrl }}" class="booking-filter-reset">Nulstil</a>
              </div>
            </form>

            <div class="booking-mobile-day-nav" data-mobile-day-nav hidden>
              <button type="button" class="booking-mobile-day-button" data-mobile-day-prev aria-label="Forrige dag">
                Forrige
              </button>
              <button type="button" class="booking-mobile-day-label-button" data-mobile-day-picker-toggle aria-label="Vælg dato">
                <span class="booking-mobile-day-label" data-mobile-day-label></span>
              </button>
              <input type="date" class="booking-mobile-day-picker-input" data-mobile-day-picker tabindex="-1" aria-hidden="true">
              <button type="button" class="booking-mobile-day-button" data-mobile-day-next aria-label="Næste dag">
                Næste
              </button>
            </div>
          </section>

          <div class="booking-table-scroll">
            <div
              class="booking-table-grid"
              data-calendar-timezone="{{ $nowIndicator['timezone'] }}"
              data-server-now-utc-ms="{{ $nowIndicator['server_now_utc_ms'] }}"
              data-slot-start-minutes="{{ $nowIndicator['slot_start_minutes'] }}"
              data-slot-end-minutes="{{ $nowIndicator['slot_end_minutes'] }}"
              style="--booking-grid-slot-count: {{ count($timeSlots) }}; --booking-grid-column-count: {{ max(1, count($staffColumns ?? [])) }};"
            >
            <div class="booking-table-corner" style="grid-column: 1; grid-row: 1;">Tid</div>

            @forelse (($staffColumns ?? []) as $staffColumn)
              <div
                class="booking-table-day"
                data-staff-id="{{ $staffColumn['id'] }}"
                data-origin-column="{{ $staffColumn['column'] }}"
                style="grid-column: {{ $staffColumn['column'] }}; grid-row: 1;"
              >
                <div class="booking-table-day-head">
                  <span class="booking-table-day-avatar{{ ! empty($staffColumn['photo_url']) ? ' has-photo' : '' }}" aria-hidden="true">
                    @if (! empty($staffColumn['photo_url']))
                      <img src="{{ $staffColumn['photo_url'] }}" alt="">
                    @else
                      {{ $staffColumn['initials'] }}
                    @endif
                  </span>
                  <strong>{{ $staffColumn['name'] }}</strong>
                </div>
              </div>
            @empty
              <div class="booking-table-day" style="grid-column: 2; grid-row: 1;">
                <strong>Ingen medarbejdere</strong>
                <span>Tilføj en aktiv medarbejder</span>
              </div>
            @endforelse

            @foreach ($timeSlots as $time)
              @php
                $row = $slotRows[$time];
                $isHour = substr($time, -2) === '00';
              @endphp

              <div class="booking-table-time{{ $isHour ? ' is-hour' : '' }}" style="grid-column: 1; grid-row: {{ $row }};">
                {{ $isHour ? $time : '' }}
              </div>

              @foreach (($staffColumns ?? []) as $staffColumn)
                @php
                  $isOpenGridSlot = (bool) data_get($openGridSlotsByStaff ?? [], $staffColumn['id'] . '.' . $time, false);
                @endphp
                <div
                  class="booking-table-cell{{ $isHour ? ' is-hour' : '' }}{{ $isOpenGridSlot ? '' : ' is-closed' }}"
                  data-staff-id="{{ $staffColumn['id'] }}"
                  data-origin-column="{{ $staffColumn['column'] }}"
                  style="grid-column: {{ $staffColumn['column'] }}; grid-row: {{ $row }};"
                >
                  @if ($isOpenGridSlot)
                    <button
                      type="button"
                      class="booking-table-cell-add"
                      data-create-booking-trigger
                      data-create-date="{{ $selectedDateIso }}"
                      data-create-time="{{ $time }}"
                      data-create-staff-id="{{ $staffColumn['id'] }}"
                      aria-label="Opret booking for {{ $staffColumn['name'] }} kl. {{ $time }}"
                    >
                      +
                    </button>
                  @endif
                </div>
              @endforeach
            @endforeach

            @foreach ($calendarBookings as $booking)
              <article
                class="booking-table-slot{{ $booking['compact'] ? ' is-compact' : '' }}{{ $booking['is_completed'] ? ' is-completed' : '' }}{{ $booking['is_selected'] ? ' is-selected' : '' }}"
                data-staff-id="{{ $booking['staff_user_id'] }}"
                data-origin-column="{{ $booking['column'] }}"
                style="
                  grid-column: {{ $booking['column'] }};
                  grid-row: {{ $booking['start_row'] }} / span {{ $booking['row_span'] }};
                  background: {{ $booking['slot_background'] }};
                  border-color: {{ $booking['slot_border'] }};
                  box-shadow: inset 0 0 0 1px {{ $booking['slot_border_soft'] }};
                "
                data-select-url="{{ $booking['select_url'] }}"
                data-booking-customer="{{ $booking['customer'] }}"
                data-booking-service="{{ $booking['service'] }}"
                data-booking-service-duration="{{ $booking['service_duration'] }}"
                data-booking-time="{{ $booking['time_range'] }}"
                data-booking-staff="{{ $booking['staff_name'] }}"
                data-booking-location="{{ $booking['location_name'] }}"
                data-booking-status="{{ $booking['status'] }}"
                data-booking-status-label="{{ $booking['status_label'] }}"
                data-booking-staff-id="{{ $booking['staff_user_id'] }}"
                data-booking-date="{{ $booking['booking_date_input'] }}"
                data-booking-time="{{ $booking['booking_time_input'] }}"
                data-booking-service-id="{{ $booking['service_id'] }}"
                data-booking-email="{{ $booking['customer_email'] ?? '' }}"
                data-booking-phone="{{ $booking['customer_phone'] ?? '' }}"
                data-booking-notes="{{ $booking['notes'] ?? '' }}"
                data-booking-can-edit="{{ $booking['can_edit'] ? '1' : '0' }}"
                data-booking-update-url="{{ $booking['update_url'] }}"
                data-booking-can-complete="{{ $booking['show_complete'] ? '1' : '0' }}"
                data-booking-can-cancel="{{ $booking['can_cancel'] ? '1' : '0' }}"
                data-booking-complete-url="{{ $booking['show_complete'] ? route('bookings.complete', $booking['id']) : '' }}"
                data-booking-cancel-url="{{ $booking['can_cancel'] ? route('bookings.cancel', $booking['id']) : '' }}"
                tabindex="0"
                role="button"
                aria-label="Vis bookingdetaljer for {{ $booking['customer'] }}"
              >
                <strong class="booking-table-slot-customer">{{ $booking['customer'] }}</strong>
                @unless ($booking['compact'])
                  <span class="booking-table-slot-service">{{ $booking['service'] }}</span>
                @endunless
                <div class="booking-table-slot-meta">
                  <span class="booking-table-slot-time">{{ $booking['time_range'] }}</span>
                  <span class="booking-table-slot-status booking-table-slot-status-{{ $booking['status'] }}">{{ $booking['status_label'] }}</span>
                </div>
              </article>
            @endforeach

            <div class="booking-now-line" data-now-line hidden>
              <span class="booking-now-line-dot" aria-hidden="true"></span>
              <span class="booking-now-line-label" data-now-line-label></span>
            </div>
          </div>
        </div>
      </div>

      <aside class="booking-side-column">
        <section class="booking-detail-card">
        @if ($selectedBooking)
          <div class="booking-detail-head">
            <p class="booking-detail-label">Bookingdetaljer</p>
            <h2>{{ $selectedBooking['customer_name'] }}</h2>
            <span class="booking-table-slot-status booking-table-slot-status-{{ $selectedBooking['status'] }}">{{ $selectedBooking['status_label'] }}</span>
          </div>

          <dl class="booking-detail-list">
            <div>
              <dt>Ydelse</dt>
              <dd>{{ $selectedBooking['service_name'] }} ({{ $selectedBooking['service_duration'] }} min)</dd>
            </div>
            <div>
              <dt>Lokation</dt>
              <dd>{{ $selectedBooking['location_name'] }}</dd>
            </div>
            <div>
              <dt>Medarbejder</dt>
              <dd>{{ $selectedBooking['staff_name'] }}</dd>
            </div>
            <div>
              <dt>Tid</dt>
              <dd>{{ $selectedBooking['starts_at_human'] }} - {{ $selectedBooking['ends_at_human'] }}</dd>
            </div>
            <div>
              <dt>E-mail</dt>
              <dd>{{ $selectedBooking['customer_email'] ?: 'Ikke oplyst' }}</dd>
            </div>
            <div>
              <dt>Telefon</dt>
              <dd>{{ $selectedBooking['customer_phone'] ?: 'Ikke oplyst' }}</dd>
            </div>
            <div>
              <dt>Noter</dt>
              <dd>{{ $selectedBooking['notes'] ?: 'Ingen noter' }}</dd>
            </div>
          </dl>

          <div class="booking-detail-history">
            <p>Historik</p>
            <ul>
              @foreach ($selectedBooking['history'] as $item)
                <li><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
              @endforeach
            </ul>
          </div>

          <div class="booking-detail-actions">
            @if ($selectedBooking['can_complete'])
              <form method="POST" action="{{ route('bookings.complete', $selectedBooking['id']) }}" onsubmit="return confirm('Markere bookingen som gennemført?');">
                @csrf
                @method('PATCH')
                <button type="submit" class="booking-table-slot-action booking-table-slot-action-complete">Marker som færdig</button>
              </form>
            @endif

            @if ($selectedBooking['can_cancel'])
              <form method="POST" action="{{ route('bookings.cancel', $selectedBooking['id']) }}" onsubmit="return confirm('Er du sikker på, at bookingen skal annulleres?');">
                @csrf
                @method('PATCH')
                <button type="submit" class="booking-table-slot-action booking-table-slot-action-cancel">Annuller booking</button>
              </form>
            @endif
          </div>

          <form
            class="booking-detail-form"
            method="POST"
            action="{{ route('bookings.update', $selectedBooking['id']) }}"
            data-booking-detail-form
            data-time-options-url="{{ route('booking-calender.time-options') }}"
            data-location-id="{{ $filterState['location_id'] }}"
          >
            @csrf
            @method('PATCH')
            <input type="hidden" name="service_id" value="{{ $selectedBooking['service_id'] }}">

            <h3>Rediger tid og medarbejder</h3>

            <label>
              <span>Dato</span>
              <input
                type="date"
                name="booking_date"
                value="{{ $selectedBooking['booking_date_input'] }}"
                required
                data-booking-detail-date
                @disabled(! $selectedBooking['can_edit'])
              >
            </label>

            <label>
              <span>Tidspunkt</span>
              <select name="booking_time" required data-booking-detail-time @disabled(! $selectedBooking['can_edit'])>
                @foreach ($timeOptions as $timeOption)
                  <option value="{{ $timeOption }}" @selected($selectedBooking['booking_time_input'] === $timeOption)>{{ $timeOption }}</option>
                @endforeach
              </select>
            </label>

            <label>
              <span>Medarbejder</span>
              <select name="staff_user_id" required @disabled(! $selectedBooking['can_edit'])>
                @foreach ($staffMembers as $staffMember)
                  <option value="{{ $staffMember->id }}" @selected((string) $selectedBooking['staff_user_id_input'] === (string) $staffMember->id)>
                    {{ $staffMember->name }}
                  </option>
                @endforeach
              </select>
            </label>

            <button type="submit" @disabled(! $selectedBooking['can_edit'])>Gem ændringer</button>
          </form>
        @else
          <div class="booking-detail-empty">
            <h2>Vælg en booking</h2>
            <p>Klik pa en booking i kalenderen for at se detaljer, historik og hurtige handlinger.</p>
          </div>
        @endif

        </section>
      </aside>
    </div>
  </section>

  <div class="booking-mobile-modal" data-mobile-booking-modal hidden>
    <button type="button" class="booking-mobile-modal-backdrop" data-mobile-booking-close aria-label="Luk"></button>
    <section class="booking-mobile-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mobile-booking-title">
      <div class="booking-mobile-modal-head">
        <p class="booking-detail-label">Bookingdetaljer</p>
        <button type="button" class="booking-mobile-modal-close" data-mobile-booking-close aria-label="Luk">Luk</button>
      </div>
      <h2 id="mobile-booking-title" data-mobile-booking-customer>Booking</h2>
      <span class="booking-table-slot-status" data-mobile-booking-status></span>

      <dl class="booking-detail-list booking-mobile-detail-list">
        <div>
          <dt>Ydelse</dt>
          <dd data-mobile-booking-service>-</dd>
        </div>
        <div>
          <dt>Lokation</dt>
          <dd data-mobile-booking-location>-</dd>
        </div>
        <div>
          <dt>Medarbejder</dt>
          <dd data-mobile-booking-staff>-</dd>
        </div>
        <div>
          <dt>Tid</dt>
          <dd data-mobile-booking-time>-</dd>
        </div>
        <div>
          <dt>E-mail</dt>
          <dd data-mobile-booking-email>-</dd>
        </div>
        <div>
          <dt>Telefon</dt>
          <dd data-mobile-booking-phone>-</dd>
        </div>
        <div>
          <dt>Noter</dt>
          <dd data-mobile-booking-notes>-</dd>
        </div>
      </dl>

      <div class="booking-mobile-modal-actions">
        <form
          method="POST"
          class="booking-mobile-edit-form"
          data-mobile-booking-edit-form
          data-time-options-url="{{ route('booking-calender.time-options') }}"
          data-location-id="{{ $filterState['location_id'] }}"
          hidden
        >
          @csrf
          @method('PATCH')
          <input type="hidden" name="service_id" value="" data-mobile-booking-edit-service>

          <label>
            <span>Dato</span>
            <input type="date" name="booking_date" data-mobile-booking-edit-date required>
          </label>

          <label>
            <span>Tidspunkt</span>
            <select name="booking_time" data-mobile-booking-edit-time required>
              <option value="">Vælg tidspunkt</option>
              @foreach ($timeOptions as $timeOption)
                <option value="{{ $timeOption }}">{{ $timeOption }}</option>
              @endforeach
            </select>
          </label>

          <label>
            <span>Medarbejder</span>
            <select name="staff_user_id" data-mobile-booking-edit-staff required>
              @foreach ($staffMembers as $staffMember)
                <option value="{{ $staffMember->id }}">{{ $staffMember->name }}</option>
              @endforeach
            </select>
          </label>

          <button type="submit">Gem ændringer</button>
        </form>

        <form
          method="POST"
          data-mobile-booking-complete-form
          hidden
          onsubmit="return confirm('Markere bookingen som gennemført?');"
        >
          @csrf
          @method('PATCH')
          <button type="submit" class="booking-table-slot-action booking-table-slot-action-complete">Marker som færdig</button>
        </form>
        <form
          method="POST"
          data-mobile-booking-cancel-form
          hidden
          onsubmit="return confirm('Er du sikker på, at bookingen skal annulleres?');"
        >
          @csrf
          @method('PATCH')
          <button type="submit" class="booking-table-slot-action booking-table-slot-action-cancel">Annuller booking</button>
        </form>
      </div>
    </section>
  </div>

  <div class="booking-mobile-modal" data-create-booking-modal data-open-on-load="{{ $hasCreateBookingErrors ? '1' : '0' }}" hidden>
    <button type="button" class="booking-mobile-modal-backdrop" data-create-booking-close aria-label="Luk"></button>
    <section class="booking-mobile-modal-panel" role="dialog" aria-modal="true" aria-labelledby="create-booking-title">
      <div class="booking-mobile-modal-head">
        <h2 id="create-booking-title">Opret booking</h2>
        <button type="button" class="booking-mobile-modal-close" data-create-booking-close aria-label="Luk">Luk</button>
      </div>

      <form
        class="booking-mobile-create-form"
        method="POST"
        action="{{ route('bookings.store', $calendarQuery) }}"
        data-create-booking-form
        data-time-options-url="{{ route('booking-calender.time-options') }}"
        data-location-id="{{ $filterState['location_id'] }}"
      >
        @csrf
        <input type="hidden" name="create_location_id" value="{{ $filterState['location_id'] }}">

        <label>
          <span>Kunde navn</span>
          <input type="text" name="create_customer_name" value="{{ old('create_customer_name') }}" required>
        </label>

        <label>
          <span>E-mail</span>
          <input type="email" name="create_customer_email" value="{{ old('create_customer_email') }}" placeholder="Valgfri">
        </label>

        <label>
          <span>Telefon</span>
          <input type="text" name="create_customer_phone" value="{{ old('create_customer_phone') }}" placeholder="Valgfri">
        </label>

        <label>
          <span>Ydelse</span>
          <select name="create_service_id" required>
            <option value="">Vælg ydelse</option>
            @foreach ($services as $service)
              <option value="{{ $service->id }}" @selected((string) old('create_service_id') === (string) $service->id)>
                {{ $service->name }}
              </option>
            @endforeach
          </select>
        </label>

        <label>
          <span>Medarbejder</span>
          <select name="create_staff_user_id" required>
            <option value="">Vælg medarbejder</option>
            @foreach ($staffMembers as $staffMember)
              <option value="{{ $staffMember->id }}" @selected((string) old('create_staff_user_id') === (string) $staffMember->id)>
                {{ $staffMember->name }}
              </option>
            @endforeach
          </select>
        </label>

        <label>
          <span>Dato</span>
          <input
            type="date"
            name="create_booking_date"
            value="{{ data_get($createBooking ?? [], 'date_input') }}"
            required
            data-create-booking-date
          >
        </label>

        <label>
          <span>Tidspunkt</span>
          <select name="create_booking_time" required data-create-booking-time>
            <option value="">Vælg tidspunkt</option>
            @foreach (data_get($createBooking ?? [], 'time_options', []) as $timeOption)
              <option value="{{ $timeOption }}" @selected((string) data_get($createBooking ?? [], 'selected_time', '') === (string) $timeOption)>
                {{ $timeOption }}
              </option>
            @endforeach
          </select>
        </label>

        <label>
          <span>Note</span>
          <input type="text" name="create_notes" value="{{ old('create_notes') }}" maxlength="1000" placeholder="Valgfri note">
        </label>

        <button type="submit">Opret booking</button>
      </form>
    </section>
  </div>

  <div class="booking-mobile-modal" data-mobile-filter-modal hidden>
    <button type="button" class="booking-mobile-modal-backdrop" data-mobile-filter-close aria-label="Luk"></button>
    <section class="booking-mobile-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mobile-filter-title">
      <form method="GET" class="booking-mobile-filter-form">
        <input type="hidden" name="date" value="{{ $selectedDateIso }}">
        <input type="hidden" name="location_id" value="{{ $filterState['location_id'] }}">
        <div class="booking-mobile-modal-head">
          <h2 id="mobile-filter-title">Flere filtre</h2>
          <button type="button" class="booking-mobile-modal-close" data-mobile-filter-close aria-label="Luk">Luk</button>
        </div>

        <label class="booking-filter-field">
          <span>Medarbejder</span>
          <select name="staff_user_id">
            <option value="">Alle</option>
            @foreach ($staffMembers as $staffMember)
              <option value="{{ $staffMember->id }}" @selected((string) $filterState['staff_user_id'] === (string) $staffMember->id)>
                {{ $staffMember->name }}
              </option>
            @endforeach
          </select>
        </label>

        <label class="booking-filter-field">
          <span>Ydelse</span>
          <select name="service_id">
            <option value="">Alle</option>
            @foreach ($services as $service)
              <option value="{{ $service->id }}" @selected((string) $filterState['service_id'] === (string) $service->id)>
                {{ $service->name }}
              </option>
            @endforeach
          </select>
        </label>

        <label class="booking-filter-field">
          <span>Status</span>
          <select name="status">
            <option value="active" @selected($filterState['status'] === 'active')>Aktive</option>
            <option value="all" @selected($filterState['status'] === 'all')>Alle</option>
            <option value="confirmed" @selected($filterState['status'] === 'confirmed')>Bekræftet</option>
            <option value="completed" @selected($filterState['status'] === 'completed')>Gennemført</option>
            <option value="canceled" @selected($filterState['status'] === 'canceled')>Annulleret</option>
          </select>
        </label>

        <div class="booking-filter-actions">
          <button type="submit">Anvend filtre</button>
          <button type="button" class="booking-filter-reset" data-mobile-filter-reset>Nulstil</button>
        </div>
      </form>
    </section>
  </div>

  <script>
    (() => {
      const slots = document.querySelectorAll('.booking-table-slot[data-select-url]');
      const mobileMediaQuery = window.matchMedia('(max-width: 980px)');
      const bookingModal = document.querySelector('[data-mobile-booking-modal]');
      const bookingModalCloseButtons = document.querySelectorAll('[data-mobile-booking-close]');
      const bookingModalCustomer = bookingModal?.querySelector('[data-mobile-booking-customer]');
      const bookingModalService = bookingModal?.querySelector('[data-mobile-booking-service]');
      const bookingModalLocation = bookingModal?.querySelector('[data-mobile-booking-location]');
      const bookingModalStaff = bookingModal?.querySelector('[data-mobile-booking-staff]');
      const bookingModalTime = bookingModal?.querySelector('[data-mobile-booking-time]');
      const bookingModalEmail = bookingModal?.querySelector('[data-mobile-booking-email]');
      const bookingModalPhone = bookingModal?.querySelector('[data-mobile-booking-phone]');
      const bookingModalNotes = bookingModal?.querySelector('[data-mobile-booking-notes]');
      const bookingModalStatus = bookingModal?.querySelector('[data-mobile-booking-status]');
      const bookingModalEditForm = bookingModal?.querySelector('[data-mobile-booking-edit-form]');
      const bookingModalEditDateField = bookingModalEditForm?.querySelector('[data-mobile-booking-edit-date]');
      const bookingModalEditTimeField = bookingModalEditForm?.querySelector('[data-mobile-booking-edit-time]');
      const bookingModalEditStaffField = bookingModalEditForm?.querySelector('[data-mobile-booking-edit-staff]');
      const bookingModalEditServiceField = bookingModalEditForm?.querySelector('[data-mobile-booking-edit-service]');
      const bookingDetailForm = document.querySelector('[data-booking-detail-form]');
      const bookingDetailDateField = bookingDetailForm?.querySelector('[data-booking-detail-date]');
      const bookingDetailTimeField = bookingDetailForm?.querySelector('[data-booking-detail-time]');
      const bookingDetailStaffField = bookingDetailForm?.querySelector('select[name="staff_user_id"]');
      const bookingModalCompleteForm = bookingModal?.querySelector('[data-mobile-booking-complete-form]');
      const bookingModalCancelForm = bookingModal?.querySelector('[data-mobile-booking-cancel-form]');
      const createBookingModal = document.querySelector('[data-create-booking-modal]');
      const createBookingCloseButtons = document.querySelectorAll('[data-create-booking-close]');
      const createBookingTriggers = document.querySelectorAll('[data-create-booking-trigger]');
      const filterModal = document.querySelector('[data-mobile-filter-modal]');
      const filterModalCloseButtons = document.querySelectorAll('[data-mobile-filter-close]');
      const filterModalForm = filterModal?.querySelector('.booking-mobile-filter-form');
      const filterModalHiddenDate = filterModalForm?.querySelector('input[name="date"]');
      const filterModalHiddenLocation = filterModalForm?.querySelector('input[name="location_id"]');
      const filterModalResetButton = filterModalForm?.querySelector('[data-mobile-filter-reset]');
      const topFilterForm = document.querySelector('.booking-filter-form-top');
      const topDateInput = topFilterForm?.querySelector('input[name="date"]');
      const topLocationInput = topFilterForm?.querySelector('select[name="location_id"]');
      const topStaffInput = topFilterForm?.querySelector('select[name="staff_user_id"]');
      const topServiceInput = topFilterForm?.querySelector('select[name="service_id"]');
      const topStatusInput = topFilterForm?.querySelector('select[name="status"]');
      const createBookingForm = document.querySelector('[data-create-booking-form]');
      const createBookingDateField = createBookingForm?.querySelector('[data-create-booking-date]');
      const createBookingTimeField = createBookingForm?.querySelector('[data-create-booking-time]');
      const createBookingServiceField = createBookingForm?.querySelector('select[name="create_service_id"]');
      const createBookingStaffField = createBookingForm?.querySelector('select[name="create_staff_user_id"]');
      const createBookingNameField = createBookingForm?.querySelector('input[name="create_customer_name"]');
      const setBodyModalState = () => {
        const modalOpen =
          (bookingModal && !bookingModal.hidden) ||
          (createBookingModal && !createBookingModal.hidden) ||
          (filterModal && !filterModal.hidden);
        document.body.classList.toggle('is-mobile-modal-open', !!modalOpen);
      };

      const renderTimeOptions = (targetSelect, timeOptions, selectedValue = '') => {
        if (!(targetSelect instanceof HTMLSelectElement)) {
          return;
        }

        while (targetSelect.options.length > 0) {
          targetSelect.remove(0);
        }

        targetSelect.add(new Option('Vælg tidspunkt', ''));

        timeOptions.forEach((timeOption) => {
          targetSelect.add(new Option(timeOption, timeOption));
        });

        if (selectedValue && timeOptions.includes(selectedValue)) {
          targetSelect.value = selectedValue;
          return;
        }

        targetSelect.value = '';
      };

      const refreshTimeOptionsForForm = async ({ form, dateField, timeField, preferredValue = null }) => {
        if (
          !(form instanceof HTMLFormElement) ||
          !(dateField instanceof HTMLInputElement) ||
          !(timeField instanceof HTMLSelectElement)
        ) {
          return;
        }

        const bookingDate = dateField.value.trim();
        const locationId = String(form.dataset.locationId || '').trim();
        const endpoint = String(form.dataset.timeOptionsUrl || '').trim();
        const serviceField = form.querySelector(
          'input[name="service_id"], select[name="service_id"], input[name="create_service_id"], select[name="create_service_id"]'
        );
        const staffField = form.querySelector(
          'input[name="staff_user_id"], select[name="staff_user_id"], input[name="create_staff_user_id"], select[name="create_staff_user_id"]'
        );
        const serviceId = serviceField instanceof HTMLInputElement || serviceField instanceof HTMLSelectElement
          ? serviceField.value.trim()
          : '';
        const staffUserId = staffField instanceof HTMLInputElement || staffField instanceof HTMLSelectElement
          ? staffField.value.trim()
          : '';

        if (bookingDate === '' || locationId === '' || endpoint === '') {
          return;
        }

        const selectedValue = typeof preferredValue === 'string' ? preferredValue : timeField.value;

        try {
          const url = new URL(endpoint, window.location.origin);
          url.searchParams.set('location_id', locationId);
          url.searchParams.set('booking_date', bookingDate);

          if (serviceId !== '') {
            url.searchParams.set('service_id', serviceId);
          } else {
            url.searchParams.delete('service_id');
          }

          if (staffUserId !== '') {
            url.searchParams.set('staff_user_id', staffUserId);
          } else {
            url.searchParams.delete('staff_user_id');
          }

          const response = await fetch(url.toString(), {
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });

          if (!response.ok) {
            return;
          }

          const payload = await response.json();
          const timeOptions = Array.isArray(payload.time_options) ? payload.time_options : [];
          renderTimeOptions(timeField, timeOptions, selectedValue);
        } catch {
          // Keep current options if fetch fails.
        }
      };

      if (
        bookingModalEditForm instanceof HTMLFormElement &&
        bookingModalEditDateField instanceof HTMLInputElement &&
        bookingModalEditTimeField instanceof HTMLSelectElement
      ) {
        bookingModalEditDateField.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: bookingModalEditForm,
            dateField: bookingModalEditDateField,
            timeField: bookingModalEditTimeField,
          });
        });

        bookingModalEditStaffField?.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: bookingModalEditForm,
            dateField: bookingModalEditDateField,
            timeField: bookingModalEditTimeField,
          });
        });
      }

      if (
        bookingDetailForm instanceof HTMLFormElement &&
        bookingDetailDateField instanceof HTMLInputElement &&
        bookingDetailTimeField instanceof HTMLSelectElement
      ) {
        bookingDetailDateField.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: bookingDetailForm,
            dateField: bookingDetailDateField,
            timeField: bookingDetailTimeField,
          });
        });

        bookingDetailStaffField?.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: bookingDetailForm,
            dateField: bookingDetailDateField,
            timeField: bookingDetailTimeField,
          });
        });
      }

      if (
        createBookingForm instanceof HTMLFormElement &&
        createBookingDateField instanceof HTMLInputElement &&
        createBookingTimeField instanceof HTMLSelectElement
      ) {
        createBookingDateField.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: createBookingForm,
            dateField: createBookingDateField,
            timeField: createBookingTimeField,
          });
        });

        createBookingServiceField?.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: createBookingForm,
            dateField: createBookingDateField,
            timeField: createBookingTimeField,
          });
        });

        createBookingStaffField?.addEventListener('change', () => {
          void refreshTimeOptionsForForm({
            form: createBookingForm,
            dateField: createBookingDateField,
            timeField: createBookingTimeField,
          });
        });
      }
      const openCreateBookingModal = async (bookingDate = '', bookingTime = '', staffUserId = '') => {
        if (!createBookingModal) {
          return;
        }

        if (createBookingDateField instanceof HTMLInputElement && bookingDate) {
          createBookingDateField.value = bookingDate;
        }

        if (createBookingStaffField instanceof HTMLSelectElement && staffUserId) {
          createBookingStaffField.value = staffUserId;
        }

        createBookingModal.hidden = false;
        setBodyModalState();

        await refreshTimeOptionsForForm({
          form: createBookingForm,
          dateField: createBookingDateField,
          timeField: createBookingTimeField,
          preferredValue: bookingTime,
        });

        if (createBookingNameField instanceof HTMLInputElement) {
          createBookingNameField.focus();
        }
      };
      const closeCreateBookingModal = () => {
        if (!createBookingModal) {
          return;
        }

        createBookingModal.hidden = true;
        setBodyModalState();
      };
      const openBookingModalFromSlot = async (slot) => {
        if (!bookingModal) {
          return;
        }

        if (bookingModalCustomer) {
          bookingModalCustomer.textContent = slot.getAttribute('data-booking-customer') || 'Booking';
        }

        if (bookingModalService) {
          const service = slot.getAttribute('data-booking-service') || '-';
          const duration = Number(slot.getAttribute('data-booking-service-duration') || '0');
          bookingModalService.textContent = duration > 0 ? `${service} (${duration} min)` : service;
        }

        if (bookingModalLocation) {
          bookingModalLocation.textContent = slot.getAttribute('data-booking-location') || '-';
        }

        if (bookingModalStaff) {
          bookingModalStaff.textContent = slot.getAttribute('data-booking-staff') || '-';
        }

        if (bookingModalTime) {
          bookingModalTime.textContent = slot.getAttribute('data-booking-time') || '-';
        }

        if (bookingModalEmail) {
          bookingModalEmail.textContent = slot.getAttribute('data-booking-email') || 'Ikke oplyst';
        }

        if (bookingModalPhone) {
          bookingModalPhone.textContent = slot.getAttribute('data-booking-phone') || 'Ikke oplyst';
        }

        if (bookingModalNotes) {
          bookingModalNotes.textContent = slot.getAttribute('data-booking-notes') || 'Ingen noter';
        }

        if (bookingModalStatus) {
          const status = slot.getAttribute('data-booking-status') || 'confirmed';
          const statusLabel = slot.getAttribute('data-booking-status-label') || '-';
          bookingModalStatus.className = `booking-table-slot-status booking-table-slot-status-${status}`;
          bookingModalStatus.textContent = statusLabel;
        }

        if (bookingModalEditForm instanceof HTMLFormElement) {
          const canEdit = slot.getAttribute('data-booking-can-edit') === '1';
          const updateUrl = slot.getAttribute('data-booking-update-url') || '';
          const bookingDate = slot.getAttribute('data-booking-date') || '';
          const bookingTime = slot.getAttribute('data-booking-time') || '';
          const staffId = slot.getAttribute('data-booking-staff-id') || '';

          bookingModalEditForm.hidden = !(canEdit && updateUrl !== '');

          if (canEdit && updateUrl !== '') {
            bookingModalEditForm.setAttribute('action', updateUrl);
            const serviceId = slot.getAttribute('data-booking-service-id') || '';

            if (bookingModalEditDateField instanceof HTMLInputElement) {
              bookingModalEditDateField.value = bookingDate;
            }

            if (bookingModalEditStaffField instanceof HTMLSelectElement) {
              bookingModalEditStaffField.value = staffId;
            }

            if (bookingModalEditServiceField instanceof HTMLInputElement) {
              bookingModalEditServiceField.value = serviceId;
            }

            await refreshTimeOptionsForForm({
              form: bookingModalEditForm,
              dateField: bookingModalEditDateField,
              timeField: bookingModalEditTimeField,
              preferredValue: bookingTime,
            });
          }
        }

        if (bookingModalCompleteForm instanceof HTMLFormElement) {
          const canComplete = slot.getAttribute('data-booking-can-complete') === '1';
          const completeUrl = slot.getAttribute('data-booking-complete-url') || '';

          bookingModalCompleteForm.hidden = !(canComplete && completeUrl !== '');

          if (canComplete && completeUrl !== '') {
            bookingModalCompleteForm.setAttribute('action', completeUrl);
          }
        }

        if (bookingModalCancelForm instanceof HTMLFormElement) {
          const canCancel = slot.getAttribute('data-booking-can-cancel') === '1';
          const cancelUrl = slot.getAttribute('data-booking-cancel-url') || '';

          bookingModalCancelForm.hidden = !(canCancel && cancelUrl !== '');

          if (canCancel && cancelUrl !== '') {
            bookingModalCancelForm.setAttribute('action', cancelUrl);
          }
        }

        bookingModal.hidden = false;
        setBodyModalState();
      };
      const closeBookingModal = () => {
        if (!bookingModal) {
          return;
        }

        bookingModal.hidden = true;
        setBodyModalState();
      };
      const openFilterModal = () => {
        if (!filterModal) {
          return;
        }

        if (filterModalHiddenDate && topDateInput) {
          filterModalHiddenDate.value = topDateInput.value || filterModalHiddenDate.value;
        }

        if (filterModalHiddenLocation && topLocationInput) {
          filterModalHiddenLocation.value = topLocationInput.value || filterModalHiddenLocation.value;
        }

        if (filterModalForm && topStaffInput) {
          const staffSelect = filterModalForm.querySelector('select[name="staff_user_id"]');

          if (staffSelect) {
            staffSelect.value = topStaffInput.value;
          }
        }

        if (filterModalForm && topServiceInput) {
          const serviceSelect = filterModalForm.querySelector('select[name="service_id"]');

          if (serviceSelect) {
            serviceSelect.value = topServiceInput.value;
          }
        }

        if (filterModalForm && topStatusInput) {
          const statusSelect = filterModalForm.querySelector('select[name="status"]');

          if (statusSelect) {
            statusSelect.value = topStatusInput.value || 'active';
          }
        }

        filterModal.hidden = false;
        setBodyModalState();
      };
      const closeFilterModal = () => {
        if (!filterModal) {
          return;
        }

        filterModal.hidden = true;
        setBodyModalState();
      };

      createBookingTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();

          const bookingDate = trigger.getAttribute('data-create-date') || '';
          const bookingTime = trigger.getAttribute('data-create-time') || '';
          const staffUserId = trigger.getAttribute('data-create-staff-id') || '';

          void openCreateBookingModal(bookingDate, bookingTime, staffUserId);
        });
      });

      const resolveMobileFilterState = () => {
        if (!filterModalForm) {
          return {
            staffUserId: '',
            serviceId: '',
            status: 'active',
          };
        }

        const formData = new FormData(filterModalForm);

        return {
          staffUserId: String(formData.get('staff_user_id') || ''),
          serviceId: String(formData.get('service_id') || ''),
          status: String(formData.get('status') || 'active'),
        };
      };

      const syncTopFiltersFromMobile = (state) => {
        if (topStaffInput) {
          topStaffInput.value = state.staffUserId;
        }

        if (topServiceInput) {
          topServiceInput.value = state.serviceId;
        }

        if (topStatusInput) {
          topStatusInput.value = state.status;
        }
      };

      const syncFilterQueryState = (state) => {
        const url = new URL(window.location.href);

        if (state.staffUserId) {
          url.searchParams.set('staff_user_id', state.staffUserId);
        } else {
          url.searchParams.delete('staff_user_id');
        }

        if (state.serviceId) {
          url.searchParams.set('service_id', state.serviceId);
        } else {
          url.searchParams.delete('service_id');
        }

        if (state.status && state.status !== 'active') {
          url.searchParams.set('status', state.status);
        } else {
          url.searchParams.delete('status');
        }

        url.searchParams.delete('selected_booking');
        window.history.replaceState({}, '', url.toString());
      };

      const applyMobileSlotFilters = (state) => {
        if (!mobileMediaQuery.matches) {
          slots.forEach((slot) => {
            slot.classList.remove('is-filter-hidden');
          });

          return;
        }

        slots.forEach((slot) => {
          const slotStaffId = slot.getAttribute('data-booking-staff-id') || '';
          const slotServiceId = slot.getAttribute('data-booking-service-id') || '';
          const slotStatus = slot.getAttribute('data-booking-status') || '';

          const matchesStaff = state.staffUserId === '' || slotStaffId === state.staffUserId;
          const matchesService = state.serviceId === '' || slotServiceId === state.serviceId;
          const matchesStatus = state.status === 'all'
            ? true
            : state.status === 'active'
              ? (slotStatus === 'confirmed' || slotStatus === 'completed')
              : slotStatus === state.status;

          slot.classList.toggle('is-filter-hidden', !(matchesStaff && matchesService && matchesStatus));
        });
      };

      slots.forEach((slot) => {
        slot.addEventListener('click', (event) => {
          if (event.target.closest('form, button, a, input, select, textarea')) {
            return;
          }

          void openBookingModalFromSlot(slot);
        });

        slot.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') {
            return;
          }

          event.preventDefault();
          void openBookingModalFromSlot(slot);
        });
      });

      const grid = document.querySelector('.booking-table-grid[data-calendar-timezone]');
      const scrollContainer = document.querySelector('.booking-table-scroll');
      const nowLine = grid?.querySelector('[data-now-line]');
      const nowLineLabel = grid?.querySelector('[data-now-line-label]');
      const autoFollowToggle = document.querySelector('[data-now-follow-toggle]');
      const autoFollowToggleLabel = autoFollowToggle?.querySelector('[data-now-follow-label]');
      const tableControls = document.querySelector('.booking-table-controls');
      const mobileFiltersToggle = tableControls?.querySelector('[data-mobile-filters-toggle]');
      const topAdvancedActions = topFilterForm?.querySelector('.booking-filter-actions');

      if (!grid || !scrollContainer || !nowLine || !nowLineLabel) {
        return;
      }

      const enforceMobileHeaderActions = () => {
        if (!topAdvancedActions) {
          return;
        }

        if (mobileMediaQuery.matches) {
          topAdvancedActions.style.setProperty('display', 'none', 'important');
          topAdvancedActions.setAttribute('hidden', 'hidden');
          return;
        }

        topAdvancedActions.style.removeProperty('display');
        topAdvancedActions.removeAttribute('hidden');
      };

      const timezone = grid.getAttribute('data-calendar-timezone') || 'UTC';
      const serverNowUtcMs = Number(grid.getAttribute('data-server-now-utc-ms') || '0');
      const slotStartMinutes = Number(grid.getAttribute('data-slot-start-minutes') || '420');
      const slotEndMinutes = Number(grid.getAttribute('data-slot-end-minutes') || '1380');
      const perfStart = window.performance.now();
      let timeFormatter;
      let labelFormatter;
      let dayLabelFormatter;

      try {
        timeFormatter = new Intl.DateTimeFormat('en-CA', {
          timeZone: timezone,
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hourCycle: 'h23',
        });
        labelFormatter = new Intl.DateTimeFormat('da-DK', {
          timeZone: timezone,
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        });
        dayLabelFormatter = new Intl.DateTimeFormat('da-DK', {
          timeZone: timezone,
          weekday: 'short',
          day: '2-digit',
          month: '2-digit',
        });
      } catch {
        timeFormatter = new Intl.DateTimeFormat('en-CA', {
          timeZone: 'UTC',
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hourCycle: 'h23',
        });
        labelFormatter = new Intl.DateTimeFormat('da-DK', {
          timeZone: 'UTC',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        });
        dayLabelFormatter = new Intl.DateTimeFormat('da-DK', {
          timeZone: 'UTC',
          weekday: 'short',
          day: '2-digit',
          month: '2-digit',
        });
      }

      let autoFollow = true;
      const mobileDayNav = document.querySelector('[data-mobile-day-nav]');
      const mobileDayLabel = mobileDayNav?.querySelector('[data-mobile-day-label]');
      const mobileDayPickerToggle = mobileDayNav?.querySelector('[data-mobile-day-picker-toggle]');
      const mobileDayPickerInput = mobileDayNav?.querySelector('[data-mobile-day-picker]');
      const mobileDayPrevButton = mobileDayNav?.querySelector('[data-mobile-day-prev]');
      const mobileDayNextButton = mobileDayNav?.querySelector('[data-mobile-day-next]');

      const applyAutoFollowButtonState = () => {
        if (!autoFollowToggle) {
          return;
        }

        autoFollowToggle.setAttribute('aria-pressed', autoFollow ? 'true' : 'false');
        autoFollowToggle.setAttribute('aria-label', autoFollow ? 'Auto-følg er til' : 'Auto-følg er fra');

        if (autoFollowToggleLabel) {
          autoFollowToggleLabel.textContent = autoFollow ? 'Til' : 'Fra';
        }
      };

      const resolveCurrentUtcMs = () => {
        if (Number.isFinite(serverNowUtcMs) && serverNowUtcMs > 0) {
          return serverNowUtcMs + (window.performance.now() - perfStart);
        }

        return Date.now();
      };

      const resolveZonedNow = () => {
        const utcMs = resolveCurrentUtcMs();
        const parts = timeFormatter.formatToParts(new Date(utcMs));
        const values = {};

        parts.forEach((part) => {
          if (part.type !== 'literal') {
            values[part.type] = part.value;
          }
        });

        if (!values.year || !values.month || !values.day || !values.hour || !values.minute) {
          return null;
        }

        const hour = Number(values.hour);
        const minute = Number(values.minute);
        const second = Number(values.second || '0');

        if (!Number.isFinite(hour) || !Number.isFinite(minute) || !Number.isFinite(second)) {
          return null;
        }

        return {
          utcMs,
          dateKey: `${values.year}-${values.month}-${values.day}`,
          minutesOfDay: (hour * 60) + minute + (second / 60),
        };
      };

      const setMobileFiltersOpen = (open) => {
        if (!tableControls || !mobileFiltersToggle) {
          return;
        }

        const shouldOpen = mobileMediaQuery.matches && open;
        tableControls.classList.remove('is-filters-open');
        mobileFiltersToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        mobileFiltersToggle.textContent = 'Flere filtre';
      };

      const parseIsoDate = (isoDate) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);

        if (!match) {
          return null;
        }

        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const utcDate = new Date(Date.UTC(year, month - 1, day));

        if (Number.isNaN(utcDate.getTime())) {
          return null;
        }

        return utcDate;
      };

      const formatIsoDate = (dateObject) => {
        if (!(dateObject instanceof Date) || Number.isNaN(dateObject.getTime())) {
          return '';
        }

        return `${dateObject.getUTCFullYear()}-${String(dateObject.getUTCMonth() + 1).padStart(2, '0')}-${String(dateObject.getUTCDate()).padStart(2, '0')}`;
      };

      const navigateCalendarDate = (deltaDays = 0) => {
        if (!(topDateInput instanceof HTMLInputElement)) {
          return;
        }

        const currentDate = parseIsoDate(topDateInput.value);

        if (!currentDate) {
          return;
        }

        const nextDate = new Date(currentDate.getTime());
        nextDate.setUTCDate(nextDate.getUTCDate() + deltaDays);
        const nextIso = formatIsoDate(nextDate);

        if (!nextIso) {
          return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('date', nextIso);
        url.searchParams.delete('week');
        url.searchParams.delete('selected_booking');
        window.location.href = url.toString();
      };

      const updateMobileDateUi = () => {
        if (!mobileDayNav || !mobileDayLabel || !(topDateInput instanceof HTMLInputElement)) {
          return;
        }

        if (mobileMediaQuery.matches) {
          mobileDayNav.removeAttribute('hidden');
        } else {
          mobileDayNav.setAttribute('hidden', 'hidden');
        }

        const currentDate = parseIsoDate(topDateInput.value);

        if (!currentDate) {
          mobileDayLabel.textContent = topDateInput.value || '-';
          return;
        }

        if (mobileDayPickerInput instanceof HTMLInputElement) {
          mobileDayPickerInput.value = topDateInput.value;
        }

        mobileDayLabel.textContent = dayLabelFormatter.format(currentDate);
      };

      const resolveRowMetrics = () => {
        const timeCells = grid.querySelectorAll('.booking-table-time');
        const first = timeCells[0];

        if (!first) {
          return null;
        }

        const second = timeCells[1] || null;
        const rowHeight = second
          ? Math.max(1, second.offsetTop - first.offsetTop)
          : Math.max(1, first.offsetHeight);

        return {
          slotTop: first.offsetTop,
          rowHeight,
        };
      };

      const centerLineInView = (smooth) => {
        if (!autoFollow || nowLine.hidden) {
          return;
        }

        const target = nowLine.offsetTop - (scrollContainer.clientHeight / 2);
        const maxScroll = Math.max(0, scrollContainer.scrollHeight - scrollContainer.clientHeight);
        const top = Math.max(0, Math.min(maxScroll, target));

        scrollContainer.scrollTo({
          top,
          behavior: smooth ? 'smooth' : 'auto',
        });
      };

      const renderNowLine = (smooth) => {
        const zonedNow = resolveZonedNow();
        const rowMetrics = resolveRowMetrics();

        if (!zonedNow || !rowMetrics) {
          nowLine.hidden = true;
          return;
        }

        const selectedDateKey = topDateInput instanceof HTMLInputElement
          ? topDateInput.value
          : '';

        if (selectedDateKey !== '' && selectedDateKey !== zonedNow.dateKey) {
          nowLine.hidden = true;
          return;
        }

        if (zonedNow.minutesOfDay < slotStartMinutes || zonedNow.minutesOfDay > slotEndMinutes) {
          nowLine.hidden = true;
          return;
        }

        const slotOffset = (zonedNow.minutesOfDay - slotStartMinutes) / 15;
        const top = rowMetrics.slotTop + (slotOffset * rowMetrics.rowHeight);
        const fullGridWidth = Math.max(grid.scrollWidth, grid.clientWidth);
        nowLine.style.left = '0px';
        nowLine.style.width = `${fullGridWidth}px`;

        nowLine.style.top = `${top}px`;
        nowLine.hidden = false;
        nowLineLabel.textContent = `Nu ${labelFormatter.format(new Date(zonedNow.utcMs))}`;

        centerLineInView(smooth);
      };

      autoFollowToggle?.addEventListener('click', () => {
        autoFollow = !autoFollow;

        applyAutoFollowButtonState();
        renderNowLine(true);
      });

      mobileDayPrevButton?.addEventListener('click', () => {
        navigateCalendarDate(-1);
      });

      mobileDayNextButton?.addEventListener('click', () => {
        navigateCalendarDate(1);
      });

      mobileDayPickerToggle?.addEventListener('click', () => {
        if (!mobileMediaQuery.matches || !(mobileDayPickerInput instanceof HTMLInputElement)) {
          return;
        }

        if (topDateInput instanceof HTMLInputElement && topDateInput.value) {
          mobileDayPickerInput.value = topDateInput.value;
        }

        if (typeof mobileDayPickerInput.showPicker === 'function') {
          mobileDayPickerInput.showPicker();
          return;
        }

        mobileDayPickerInput.focus({ preventScroll: true });
        mobileDayPickerInput.click();
      });

      mobileDayPickerInput?.addEventListener('change', () => {
        if (!(mobileDayPickerInput instanceof HTMLInputElement)) {
          return;
        }

        const pickedDate = mobileDayPickerInput.value;

        if (!pickedDate) {
          return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('date', pickedDate);
        url.searchParams.delete('week');
        url.searchParams.delete('selected_booking');
        window.location.href = url.toString();
      });

      filterModalForm?.addEventListener('submit', (event) => {
        if (!mobileMediaQuery.matches) {
          return;
        }

        event.preventDefault();

        const state = resolveMobileFilterState();
        syncTopFiltersFromMobile(state);
        syncFilterQueryState(state);
        applyMobileSlotFilters(state);
        closeFilterModal();
      });

      filterModalResetButton?.addEventListener('click', () => {
        if (!filterModalForm) {
          return;
        }

        const staffSelect = filterModalForm.querySelector('select[name="staff_user_id"]');
        const serviceSelect = filterModalForm.querySelector('select[name="service_id"]');
        const statusSelect = filterModalForm.querySelector('select[name="status"]');

        if (staffSelect) {
          staffSelect.value = '';
        }

        if (serviceSelect) {
          serviceSelect.value = '';
        }

        if (statusSelect) {
          statusSelect.value = 'active';
        }

        const state = resolveMobileFilterState();
        syncTopFiltersFromMobile(state);
        syncFilterQueryState(state);
        applyMobileSlotFilters(state);
        closeFilterModal();
      });

      topLocationInput?.addEventListener('change', () => {
        if (!mobileMediaQuery.matches || !topFilterForm) {
          return;
        }

        topFilterForm.requestSubmit();
      });

      topDateInput?.addEventListener('change', () => {
        updateMobileDateUi();
      });

      bookingModalCloseButtons.forEach((button) => {
        button.addEventListener('click', closeBookingModal);
      });

      createBookingCloseButtons.forEach((button) => {
        button.addEventListener('click', closeCreateBookingModal);
      });

      filterModalCloseButtons.forEach((button) => {
        button.addEventListener('click', closeFilterModal);
      });

      if (createBookingModal?.dataset.openOnLoad === '1') {
        const initialDate = createBookingDateField instanceof HTMLInputElement
          ? createBookingDateField.value
          : '';
        const initialTime = createBookingTimeField instanceof HTMLSelectElement
          ? createBookingTimeField.value
          : '';
        void openCreateBookingModal(initialDate, initialTime);
      }

      mobileFiltersToggle?.addEventListener('click', () => {
        if (!mobileMediaQuery.matches) {
          return;
        }

        openFilterModal();
      });

      document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
          return;
        }

        closeBookingModal();
        closeCreateBookingModal();
        closeFilterModal();
      });

      if (typeof mobileMediaQuery.addEventListener === 'function') {
        mobileMediaQuery.addEventListener('change', () => {
          enforceMobileHeaderActions();
          updateMobileDateUi();
          renderNowLine(false);
          applyMobileSlotFilters(resolveMobileFilterState());
          setMobileFiltersOpen(false);
          closeBookingModal();
          closeCreateBookingModal();
          closeFilterModal();
        });
      } else if (typeof mobileMediaQuery.addListener === 'function') {
        mobileMediaQuery.addListener(() => {
          enforceMobileHeaderActions();
          updateMobileDateUi();
          renderNowLine(false);
          applyMobileSlotFilters(resolveMobileFilterState());
          setMobileFiltersOpen(false);
          closeBookingModal();
          closeCreateBookingModal();
          closeFilterModal();
        });
      }

      enforceMobileHeaderActions();
      applyAutoFollowButtonState();
      applyMobileSlotFilters(resolveMobileFilterState());
      setMobileFiltersOpen(false);
      updateMobileDateUi();
      renderNowLine(false);

      const msUntilNextMinute = Math.max(250, 60000 - (Math.floor(resolveCurrentUtcMs()) % 60000));

      window.setTimeout(() => {
        renderNowLine(true);
        window.setInterval(() => {
          renderNowLine(true);
        }, 60000);
      }, msUntilNextMinute);

      window.addEventListener('resize', () => {
        enforceMobileHeaderActions();
        applyMobileSlotFilters(resolveMobileFilterState());
        updateMobileDateUi();
        renderNowLine(false);
      });
    })();
  </script>
@endsection
