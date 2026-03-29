<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BrandingSettingsController;
use App\Http\Controllers\BookingCalendarController;
use App\Http\Controllers\BookingManagementController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MyShiftsController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformLoginController;
use App\Http\Controllers\Platform\PlatformTenantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\ServiceManagementController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

// GLOBALE URL ROUTES
Route::get('/book-tid', [PublicBookingController::class, 'create'])
    ->middleware('throttle:public-booking-view')
    ->name('public-booking.create');
Route::get('/book-tid/time-options', [PublicBookingController::class, 'timeOptions'])
    ->middleware('throttle:public-booking-time-options')
    ->name('public-booking.time-options');
Route::post('/book-tid', [PublicBookingController::class, 'store'])
    ->middleware('throttle:public-booking-store')
    ->name('public-booking.store');

Route::prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::middleware('guest:platform')->group(function (): void {
            Route::get('/login', [PlatformLoginController::class, 'show'])->name('login');
            Route::post('/login', [PlatformLoginController::class, 'store'])
                ->middleware('throttle:login')
                ->name('login.store');
        });

        Route::middleware('auth:platform')->group(function (): void {
            Route::get('/', [PlatformDashboardController::class, 'index'])->name('dashboard');
            Route::post('/tenants', [PlatformDashboardController::class, 'store'])->name('tenants.store');
            Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show'])->name('tenants.show');
            Route::patch('/tenants/{tenant}', [PlatformTenantController::class, 'update'])->name('tenants.update');
            Route::delete('/tenants/{tenant}', [PlatformTenantController::class, 'destroy'])->name('tenants.destroy');
            Route::post('/tenants/{tenant}/owners', [PlatformTenantController::class, 'storeOwner'])->name('tenants.owners.store');
            Route::post('/tenants/{tenant}/locations', [PlatformTenantController::class, 'storeLocation'])->name('tenants.locations.store');
            Route::patch('/tenants/{tenant}/locations/{location}', [PlatformTenantController::class, 'updateLocation'])->name('tenants.locations.update');
            Route::delete('/tenants/{tenant}/locations/{location}', [PlatformTenantController::class, 'destroyLocation'])->name('tenants.locations.destroy');
            Route::post('/logout', [PlatformLoginController::class, 'destroy'])->name('logout');
        });
    });

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', BookingCalendarController::class)->name('booking-calender');
    Route::get('/mine-vagter', MyShiftsController::class)->name('my-shifts.index');
    Route::get('/bookinger/time-options', [BookingCalendarController::class, 'timeOptions'])
        ->middleware('can:bookings.manage')
        ->name('booking-calender.time-options');
    Route::post('/bookinger', [BookingManagementController::class, 'store'])
        ->middleware('can:bookings.manage')
        ->name('bookings.store');
    Route::patch('/bookinger/{booking}', [BookingManagementController::class, 'update'])
        ->middleware('can:bookings.manage')
        ->name('bookings.update');
    Route::patch('/bookinger/{booking}/cancel', [BookingManagementController::class, 'cancel'])
        ->middleware('can:bookings.manage')
        ->name('bookings.cancel');
    Route::patch('/bookinger/{booking}/complete', [BookingManagementController::class, 'complete'])
        ->middleware('can:bookings.manage')
        ->name('bookings.complete');
    Route::get('/ydelser', [ServiceManagementController::class, 'index'])
        ->middleware('can:services.manage')
        ->name('services.index');
    Route::post('/ydelser', [ServiceManagementController::class, 'store'])
        ->middleware('can:services.manage')
        ->name('services.store');
    Route::post('/ydelser/kategorier', [ServiceManagementController::class, 'storeCategory'])
        ->middleware('can:services.manage')
        ->name('services.categories.store');
    Route::patch('/ydelser/kategorier/{serviceCategory}', [ServiceManagementController::class, 'updateCategory'])
        ->middleware('can:services.manage')
        ->name('services.categories.update');
    Route::delete('/ydelser/kategorier/{serviceCategory}', [ServiceManagementController::class, 'destroyCategory'])
        ->middleware('can:services.manage')
        ->name('services.categories.destroy');
    Route::patch('/ydelser/{service}', [ServiceManagementController::class, 'update'])
        ->middleware('can:services.manage')
        ->name('services.update');
    Route::patch('/ydelser/{service}/toggle-active', [ServiceManagementController::class, 'toggleActive'])
        ->middleware('can:services.manage')
        ->name('services.toggle-active');
    Route::patch('/ydelser/{service}/toggle-online', [ServiceManagementController::class, 'toggleOnlineBookable'])
        ->middleware('can:services.manage')
        ->name('services.toggle-online');
    Route::delete('/ydelser/{service}', [ServiceManagementController::class, 'destroy'])
        ->middleware('can:services.manage')
        ->name('services.destroy');
    Route::get('/tilgaengelighed', [AvailabilityController::class, 'index'])
        ->middleware('can:availability.manage')
        ->name('availability.index');
    Route::get('/indstillinger', [BrandingSettingsController::class, 'index'])
        ->middleware('can:settings.location.manage')
        ->name('settings.index');
    Route::patch('/indstillinger', [BrandingSettingsController::class, 'update'])
        ->middleware('can:settings.location.manage')
        ->name('settings.update');
    Route::get('/profil', [ProfileController::class, 'index'])->name('profile.index');
    Route::patch('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/tilgaengelighed/opening-hours', [AvailabilityController::class, 'storeOpeningHour'])
        ->middleware('can:availability.manage')
        ->name('availability.opening-hours.store');
    Route::delete('/tilgaengelighed/opening-hours/{openingHour}', [AvailabilityController::class, 'destroyOpeningHour'])
        ->middleware('can:availability.manage')
        ->name('availability.opening-hours.destroy');
    Route::post('/tilgaengelighed/closures', [AvailabilityController::class, 'storeClosure'])
        ->middleware('can:availability.manage')
        ->name('availability.closures.store');
    Route::delete('/tilgaengelighed/closures/{closure}', [AvailabilityController::class, 'destroyClosure'])
        ->middleware('can:availability.manage')
        ->name('availability.closures.destroy');
    Route::post('/tilgaengelighed/date-overrides', [AvailabilityController::class, 'storeDateOverride'])
        ->middleware('can:availability.manage')
        ->name('availability.date-overrides.store');
    Route::delete('/tilgaengelighed/date-overrides/{override}', [AvailabilityController::class, 'destroyDateOverride'])
        ->middleware('can:availability.manage')
        ->name('availability.date-overrides.destroy');
    Route::post('/tilgaengelighed/date-overrides/{override}/slots', [AvailabilityController::class, 'storeDateOverrideSlot'])
        ->middleware('can:availability.manage')
        ->name('availability.date-overrides.slots.store');
    Route::delete('/tilgaengelighed/date-overrides/slots/{overrideSlot}', [AvailabilityController::class, 'destroyDateOverrideSlot'])
        ->middleware('can:availability.manage')
        ->name('availability.date-overrides.slots.destroy');
    Route::get('/brugere', [UserManagementController::class, 'index'])
        ->middleware('can:users.manage')
        ->name('users.index');
    Route::post('/brugere', [UserManagementController::class, 'store'])
        ->middleware('can:users.manage')
        ->name('users.store');
    Route::patch('/brugere/rettigheder', [UserManagementController::class, 'updatePermissions'])
        ->middleware('can:users.permissions.manage')
        ->name('users.permissions.update');
    Route::patch('/brugere/kompetencer', [UserManagementController::class, 'updateCompetenciesBulk'])
        ->middleware('can:users.manage')
        ->name('users.competencies.bulk-update');
    Route::post('/brugere/vagter', [UserManagementController::class, 'storeWorkShift'])
        ->middleware('can:users.manage')
        ->name('users.work-shifts.store');
    Route::post('/brugere/vagter/offentliggoer', [UserManagementController::class, 'publishWorkShifts'])
        ->middleware('can:users.manage')
        ->name('users.work-shifts.publish');
    Route::post('/brugere/vagter/{workShift}/offentliggoer', [UserManagementController::class, 'publishSingleWorkShift'])
        ->middleware('can:users.manage')
        ->name('users.work-shifts.publish-single');
    Route::delete('/brugere/vagter/{workShift}', [UserManagementController::class, 'destroyWorkShift'])
        ->middleware('can:users.manage')
        ->name('users.work-shifts.destroy');
    Route::patch('/brugere/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])
        ->middleware('can:users.manage')
        ->name('users.toggle-active');
    Route::patch('/brugere/{user}', [UserManagementController::class, 'update'])
        ->middleware('can:users.manage')
        ->name('users.update');
    Route::delete('/brugere/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('can:users.manage')
        ->name('users.destroy');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
