<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Finance\Support\FinanceRates;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Budget and ownership rates (TDD M10): what "What can I afford?", "From ₦X/mo" and the
 * cost-of-ownership card use. Shown to buyers as estimates, never loan offers.
 *
 * @property Form $form
 */
class FinanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Finance rates';

    protected static ?string $title = 'Finance rates';

    protected static ?string $slug = 'settings/finance';

    protected static string $view = 'filament.pages.finance-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public const TENOR_CHOICES = [6, 12, 18, 24, 36, 48, 60, 72];

    public function mount(): void
    {
        $this->form->fill(self::toForm((array) config('lotlink.finance')));
    }

    public function form(Form $form): Form
    {
        $naira = fn (string $name, string $label) => Forms\Components\TextInput::make($name)->label($label)->prefix('₦')->numeric()->integer()->minValue(0)->maxValue(100_000_000)->required();

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Budget and loan estimates')
                ->description('Used by "What can I afford?", the monthly figure on car pages and the finance calculator.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('affordability_percent')->label('Share of spare income for a car loan')->suffix('%')
                        ->numeric()->minValue(5)->maxValue(80)->required()
                        ->helperText('Of monthly income minus commitments. 35% is a common bank limit.'),
                    Forms\Components\TextInput::make('interest_rate')->label('Interest rate (a year)')->suffix('%')
                        ->numeric()->minValue(0)->maxValue(100)->step(0.1)->required(),
                    Forms\Components\TextInput::make('deposit_percent')->label('Default deposit')->suffix('%')
                        ->numeric()->integer()->minValue(0)->maxValue(90)->required(),
                    Forms\Components\Select::make('tenor_months')->label('Default loan length')->required()
                        ->options(fn (Get $get) => collect((array) $get('tenors'))->map(fn ($m) => (int) $m)->sort()->mapWithKeys(fn (int $m) => [$m => "{$m} months"])->all()),
                    Forms\Components\CheckboxList::make('tenors')->label('Loan lengths buyers can pick')->required()->live()
                        ->options(collect(self::TENOR_CHOICES)->mapWithKeys(fn (int $m) => [$m => "{$m} months"])->all())
                        ->columns(4)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Cost of ownership (a year)')
                ->description('The running-costs card on car pages.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('insurance_percent')->label('Comprehensive insurance')->suffix('% of price')
                        ->numeric()->minValue(0)->maxValue(20)->step(0.1)->required(),
                    $naira('papers', 'Registration and papers'),
                    $naira('fuel_price', 'Fuel price (a litre)'),
                    Forms\Components\TextInput::make('km_per_month')->label('Distance driven a month')->suffix('km')->numeric()->integer()->minValue(0)->maxValue(50_000)->required(),
                    Forms\Components\Repeater::make('km_per_litre')->maxItems(12)->label('Fuel economy by engine size')
                        ->helperText('Each row covers engines up to that size; the last row covers anything bigger.')
                        ->schema([
                            Forms\Components\TextInput::make('up_to')->label('Engine up to')->suffix('cc')->numeric()->integer()->minValue(1)->maxValue(99_999)->required(),
                            Forms\Components\TextInput::make('value')->label('Km a litre')->numeric()->integer()->minValue(1)->maxValue(50)->required(),
                        ])->columns(2)->minItems(1)->reorderable(false)->addActionLabel('Add engine size'),
                    Forms\Components\Repeater::make('servicing')->maxItems(12)->label('Servicing and repairs by car age')
                        ->helperText('Each row covers cars up to that age; the last row covers anything older.')
                        ->schema([
                            Forms\Components\TextInput::make('up_to')->label('Up to')->suffix('years old')->numeric()->integer()->minValue(0)->maxValue(99)->required(),
                            Forms\Components\TextInput::make('value')->label('A year')->prefix('₦')->numeric()->integer()->minValue(0)->maxValue(100_000_000)->required(),
                        ])->columns(2)->minItems(1)->reorderable(false)->addActionLabel('Add age band'),
                ]),
            Forms\Components\Section::make('Preview')
                ->description('A ₦10,000,000, 2.5L, 5-year-old car with these values (save to apply).')
                ->schema([
                    Forms\Components\Placeholder::make('preview')->hiddenLabel()
                        ->content(fn () => self::preview()),
                ]),
        ]);
    }

    public function save(): void
    {
        $values = self::fromForm($this->form->getState());

        /** @var User $user */
        $user = auth()->user();
        FinanceRates::save($values, $user);

        Notification::make()->title('Finance rates saved')->body('Buyers see the new estimates straight away.')->success()->send();
        $this->form->fill(self::toForm((array) config('lotlink.finance')));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('Reset to defaults')->color('gray')
                ->visible(fn () => FinanceRates::overrides() !== [])
                ->requiresConfirmation()
                ->modalDescription('Go back to the rates in config/lotlink.php (and the FINANCE_* environment values).')
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    FinanceRates::reset($user);
                    $this->form->fill(self::toForm((array) config('lotlink.finance')));
                    Notification::make()->title('Finance rates reset to the defaults')->success()->send();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $finance
     * @return array<string, mixed>
     */
    public static function toForm(array $finance): array
    {
        $bands = fn (array $bands) => collect($bands)->map(fn ($value, $upTo) => ['up_to' => (int) $upTo, 'value' => (int) $value])->values()->all();

        return [
            'affordability_percent' => round((float) $finance['affordability_ratio'] * 100, 1),
            'interest_rate' => (float) $finance['interest_rate'],
            'deposit_percent' => (int) $finance['deposit_percent'],
            'tenor_months' => (int) $finance['tenor_months'],
            'tenors' => array_map('intval', (array) $finance['tenors']),
            'insurance_percent' => (float) $finance['insurance_percent'],
            'papers' => (int) $finance['papers'],
            'fuel_price' => (int) $finance['fuel_price'],
            'km_per_month' => (int) $finance['km_per_month'],
            'km_per_litre' => $bands((array) $finance['km_per_litre']),
            'servicing' => $bands((array) $finance['servicing']),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function fromForm(array $state): array
    {
        $bands = function (string $field) use ($state): array {
            $out = [];
            foreach ((array) ($state[$field] ?? []) as $row) {
                $upTo = (int) $row['up_to'];
                if (array_key_exists($upTo, $out)) {
                    throw ValidationException::withMessages(["data.{$field}" => 'Each row needs a different "up to" value.']);
                }
                $out[$upTo] = (int) $row['value'];
            }
            ksort($out);

            return $out;
        };

        $tenors = array_values(array_unique(array_map('intval', (array) $state['tenors'])));
        sort($tenors);

        if (! in_array((int) $state['tenor_months'], $tenors, true)) {
            throw ValidationException::withMessages(['data.tenor_months' => 'Pick one of the loan lengths buyers can choose.']);
        }

        return [
            'affordability_ratio' => round((float) $state['affordability_percent'] / 100, 4),
            'interest_rate' => (float) $state['interest_rate'],
            'deposit_percent' => (int) $state['deposit_percent'],
            'tenor_months' => (int) $state['tenor_months'],
            'tenors' => $tenors,
            'insurance_percent' => (float) $state['insurance_percent'],
            'papers' => (int) $state['papers'],
            'fuel_price' => (int) $state['fuel_price'],
            'km_per_month' => (int) $state['km_per_month'],
            'km_per_litre' => $bands('km_per_litre'),
            'servicing' => $bands('servicing'),
        ];
    }

    private static function preview(): string
    {
        $price = 10_000_000;
        $from = FinanceCalculator::fromPrice($price);
        $own = FinanceCalculator::ownership($price, 2500, (int) now()->year - 5);
        $format = fn (int $n) => '₦'.number_format($n);

        return "From {$format($from['monthly'])} a month ({$from['deposit_percent']}% down, {$from['months']} months) · running costs {$format($own['total'])} a year ("
            .collect($own['items'])->map(fn (array $i) => strtolower($i['label']).' '.$format($i['amount']))->implode(', ').')';
    }
}
