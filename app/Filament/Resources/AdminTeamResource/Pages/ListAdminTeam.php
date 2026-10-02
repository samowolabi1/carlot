<?php

namespace App\Filament\Resources\AdminTeamResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Actions\ManageAdminTeam;
use App\Domain\Admin\AdminRole;
use App\Filament\Resources\AdminTeamResource;
use App\Rules\FieldPattern;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListAdminTeam extends ListRecords
{
    protected static string $resource = AdminTeamResource::class;

    protected ?string $subheading = 'LotLink staff who can sign in to /admin, and what each can do. Everyone uses a password and two-step sign-in; everything is in the audit log.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')->label('Invite an admin')->icon('heroicon-o-user-plus')
                ->modalDescription('They get an email with a link to set a password (valid 7 days), then turn on two-step sign-in. Use their work email: it can\'t already have a LotLink account.')
                ->form([
                    Forms\Components\TextInput::make('name')->label('Full name')->required()->maxLength(80)->rules([new FieldPattern('person_name')]),
                    Forms\Components\TextInput::make('email')->label('Work email')->email()->rule('email:rfc,strict')->required()->maxLength(190),
                    Forms\Components\Radio::make('role')->options(AdminTeamResource::roleOptions())->descriptions(AdminTeamResource::roleDescriptions())
                        ->default(AdminRole::Support->value)->required(),
                ])
                ->action(fn (array $data) => AdminTeamResource::team(
                    fn (ManageAdminTeam $t, User $owner) => $t->invite($owner, (string) $data['name'], (string) $data['email'], AdminRole::from($data['role'])),
                    "Invitation sent to {$data['email']}",
                )),
        ];
    }
}
