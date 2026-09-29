<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Support\AdvertPricing;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * What lots pay LotLink to promote: homepage and search banners (price and how many run at once),
 * car spotlights and featured lots. Changes apply to new bookings only.
 *
 * @property Form $form
 */
class AdvertPriceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Advert prices';

    protected static ?string $title = 'Advert prices';

    protected static ?string $slug = 'settings/advert-prices';

    protected static string $view = 'filament.pages.advert-prices';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(self::toForm(AdvertPricing::current()));
    }

    public function form(Form $form): Form
    {
        $prices = fn (string $key) => collect(AdvertPricing::DAYS)->map(fn (int $days) => Forms\Components\TextInput::make("{$key}.p{$days}")
            ->label("{$days} days")->prefix('₦')->numeric()->integer()->minValue(100)->maxValue(100_000_000)->required()
            ->helperText(fn (Forms\Get $get) => ($p = (int) $get("{$key}.p{$days}")) > 0 ? '₦'.number_format(intdiv($p, $days)).' a day' : null))->all();

        $banner = fn (AdPlacement $placement) => Forms\Components\Section::make($placement->label())
            ->description($placement->description().' '.self::booked($placement))
            ->columns(4)
            ->schema([
                ...$prices($placement->value),
                Forms\Components\TextInput::make("{$placement->value}.slots")->label('Running at once')->numeric()->integer()->minValue(1)->maxValue(20)->required()
                    ->helperText('Lowering this doesn\'t stop adverts already booked.'),
            ]);

        return $form->statePath('data')->schema([
            $banner(AdPlacement::HomeBanner),
            $banner(AdPlacement::SearchBanner),
            Forms\Components\Section::make('Car spotlight')->description('One car first in matching searches ("Sponsored") and in the home page Spotlight row. Pro plans\' free monthly spotlights stay free.')
                ->columns(3)->schema($prices('car')),
            Forms\Components\Section::make('Featured lot')->description('The lot in the "Featured lots" row on the home page.')
                ->columns(3)->schema($prices('featured_lot')),
        ]);
    }

    public function save(): void
    {
        /** @var User $user */
        $user = auth()->user();
        AdvertPricing::save(self::fromForm($this->form->getState()), $user);

        Notification::make()->title('Advert prices saved')->body('New bookings use them straight away. Adverts already paid for keep their price.')->success()->send();
        $this->form->fill(self::toForm(AdvertPricing::current()));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('Reset to defaults')->color('gray')
                ->visible(fn () => AdvertPricing::overrides() !== [])
                ->requiresConfirmation()
                ->modalDescription('Go back to the prices and slots in config/lotlink.php.')
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    AdvertPricing::reset($user);
                    $this->form->fill(self::toForm(AdvertPricing::current()));
                    Notification::make()->title('Advert prices reset to the defaults')->success()->send();
                }),
        ];
    }

    /**
     * @param  array{home_banner: array{slots: int, prices: array<int, int>}, search_banner: array{slots: int, prices: array<int, int>}, car: array<int, int>, featured_lot: array<int, int>}  $current
     * @return array<string, mixed>
     */
    public static function toForm(array $current): array
    {
        $prices = fn (array $list) => collect(AdvertPricing::DAYS)->mapWithKeys(fn (int $d) => ["p{$d}" => $list[$d] ?? null])->all();

        return [
            'home_banner' => ['slots' => $current['home_banner']['slots'], ...$prices($current['home_banner']['prices'])],
            'search_banner' => ['slots' => $current['search_banner']['slots'], ...$prices($current['search_banner']['prices'])],
            'car' => $prices($current['car']),
            'featured_lot' => $prices($current['featured_lot']),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{home_banner: array{slots: int, prices: array<int, int>}, search_banner: array{slots: int, prices: array<int, int>}, car: array<int, int>, featured_lot: array<int, int>}
     */
    public static function fromForm(array $state): array
    {
        $prices = fn (string $key) => collect(AdvertPricing::DAYS)->mapWithKeys(fn (int $d) => [$d => (int) ($state[$key]["p{$d}"] ?? 0)])->all();

        return [
            'home_banner' => ['slots' => (int) $state['home_banner']['slots'], 'prices' => $prices('home_banner')],
            'search_banner' => ['slots' => (int) $state['search_banner']['slots'], 'prices' => $prices('search_banner')],
            'car' => $prices('car'),
            'featured_lot' => $prices('featured_lot'),
        ];
    }

    private static function booked(AdPlacement $placement): string
    {
        $live = AdCampaign::query()->live()->where('placement', $placement)->count();
        $waiting = AdCampaign::withoutGlobalScopes()->where('placement', $placement)->where('status', AdStatus::InReview)->count();

        return "Now: {$live} running".($waiting ? ", {$waiting} waiting for review" : '').'.';
    }
}
