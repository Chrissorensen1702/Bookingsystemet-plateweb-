<?php

namespace App\Http\Controllers;

use App\Enums\TenantRole;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWorkShift;
use App\Support\ActivityLogger;
use App\Support\TenantRolePermissionManager;
use App\Support\UploadsStorage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $requestedUsersView = trim((string) $request->query('users_view', ''));

        if (in_array($requestedUsersView, ['permissions', 'activity'], true)) {
            return redirect()->route('settings.index', $this->settingsRedirectParameters(
                $request,
                $tenantId,
                $requestedUsersView
            ));
        }

        $workShiftsEnabled = $this->isWorkShiftsEnabledForTenant($tenantId);
        /** @var User $actor */
        $actor = $request->user();
        $allowedRoles = $this->allowedRoleValuesFor($actor);
        $locationOptions = $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);
        $selectedCompetencyLocationId = max(0, (int) $request->query('competency_location_id', 0));
        $selectedWorkhoursLocationId = max(0, (int) $request->query('workhours_location_id', 0));
        $selectedCompetencyLocation = $selectedCompetencyLocationId > 0
            ? $locationOptions->firstWhere('id', $selectedCompetencyLocationId)
            : null;
        $selectedWorkhoursLocation = $selectedWorkhoursLocationId > 0
            ? $locationOptions->firstWhere('id', $selectedWorkhoursLocationId)
            : null;

        if (! $selectedCompetencyLocation && $locationOptions->isNotEmpty()) {
            $selectedCompetencyLocation = $locationOptions->first();
            $selectedCompetencyLocationId = (int) ($selectedCompetencyLocation?->id ?? 0);
        }

        if (! $selectedWorkhoursLocation && $locationOptions->isNotEmpty()) {
            $selectedWorkhoursLocation = $locationOptions->first();
            $selectedWorkhoursLocationId = (int) ($selectedWorkhoursLocation?->id ?? 0);
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $workhoursDateInput = trim((string) $request->query('workhours_date', ''));

        try {
            $workhoursDate = $workhoursDateInput !== ''
                ? CarbonImmutable::createFromFormat('Y-m-d', $workhoursDateInput, $timezone)->startOfDay()
                : CarbonImmutable::now($timezone)->startOfDay();
        } catch (\Throwable) {
            $workhoursDate = CarbonImmutable::now($timezone)->startOfDay();
        }

        $weekNavDirection = trim((string) $request->query('week_nav', ''));

        if ($weekNavDirection === 'prev') {
            $workhoursDate = $workhoursDate->subWeek();
        } elseif ($weekNavDirection === 'next') {
            $workhoursDate = $workhoursDate->addWeek();
        }

        $workhoursWeekStart = $workhoursDate->startOfWeek(CarbonInterface::MONDAY);
        $workhoursWeekEnd = $workhoursWeekStart->addDays(6);

        $usersQuery = User::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'locations' => fn ($query) => $query
                    ->select('locations.id', 'locations.name')
                    ->where('location_user.is_active', true)
                    ->orderBy('locations.name'),
                'services' => fn ($query) => $query
                    ->select('services.id', 'services.name')
                    ->orderBy('services.sort_order')
                    ->orderBy('services.name'),
            ]);

        if (! $actor->isOwner()) {
            $usersQuery->whereIn('role', $allowedRoles);
        }

        $users = $usersQuery
            ->orderByRaw('LOWER(name)')
            ->get();
        $locationCompetencyServiceIdsByUser = [];

        if ($selectedCompetencyLocationId > 0 && $users->isNotEmpty()) {
            $locationCompetencyServiceIdsByUser = DB::table('location_service_user')
                ->select(['user_id', 'service_id'])
                ->where('location_id', $selectedCompetencyLocationId)
                ->whereIn('user_id', $users->pluck('id')->all())
                ->get()
                ->groupBy('user_id')
                ->map(static function ($rows): array {
                    return $rows
                        ->pluck('service_id')
                        ->map(static fn (int $id): int => $id)
                        ->unique()
                        ->values()
                        ->all();
                })
                ->all();
        }

        $competencyUsers = $users
            ->filter(function (User $user) use ($selectedCompetencyLocationId): bool {
                if (! $user->usesLocationCompetencies()) {
                    return true;
                }

                if ($selectedCompetencyLocationId <= 0) {
                    return false;
                }

                return $user->locations->contains('id', $selectedCompetencyLocationId);
            })
            ->values();
        $selectedCompetencyUserId = max(0, (int) $request->query('competency_user_id', 0));
        /** @var User|null $selectedCompetencyUser */
        $selectedCompetencyUser = $selectedCompetencyUserId > 0
            ? $competencyUsers->firstWhere('id', $selectedCompetencyUserId)
            : null;

        if (! $selectedCompetencyUser && $competencyUsers->isNotEmpty()) {
            /** @var User $fallbackCompetencyUser */
            $fallbackCompetencyUser = $competencyUsers->first();
            $selectedCompetencyUser = $fallbackCompetencyUser;
            $selectedCompetencyUserId = (int) $fallbackCompetencyUser->id;
        }

        $selectedCompetencyServiceIds = [];

        if ($selectedCompetencyUser) {
            if ($selectedCompetencyUser->usesLocationCompetencies()) {
                $selectedCompetencyServiceIds = collect(
                    $locationCompetencyServiceIdsByUser[(int) $selectedCompetencyUser->id] ?? []
                )
                    ->map(static fn (int $id): int => $id)
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $selectedCompetencyServiceIds = $selectedCompetencyUser->services
                    ->pluck('id')
                    ->map(static fn (int $id): int => $id)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        $workhoursUsersQuery = User::query()
            ->where('tenant_id', $tenantId)
            ->bookable();

        if ($selectedWorkhoursLocationId > 0) {
            $workhoursUsersQuery->whereHas('locations', function ($query) use ($selectedWorkhoursLocationId): void {
                $query->whereKey($selectedWorkhoursLocationId)
                    ->where('location_user.is_active', true);
            });
        }

        if (! $actor->isOwner()) {
            $workhoursUsersQuery->whereIn('role', $allowedRoles);
        }

        $workhoursUsers = $workhoursUsersQuery
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'tenant_id', 'name', 'role', 'is_bookable', 'is_active']);

        $workhoursShiftsByUserAndDate = [];
        $workhoursShifts = collect();

        if ($selectedWorkhoursLocationId > 0 && $workhoursUsers->isNotEmpty()) {
            $workhoursShifts = UserWorkShift::query()
                ->where('tenant_id', $tenantId)
                ->where('location_id', $selectedWorkhoursLocationId)
                ->whereIn('user_id', $workhoursUsers->pluck('id')->all())
                ->whereBetween('shift_date', [
                    $workhoursWeekStart->toDateString(),
                    $workhoursWeekEnd->toDateString(),
                ])
                ->orderBy('shift_date')
                ->orderBy('starts_at')
                ->get([
                    'id',
                    'tenant_id',
                    'location_id',
                    'user_id',
                    'shift_date',
                    'starts_at',
                    'ends_at',
                    'break_starts_at',
                    'break_ends_at',
                    'work_role',
                    'notes',
                    'is_public',
                    'published_at',
                ]);

            $workhoursShiftsByUserAndDate = $workhoursShifts
                ->groupBy(static function (UserWorkShift $shift): string {
                    return (int) $shift->user_id . '|' . $shift->shift_date->format('Y-m-d');
                })
                ->map(static fn ($shifts) => $shifts->values())
                ->all();
        }

        $workhoursShiftCount = (int) $workhoursShifts->count();
        $workhoursPublishedCount = (int) $workhoursShifts
            ->where('is_public', true)
            ->count();
        $isWorkhoursWeekPublic = $workhoursShiftCount > 0 && $workhoursPublishedCount === $workhoursShiftCount;

        return view('users', [
            'users' => $users,
            'competencyUsers' => $competencyUsers,
            'selectedCompetencyUser' => $selectedCompetencyUser,
            'selectedCompetencyUserId' => $selectedCompetencyUserId,
            'selectedCompetencyServiceIds' => $selectedCompetencyServiceIds,
            'selectedCompetencyLocation' => $selectedCompetencyLocation,
            'selectedCompetencyLocationId' => $selectedCompetencyLocationId,
            'locationCompetencyServiceIdsByUser' => $locationCompetencyServiceIdsByUser,
            'roles' => $this->rolesFor($actor),
            'locationOptions' => $locationOptions,
            'selectedWorkhoursLocationId' => $selectedWorkhoursLocationId,
            'workhoursDateInput' => $workhoursDate->toDateString(),
            'workhoursWeekStart' => $workhoursWeekStart,
            'workhoursUsers' => $workhoursUsers,
            'workhoursShiftsByUserAndDate' => $workhoursShiftsByUserAndDate,
            'workhoursShiftCount' => $workhoursShiftCount,
            'isWorkhoursWeekPublic' => $isWorkhoursWeekPublic,
            'workShiftsEnabled' => $workShiftsEnabled,
            'competencyServices' => Service::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_online_bookable']),
        ]);
    }

    public function store(Request $request, ?ActivityLogger $activityLogger = null): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        /** @var User $actor */
        $actor = $request->user();
        $allowedRoles = $this->allowedRoleValuesFor($actor);
        abort_if($allowedRoles === [], 403);
        $allowedLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'initials' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]{1,6}$/'],
            'role' => ['required', Rule::in($allowedRoles)],
            'competency_scope' => ['required', Rule::in([User::COMPETENCY_SCOPE_GLOBAL, User::COMPETENCY_SCOPE_LOCATION])],
            'password' => ['required', 'confirmed', Password::defaults()],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['integer', Rule::in($allowedLocationIds)],
            'profile_photo' => ['nullable', 'image', 'max:3072', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $payload['email'] = mb_strtolower(trim($payload['email']));
        $payload['initials'] = $this->resolveInitials($payload['name'], $payload['initials'] ?? null);
        $payload['is_bookable'] = $request->boolean('is_bookable');
        $payload['is_active'] = true;
        $payload['tenant_id'] = $tenantId;
        $selectedLocationIds = $this->resolveSelectedLocationIdsFromRequest($request, $allowedLocationIds);

        if ($payload['is_bookable'] && $selectedLocationIds === []) {
            return redirect()
                ->route('users.index')
                ->withErrors(['location_ids' => 'Vælg mindst en lokation for bookbare medarbejdere.'])
                ->withInput();
        }

        /** @var User $user */
        $user = User::query()->create($payload);
        $profilePhotoPath = $this->storeUserProfilePhoto($request->file('profile_photo'), $tenantId, (int) $user->id);

        if ($profilePhotoPath !== null) {
            $user->forceFill([
                'profile_photo_path' => $profilePhotoPath,
            ])->save();
        }

        $this->ensureBookableUserLocationAssignments($user, $selectedLocationIds);
        $activityLogger->logUserCreated(
            $actor,
            $user,
            $user->locations()->orderBy('name')->pluck('name')->all()
        );

        $statusMessage = 'Brugeren er oprettet. Bekræftelsesmail er sendt.';

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            report($exception);
            $statusMessage = 'Brugeren er oprettet, men bekræftelsesmailen kunne ikke sendes. Tjek mail-opsætningen.';
        }

        return redirect()
            ->route('users.index')
            ->with('status', $statusMessage);
    }

    public function update(Request $request, User $user, ?ActivityLogger $activityLogger = null): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_if(! $this->canManageTargetUser($actor, $user), 403);
        $allowedRoles = $this->allowedRoleValuesFor($actor);
        abort_if($allowedRoles === [], 403);
        $allowedLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);
        $beforeSnapshot = $activityLogger->userSnapshot(
            $user->loadMissing('locations:id,name'),
            $user->locations->pluck('name')->all()
        );

        if (! filled($request->input('password'))) {
            $request->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'initials' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]{1,6}$/'],
            'role' => ['required', Rule::in($allowedRoles)],
            'competency_scope' => ['required', Rule::in([User::COMPETENCY_SCOPE_GLOBAL, User::COMPETENCY_SCOPE_LOCATION])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['integer', Rule::in($allowedLocationIds)],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'profile_photo' => ['nullable', 'image', 'max:3072', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $payload['email'] = mb_strtolower(trim($payload['email']));
        $payload['initials'] = $this->resolveInitials($payload['name'], $payload['initials'] ?? null);
        $payload['is_bookable'] = $request->boolean('is_bookable');
        $selectedLocationIds = $this->resolveSelectedLocationIdsFromRequest($request, $allowedLocationIds);

        if ($payload['is_bookable'] && $selectedLocationIds === []) {
            return $this->redirectToEditUser(
                $request,
                $user,
                'Vælg mindst en lokation for bookbare medarbejdere.'
            );
        }

        if ($this->wouldRemoveLastOwner($tenantId, $user, $payload['role'])) {
            return $this->redirectToEditUser($request, $user, 'Der skal altid være mindst en ejer i systemet.');
        }

        if (blank($payload['password'])) {
            unset($payload['password']);
        }

        $normalizedEmail = mb_strtolower(trim((string) $payload['email']));
        $emailChanged = $normalizedEmail !== mb_strtolower(trim((string) $user->email));
        $payload['email'] = $normalizedEmail;

        $profilePhotoPath = UploadsStorage::normalizePath($user->profile_photo_path);

        if ((bool) ($payload['remove_profile_photo'] ?? false)) {
            $this->deleteManagedUserProfilePhoto($profilePhotoPath, $tenantId, (int) $user->id);
            $profilePhotoPath = null;
        }

        if ($request->hasFile('profile_photo')) {
            $storedProfilePhotoPath = $this->storeUserProfilePhoto($request->file('profile_photo'), $tenantId, (int) $user->id);

            if ($storedProfilePhotoPath !== null) {
                $this->deleteManagedUserProfilePhoto(
                    $profilePhotoPath,
                    $tenantId,
                    (int) $user->id,
                    $storedProfilePhotoPath
                );

                $profilePhotoPath = $storedProfilePhotoPath;
            }
        }

        $payload['profile_photo_path'] = $profilePhotoPath !== null ? $profilePhotoPath : null;
        unset($payload['remove_profile_photo']);

        if ($emailChanged) {
            $user->forceFill(array_merge($payload, [
                'email_verified_at' => null,
            ]))->save();
        } else {
            $user->update($payload);
        }
        $this->ensureBookableUserLocationAssignments($user, $selectedLocationIds);
        $refreshedUser = $user->fresh()->load('locations:id,name');
        $activityLogger->logUserUpdated(
            $actor,
            $refreshedUser,
            $beforeSnapshot,
            $refreshedUser->locations->pluck('name')->all()
        );

        $statusMessage = 'Brugeren er opdateret.';

        if ($emailChanged) {
            try {
                $user->sendEmailVerificationNotification();
                $statusMessage = 'Brugeren er opdateret. Bekraeftelsesmail er sendt til den nye e-mailadresse.';
            } catch (\Throwable $exception) {
                report($exception);
                $statusMessage = 'Brugeren er opdateret, men bekræftelsesmailen til den nye e-mailadresse kunne ikke sendes.';
            }
        }

        return redirect()
            ->route('users.index')
            ->with('status', $statusMessage);
    }

    public function toggleActive(Request $request, User $user, ?ActivityLogger $activityLogger = null): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_if(! $this->canManageTargetUser($actor, $user), 403);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $nextIsActive = (bool) $validated['is_active'];

        if (! $nextIsActive && $actor->is($user)) {
            return redirect()
                ->route('users.index', ['users_view' => 'manage'])
                ->withErrors(['users_toggle' => 'Du kan ikke sætte din egen bruger inaktiv, mens du er logget ind.']);
        }

        if (! $nextIsActive && $this->wouldDeactivateLastActiveOwner($tenantId, $user)) {
            return redirect()
                ->route('users.index', ['users_view' => 'manage'])
                ->withErrors(['users_toggle' => 'Der skal altid være mindst en aktiv ejer i systemet.']);
        }

        $user->update([
            'is_active' => $nextIsActive,
        ]);
        $activityLogger->logUserActivationChanged(
            $actor,
            $user->fresh()->load('locations:id,name'),
            $nextIsActive
        );

        return redirect()
            ->route('users.index', ['users_view' => 'manage'])
            ->with('status', $nextIsActive ? 'Brugeren er sat aktiv.' : 'Brugeren er sat inaktiv.');
    }

    public function destroy(Request $request, User $user, ?ActivityLogger $activityLogger = null): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $user->tenant_id !== $tenantId, 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_if(! $this->canManageTargetUser($actor, $user), 403);

        if ($request->user()?->is($user)) {
            return $this->redirectToEditUser($request, $user, 'Du kan ikke slette din egen bruger, mens du er logget ind.');
        }

        if ($this->wouldRemoveLastOwner($tenantId, $user, null)) {
            return $this->redirectToEditUser($request, $user, 'Den sidste ejer kan ikke slettes.');
        }

        $beforeSnapshot = array_merge(
            ['id' => (int) $user->id],
            $activityLogger->userSnapshot(
                $user->loadMissing('locations:id,name'),
                $user->locations->pluck('name')->all()
            )
        );
        $this->deleteManagedUserProfilePhoto($user->profile_photo_path, $tenantId, (int) $user->id);
        $user->delete();
        $activityLogger->logUserDeleted($actor, $tenantId, $beforeSnapshot);

        return redirect()
            ->route('users.index')
            ->with('status', 'Brugeren er slettet.');
    }

    public function updatePermissions(Request $request, ?ActivityLogger $activityLogger = null): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        /** @var User $actor */
        $actor = $request->user();
        abort_if(! $actor->canManageRolePermissions(), 403);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['sometimes', 'array'],
        ]);
        $requestedMatrix = data_get($validated, 'permissions', []);

        if (! is_array($requestedMatrix)) {
            $requestedMatrix = [];
        }

        $this->permissionManager()->updateTenantRolePermissions($tenantId, $requestedMatrix);
        $activityLogger->logRolePermissionsUpdated($actor, $tenantId);

        return redirect()
            ->route('settings.index', $this->settingsRedirectParameters(
                $request,
                $tenantId,
                'permissions'
            ))
            ->with('status', 'Rettighederne er opdateret.');
    }

    public function updateCompetenciesBulk(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        /** @var User $actor */
        $actor = $request->user();
        $allowedLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        if ($allowedLocationIds === []) {
            return redirect()
                ->route('users.index', ['users_view' => 'competencies'])
                ->withErrors(['competencies' => 'Ingen aktive lokationer fundet for din adgang.']);
        }

        $validated = $request->validate([
            'competency_location_id' => ['required', 'integer', Rule::in($allowedLocationIds)],
            'competency_user_id' => ['required', 'integer'],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer'],
        ]);
        $selectedLocationId = (int) $validated['competency_location_id'];
        $targetUserId = (int) $validated['competency_user_id'];
        $selectedServiceIds = collect($validated['service_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($selectedServiceIds !== []) {
            $validServiceIds = Service::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $selectedServiceIds)
                ->pluck('id')
                ->map(static fn (int $id): int => $id)
                ->all();

            if (count($validServiceIds) !== count($selectedServiceIds)) {
                return redirect()
                    ->route('users.index', [
                        'users_view' => 'competencies',
                        'competency_location_id' => $selectedLocationId,
                        'competency_user_id' => $targetUserId,
                    ])
                    ->withErrors(['competencies' => 'En eller flere ydelser kunne ikke findes. Opdater siden og prøv igen.']);
            }

            $selectedServiceIds = $validServiceIds;
        }

        $allowedRoles = $this->allowedRoleValuesFor($actor);
        $targetUserQuery = User::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $targetUserId);

        if (! $actor->isOwner()) {
            $targetUserQuery->whereIn('role', $allowedRoles);
        }

        /** @var User|null $targetUser */
        $targetUser = $targetUserQuery->first(['id', 'tenant_id', 'role', 'competency_scope']);

        if (! $targetUser || ! $this->canManageTargetUser($actor, $targetUser)) {
            return redirect()
                ->route('users.index', [
                    'users_view' => 'competencies',
                    'competency_location_id' => $selectedLocationId,
                ])
                ->withErrors(['competencies' => 'Denne medarbejder kan ikke redigeres med din nuværende rolle.']);
        }

        if (! $targetUser->usesLocationCompetencies()) {
            $targetUser->services()->sync($selectedServiceIds);
        } else {
            $isAssignedToLocation = DB::table('location_user')
                ->where('location_id', $selectedLocationId)
                ->where('user_id', (int) $targetUser->id)
                ->where('is_active', true)
                ->exists();

            if (! $isAssignedToLocation) {
                return redirect()
                    ->route('users.index', [
                        'users_view' => 'competencies',
                        'competency_location_id' => $selectedLocationId,
                        'competency_user_id' => $targetUserId,
                    ])
                    ->withErrors(['competencies' => 'Medarbejderen er ikke aktiv på den valgte lokation.']);
            }

            DB::transaction(function () use ($selectedLocationId, $targetUser, $selectedServiceIds): void {
                DB::table('location_service_user')
                    ->where('location_id', $selectedLocationId)
                    ->where('user_id', (int) $targetUser->id)
                    ->delete();

                if ($selectedServiceIds === []) {
                    return;
                }

                $now = now();
                DB::table('location_service_user')->insert(
                    collect($selectedServiceIds)
                        ->map(static fn (int $serviceId): array => [
                            'location_id' => $selectedLocationId,
                            'service_id' => $serviceId,
                            'user_id' => (int) $targetUser->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all()
                );
            });
        }

        return redirect()
            ->route('users.index', [
                'users_view' => 'competencies',
                'competency_location_id' => $selectedLocationId,
                'competency_user_id' => $targetUserId,
            ])
            ->with('status', 'Kompetencer er opdateret.');
    }

    public function storeWorkShift(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $this->abortIfWorkShiftsDisabled($tenantId);
        /** @var User $actor */
        $actor = $request->user();
        $allowedRoles = $this->allowedRoleValuesFor($actor);
        abort_if($allowedRoles === [], 403);
        $allowedLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        $validated = $request->validate([
            'form_scope' => ['nullable', 'string'],
            'shift_mode' => ['nullable', Rule::in(['create', 'update'])],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('user_work_shifts', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'workhours_location_id' => ['required', 'integer', Rule::in($allowedLocationIds)],
            'workhours_date' => ['required', 'date'],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('tenant_id', $tenantId)
            )],
            'shift_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'break_starts_at' => ['nullable', 'date_format:H:i'],
            'break_ends_at' => ['nullable', 'date_format:H:i'],
            'is_public' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $locationId = (int) $validated['workhours_location_id'];
        $targetUserId = (int) $validated['user_id'];
        $shiftDate = (string) $validated['shift_date'];
        $startsAt = (string) $validated['starts_at'];
        $endsAt = (string) $validated['ends_at'];
        $breakStartsAt = filled($validated['break_starts_at'] ?? null) ? (string) $validated['break_starts_at'] : null;
        $breakEndsAt = filled($validated['break_ends_at'] ?? null) ? (string) $validated['break_ends_at'] : null;
        $workRole = UserWorkShift::ROLE_SERVICE;
        $hasPublicField = $request->has('is_public');
        $notes = filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null;
        $shiftId = max(0, (int) ($validated['shift_id'] ?? 0));
        $shiftMode = (string) ($validated['shift_mode'] ?? 'create');
        $isUpdateMode = $shiftMode === 'update';

        $targetUserQuery = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($targetUserId);

        if (! $actor->isOwner()) {
            $targetUserQuery->whereIn('role', $allowedRoles);
        }

        /** @var User|null $targetUser */
        $targetUser = $targetUserQuery->first();
        abort_if(! $targetUser || ! $this->canManageTargetUser($actor, $targetUser), 403);

        $userActiveAtLocation = $targetUser->locations()
            ->where('locations.id', $locationId)
            ->where('location_user.is_active', true)
            ->exists();

        if (! $userActiveAtLocation) {
            throw ValidationException::withMessages([
                'user_id' => 'Medarbejderen er ikke aktiv på den valgte lokation.',
            ]);
        }

        if (! $targetUser->is_bookable) {
            throw ValidationException::withMessages([
                'user_id' => 'Denne medarbejder er ikke bookbar og kan derfor ikke tildeles bookbar tid.',
            ]);
        }

        $startMinutes = $this->minutesFromHourMinute($startsAt);
        $endMinutes = $this->minutesFromHourMinute($endsAt);

        if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
            throw ValidationException::withMessages([
                'ends_at' => 'Bookbar tid skal slutte efter starttid.',
            ]);
        }

        if (($breakStartsAt === null) xor ($breakEndsAt === null)) {
            throw ValidationException::withMessages([
                'break_starts_at' => 'Udfyld både pause start og pause slut, eller lad begge være tomme.',
            ]);
        }

        if ($breakStartsAt !== null && $breakEndsAt !== null) {
            $breakStartMinutes = $this->minutesFromHourMinute($breakStartsAt);
            $breakEndMinutes = $this->minutesFromHourMinute($breakEndsAt);

            if (
                $breakStartMinutes === null
                || $breakEndMinutes === null
                || $breakEndMinutes <= $breakStartMinutes
            ) {
                throw ValidationException::withMessages([
                    'break_ends_at' => 'Pause skal slutte efter pause-start.',
                ]);
            }

            if ($breakStartMinutes < $startMinutes || $breakEndMinutes > $endMinutes) {
                throw ValidationException::withMessages([
                    'break_starts_at' => 'Pause skal ligge inden for den bookbare tid.',
                ]);
            }
        }

        /** @var UserWorkShift|null $existingShift */
        $existingShift = null;

        if ($isUpdateMode && $shiftId > 0) {
            $existingShift = UserWorkShift::query()
                ->where('tenant_id', $tenantId)
                ->find($shiftId);
        }

        if ($existingShift && (int) $existingShift->tenant_id !== $tenantId) {
            $existingShift = null;
        }

        if ($isUpdateMode && (! $existingShift || $shiftId <= 0)) {
            throw ValidationException::withMessages([
                'shift_id' => 'Vagten kunne ikke findes. Opdater siden og prøv igen.',
            ]);
        }

        $isPublic = $hasPublicField
            ? $request->boolean('is_public')
            : ($existingShift ? (bool) $existingShift->is_public : false);

        if ($existingShift) {
            $existingShiftUser = User::query()
                ->where('tenant_id', $tenantId)
                ->find((int) $existingShift->user_id);
            abort_if(
                ! $existingShiftUser || ! $this->canManageTargetUser($actor, $existingShiftUser),
                403
            );
        }

        $startsAtSql = $startsAt . ':00';
        $endsAtSql = $endsAt . ':00';
        $overlapExists = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $targetUser->id)
            ->whereDate('shift_date', $shiftDate)
            ->when($existingShift !== null, static function ($query) use ($existingShift): void {
                $query->whereKeyNot((int) $existingShift->id);
            })
            ->where('starts_at', '<', $endsAtSql)
            ->where('ends_at', '>', $startsAtSql)
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'starts_at' => 'Medarbejderen har allerede en vagt i det valgte tidsrum.',
            ]);
        }

        $publishedAt = null;
        $publishedByUserId = null;

        if ($isPublic) {
            if ($existingShift && (bool) $existingShift->is_public && $existingShift->published_at !== null) {
                $publishedAt = $existingShift->published_at;
                $publishedByUserId = $existingShift->published_by_user_id ?: (int) $actor->id;
            } else {
                $publishedAt = now();
                $publishedByUserId = (int) $actor->id;
            }
        }

        $payload = [
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'user_id' => (int) $targetUser->id,
            'shift_date' => $shiftDate,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_starts_at' => $breakStartsAt,
            'break_ends_at' => $breakEndsAt,
            'work_role' => $workRole,
            'is_public' => $isPublic,
            'published_at' => $publishedAt,
            'published_by_user_id' => $publishedByUserId,
            'notes' => $notes,
        ];

        if ($isUpdateMode && $existingShift) {
            $existingShift->update($payload);
        } else {
            $payload['created_by_user_id'] = (int) $actor->id;
            UserWorkShift::query()->create($payload);
        }

        return redirect()
            ->route('users.index', $this->workhoursRedirectParams(
                $request,
                $locationId,
                $shiftDate
            ))
            ->with('status', 'Bookbarhed er gemt.');
    }

    public function publishWorkShifts(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $this->abortIfWorkShiftsDisabled($tenantId);
        /** @var User $actor */
        $actor = $request->user();
        $allowedRoles = $this->allowedRoleValuesFor($actor);
        abort_if($allowedRoles === [], 403);
        $allowedLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        $validated = $request->validate([
            'workhours_location_id' => ['required', 'integer', Rule::in($allowedLocationIds)],
            'workhours_date' => ['required', 'date'],
            'publish_from_date' => ['required', 'date'],
            'publish_to_date' => ['required', 'date', 'after_or_equal:publish_from_date'],
        ]);

        $locationId = (int) $validated['workhours_location_id'];
        $timezone = (string) config('app.timezone', 'UTC');

        try {
            $workhoursDate = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $validated['workhours_date'],
                $timezone
            )->startOfDay();
        } catch (\Throwable) {
            $workhoursDate = CarbonImmutable::now($timezone)->startOfDay();
        }

        try {
            $publishFromDate = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $validated['publish_from_date'],
                $timezone
            )->startOfDay();
            $publishToDate = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $validated['publish_to_date'],
                $timezone
            )->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'publish_from_date' => 'Periode-datoer er ugyldige. Vælg periode igen.',
            ]);
        }

        $query = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereBetween('shift_date', [
                $publishFromDate->toDateString(),
                $publishToDate->toDateString(),
            ]);

        if (! $actor->isOwner()) {
            $query->whereHas('user', function ($userQuery) use ($allowedRoles): void {
                $userQuery->whereIn('role', $allowedRoles);
            });
        }

        $now = now();
        $query->update([
            'is_public' => true,
            'published_at' => $now,
            'published_by_user_id' => (int) $actor->id,
            'updated_at' => $now,
        ]);

        return redirect()->route('users.index', [
            'users_view' => 'workhours',
            'workhours_location_id' => $locationId,
            'workhours_date' => $workhoursDate->toDateString(),
        ]);
    }

    public function publishSingleWorkShift(Request $request, UserWorkShift $workShift): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $this->abortIfWorkShiftsDisabled($tenantId);
        abort_if((int) $workShift->tenant_id !== $tenantId, 404);
        /** @var User $actor */
        $actor = $request->user();

        $targetUser = User::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $workShift->user_id);
        abort_if(! $targetUser || ! $this->canManageTargetUser($actor, $targetUser), 403);

        $workShift->update([
            'is_public' => true,
            'published_at' => now(),
            'published_by_user_id' => (int) $actor->id,
        ]);

        $fallbackLocationId = (int) $workShift->location_id;
        $fallbackDate = $workShift->shift_date instanceof CarbonInterface
            ? $workShift->shift_date->toDateString()
            : (string) $workShift->shift_date;

        return redirect()->route('users.index', $this->workhoursRedirectParams(
            $request,
            $fallbackLocationId,
            $fallbackDate
        ));
    }

    public function destroyWorkShift(Request $request, UserWorkShift $workShift): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $this->abortIfWorkShiftsDisabled($tenantId);
        abort_if((int) $workShift->tenant_id !== $tenantId, 404);
        /** @var User $actor */
        $actor = $request->user();
        $targetUser = User::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $workShift->user_id);
        abort_if(! $targetUser || ! $this->canManageTargetUser($actor, $targetUser), 403);

        $fallbackLocationId = (int) $workShift->location_id;
        $fallbackDate = $workShift->shift_date instanceof CarbonInterface
            ? $workShift->shift_date->toDateString()
            : (string) $workShift->shift_date;

        $workShift->delete();

        return redirect()
            ->route('users.index', $this->workhoursRedirectParams(
                $request,
                $fallbackLocationId,
                $fallbackDate
            ))
            ->with('status', 'Vagten er slettet.');
    }

    private function rolesFor(User $actor): array
    {
        return array_intersect_key(
            TenantRole::options(),
            array_flip($this->allowedRoleValuesFor($actor))
        );
    }

    /**
     * @return list<string>
     */
    private function allowedRoleValuesFor(User $actor): array
    {
        $configured = config('tenant_permissions.user_management.manageable_roles.' . $actor->roleValue(), []);

        if (is_array($configured)) {
            $validRoles = array_flip(TenantRole::values());
            $resolved = collect($configured)
                ->map(static fn (mixed $role): string => trim((string) $role))
                ->filter(static fn (string $role): bool => $role !== '' && isset($validRoles[$role]))
                ->values()
                ->all();

            if ($resolved !== []) {
                return $resolved;
            }
        }

        return match ($actor->roleValue()) {
            User::ROLE_OWNER => TenantRole::values(),
            User::ROLE_LOCATION_MANAGER => [User::ROLE_MANAGER, User::ROLE_STAFF],
            User::ROLE_MANAGER => [User::ROLE_STAFF],
            default => [],
        };
    }

    private function canManageTargetUser(User $actor, User $target): bool
    {
        return in_array($target->roleValue(), $this->allowedRoleValuesFor($actor), true);
    }

    private function redirectToEditUser(Request $request, User $user, string $message): RedirectResponse
    {
        return redirect()
            ->route('users.index')
            ->withErrors(['user_edit' => $message])
            ->withInput([
                'form_scope' => 'edit',
                'modal_user_id' => $user->id,
                'name' => (string) $request->input('name', $user->name),
                'email' => (string) $request->input('email', $user->email),
                'initials' => (string) $request->input('initials', $user->initials),
                'role' => (string) $request->input('role', $user->roleValue()),
                'competency_scope' => (string) $request->input('competency_scope', $user->competencyScopeValue()),
                'is_bookable' => $request->boolean('is_bookable', $user->is_bookable),
                'remove_profile_photo' => $request->boolean('remove_profile_photo'),
                'location_ids' => collect($request->input(
                    'location_ids',
                    $user->locations()->wherePivot('is_active', true)->pluck('locations.id')->all()
                ))
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->values()
                    ->all(),
            ]);
    }

    private function wouldRemoveLastOwner(int $tenantId, User $user, ?string $newRole): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        if ($newRole === User::ROLE_OWNER) {
            return false;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_OWNER)
            ->count() <= 1;
    }

    private function wouldDeactivateLastActiveOwner(int $tenantId, User $user): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        if (! (bool) $user->is_active) {
            return false;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_OWNER)
            ->where('is_active', true)
            ->count() <= 1;
    }

    private function resolveInitials(string $name, ?string $initials): string
    {
        if (filled($initials)) {
            return strtoupper(trim((string) $initials));
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $generated = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $generated !== '' ? $generated : 'NA';
    }

    private function minutesFromHourMinute(string $value): ?int
    {
        $candidate = trim($value);

        if (preg_match('/^(2[0-3]|[01][0-9]):([0-5][0-9])$/', $candidate, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /**
     * @return array{users_view: string, workhours_location_id: int, workhours_date: string}
     */
    private function workhoursRedirectParams(
        Request $request,
        int $fallbackLocationId,
        string $fallbackDate
    ): array {
        $locationId = max(1, (int) $request->input(
            'workhours_location_id',
            $request->query('workhours_location_id', $fallbackLocationId)
        ));
        $dateInput = trim((string) $request->input(
            'workhours_date',
            $request->query('workhours_date', $fallbackDate)
        ));
        $timezone = (string) config('app.timezone', 'UTC');

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $dateInput, $timezone)->toDateString();
        } catch (\Throwable) {
            $date = CarbonImmutable::now($timezone)->toDateString();
        }

        return [
            'users_view' => 'workhours',
            'workhours_location_id' => $locationId,
            'workhours_date' => $date,
        ];
    }

    /**
     * @param list<int> $selectedLocationIds
     */
    private function ensureBookableUserLocationAssignments(User $user, array $selectedLocationIds): void
    {
        if (! $user->is_bookable) {
            $user->locations()->detach();

            return;
        }

        if ($selectedLocationIds === []) {
            return;
        }

        $user->locations()->syncWithPivotValues(
            $selectedLocationIds,
            ['is_active' => true],
            true
        );
    }

    /**
     * @param list<int> $allowedLocationIds
     * @return list<int>
     */
    private function resolveSelectedLocationIdsFromRequest(Request $request, array $allowedLocationIds): array
    {
        return collect($request->input('location_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => in_array($id, $allowedLocationIds, true))
            ->unique()
            ->values()
            ->all();
    }

    private function storeUserProfilePhoto(?UploadedFile $file, int $tenantId, int $userId): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        if ($extension === '') {
            return null;
        }

        $directoryRelative = 'tenant-assets/' . $tenantId . '/users/' . $userId . '/profile';

        foreach (UploadsStorage::files($directoryRelative) as $existingFile) {
            if (preg_match('/\/profile\.[A-Za-z0-9]+$/', $existingFile) === 1) {
                UploadsStorage::delete($existingFile);
            }
        }

        $filename = 'profile.' . $extension;
        $storedPath = UploadsStorage::putFileAs($directoryRelative, $file, $filename);

        if (! is_string($storedPath) || $storedPath === '') {
            return null;
        }

        return $storedPath;
    }

    private function deleteManagedUserProfilePhoto(
        ?string $path,
        int $tenantId,
        int $userId,
        ?string $excludePath = null
    ): void {
        $trimmed = UploadsStorage::normalizePath($path);

        if ($trimmed === null) {
            return;
        }

        $normalizedExcludePath = UploadsStorage::normalizePath($excludePath);

        if ($normalizedExcludePath !== null && $trimmed === $normalizedExcludePath) {
            return;
        }

        $expectedPrefix = 'tenant-assets/' . $tenantId . '/users/' . $userId . '/profile/';

        if (! str_starts_with($trimmed, $expectedPrefix)) {
            return;
        }

        UploadsStorage::delete($trimmed);
    }

    /**
     * @return array{location_id: int, settings_view: string}
     */
    private function settingsRedirectParameters(Request $request, int $tenantId, string $settingsView): array
    {
        $requestedLocationId = max(0, (int) $request->input(
            'location_id',
            $request->query('location_id', 0)
        ));

        $locationId = $this->canAccessLocation($request, $tenantId, $requestedLocationId)
            ? $requestedLocationId
            : $this->resolveLocationId($request, $tenantId);

        return [
            'location_id' => $locationId,
            'settings_view' => $settingsView,
        ];
    }

    private function permissionManager(): TenantRolePermissionManager
    {
        return app(TenantRolePermissionManager::class);
    }

    private function abortIfWorkShiftsDisabled(int $tenantId): void
    {
        abort_unless(
            $this->isWorkShiftsEnabledForTenant($tenantId),
            403,
            'Bookbarhed er slået fra i indstillinger for denne virksomhed.'
        );
    }

    private function isWorkShiftsEnabledForTenant(int $tenantId): bool
    {
        return (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);
    }
}
