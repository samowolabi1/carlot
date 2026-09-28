<?php

use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\ProfileNameController;
use App\Http\Controllers\Bookings\BookingController;
use App\Http\Controllers\Bookings\CustomerBookingController;
use App\Http\Controllers\Dealer\CalendarController;
use App\Http\Controllers\Dealer\DashboardController;
use App\Http\Controllers\Dealer\DealerHomeController;
use App\Http\Controllers\Dealer\Manager\CustomerController;
use App\Http\Controllers\Dealer\Manager\OrderController;
use App\Http\Controllers\Dealer\Manager\PaymentController;
use App\Http\Controllers\Dealer\Manager\SyncController;
use App\Http\Controllers\Dealer\Manager\TaskController;
use App\Http\Controllers\Dealer\Manager\TodayController;
use App\Http\Controllers\Dealer\Manager\WalkInController;
use App\Http\Controllers\Dealer\OnboardingController;
use App\Http\Controllers\Dealer\SettingsController;
use App\Http\Controllers\Dealer\StaffController;
use App\Http\Controllers\Dealer\VehicleController;
use App\Http\Controllers\Dealer\VehicleMediaController;
use App\Http\Controllers\Dealer\VinDecodeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Marketplace\CarController;
use App\Http\Controllers\Marketplace\CompareController;
use App\Http\Controllers\Marketplace\FavouriteController;
use App\Http\Controllers\Marketplace\HomeController;
use App\Http\Controllers\Marketplace\LotSiteController;
use App\Http\Controllers\Marketplace\SearchController;
use App\Http\Controllers\Orders\OrderTrackingController;
use Illuminate\Support\Facades\Route;

// Marketplace (M4) and lot mini-sites (M6)
Route::get('/', HomeController::class)->name('home');
Route::get('/cars', SearchController::class)->middleware('throttle:120,1')->name('cars.index');
Route::get('/car/{ref}', CarController::class)->where('ref', '[0-9A-Za-z]{26}(-[a-z0-9-]+)?')->name('cars.show');
Route::get('/compare', CompareController::class)->name('compare');
Route::get('/l/{lot:slug}', LotSiteController::class)->name('lots.show');
Route::get('/lots/{lot:slug}/slots', [BookingController::class, 'slots'])->name('lots.slots');

// Bookings (M7). Signed links from WhatsApp/SMS open these without signing in.
Route::get('/bookings/{appointment}', [CustomerBookingController::class, 'show'])->name('bookings.show');
Route::get('/bookings/{appointment}/calendar.ics', [CustomerBookingController::class, 'calendar'])->name('bookings.calendar');
Route::post('/bookings/{appointment}/cancel', [CustomerBookingController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');

// Order tracking (M19). Signed links on receipts and messages; no sign-in needed.
Route::middleware(['signed', 'throttle:60,1'])->scopeBindings()->group(function () {
    Route::get('/o/{order}', [OrderTrackingController::class, 'show'])->name('orders.track');
    Route::get('/o/{order}/receipts/{payment}', [OrderTrackingController::class, 'receipt'])->name('orders.receipt');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [OtpLoginController::class, 'create'])->name('login');
    Route::post('/auth/otp', [OtpLoginController::class, 'store'])->middleware('throttle:otp')->name('login.send');
    Route::get('/auth/verify', [OtpLoginController::class, 'edit'])->name('login.verify');
    Route::post('/auth/verify', [OtpLoginController::class, 'update'])->middleware('throttle:otp')->name('login.check');
    Route::post('/auth/resend', [OtpLoginController::class, 'resend'])->middleware('throttle:otp')->name('login.resend');
});

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [OtpLoginController::class, 'destroy'])->name('logout');
    Route::get('/welcome', [ProfileNameController::class, 'edit'])->name('profile.name');
    Route::put('/welcome', [ProfileNameController::class, 'update'])->name('profile.name.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/saved', [FavouriteController::class, 'index'])->name('saved');
    Route::post('/favourites/{vehicle}', [FavouriteController::class, 'store'])->name('favourites.store');
    Route::delete('/favourites/{vehicle}', [FavouriteController::class, 'destroy'])->name('favourites.destroy');
    Route::get('/favourites/{vehicle}/save', [FavouriteController::class, 'remember'])->name('favourites.remember');
});

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::get('/book/{lot:slug}', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('/appointments', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
    Route::get('/bookings', [CustomerBookingController::class, 'index'])->name('bookings.index');
    Route::patch('/bookings/{appointment}', [CustomerBookingController::class, 'update'])->middleware('throttle:10,1')->name('bookings.update');

    Route::get('/dealer', DealerHomeController::class)->name('dealer.home');
    Route::get('/dealer/start', [OnboardingController::class, 'create'])->name('dealer.onboarding.start');
    Route::post('/dealer/lots', [OnboardingController::class, 'store'])->name('dealer.lots.store');

    Route::prefix('/dealer/{lot}')
        ->middleware('lot.member')
        ->name('dealer.')
        ->scopeBindings()
        ->group(function () {
            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/onboarding/{step}', [OnboardingController::class, 'show'])->name('onboarding.show');
            Route::post('/onboarding/submit', [OnboardingController::class, 'submit'])->name('onboarding.submit');

            Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
            Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
            Route::post('/settings/branding', [SettingsController::class, 'updateBranding'])->name('settings.branding');
            Route::put('/settings/location', [SettingsController::class, 'updateLocation'])->name('settings.location');
            Route::put('/settings/hours', [SettingsController::class, 'updateHours'])->name('settings.hours');

            Route::get('/staff', [StaffController::class, 'index'])->name('staff');
            Route::post('/staff/invitations', [StaffController::class, 'invite'])->name('staff.invite');
            Route::post('/staff/invitations/{invitation}/resend', [StaffController::class, 'resend'])->name('staff.invitations.resend');
            Route::delete('/staff/invitations/{invitation}', [StaffController::class, 'cancel'])->name('staff.invitations.cancel');
            Route::patch('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
            Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');

            Route::put('/settings/booking', [SettingsController::class, 'updateBooking'])->name('settings.booking');
            Route::post('/settings/closures', [SettingsController::class, 'storeClosure'])->name('settings.closures.store');
            Route::delete('/settings/closures/{closure}', [SettingsController::class, 'destroyClosure'])->name('settings.closures.destroy');

            // Appointments (M7)
            Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
            Route::patch('/appointments/{appointment}', [CalendarController::class, 'update'])->name('appointments.update');
            Route::post('/appointments/{appointment}/cancel', [CalendarController::class, 'cancel'])->name('appointments.cancel');
            Route::get('/appointments/{appointment}/slots', [CalendarController::class, 'slots'])->name('appointments.slots');

            // Inventory (M3)
            Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
            Route::get('/vehicles/create', [VehicleController::class, 'create'])->name('vehicles.create');
            Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
            Route::post('/vehicles/decode-vin', VinDecodeController::class)->middleware('throttle:30,1')->name('vehicles.decode-vin');
            Route::get('/vehicles/{vehicle}/edit/{step}', [VehicleController::class, 'edit'])->name('vehicles.edit');
            Route::put('/vehicles/{vehicle}/identity', [VehicleController::class, 'updateIdentity'])->name('vehicles.identity');
            Route::put('/vehicles/{vehicle}/details', [VehicleController::class, 'updateDetails'])->name('vehicles.details');
            Route::put('/vehicles/{vehicle}/price', [VehicleController::class, 'updatePrice'])->name('vehicles.price');
            Route::patch('/vehicles/{vehicle}/status', [VehicleController::class, 'updateStatus'])->name('vehicles.status');
            Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');

            Route::get('/vehicles/{vehicle}/media', [VehicleMediaController::class, 'index'])->name('vehicles.media.index');
            Route::post('/vehicles/{vehicle}/media/presign', [VehicleMediaController::class, 'presign'])->name('vehicles.media.presign');
            Route::post('/vehicles/{vehicle}/media/upload', [VehicleMediaController::class, 'upload'])->name('vehicles.media.upload');
            Route::post('/vehicles/{vehicle}/media', [VehicleMediaController::class, 'store'])->name('vehicles.media.store');
            Route::put('/vehicles/{vehicle}/media/order', [VehicleMediaController::class, 'reorder'])->name('vehicles.media.reorder');
            Route::delete('/vehicles/{vehicle}/media/{media}', [VehicleMediaController::class, 'destroy'])->name('vehicles.media.destroy');

            // Lot Manager lite (M19) and sales (M13)
            Route::prefix('/manager')->name('manager.')->group(function () {
                Route::get('/today', TodayController::class)->name('today');
                Route::get('/walk-ins', [WalkInController::class, 'index'])->name('walk-ins.index');
                Route::post('/walk-ins', [WalkInController::class, 'store'])->name('walk-ins.store');
                Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
                Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
                Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
                Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
                Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
                Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
                Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
                Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
                Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->name('orders.payments.store');
                Route::get('/orders/{order}/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('orders.receipt');
                Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
                Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
                Route::post('/sync', SyncController::class)->middleware('throttle:30,1')->name('sync');
            });
        });
});
