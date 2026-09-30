<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Finance\Actions\DecideLender;
use App\Domain\Finance\Actions\SaveLender;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Support\LenderRules;
use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use App\Domain\Support\PhoneNumber;
use App\Domain\Support\Regions;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\LenderResource\Pages;
use App\Rules\FieldPattern;
use App\Rules\PhoneNumberRule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Lenders (car loan partners): approve the ones that sign up, onboard partners directly, pause or edit them. */
class LenderResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = Lender::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Car loans';

    protected static ?string $navigationLabel = 'Lenders';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordRouteKeyName = 'slug';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('lenders_waiting');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['applications', 'applications as open_count' => fn ($q) => $q->whereIn('status', FinanceStatus::open())])
            ->withSum(['applications as disbursed_total' => fn ($q) => $q->where('status', FinanceStatus::Disbursed)], 'disbursed_amount');
    }

    public static function canCreate(): bool
    {
        return false; // "Onboard a lender" on the list page.
    }

    /** The lender's details, for onboarding and editing (same limits as the app: LenderRules). @return list<Forms\Components\Component> */
    public static function detailsSchema(bool $onboarding = false): array
    {
        $states = collect(Regions::options())->mapWithKeys(fn (array $r) => [$r['value'] => $r['label']]);

        return [
            Forms\Components\Section::make('Company')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->rules([new FieldPattern('business_name')])->columnSpanFull(),
                Forms\Components\Select::make('licence_type')->label('Licence')->options(collect(LenderType::cases())->mapWithKeys(fn (LenderType $t) => [$t->value => $t->label()]))->required(),
                Forms\Components\TextInput::make('licence_number')->required()->maxLength(40)->rules([new FieldPattern('reference')]),
                Forms\Components\TextInput::make('contact_name')->label('Contact person')->required()->maxLength(80)->rules([new FieldPattern('person_name')]),
                Forms\Components\TextInput::make('contact_phone')->label('Contact phone')->tel()->required()->maxLength(20)->rules([new FieldPattern('phone'), new PhoneNumberRule]),
                Forms\Components\TextInput::make('contact_email')->label('Contact email')->email()->rule('email:rfc,strict')->required()->maxLength(190),
                Forms\Components\TextInput::make('website')->url()->maxLength(190),
                Forms\Components\Textarea::make('about')->label('About (buyers see this)')->rows(2)->maxLength(600)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Car loan')->columns(3)->schema([
                Forms\Components\TextInput::make('rate')->label('Rate from (% a year)')->numeric()->minValue(1)->maxValue(99)->step(0.01)->required(),
                Forms\Components\TextInput::make('min_amount')->label('Smallest loan (₦)')->integer()->minValue(LenderRules::MIN_LOAN)->maxValue(Fields::MONEY_MAX)->required(),
                Forms\Components\TextInput::make('max_amount')->label('Largest loan (₦)')->integer()->minValue(LenderRules::MIN_LOAN)->maxValue(Fields::MONEY_MAX)->required()->gte('min_amount'),
                Forms\Components\TextInput::make('min_deposit_percent')->label('Smallest deposit (%)')->integer()->minValue(0)->maxValue(90)->required()->default(20),
                Forms\Components\CheckboxList::make('tenors')->label('Loan lengths (months)')->options(collect(Lender::TENORS)->mapWithKeys(fn (int $t) => [$t => "{$t}"]))->columns(5)->required()->columnSpan(2),
                Forms\Components\Select::make('states')->label('States (empty: every state)')->multiple()->options($states)->searchable()->columnSpanFull(),
            ]),
            Forms\Components\Section::make('How applications reach them')->columns(2)->schema([
                Forms\Components\Select::make('integration')->options(collect(LenderIntegration::cases())->mapWithKeys(fn (LenderIntegration $i) => [$i->value => $i->label()]))
                    ->required()->default(LenderIntegration::Portal->value)->live()->columnSpanFull(),
                Forms\Components\TextInput::make('api_url')->label('API address')->url()->maxLength(255)->visible(fn (Forms\Get $get) => $get('integration') === 'api'),
                Forms\Components\TextInput::make('api_key')->label('API key')->password()->maxLength(255)->helperText('Leave empty to keep the saved key.')->visible(fn (Forms\Get $get) => $get('integration') === 'api'),
            ]),
            ...($onboarding ? [Forms\Components\Section::make('Their first admin')->description('They sign in to the lender portal with this email (a one-time code).')->columns(2)->schema([
                Forms\Components\TextInput::make('admin_name')->label('Full name')->required()->maxLength(80)->rules([new FieldPattern('person_name')]),
                Forms\Components\TextInput::make('admin_email')->label('Work email')->email()->rule('email:rfc,strict')->required()->maxLength(190),
            ])] : []),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->description(fn (Lender $l) => $l->licence_type->label().' · '.$l->licence_number),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (LenderStatus $state) => $state->label())
                    ->color(fn (LenderStatus $state) => match ($state) {
                        LenderStatus::Pending => 'warning',
                        LenderStatus::Active => 'success',
                        LenderStatus::Suspended, LenderStatus::Rejected => 'danger',
                    }),
                Tables\Columns\TextColumn::make('rate_bp')->label('Product')->formatStateUsing(fn (Lender $l) => $l->productLine())->wrap(),
                Tables\Columns\TextColumn::make('integration')->formatStateUsing(fn (LenderIntegration $state) => match ($state) {
                    LenderIntegration::Portal => 'Portal', LenderIntegration::Api => 'API', LenderIntegration::Demo => 'Demo',
                })->toggleable(),
                Tables\Columns\TextColumn::make('open_count')->label('Open')->sortable(),
                Tables\Columns\TextColumn::make('applications_count')->label('All applications')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('disbursed_total')->label('Paid to lots')->formatStateUsing(fn ($state) => Money::format((int) $state))->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('contact_email')->label('Contact')->description(fn (Lender $l) => PhoneNumber::display($l->contact_phone))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(LenderStatus::cases())->mapWithKeys(fn (LenderStatus $s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\Action::make('licence')->label('Licence')->icon('heroicon-o-document-text')->color('gray')
                    ->visible(fn (Lender $l) => $l->licence_path !== null)
                    ->url(fn (Lender $l) => $l->licenceUrl(), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Lender $l) => in_array($l->status, [LenderStatus::Pending, LenderStatus::Rejected], true))
                    ->requiresConfirmation()->modalDescription('Check the licence number with the CBN list and the licence copy first. Buyers will see this lender straight away.')
                    ->action(fn (Lender $l) => self::decide($l, 'approve', null, 'Lender approved')),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Lender $l) => $l->status === LenderStatus::Pending)
                    ->form([Forms\Components\Textarea::make('note')->label('Why? (the lender sees this)')->required()->rows(3)->maxLength(500)])
                    ->action(fn (Lender $l, array $data) => self::decide($l, 'reject', $data['note'], 'Lender told')),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('edit')->icon('heroicon-o-pencil-square')->modalWidth('3xl')
                        ->fillForm(fn (Lender $l) => [
                            'name' => $l->name, 'licence_type' => $l->licence_type->value, 'licence_number' => $l->licence_number,
                            'contact_name' => $l->contact_name, 'contact_phone' => PhoneNumber::display($l->contact_phone), 'contact_email' => $l->contact_email,
                            'website' => $l->website, 'about' => $l->about, 'rate' => $l->rate_bp / 100, 'min_amount' => intdiv($l->min_amount, 100),
                            'max_amount' => intdiv($l->max_amount, 100), 'min_deposit_percent' => $l->min_deposit_percent, 'tenors' => array_map('strval', $l->tenors),
                            'states' => $l->states ?? [], 'integration' => $l->integration->value, 'api_url' => $l->api_url,
                        ])
                        ->form(self::detailsSchema())
                        ->action(function (Lender $l, array $data): void {
                            try {
                                /** @var User $admin */
                                $admin = Auth::user();
                                app(SaveLender::class)->run($l, $data, $admin);
                                Notification::make()->title('Saved')->success()->send();
                            } catch (Throwable $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Tables\Actions\Action::make('suspend')->icon('heroicon-o-pause-circle')->color('danger')
                        ->visible(fn (Lender $l) => $l->status === LenderStatus::Active)
                        ->form([Forms\Components\Textarea::make('note')->label('Why? (the lender sees this)')->required()->rows(3)->maxLength(500)])
                        ->modalDescription('Buyers stop seeing this lender and it gets no new applications. Open applications stay with it.')
                        ->action(fn (Lender $l, array $data) => self::decide($l, 'suspend', $data['note'], 'Lender paused')),
                    Tables\Actions\Action::make('reactivate')->icon('heroicon-o-play-circle')->color('success')
                        ->visible(fn (Lender $l) => $l->status === LenderStatus::Suspended)->requiresConfirmation()
                        ->action(fn (Lender $l) => self::decide($l, 'reactivate', null, 'Lender active again')),
                    Tables\Actions\Action::make('applications')->label('Applications')->icon('heroicon-o-document-duplicate')
                        ->url(fn (Lender $l) => FinanceApplicationResource::getUrl('index', ['tableFilters' => ['lender' => ['value' => $l->id]]])),
                ]),
            ]);
    }

    private static function decide(Lender $lender, string $decision, ?string $note, string $done): void
    {
        try {
            /** @var User $admin */
            $admin = Auth::user();
            app(DecideLender::class)->run($lender, $decision, $admin, $note);
            Notification::make()->title($done)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLenders::route('/')];
    }
}
