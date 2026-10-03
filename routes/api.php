<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CarController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\Dealer\LeadController;
use App\Http\Controllers\Api\V1\Dealer\StockController;
use App\Http\Controllers\Api\V1\LegalController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SavedController;
use Illuminate\Support\Facades\Route;

/*
 * CarYard API v1 for the mobile app (Sanctum bearer tokens). JSON only; public IDs are ULIDs and slugs.
 * Every write goes through the same Actions as the web. Docs: docs/api.md.
 */
Route::prefix('v1')->name('api.')->middleware('throttle:api')->group(function () {
    // Sign-in: a one-time code (creates the account) or a password, answered with a token.
    Route::post('/auth/code', [AuthController::class, 'code'])->middleware('throttle:otp')->name('auth.code');
    Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:otp')->name('auth.token');
    Route::post('/auth/password', [AuthController::class, 'password'])->middleware('throttle:20,1')->name('auth.password');

    // Public marketplace (a token, if sent, adds "saved" flags).
    Route::get('/cars', [CarController::class, 'index'])->name('cars.index');
    Route::get('/cars/filters', [CarController::class, 'filters'])->name('cars.filters');
    Route::get('/cars/{ulid}', [CarController::class, 'show'])->where('ulid', '[0-9A-Za-z]{26}')->name('cars.show');
    Route::get('/lots/{slug}', [LotController::class, 'show'])->name('lots.show');
    Route::get('/lots/{slug}/slots', [LotController::class, 'slots'])->name('lots.slots');
    Route::get('/legal', [LegalController::class, 'index'])->name('legal.index');

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('/auth/token', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::patch('/me', [AuthController::class, 'update'])->name('me.update');
        Route::post('/legal/accept', [LegalController::class, 'accept'])->middleware('throttle:10,1')->name('legal.accept');

        // Everything else waits until the current Terms and Privacy Policy are accepted (403 terms_not_accepted).
        Route::middleware('terms.accepted')->group(function () {

            Route::get('/saved', [SavedController::class, 'index'])->name('saved.index');
            Route::put('/saved/{vehicle}', [SavedController::class, 'store'])->middleware('throttle:60,1')->name('saved.store');
            Route::delete('/saved/{vehicle}', [SavedController::class, 'destroy'])->name('saved.destroy');

            Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
            Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:20,1')->name('conversations.store');
            Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
            Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('throttle:30,1')->name('conversations.reply');

            Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.store');
            Route::get('/bookings/{appointment}', [BookingController::class, 'show'])->name('bookings.show');
            Route::patch('/bookings/{appointment}', [BookingController::class, 'update'])->middleware('throttle:10,1')->name('bookings.update');
            Route::post('/bookings/{appointment}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');

            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

            // Lot staff: the sellers come from GET /me; everything below checks membership (lot.member) and scopes to the seller.
            Route::prefix('/dealer/lots/{lot}')->name('dealer.')->middleware('lot.member')->scopeBindings()->group(function () {
                Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
                Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
                Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
                Route::post('/leads/{lead}/messages', [LeadController::class, 'message'])->middleware('throttle:30,1')->name('leads.messages');
                Route::get('/vehicles', [StockController::class, 'vehicles'])->name('vehicles.index');
                Route::get('/appointments', [StockController::class, 'appointments'])->name('appointments.index');
            });
        });
    });
});
