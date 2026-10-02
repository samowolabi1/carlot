<?php

namespace App\Filament\Resources\LotResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Lots\Actions\OnboardLot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Regions;
use App\Filament\Resources\LotResource;
use App\Rules\FieldPattern;
use App\Rules\PhoneNumberRule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListLots extends ListRecords
{
    protected static string $resource = LotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Field sales: sign a lot up for its owner from any state; the owner signs in and finishes setting up.
            Action::make('onboard')->authorize(fn () => LotResource::allows(AdminArea::Approvals))->label('Onboard a lot')->icon('heroicon-o-plus-circle')
                ->modalHeading('Onboard a car lot')
                ->modalDescription('Creates the owner\'s account and the lot. The owner signs in with the email or WhatsApp number you enter (a one-time code, no password) and adds cars, photos and bank details.')
                ->modalWidth('2xl')
                ->form([
                    Forms\Components\Section::make('Owner')->columns(2)->schema([
                        Forms\Components\TextInput::make('owner_name')->label('Full name')->required()->maxLength(80)->rules([new FieldPattern('person_name')])->columnSpanFull(),
                        Forms\Components\Radio::make('sign_in')->label('They will sign in with')->required()->default('whatsapp')->inline()->live()
                            ->options(['whatsapp' => 'WhatsApp number', 'email' => 'Email address'])->columnSpanFull(),
                        Forms\Components\TextInput::make('phone')->label('WhatsApp number')->tel()->maxLength(20)->rules(['nullable', new FieldPattern('phone'), new PhoneNumberRule])->placeholder('0803 123 4567')
                            ->required(fn (Get $get) => $get('sign_in') === 'whatsapp'),
                        Forms\Components\TextInput::make('email')->label('Email')->email()->rule('email:rfc,strict')->maxLength(190)
                            ->required(fn (Get $get) => $get('sign_in') === 'email'),
                    ]),
                    Forms\Components\Section::make('Lot')->columns(2)->schema([
                        Forms\Components\TextInput::make('lot_name')->label('Lot name')->required()->maxLength(120)->rules([new FieldPattern('business_name')])->columnSpanFull(),
                        Forms\Components\Select::make('state')->options(collect(Regions::options())->mapWithKeys(fn (array $r) => [$r['value'] => $r['label']]))
                            ->required()->searchable(),
                        Forms\Components\TextInput::make('city')->label('Area / city')->required()->maxLength(80)->rules([new FieldPattern('place')]),
                        Forms\Components\TextInput::make('address')->label('Street address')->maxLength(255)->columnSpanFull(),
                        Forms\Components\TextInput::make('lot_phone')->label('Phone buyers call')->tel()->maxLength(20)->rules(['nullable', new FieldPattern('phone'), new PhoneNumberRule])->helperText('Leave empty to use the WhatsApp number.'),
                        Forms\Components\Select::make('plan_id')->label('Plan')->options(Plan::query()->orderBy('sort')->pluck('name', 'id'))->placeholder('Default (trial)'),
                        Forms\Components\Toggle::make('approve')->label('Approve the lot now')->helperText('Only if you have met the lot. Otherwise it waits in "Lots" for approval like any other.')->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data, OnboardLot $onboard): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    $result = $onboard->run($admin, [
                        'owner_name' => (string) $data['owner_name'],
                        'sign_in' => $data['sign_in'] === 'email' ? 'email' : 'whatsapp',
                        'email' => $data['email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'lot_name' => (string) $data['lot_name'],
                        'lot_phone' => $data['lot_phone'] ?? null,
                        'state' => (string) $data['state'],
                        'city' => (string) $data['city'],
                        'address' => $data['address'] ?? null,
                        'plan_id' => filled($data['plan_id'] ?? null) ? (int) $data['plan_id'] : null,
                        'approve' => (bool) ($data['approve'] ?? false),
                    ]);

                    $notice = Notification::make()->title("{$result['lot']->name} is set up");
                    ($result['welcomed']
                        ? $notice->body('We sent the owner a welcome with how to sign in.')->success()
                        : $notice->body('We couldn\'t send the welcome message. Tell the owner to sign in at LotLink with the '.($data['sign_in'] === 'email' ? 'email' : 'WhatsApp number').' you entered.')->warning())
                        ->send();
                }),
        ];
    }
}
