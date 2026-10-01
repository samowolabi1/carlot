<?php

namespace App\Filament\Pages;

use App\Domain\System\ServerHealth;
use Filament\Pages\Page;

/** The server checks from `php artisan lotlink:doctor`, for hosts without SSH (cPanel): PHP, folders, images, cron, queue, mail. */
class SystemHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'system-health';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.system-health';

    /** @return array<string, list<array{group: string, label: string, status: string, detail: string}>> */
    public function groups(): array
    {
        return collect(ServerHealth::checks())->groupBy('group')->map(fn ($c) => $c->values()->all())->all();
    }
}
