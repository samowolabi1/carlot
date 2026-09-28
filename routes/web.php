<?php

use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\ProfileNameController;
use App\Http\Controllers\Dealer\DashboardController;
use App\Http\Controllers\Dealer\DealerHomeController;
use App\Http\Controllers\Dealer\OnboardingController;
use App\Http\Controllers\Dealer\SettingsController;
use App\Http\Controllers\Dealer\StaffController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

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

Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

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
        });
});
