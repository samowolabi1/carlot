<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Finance\Support\FinanceRates;
use App\Filament\Pages\Concerns\AdminPage;
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
 * Budget and loan rates (TDD M10): what "What can I afford?" and the loan repayment calculator
 * on car pages use. Shown to buyers as estimates, never loan offers.
 *
 * @property Form $form
 */
class FinanceSettings extends Page implements HasForms
{
    use AdminPage;

    public static function adminAreas(): array
    {
        return [AdminArea::Billing];
    }

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
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Budget and loan estimates')
                ->description('Used by "What can I afford?" and the loan repayment calculator on car pages.')
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
            Forms\Components\Section::make('Preview')
                ->description('A loan on a ₦10,000,000 car with these values (save to apply).')
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
        return [
            'affordability_percent' => round((float) $finance['affordability_ratio'] * 100, 1),
            'interest_rate' => (float) $finance['interest_rate'],
            'deposit_percent' => (int) $finance['deposit_percent'],
            'tenor_months' => (int) $finance['tenor_months'],
            'tenors' => array_map('intval', (array) $finance['tenors']),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function fromForm(array $state): array
    {
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
        ];
    }

    private static function preview(): string
    {
        $from = FinanceCalculator::fromPrice(10_000_000);

        return 'About ₦'.number_format($from['monthly'])." a month with {$from['deposit_percent']}% down over {$from['months']} months at {$from['rate']}% a year.";
    }
}
