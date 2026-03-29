@php
  $isServiceOn = (int) ($service->active_locations_count ?? 0) > 0;
  $isOnlineBookable = (bool) ($service->is_online_bookable ?? false);
  $requiresStaffSelection = (bool) ($service->requiresStaffSelection() ?? true);
  $servicePriceKrDisplay = $service->price_minor !== null
    ? number_format(((int) $service->price_minor) / 100, 2, ',', '.')
    : null;
  $servicePriceKrInput = $service->price_minor !== null
    ? number_format(((int) $service->price_minor) / 100, 2, '.', '')
    : '';
  $serviceActiveLocationIds = $service->locations
    ->filter(fn ($location) => (bool) ($location->pivot?->is_active ?? false))
    ->pluck('id')
    ->implode(',');
  $serviceLocationOverridesJson = $service->locations
    ->mapWithKeys(function ($location): array {
      $durationOverride = $location->pivot?->duration_minutes;
      $priceOverrideMinor = $location->pivot?->price_minor;

      return [
        (string) $location->id => [
          'active' => (bool) ($location->pivot?->is_active ?? false),
          'duration_minutes' => $durationOverride !== null ? (int) $durationOverride : null,
          'price_kr' => $priceOverrideMinor !== null
            ? number_format(((int) $priceOverrideMinor) / 100, 2, '.', '')
            : null,
          'sort_order' => $location->pivot?->sort_order !== null
            ? (int) $location->pivot->sort_order
            : null,
        ],
      ];
    })
    ->toJson();
@endphp

<article
  class="services-list-item"
  data-service-item
  data-service-group="{{ $serviceGroup ?? 'all' }}"
  data-service-search="{{ mb_strtolower(trim($service->name . ' ' . ($service->category_name ?? '') . ' ' . ($service->category_description ?? '') . ' ' . ($service->description ?? '')), 'UTF-8') }}"
>
  <div class="services-summary">
    <span
      class="services-swatch"
      aria-hidden="true"
      style="--service-color: {{ $service->color ?? '#5C80BC' }};"
    ></span>

    <div class="services-summary-copy">
      <strong>{{ $service->name }}</strong>
      <span>
        {{ $servicePriceKrDisplay ? $servicePriceKrDisplay . ' kr.' : 'Ingen pris' }}
        • {{ $service->category_name }}
        • {{ $service->bookings_count }} bookinger
        • {{ $service->active_locations_count }}/{{ $totalLocationCount }} lokationer aktive
      </span>
      @if ($service->description)
        <p>{{ $service->description }}</p>
      @endif
    </div>
  </div>

  <div class="services-row-actions">
    <form method="POST" action="{{ route('services.toggle-online', $service) }}" class="services-toggle-form">
      @csrf
      @method('PATCH')
      <button
        type="submit"
        class="services-button services-button-toggle-online {{ $isOnlineBookable ? 'is-on' : 'is-off' }}"
      >
        {{ $isOnlineBookable ? 'Online' : 'Offline' }}
      </button>
    </form>

    <form method="POST" action="{{ route('services.toggle-active', $service) }}" class="services-toggle-form">
      @csrf
      @method('PATCH')
      <button
        type="submit"
        class="services-button services-button-toggle {{ $isServiceOn ? 'is-on' : 'is-off' }}"
      >
        {{ $isServiceOn ? 'On' : 'Off' }}
      </button>
    </form>

    <button
      type="button"
      class="services-button services-button-ghost"
      data-service-trigger
      data-service-id="{{ $service->id }}"
      data-service-name="{{ $service->name }}"
      data-service-duration="{{ $service->duration_minutes }}"
      data-service-price-kr="{{ $servicePriceKrInput }}"
      data-service-color="{{ $service->color ?? '#5C80BC' }}"
      data-service-description="{{ $service->description ?? '' }}"
      data-service-online-bookable="{{ $isOnlineBookable ? '1' : '0' }}"
      data-service-requires-staff-selection="{{ $requiresStaffSelection ? '1' : '0' }}"
      data-service-category-id="{{ $service->service_category_id ?? '' }}"
      data-service-sort-order="{{ $service->sort_order ?? 1 }}"
      data-service-buffer-before="{{ $service->buffer_before_minutes ?? 0 }}"
      data-service-buffer-after="{{ $service->buffer_after_minutes ?? 0 }}"
      data-service-min-notice="{{ $service->min_notice_minutes ?? 0 }}"
      data-service-max-advance-days="{{ $service->max_advance_days ?? '' }}"
      data-service-cancellation-notice="{{ $service->cancellation_notice_hours ?? 24 }}"
      data-service-bookings="{{ $service->bookings_count }}"
      data-service-active-locations="{{ $serviceActiveLocationIds }}"
      data-service-location-overrides="{{ e($serviceLocationOverridesJson) }}"
      data-update-action="{{ route('services.update', $service) }}"
      data-delete-action="{{ route('services.destroy', $service) }}"
    >
      Rediger
    </button>
  </div>
</article>
