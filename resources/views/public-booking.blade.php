<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @php
    $brand = $publicBrand ?? null;
  @endphp
  @include('layouts.partials.pwa-meta')
  @if (! empty($brand['name']))
    <meta name="apple-mobile-web-app-title" content="{{ $brand['name'] }}">
  @endif
  @if (! empty($brand['primary_hex']))
    <meta name="theme-color" content="{{ $brand['primary_hex'] }}">
  @endif
  <title>Book en tid | {{ $brand['name'] ?? 'Bookingsystem' }}</title>
  @if (! empty($brand['primary_rgb']) || ! empty($brand['accent_rgb']) || ! empty($brand['primary_hex']) || ! empty($brand['accent_hex']))
    <style>
      body.public-booking-body {
        @if (! empty($brand['primary_rgb']))
          --color-primary-rgb: {{ $brand['primary_rgb'] }};
        @endif
        @if (! empty($brand['accent_rgb']))
          --color-accent-rgb: {{ $brand['accent_rgb'] }};
        @endif
        @if (! empty($brand['primary_hex']))
          --color-primary: {{ $brand['primary_hex'] }};
        @endif
        @if (! empty($brand['accent_hex']))
          --color-accent: {{ $brand['accent_hex'] }};
        @endif
      }
    </style>
  @endif
  @vite(['resources/css/app-booking.css', 'resources/js/pwa.js', 'resources/js/pages/public-booking.js'])
</head>
<body class="public-booking-body">
  @if (! empty($brand['show_powered_by']))
    <div class="public-booking-powered-header" role="note" aria-label="Powered by PlateBooking">
      <div class="public-booking-powered-header-inner">
        <span>Powered by PlateBooking</span>
      </div>
    </div>
  @endif

  <main class="public-booking-page">
    <section class="public-booking-shell">
      @php
        $bookingDisabled = $services->isEmpty() || $staffMembers->isEmpty();
        $requiresServiceCategories = (bool) ($requiresServiceCategories ?? true);
        $selectedServiceId = (string) old('service_id', '');
        $selectedCategoryId = max(0, (int) old('service_category_id', 0));
        $serviceCategories = $services
          ->groupBy(fn ($service) => (int) ($service->service_category_id ?? 0))
          ->map(function ($categoryServices, $categoryId) {
            $firstServiceInCategory = $categoryServices->first();
            $categoryName = trim((string) ($firstServiceInCategory?->category_name ?? 'Standard'));
            $categoryDescription = trim((string) ($firstServiceInCategory?->category_description ?? ''));

            return [
              'id' => (int) $categoryId,
              'key' => (string) $categoryId,
              'name' => $categoryName !== '' ? $categoryName : 'Standard',
              'sort_order' => $categoryServices
                ->map(function ($service): int {
                  $localSort = $service->getAttribute('location_sort_order');

                  if ($localSort !== null && is_numeric($localSort)) {
                    return max(0, (int) $localSort);
                  }

                  return max(0, (int) ($service->sort_order ?? 0));
                })
                ->min() ?? 0,
              'description' => $categoryDescription !== ''
                ? $categoryDescription
                : 'Vælg en ydelse i kategorien "' . ($categoryName !== '' ? $categoryName : 'Standard') . '".',
              'services' => $categoryServices->values(),
            ];
          })
          ->sortBy([
            ['sort_order', 'asc'],
            ['name', 'asc'],
          ])
          ->values();
        $selectedService = $selectedServiceId !== ''
          ? $services->firstWhere('id', (int) $selectedServiceId)
          : null;

        if ($selectedCategoryId <= 0 && $selectedService) {
          $selectedCategoryId = (int) ($selectedService->service_category_id ?? 0);
        }

        $selectedCategoryName = $selectedCategoryId > 0
          ? (string) ($serviceCategories->firstWhere('id', $selectedCategoryId)['name'] ?? '')
          : '';

        $initialStep = $requiresServiceCategories ? 1 : 2;
      @endphp
      @if ($errors->hasAny(['name', 'email', 'phone', 'notes']))
        @php
          $initialStep = 4;
        @endphp
      @elseif ($errors->hasAny(['staff_user_id', 'booking_date', 'booking_time']))
        @php
          $initialStep = 3;
        @endphp
      @elseif (old('name') || old('email') || old('phone') || old('notes'))
        @php
          $initialStep = 4;
        @endphp
      @elseif (old('staff_user_id') || old('booking_date') || old('booking_time'))
        @php
          $initialStep = 3;
        @endphp
      @elseif ($selectedServiceId !== '')
        @php
          $initialStep = 2;
        @endphp
      @endif

      <div class="public-booking-layout public-booking-layout-main">
        <section class="public-booking-form-card">
          <div class="public-booking-section-head">
            <p class="public-booking-eyebrow">Bookingformular</p>
            <h1>Book din tid</h1>
            <p>{{ $bookingIntroText ?? 'Vælg ydelse, tidspunkt og kontaktoplysninger. Når du opretter, ligger bookingen straks i kalenderen.' }}</p>
          </div>

          @if (session('status'))
            @php
              $summary = session('booking_summary');
            @endphp
            <div class="public-booking-success" role="status">
              <strong>{{ session('status') }}</strong>
              @if (is_array($summary))
                <p>{{ $summary['service'] }} hos {{ $summary['staff_member'] }} den {{ $summary['date'] }} kl. {{ $summary['time'] }}.</p>
              @endif
            </div>
          @endif

          @if ($errors->any())
            <div class="public-booking-alert" role="alert">
              {{ $errors->first() }}
            </div>
          @endif

          <form
            class="public-booking-form"
            method="POST"
            data-initial-step="{{ $initialStep }}"
            data-require-categories="{{ $requiresServiceCategories ? '1' : '0' }}"
            data-selected-service-requires-staff-selection="{{ $selectedServiceRequiresStaffSelection ? '1' : '0' }}"
            data-time-options-url="{{ route('public-booking.time-options', array_filter([
              'tenant' => filled($tenantQuery ?? null) ? $tenantQuery : null,
            ])) }}"
            action="{{ route('public-booking.store', array_filter([
              'tenant' => filled($tenantQuery ?? null) ? $tenantQuery : null,
              'location_id' => $selectedLocationId,
            ])) }}"
          >
            @csrf
            <input
              type="hidden"
              name="location_id"
              value="{{ old('location_id', $selectedLocationId) }}"
              data-public-booking-location-id
            >
            <input
              type="hidden"
              name="service_id"
              value="{{ $selectedServiceId }}"
              data-public-booking-service
            >
            <input
              type="hidden"
              name="service_category_id"
              value="{{ $selectedCategoryId > 0 ? $selectedCategoryId : '' }}"
              data-public-booking-category
            >

            <div class="public-booking-stepper">
              <div class="public-booking-step-indicator" data-step-indicator="1">
                <span>1</span>
                <strong>Kategori</strong>
              </div>
              <div class="public-booking-step-indicator" data-step-indicator="2">
                <span>2</span>
                <strong>Ydelse</strong>
              </div>
              <div class="public-booking-step-indicator" data-step-indicator="3">
                <span>3</span>
                <strong>Tid</strong>
              </div>
              <div class="public-booking-step-indicator" data-step-indicator="4">
                <span>4</span>
                <strong>Oplysninger</strong>
              </div>
            </div>

            <div class="public-booking-step" data-step-panel="1">
              <div class="public-booking-form-group">
                @if ($requiresServiceCategories)
                  <h3>Vælg kategori</h3>

                  @if ($services->isNotEmpty())
                    <div class="public-booking-category-picker" data-category-picker>
                      <div class="public-booking-category-grid" role="tablist" aria-label="Ydelseskategorier">
                        @foreach ($serviceCategories as $category)
                          @php
                            $categoryServicesCount = collect($category['services'] ?? [])->count();
                          @endphp
                          <button
                            type="button"
                            class="public-booking-category-option{{ $selectedCategoryId > 0 && $selectedCategoryId === (int) $category['id'] ? ' is-active' : '' }}"
                            data-service-category-card="{{ $category['key'] }}"
                            data-service-category-name="{{ $category['name'] }}"
                            data-service-category-description="{{ $category['description'] }}"
                            aria-pressed="{{ $selectedCategoryId > 0 && $selectedCategoryId === (int) $category['id'] ? 'true' : 'false' }}"
                            @disabled($bookingDisabled)
                          >
                            <div class="public-booking-service-head public-booking-category-head">
                              <strong>{{ $category['name'] }}</strong>
                              <div class="public-booking-service-tags">
                                <span class="public-booking-tag">{{ $categoryServicesCount }} ydelser</span>
                              </div>
                            </div>
                            <p>{{ $category['description'] }}</p>
                          </button>
                        @endforeach
                      </div>
                    </div>
                  @endif
                @else
                  <h3>Direkte valg af ydelse</h3>
                  <p class="public-booking-step-meta">Denne booking bruger ikke kategori-opdeling. Vælg ydelse direkte i næste trin.</p>
                @endif
              </div>

              <p class="public-booking-step-error" data-step-error></p>
              <p class="public-booking-step-hint">
                {{ $requiresServiceCategories ? 'Vælg en kategori for at fortsætte automatisk.' : 'Kategori-step er slået fra for denne virksomhed.' }}
              </p>
            </div>

            <div class="public-booking-step" data-step-panel="2">
              <div class="public-booking-form-group">
                <h3>Vælg ydelse</h3>

                @if ($services->isNotEmpty())
                  @if ($requiresServiceCategories)
                    <p class="public-booking-step-meta" data-service-category-current>
                      {{ $selectedCategoryName !== '' ? 'Kategori: ' . $selectedCategoryName : 'Vælg kategori først' }}
                    </p>
                  @else
                    <p class="public-booking-step-meta" data-service-category-current>Alle aktive online-ydelser</p>
                  @endif
                  <div class="public-booking-service-picker" data-service-picker>
                    @foreach ($serviceCategories as $category)
                      <section
                        class="public-booking-service-group"
                        data-service-group="{{ $category['key'] }}"
                        @if ($requiresServiceCategories && ($selectedCategoryId <= 0 || $selectedCategoryId !== (int) $category['id'])) hidden @endif
                      >
                        @foreach ($category['services'] as $service)
                          <button
                            type="button"
                            class="public-booking-service-option{{ $selectedServiceId === (string) $service->id ? ' is-active' : '' }}"
                            data-service-card="{{ $service->id }}"
                            data-service-category="{{ $category['key'] }}"
                            data-service-requires-staff-selection="{{ ($workShiftsEnabled ?? true) && $service->requiresStaffSelection() ? '1' : '0' }}"
                            @disabled($bookingDisabled)
                          >
                            @php
                              $servicePriceDisplay = $service->effectivePriceMinor() !== null
                                ? number_format(((int) $service->effectivePriceMinor()) / 100, 2, ',', '.')
                                : null;
                            @endphp
                            <div class="public-booking-service-head">
                              <strong>{{ $service->name }}</strong>
                              <div class="public-booking-service-tags">
                                <span class="public-booking-tag">{{ $service->effectiveDurationMinutes() }} min</span>
                                @if ($servicePriceDisplay)
                                  <span class="public-booking-tag">{{ $servicePriceDisplay }} kr.</span>
                                @endif
                                @if ($service->cancellationNoticeHours() > 0)
                                  <span class="public-booking-tag">Aflys {{ $service->cancellationNoticeHours() }}t før</span>
                                @endif
                              </div>
                            </div>
                            @if ($service->description)
                              <p>{{ $service->description }}</p>
                            @else
                              <p>Denne ydelse kan bookes online og planlægges automatisk med den valgte varighed.</p>
                            @endif
                          </button>
                        @endforeach
                      </section>
                    @endforeach
                  </div>
                @endif
              </div>

              <p class="public-booking-step-error" data-step-error></p>
              <p class="public-booking-step-hint">Vælg en ydelse for at fortsætte automatisk.</p>
              @if ($requiresServiceCategories)
                <div class="public-booking-step-actions is-single">
                  <button type="button" class="public-booking-button-secondary" data-step-prev>Tilbage</button>
                </div>
              @endif
            </div>

            <div class="public-booking-step" data-step-panel="3">
              <div class="public-booking-form-group">
                <h3>Vælg tid</h3>
                <p class="public-booking-step-meta">{{ $bookingWindowLabel ?? '' }}</p>
                <div class="public-booking-grid">
                  <label
                    class="public-booking-field public-booking-field-staff"
                    data-public-booking-staff-field
                    @if ($selectedServiceId !== '' && ! $selectedServiceRequiresStaffSelection) hidden @endif
                  >
                    <span>Medarbejder</span>
                    <select name="staff_user_id" data-public-booking-staff @disabled($bookingDisabled)>
                      <option value="">
                        @if ($selectedServiceId === '')
                          Vælg ydelse først
                        @elseif ($selectedServiceRequiresStaffSelection)
                          Vælg medarbejder
                        @else
                          Tildeles automatisk
                        @endif
                      </option>
                      @foreach ($staffMembers as $staffMember)
                        @php
                          $eligibleServiceIds = collect($staffMember->getAttribute('eligible_service_ids'))
                            ->map(static fn (int $id): int => $id)
                            ->implode(',');
                        @endphp
                        <option
                          value="{{ $staffMember->id }}"
                          data-service-ids="{{ $eligibleServiceIds }}"
                          @selected((string) old('staff_user_id') === (string) $staffMember->id)
                        >
                          {{ $staffMember->name }}
                        </option>
                      @endforeach
                    </select>
                  </label>
                  <p
                    class="public-booking-field-help public-booking-field-full"
                    data-public-booking-staff-auto-note
                    @if ($selectedServiceId === '' || $selectedServiceRequiresStaffSelection) hidden @endif
                  >
                    Medarbejder tildeles automatisk blandt ledige behandlere.
                  </p>

                  <label class="public-booking-field">
                    <span>Dato</span>
                    <input
                      type="date"
                      name="booking_date"
                      value="{{ old('booking_date', $timeOptionsDate ?? now()->toDateString()) }}"
                      min="{{ now()->toDateString() }}"
                      required
                      data-public-booking-date
                      @disabled($bookingDisabled)
                    >
                  </label>

                  <label class="public-booking-field">
                    <span>Tidspunkt</span>
                    <select name="booking_time" required data-public-booking-time @disabled($bookingDisabled)>
                      <option value="">Vælg tidspunkt</option>
                      @foreach ($timeOptions as $timeOption)
                        <option value="{{ $timeOption }}" @selected(old('booking_time') === $timeOption)>{{ $timeOption }}</option>
                      @endforeach
                    </select>
                  </label>
                </div>
              </div>

              <p class="public-booking-step-error" data-step-error></p>
              <p class="public-booking-step-hint">Når tid er valgt, går du automatisk videre.</p>
              <div class="public-booking-step-actions is-single">
                <button type="button" class="public-booking-button-secondary" data-step-prev>Tilbage</button>
              </div>
            </div>

            <div class="public-booking-step" data-step-panel="4">
              <div class="public-booking-form-group">
                <h3>Dine oplysninger</h3>
                <div class="public-booking-grid">
                  <label class="public-booking-field">
                    <span>Navn</span>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="Dit navn" required @disabled($bookingDisabled)>
                  </label>

                  <label class="public-booking-field">
                    <span>E-mail</span>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="navn@eksempel.dk" required @disabled($bookingDisabled)>
                  </label>

                  <label class="public-booking-field">
                    <span>Telefon</span>
                    <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+45 12 34 56 78" required @disabled($bookingDisabled)>
                  </label>

                  <label class="public-booking-field public-booking-field-full">
                    <span>Noter</span>
                    <textarea name="notes" rows="4" placeholder="Evt. info virksomheden skal kende til" @disabled($bookingDisabled)>{{ old('notes') }}</textarea>
                  </label>
                </div>
              </div>

              <p class="public-booking-step-error" data-step-error></p>
              <div class="public-booking-step-actions">
                <button type="button" class="public-booking-button-secondary" data-step-prev>Tilbage</button>
                <button type="submit" class="public-booking-button" @disabled($bookingDisabled)>Opret booking</button>
              </div>

              <p class="public-booking-help">
                Vi bruger oplysningerne til at oprette bookingen og kontakte dig, hvis tiden skal justeres.
              </p>
            </div>

            @if ($bookingDisabled)
              <div class="public-booking-empty">
                <strong>Booking er ikke klar endnu</strong>
                <span>Der skal oprettes mindst en ydelse og en medarbejder i dashboardet, før kunder kan booke online.</span>
              </div>
            @endif
          </form>
        </section>

        @php
          $postalCode = trim((string) ($selectedLocation?->postal_code ?? ''));
          $city = trim((string) ($selectedLocation?->city ?? ''));
          $postalCity = trim($postalCode . ' ' . $city);
          $publicContactPhone = trim((string) ($selectedLocation?->public_contact_phone ?? ''));
          $publicContactEmail = trim((string) ($selectedLocation?->public_contact_email ?? ''));
          $publicContactEmailHref = $publicContactEmail !== '' ? 'mailto:' . $publicContactEmail : null;

          $locationAddressLines = collect([
            trim((string) ($selectedLocation?->address_line_1 ?? '')),
            trim((string) ($selectedLocation?->address_line_2 ?? '')),
          ])->filter(static fn (string $line): bool => $line !== '')->values();

          if ($postalCity !== '') {
            $locationAddressLines->push($postalCity);
          }
        @endphp

        <aside class="public-booking-side-stack">
          <section class="public-booking-card public-booking-brand-card">
            @php
              $locationAddressLines = $locationAddressLines ?? collect();
            @endphp
            <div class="public-booking-brand-address">
              <p class="public-booking-eyebrow">Adresse</p>
              @if ($locationAddressLines->isNotEmpty())
                <address>
                  @foreach ($locationAddressLines as $addressLine)
                    <span>{{ $addressLine }}</span>
                  @endforeach
                </address>
              @else
                <p class="public-booking-brand-address-empty">Adresse ikke angivet endnu.</p>
              @endif

              @if ($publicContactPhone !== '' || $publicContactEmail !== '')
                <div class="public-booking-brand-contact">
                  <p class="public-booking-eyebrow">Kontakt</p>
                  <div class="public-booking-brand-contact-list">
                    @if ($publicContactPhone !== '')
                      <a href="tel:{{ preg_replace('/\s+/', '', $publicContactPhone) }}">{{ $publicContactPhone }}</a>
                    @endif
                    @if ($publicContactEmailHref)
                      <a href="{{ $publicContactEmailHref }}">{{ $publicContactEmail }}</a>
                    @endif
                  </div>
                </div>
              @endif
            </div>

            <div class="public-booking-brand-visual">
              @if (! empty($brand['logo_url']))
                <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['logo_alt'] ?? 'Booking logo' }}">
              @endif
              <strong>{{ $brand['name'] ?? 'Bookingsystem' }}</strong>
            </div>
          </section>

          <section class="public-booking-card public-booking-hours-card">
            <div class="public-booking-section-head public-booking-section-head-compact">
              <p class="public-booking-eyebrow">Åbningstider</p>
              <h2>Ugens tider</h2>
              <p>Gælder for {{ $selectedLocation?->name ?? 'valgt afdeling' }}.</p>
            </div>

            @if ($locations->count() > 1)
              <form method="GET" class="public-booking-location-switch public-booking-location-switch-side">
                @if (filled($tenantQuery ?? null))
                  <input type="hidden" name="tenant" value="{{ $tenantQuery }}">
                @endif

                <select id="location-switch" name="location_id" onchange="this.form.submit()" aria-label="Lokation">
                  @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((int) $selectedLocationId === (int) $location->id)>
                      {{ $location->name }}
                    </option>
                  @endforeach
                </select>
              </form>
            @endif

            <div class="public-booking-hours-list">
              @foreach (($weekDays ?? []) as $weekday => $label)
                @php
                  $daySlots = ($openingHoursByDay ?? collect())->get($weekday, collect());
                @endphp
                <article class="public-booking-hours-row">
                  <strong>{{ $label }}</strong>
                  @if ($daySlots->isEmpty())
                    <span>Lukket</span>
                  @else
                    <span>
                      {{ $daySlots
                        ->map(fn ($slot) => substr((string) $slot->opens_at, 0, 5) . ' - ' . substr((string) $slot->closes_at, 0, 5))
                        ->implode(' · ') }}
                    </span>
                  @endif
                </article>
              @endforeach
            </div>
          </section>

          <a href="{{ route('login') }}" class="public-booking-login-link public-booking-login-link-side">Medarbejder-login</a>
        </aside>
      </div>
    </section>
  </main>
</body>
</html>
