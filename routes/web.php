<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\BudgetController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Auth\ProfileNameController;
use App\Http\Controllers\Billing\SandboxCheckoutController;
use App\Http\Controllers\Bookings\BookingController;
use App\Http\Controllers\Bookings\CustomerBookingController;
use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Chat\LeadIntentController;
use App\Http\Controllers\Dealer\BillingController;
use App\Http\Controllers\Dealer\CalendarController;
use App\Http\Controllers\Dealer\DashboardController;
use App\Http\Controllers\Dealer\DealController;
use App\Http\Controllers\Dealer\DealerHomeController;
use App\Http\Controllers\Dealer\LeadController;
use App\Http\Controllers\Dealer\Manager\CustomerController;
use App\Http\Controllers\Dealer\Manager\DocumentController;
use App\Http\Controllers\Dealer\Manager\InstalmentController;
use App\Http\Controllers\Dealer\Manager\OrderController;
use App\Http\Controllers\Dealer\Manager\PaymentController;
use App\Http\Controllers\Dealer\Manager\ReportController;
use App\Http\Controllers\Dealer\Manager\SyncController;
use App\Http\Controllers\Dealer\Manager\TaskController;
use App\Http\Controllers\Dealer\Manager\TodayController;
use App\Http\Controllers\Dealer\Manager\WalkInController;
use App\Http\Controllers\Dealer\OnboardingController;
use App\Http\Controllers\Dealer\ReferralController;
use App\Http\Controllers\Dealer\SettingsController;
use App\Http\Controllers\Dealer\SpotlightController;
use App\Http\Controllers\Dealer\StaffController;
use App\Http\Controllers\Dealer\VehicleController;
use App\Http\Controllers\Dealer\VehicleCostController;
use App\Http\Controllers\Dealer\VehicleMediaController;
use App\Http\Controllers\Dealer\VinDecodeController;
use App\Http\Controllers\Deals\OfferController;
use App\Http\Controllers\Deals\ReservationController;
use App\Http\Controllers\Deals\TradeInController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Marketplace\CarController;
use App\Http\Controllers\Marketplace\CompareController;
use App\Http\Controllers\Marketplace\FavouriteController;
use App\Http\Controllers\Marketplace\FollowController;
use App\Http\Controllers\Marketplace\HomeController;
use App\Http\Controllers\Marketplace\LotSiteController;
use App\Http\Controllers\Marketplace\SearchController;
use App\Http\Controllers\Orders\OrderTrackingController;
use App\Http\Controllers\Sharing\ShareController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

// Marketplace (M4) and lot mini-sites (M6)
Route::get('/', HomeController::class)->name('home');
Route::get('/cars', SearchController::class)->middleware('throttle:120,1')->name('cars.index');
Route::get('/car/{ref}', CarController::class)->where('ref', '[0-9A-Za-z]{26}(-[a-z0-9-]+)?')->name('cars.show');
Route::get('/compare', CompareController::class)->name('compare');
Route::get('/l/{lot:slug}', LotSiteController::class)->name('lots.show');
Route::get('/budget', [BudgetController::class, 'show'])->name('budget');
Route::get('/budget/count', [BudgetController::class, 'count'])->middleware('throttle:120,1')->name('budget.count');
// Paystack webhooks (CSRF-exempt in bootstrap/app.php; the signature is checked instead).
Route::post('/webhooks/paystack', PaystackWebhookController::class)->name('webhooks.paystack');

// Local stand-in for Paystack's checkout (PAYMENT_DRIVER=sandbox).
Route::middleware('signed')->group(function () {
    Route::get('/billing/sandbox/{payment}', [SandboxCheckoutController::class, 'show'])->name('billing.sandbox');
    Route::post('/billing/sandbox/{payment}', [SandboxCheckoutController::class, 'complete'])->name('billing.sandbox.complete');
});

Route::get('/c/{code}', [ShareController::class, 'go'])->where('code', '[a-z2-9]{8}')->name('share.go');
Route::post('/shares', [ShareController::class, 'store'])->middleware('throttle:30,1')->name('shares.store');
Route::get('/lots/{lot:slug}/slots', [BookingController::class, 'slots'])->name('lots.slots');

// Bookings (M7). Signed links from WhatsApp/SMS open these without signing in.
Route::get('/bookings/{appointment}', [CustomerBookingController::class, 'show'])->name('bookings.show');
Route::get('/bookings/{appointment}/calendar.ics', [CustomerBookingController::class, 'calendar'])->name('bookings.calendar');
Route::post('/bookings/{appointment}/cancel', [CustomerBookingController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');

// Trade-in photos are private; the lot's team and the buyer get short-lived signed links.
Route::get('/trade-ins/{tradeIn}/photos/{index}', [TradeInController::class, 'photo'])->whereNumber('index')->middleware('signed')->name('trade-ins.photo');

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
    Route::get('/account', AccountController::class)->name('account');
    Route::get('/following', [FollowController::class, 'index'])->name('following');

    // Notification centre (M18)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/settings', [NotificationController::class, 'settings'])->name('notifications.settings');
    Route::put('/notifications/settings', [NotificationController::class, 'update'])->name('notifications.update');

    // Chat (M11). Poll is used by both sides when Reverb isn't running.
    Route::get('/messages', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/start', [ConversationController::class, 'start'])->middleware('throttle:20,1')->name('conversations.start');
    Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:20,1')->name('conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'reply'])->middleware('throttle:60,1')->name('conversations.reply');
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'poll'])->middleware('throttle:240,1')->name('conversations.poll');
    Route::post('/leads/intent', LeadIntentController::class)->middleware('throttle:30,1')->name('leads.intent');
    Route::post('/lots/{lot:slug}/follow', [FollowController::class, 'store'])->name('lots.follow');
    Route::delete('/lots/{lot:slug}/follow', [FollowController::class, 'destroy'])->name('lots.unfollow');
    Route::put('/budget', [BudgetController::class, 'update'])->name('budget.update');
    Route::delete('/budget', [BudgetController::class, 'destroy'])->name('budget.destroy');
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
    Route::post('/bookings/{appointment}/deposit', [BookingController::class, 'deposit'])->middleware('throttle:10,1')->name('bookings.deposit');
    Route::get('/bookings/deposit/callback', [BookingController::class, 'depositCallback'])->name('bookings.deposit.callback');

    // Offers, trade-ins and reservations (M12)
    Route::get('/car/{car}/offer', [OfferController::class, 'create'])->name('offers.create');
    Route::post('/vehicles/{car}/offers', [OfferController::class, 'store'])->middleware('throttle:10,1')->name('offers.store');
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept'])->middleware('throttle:10,1')->name('offers.accept');
    Route::post('/offers/{offer}/decline', [OfferController::class, 'decline'])->middleware('throttle:10,1')->name('offers.decline');
    Route::get('/lots/{lot:slug}/trade-in', [TradeInController::class, 'create'])->name('trade-ins.create');
    Route::post('/lots/{lot:slug}/trade-ins', [TradeInController::class, 'store'])->middleware('throttle:5,1')->name('trade-ins.store');
    Route::post('/trade-ins/{tradeIn}/answer', [TradeInController::class, 'answer'])->middleware('throttle:10,1')->name('trade-ins.answer');
    Route::get('/car/{car}/reserve', [ReservationController::class, 'create'])->name('reservations.create');
    Route::post('/vehicles/{car}/reservations', [ReservationController::class, 'store'])->middleware('throttle:10,1')->name('reservations.store');
    Route::get('/reservations/callback', [ReservationController::class, 'callback'])->name('reservations.callback');

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

            // Leads and chat (M11)
            Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
            Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
            Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
            Route::post('/leads/{lead}/notes', [LeadController::class, 'note'])->name('leads.notes.store');
            Route::post('/leads/{lead}/messages', [LeadController::class, 'message'])->middleware('throttle:60,1')->name('leads.messages.store');
            Route::post('/leads/{lead}/read', [LeadController::class, 'read'])->name('leads.read');

            // Car costs (M19, owners and managers)
            Route::get('/vehicles/{vehicle}/costs', [VehicleCostController::class, 'index'])->name('vehicles.costs.index');
            Route::post('/vehicles/{vehicle}/costs', [VehicleCostController::class, 'store'])->name('vehicles.costs.store');
            Route::delete('/vehicles/{vehicle}/costs/{cost}', [VehicleCostController::class, 'destroy'])->name('vehicles.costs.destroy');
            Route::get('/vehicles/{vehicle}/costs/{cost}/receipt', [VehicleCostController::class, 'receipt'])->name('vehicles.costs.receipt');
            Route::get('/referrals', ReferralController::class)->name('referrals');

            // Offers, trade-ins and reservations (M12)
            Route::get('/offers', [DealController::class, 'index'])->name('offers.index');
            Route::post('/offers/{offer}/respond', [DealController::class, 'respond'])->name('offers.respond');
            Route::patch('/trade-ins/{tradeIn}', [DealController::class, 'value'])->name('trade-ins.update');
            Route::post('/trade-ins/{tradeIn}/ask-photos', [DealController::class, 'askForPhotos'])->middleware('throttle:10,1')->name('trade-ins.ask-photos');
            Route::post('/reservations/{reservation}/cancel', [DealController::class, 'cancelReservation'])->name('reservations.cancel');
            Route::put('/settings/deals', [SettingsController::class, 'updateDeals'])->name('settings.deals');

            // Billing and spotlight (M16, M5)
            Route::get('/billing', [BillingController::class, 'show'])->name('billing');
            Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
            Route::get('/billing/callback', [BillingController::class, 'callback'])->name('billing.callback');
            Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('/billing/coupon', [BillingController::class, 'coupon'])->middleware('throttle:10,1')->name('billing.coupon');
            Route::get('/billing/card', [BillingController::class, 'card'])->name('billing.card');
            Route::get('/billing/invoices/{billingPayment}', [BillingController::class, 'invoice'])->name('billing.invoice');
            Route::get('/spotlight/options', [SpotlightController::class, 'options'])->name('spotlight.options');
            Route::post('/vehicles/{vehicle}/spotlight', [SpotlightController::class, 'car'])->name('vehicles.spotlight');
            Route::post('/spotlight/featured', [SpotlightController::class, 'featured'])->name('spotlight.featured');

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
                // Lot Manager Pro (M19): instalments, papers and handover, reports
                Route::put('/orders/{order}/instalments', [InstalmentController::class, 'update'])->name('orders.instalments.update');
                Route::delete('/orders/{order}/instalments', [InstalmentController::class, 'destroy'])->name('orders.instalments.destroy');
                Route::post('/orders/{order}/documents', [DocumentController::class, 'store'])->name('orders.documents.store');
                Route::post('/orders/{order}/documents/{document}', [DocumentController::class, 'update'])->name('orders.documents.update');
                Route::delete('/orders/{order}/documents/{document}', [DocumentController::class, 'destroy'])->name('orders.documents.destroy');
                Route::get('/orders/{order}/documents/{document}/file', [DocumentController::class, 'file'])->name('orders.documents.file');
                Route::get('/reports', ReportController::class)->name('reports');
                Route::post('/sync', SyncController::class)->middleware('throttle:30,1')->name('sync');
            });
        });
});
