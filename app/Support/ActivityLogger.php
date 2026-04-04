<?php

namespace App\Support;

use App\Models\ActivityEvent;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;

class ActivityLogger
{
    public const CATEGORY_BOOKINGS = 'bookings';
    public const CATEGORY_USERS = 'users';
    public const CATEGORY_SERVICES = 'services';
    public const CATEGORY_SETTINGS = 'settings';

    public function bookingSnapshot(Booking $booking): array
    {
        $booking->loadMissing([
            'customer:id,name',
            'service:id,name',
            'staffMember:id,name',
            'location:id,name',
        ]);

        return [
            'customer_name' => $booking->customer?->name ?? 'Ukendt kunde',
            'service_name' => $booking->service?->name ?? 'Ukendt ydelse',
            'staff_name' => $booking->staffMember?->name ?? 'Ukendt medarbejder',
            'location_id' => (int) ($booking->location_id ?? 0),
            'location_name' => $booking->location?->name ?? 'Ukendt lokation',
            'time_range' => $this->formatBookingRange($booking),
            'status_label' => $this->bookingStatusLabel((string) $booking->status),
        ];
    }

    public function userSnapshot(User $user, array $locationNames = []): array
    {
        if ($locationNames === [] && $user->relationLoaded('locations')) {
            $locationNames = $user->locations
                ->pluck('name')
                ->map(static fn (string $name): string => trim($name))
                ->filter()
                ->values()
                ->all();
        }

        return [
            'name' => trim((string) $user->name),
            'email' => trim((string) $user->email),
            'role_label' => $user->roleLabel(),
            'bookable_label' => $user->is_bookable ? 'Ja' : 'Nej',
            'status_label' => $user->is_active ? 'Aktiv' : 'Inaktiv',
            'competency_scope' => $user->competencyScopeLabel(),
            'locations' => $this->implodeList($locationNames, 'Ingen lokationer'),
        ];
    }

    public function serviceSnapshot(Service $service, array $locationNames = []): array
    {
        $service->loadMissing('category:id,name');

        if ($locationNames === [] && $service->relationLoaded('locations')) {
            $locationNames = $service->locations
                ->filter(static fn ($location): bool => (bool) ($location->pivot?->is_active ?? false))
                ->pluck('name')
                ->map(static fn (string $name): string => trim($name))
                ->filter()
                ->values()
                ->all();
        }

        return [
            'name' => trim((string) $service->name),
            'category_name' => trim((string) ($service->category?->name ?? $service->category_name ?? 'Standard')),
            'duration' => $this->formatDuration((int) $service->duration_minutes),
            'price' => $this->formatPrice((int) ($service->price_minor ?? 0)),
            'online_label' => (bool) $service->is_online_bookable ? 'Ja' : 'Nej',
            'staff_selection' => $service->requiresStaffSelection() ? 'Paakraevet' : 'Ikke paakraevet',
            'locations' => $this->implodeList($locationNames, 'Alle / ikke angivet'),
        ];
    }

    public function serviceCategorySnapshot(ServiceCategory $serviceCategory): array
    {
        return [
            'name' => trim((string) $serviceCategory->name),
            'description' => trim((string) ($serviceCategory->description ?? '')),
            'sort_order' => (string) ((int) ($serviceCategory->sort_order ?? 0)),
        ];
    }

    public function locationSettingsSnapshot(Location $location): array
    {
        return [
            'name' => trim((string) $location->name),
            'intro' => trim((string) ($location->public_booking_intro_text ?? '')),
            'confirmation' => trim((string) ($location->public_booking_confirmation_text ?? '')),
            'address' => $this->implodeList([
                trim((string) ($location->address_line_1 ?? '')),
                trim((string) ($location->address_line_2 ?? '')),
                trim((string) ($location->postal_code ?? '')),
                trim((string) ($location->city ?? '')),
            ]),
            'phone' => trim((string) ($location->public_contact_phone ?? '')),
            'email' => trim((string) ($location->public_contact_email ?? '')),
        ];
    }

    public function brandingSnapshot(Tenant $tenant): array
    {
        return [
            'brand_name' => trim((string) ($tenant->public_brand_name ?? '')),
            'slug' => trim((string) ($tenant->slug ?? '')),
            'primary_color' => trim((string) ($tenant->public_primary_color ?? '')),
            'accent_color' => trim((string) ($tenant->public_accent_color ?? '')),
            'show_powered_by' => (bool) ($tenant->show_powered_by ?? false) ? 'Ja' : 'Nej',
            'require_service_categories' => (bool) ($tenant->require_service_categories ?? false) ? 'Ja' : 'Nej',
            'work_shifts_enabled' => (bool) ($tenant->work_shifts_enabled ?? false) ? 'Ja' : 'Nej',
            'has_logo' => filled($tenant->public_logo_path ?? null) ? 'Ja' : 'Nej',
        ];
    }

    public function logBookingCreated(User $actor, Booking $booking): void
    {
        $snapshot = $this->bookingSnapshot($booking);

        $this->record(
            tenantId: (int) $booking->tenant_id,
            locationId: (int) $booking->location_id,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_BOOKINGS,
            eventKey: 'booking.created',
            subjectType: 'booking',
            subjectId: (int) $booking->id,
            message: trim($actor->name . ' oprettede booking for ' . $snapshot['customer_name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Kunde' => $snapshot['customer_name'],
                    'Ydelse' => $snapshot['service_name'],
                    'Medarbejder' => $snapshot['staff_name'],
                    'Tidspunkt' => $snapshot['time_range'],
                    'Lokation' => $snapshot['location_name'],
                ]),
            ]
        );
    }

    public function logBookingUpdated(User $actor, Booking $booking, array $before): void
    {
        $after = $this->bookingSnapshot($booking);

        $this->record(
            tenantId: (int) $booking->tenant_id,
            locationId: (int) $booking->location_id,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_BOOKINGS,
            eventKey: 'booking.updated',
            subjectType: 'booking',
            subjectId: (int) $booking->id,
            message: trim($actor->name . ' aendrede booking for ' . ($after['customer_name'] ?? $before['customer_name'] ?? 'Ukendt kunde') . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Kunde' => $after['customer_name'] ?? null,
                    'Ydelse' => $after['service_name'] ?? null,
                    'Lokation' => $after['location_name'] ?? null,
                ]),
                'changes' => $this->changeItems($before, $after, [
                    'time_range' => 'Tidspunkt',
                    'staff_name' => 'Medarbejder',
                    'status_label' => 'Status',
                ]),
            ]
        );
    }

    public function logBookingCanceled(User $actor, Booking $booking): void
    {
        $snapshot = $this->bookingSnapshot($booking);

        $this->record(
            tenantId: (int) $booking->tenant_id,
            locationId: (int) $booking->location_id,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_BOOKINGS,
            eventKey: 'booking.canceled',
            subjectType: 'booking',
            subjectId: (int) $booking->id,
            message: trim($actor->name . ' annullerede booking for ' . $snapshot['customer_name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Ydelse' => $snapshot['service_name'],
                    'Medarbejder' => $snapshot['staff_name'],
                    'Tidspunkt' => $snapshot['time_range'],
                    'Lokation' => $snapshot['location_name'],
                ]),
            ]
        );
    }

    public function logBookingCompleted(User $actor, Booking $booking): void
    {
        $snapshot = $this->bookingSnapshot($booking);

        $this->record(
            tenantId: (int) $booking->tenant_id,
            locationId: (int) $booking->location_id,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_BOOKINGS,
            eventKey: 'booking.completed',
            subjectType: 'booking',
            subjectId: (int) $booking->id,
            message: trim($actor->name . ' markerede booking for ' . $snapshot['customer_name'] . ' som gennemfoert.'),
            metadata: [
                'context' => $this->contextItems([
                    'Ydelse' => $snapshot['service_name'],
                    'Medarbejder' => $snapshot['staff_name'],
                    'Tidspunkt' => $snapshot['time_range'],
                    'Lokation' => $snapshot['location_name'],
                ]),
            ]
        );
    }

    public function logUserCreated(User $actor, User $user, array $locationNames = []): void
    {
        $snapshot = $this->userSnapshot($user, $locationNames);

        $this->record(
            tenantId: (int) $user->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_USERS,
            eventKey: 'user.created',
            subjectType: 'user',
            subjectId: (int) $user->id,
            message: trim($actor->name . ' oprettede medarbejderen ' . $snapshot['name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Rolle' => $snapshot['role_label'],
                    'Bookbar' => $snapshot['bookable_label'],
                    'Kompetencer' => $snapshot['competency_scope'],
                    'Lokationer' => $snapshot['locations'],
                    'E-mail' => $snapshot['email'],
                ]),
            ]
        );
    }

    public function logUserUpdated(User $actor, User $user, array $before, array $locationNames = []): void
    {
        $after = $this->userSnapshot($user, $locationNames);

        $this->record(
            tenantId: (int) $user->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_USERS,
            eventKey: 'user.updated',
            subjectType: 'user',
            subjectId: (int) $user->id,
            message: trim($actor->name . ' opdaterede medarbejderen ' . ($after['name'] ?? $before['name'] ?? 'Ukendt medarbejder') . '.'),
            metadata: [
                'changes' => $this->changeItems($before, $after, [
                    'name' => 'Navn',
                    'email' => 'E-mail',
                    'role_label' => 'Rolle',
                    'bookable_label' => 'Bookbar',
                    'status_label' => 'Status',
                    'competency_scope' => 'Kompetencer',
                    'locations' => 'Lokationer',
                ]),
            ]
        );
    }

    public function logUserActivationChanged(User $actor, User $user, bool $isActive): void
    {
        $snapshot = $this->userSnapshot($user);

        $this->record(
            tenantId: (int) $user->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_USERS,
            eventKey: $isActive ? 'user.activated' : 'user.deactivated',
            subjectType: 'user',
            subjectId: (int) $user->id,
            message: trim($actor->name . ' satte ' . $snapshot['name'] . ' som ' . mb_strtolower($snapshot['status_label']) . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Rolle' => $snapshot['role_label'],
                    'Bookbar' => $snapshot['bookable_label'],
                    'Lokationer' => $snapshot['locations'],
                ]),
            ]
        );
    }

    public function logUserDeleted(User $actor, int $tenantId, array $snapshot): void
    {
        $this->record(
            tenantId: $tenantId,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_USERS,
            eventKey: 'user.deleted',
            subjectType: 'user',
            subjectId: (int) ($snapshot['id'] ?? 0),
            message: trim($actor->name . ' slettede medarbejderen ' . ($snapshot['name'] ?? 'Ukendt medarbejder') . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Rolle' => $snapshot['role_label'] ?? null,
                    'Bookbar' => $snapshot['bookable_label'] ?? null,
                    'Lokationer' => $snapshot['locations'] ?? null,
                    'E-mail' => $snapshot['email'] ?? null,
                ]),
            ]
        );
    }

    public function logServiceCreated(User $actor, Service $service, array $locationNames = []): void
    {
        $snapshot = $this->serviceSnapshot($service, $locationNames);

        $this->record(
            tenantId: (int) $service->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service.created',
            subjectType: 'service',
            subjectId: (int) $service->id,
            message: trim($actor->name . ' oprettede ydelsen ' . $snapshot['name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Kategori' => $snapshot['category_name'],
                    'Varighed' => $snapshot['duration'],
                    'Pris' => $snapshot['price'],
                    'Online booking' => $snapshot['online_label'],
                    'Lokationer' => $snapshot['locations'],
                ]),
            ]
        );
    }

    public function logServiceUpdated(User $actor, Service $service, array $before, array $locationNames = []): void
    {
        $after = $this->serviceSnapshot($service, $locationNames);

        $this->record(
            tenantId: (int) $service->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service.updated',
            subjectType: 'service',
            subjectId: (int) $service->id,
            message: trim($actor->name . ' opdaterede ydelsen ' . ($after['name'] ?? $before['name'] ?? 'Ukendt ydelse') . '.'),
            metadata: [
                'changes' => $this->changeItems($before, $after, [
                    'name' => 'Navn',
                    'category_name' => 'Kategori',
                    'duration' => 'Varighed',
                    'price' => 'Pris',
                    'online_label' => 'Online booking',
                    'staff_selection' => 'Medarbejdervalg',
                    'locations' => 'Lokationer',
                ]),
            ]
        );
    }

    public function logServiceActiveToggle(User $actor, Service $service, bool $isActive, array $locationNames = []): void
    {
        $snapshot = $this->serviceSnapshot($service, $locationNames);

        $this->record(
            tenantId: (int) $service->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: $isActive ? 'service.enabled' : 'service.disabled',
            subjectType: 'service',
            subjectId: (int) $service->id,
            message: trim($actor->name . ' ' . ($isActive ? 'aktiverede' : 'deaktiverede') . ' ydelsen ' . $snapshot['name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Lokationer' => $snapshot['locations'],
                ]),
            ]
        );
    }

    public function logServiceOnlineToggle(User $actor, Service $service, bool $isOnline): void
    {
        $snapshot = $this->serviceSnapshot($service);

        $this->record(
            tenantId: (int) $service->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: $isOnline ? 'service.online.enabled' : 'service.online.disabled',
            subjectType: 'service',
            subjectId: (int) $service->id,
            message: trim($actor->name . ' ' . ($isOnline ? 'aktiverede' : 'deaktiverede') . ' online booking for ' . $snapshot['name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Kategori' => $snapshot['category_name'],
                    'Pris' => $snapshot['price'],
                ]),
            ]
        );
    }

    public function logServiceDeleted(User $actor, int $tenantId, array $snapshot, int $serviceId): void
    {
        $this->record(
            tenantId: $tenantId,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service.deleted',
            subjectType: 'service',
            subjectId: $serviceId,
            message: trim($actor->name . ' slettede ydelsen ' . ($snapshot['name'] ?? 'Ukendt ydelse') . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Kategori' => $snapshot['category_name'] ?? null,
                    'Varighed' => $snapshot['duration'] ?? null,
                    'Pris' => $snapshot['price'] ?? null,
                ]),
            ]
        );
    }

    public function logServiceCategoryCreated(User $actor, ServiceCategory $serviceCategory): void
    {
        $snapshot = $this->serviceCategorySnapshot($serviceCategory);

        $this->record(
            tenantId: (int) $serviceCategory->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service-category.created',
            subjectType: 'service_category',
            subjectId: (int) $serviceCategory->id,
            message: trim($actor->name . ' oprettede kategorien ' . $snapshot['name'] . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Beskrivelse' => $snapshot['description'] !== '' ? $snapshot['description'] : 'Ingen',
                    'Sortering' => $snapshot['sort_order'],
                ]),
            ]
        );
    }

    public function logServiceCategoryUpdated(User $actor, ServiceCategory $serviceCategory, array $before): void
    {
        $after = $this->serviceCategorySnapshot($serviceCategory);

        $this->record(
            tenantId: (int) $serviceCategory->tenant_id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service-category.updated',
            subjectType: 'service_category',
            subjectId: (int) $serviceCategory->id,
            message: trim($actor->name . ' opdaterede kategorien ' . ($after['name'] ?? $before['name'] ?? 'Ukendt kategori') . '.'),
            metadata: [
                'changes' => $this->changeItems($before, $after, [
                    'name' => 'Navn',
                    'description' => 'Beskrivelse',
                    'sort_order' => 'Sortering',
                ]),
            ]
        );
    }

    public function logServiceCategoryDeleted(User $actor, int $tenantId, array $snapshot, int $categoryId): void
    {
        $this->record(
            tenantId: $tenantId,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SERVICES,
            eventKey: 'service-category.deleted',
            subjectType: 'service_category',
            subjectId: $categoryId,
            message: trim($actor->name . ' slettede kategorien ' . ($snapshot['name'] ?? 'Ukendt kategori') . '.'),
            metadata: [
                'context' => $this->contextItems([
                    'Beskrivelse' => ($snapshot['description'] ?? '') !== '' ? $snapshot['description'] : 'Ingen',
                ]),
            ]
        );
    }

    public function logLocationSettingsUpdated(User $actor, Location $location, array $before): void
    {
        $after = $this->locationSettingsSnapshot($location);

        $this->record(
            tenantId: (int) $location->tenant_id,
            locationId: (int) $location->id,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SETTINGS,
            eventKey: 'settings.location.updated',
            subjectType: 'location',
            subjectId: (int) $location->id,
            message: trim($actor->name . ' opdaterede lokale indstillinger for ' . $location->name . '.'),
            metadata: [
                'changes' => $this->changeItems($before, $after, [
                    'name' => 'Afdelingsnavn',
                    'intro' => 'Booking-intro',
                    'confirmation' => 'Bookingbesked',
                    'address' => 'Adresse',
                    'phone' => 'Telefon',
                    'email' => 'E-mail',
                ]),
            ]
        );
    }

    public function logBrandingUpdated(User $actor, Tenant $tenant, array $before): void
    {
        $after = $this->brandingSnapshot($tenant);

        $this->record(
            tenantId: (int) $tenant->id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SETTINGS,
            eventKey: 'settings.branding.updated',
            subjectType: 'tenant',
            subjectId: (int) $tenant->id,
            message: trim($actor->name . ' opdaterede branding og globale indstillinger.'),
            metadata: [
                'changes' => $this->changeItems($before, $after, [
                    'brand_name' => 'Brandnavn',
                    'slug' => 'Slug',
                    'primary_color' => 'Primaer farve',
                    'accent_color' => 'Accentfarve',
                    'show_powered_by' => 'Powered by',
                    'require_service_categories' => 'Kategorier paakraevet',
                    'work_shifts_enabled' => 'Arbejdsplaner aktive',
                    'has_logo' => 'Logo uploadet',
                ]),
            ]
        );
    }

    public function logBrandingReset(User $actor, Tenant $tenant): void
    {
        $this->record(
            tenantId: (int) $tenant->id,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SETTINGS,
            eventKey: 'settings.branding.reset',
            subjectType: 'tenant',
            subjectId: (int) $tenant->id,
            message: trim($actor->name . ' nulstillede branding for virksomheden.'),
            metadata: []
        );
    }

    public function logRolePermissionsUpdated(User $actor, int $tenantId): void
    {
        $this->record(
            tenantId: $tenantId,
            locationId: null,
            actorUserId: (int) $actor->id,
            category: self::CATEGORY_SETTINGS,
            eventKey: 'settings.permissions.updated',
            subjectType: 'tenant',
            subjectId: $tenantId,
            message: trim($actor->name . ' opdaterede adgangsniveauer og roller.'),
            metadata: []
        );
    }

    private function record(
        int $tenantId,
        ?int $locationId,
        ?int $actorUserId,
        string $category,
        string $eventKey,
        ?string $subjectType,
        ?int $subjectId,
        string $message,
        array $metadata = []
    ): void {
        ActivityEvent::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId > 0 ? $locationId : null,
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'category' => $category,
            'event_key' => $eventKey,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId > 0 ? $subjectId : null,
            'message' => trim($message),
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    private function formatBookingRange(Booking $booking): string
    {
        $startsAt = $booking->starts_at?->format('d.m.Y H:i') ?? '-';
        $endsAt = $booking->ends_at?->format('H:i') ?? '-';

        return $startsAt . ' - ' . $endsAt;
    }

    private function bookingStatusLabel(string $status): string
    {
        return match ($status) {
            Booking::STATUS_COMPLETED => 'Gennemfoert',
            Booking::STATUS_CANCELED => 'Annulleret',
            default => 'Bekraeftet',
        };
    }

    private function contextItems(array $items): array
    {
        return collect($items)
            ->map(static function (mixed $value, string $label): ?array {
                $normalized = is_string($value) ? trim($value) : (string) $value;

                if ($normalized === '') {
                    return null;
                }

                return [
                    'label' => $label,
                    'value' => $normalized,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function changeItems(array $before, array $after, array $labels): array
    {
        $changes = [];

        foreach ($labels as $key => $label) {
            $beforeValue = trim((string) ($before[$key] ?? ''));
            $afterValue = trim((string) ($after[$key] ?? ''));

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'label' => $label,
                'before' => $beforeValue !== '' ? $beforeValue : 'Tom',
                'after' => $afterValue !== '' ? $afterValue : 'Tom',
            ];
        }

        return $changes;
    }

    private function implodeList(array $items, string $fallback = ''): string
    {
        $normalized = collect($items)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        if ($normalized === []) {
            return $fallback;
        }

        return implode(', ', $normalized);
    }

    private function formatDuration(int $minutes): string
    {
        return max(0, $minutes) . ' min.';
    }

    private function formatPrice(int $priceMinor): string
    {
        if ($priceMinor <= 0) {
            return '0,00 kr.';
        }

        return number_format($priceMinor / 100, 2, ',', '.') . ' kr.';
    }
}
