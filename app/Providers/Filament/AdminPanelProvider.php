<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequireAdminTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Every admin table searches as you type, with a short pause (Filament waits 500 ms by default).
        Table::configureUsing(fn (Table $table) => $table->searchDebounce('250ms'));

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('LotLink Admin')
            ->colors([
                'primary' => Color::hex('#C2410C'),
                'gray' => Color::Stone,
            ])
            ->font('DM Sans')
            // What needs doing first, then the platform, people, money, and setup at the bottom (collapsed).
            ->navigationGroups([
                NavigationGroup::make('Review queue'),
                NavigationGroup::make('Marketplace'),
                NavigationGroup::make('Car loans'),
                NavigationGroup::make('Support'),
                NavigationGroup::make('Engagement'),
                NavigationGroup::make('Billing'),
                NavigationGroup::make('Catalogue')->collapsed(),
                NavigationGroup::make('Settings')->collapsed(),
                NavigationGroup::make('System')->collapsed(),
            ])
            // Moving between admin pages swaps only the content (no full reload). Links that leave the admin
            // open in a new tab, and "Log in as" returns a plain redirect, which always loads the whole page.
            // The platform's legal links in the admin too (staff act under the same Terms and Privacy Policy).
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('filament.legal-footer'))
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            // Results as you type: a shorter pause than Filament's 500 ms default.
            ->globalSearchDebounce('250ms')
            // Queue workers' dashboard, when the queue runs on Redis (Horizon).
            ->navigationItems([
                NavigationItem::make('Queues')->url('/horizon', shouldOpenInNewTab: true)->icon('heroicon-o-queue-list')
                    ->group('System')->sort(90)->visible(fn (): bool => config('queue.default') === 'redis'),
            ])
            ->globalSearchDebounce('400ms')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The default "Welcome / Sign out" card is left out: sign out is in the user menu.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireAdminTwoFactor::class,
            ]);
    }
}
