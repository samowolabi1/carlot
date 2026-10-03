<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Helpdesk\Actions\ChangeTicketStatus;
use App\Domain\Helpdesk\Actions\ReplyToTicket;
use App\Domain\Helpdesk\Actions\TicketAttachment;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Filament\Resources\SupportTicketResource;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * One ticket: the conversation with the seller, and the actions to reply, add an internal note,
 * or update status, priority and who owns it.
 *
 * @property SupportTicket $record
 */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected static string $view = 'filament.pages.support-ticket';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SupportTicket $ticket */
        $ticket = $this->record;
        $ticket->forceFill(['admin_read_at' => now()])->saveQuietly();
    }

    public function getTitle(): string
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->record;

        return "{$ticket->reference}: {$ticket->subject}";
    }

    /** @return Collection<int, SupportMessage> */
    public function thread(): Collection
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->record;

        return $ticket->messages()->with('author')->get();
    }

    protected function getHeaderActions(): array
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->record;

        return [
            Action::make('reply')->label('Reply to the seller')->icon('heroicon-o-paper-airplane')
                ->modalWidth('2xl')
                ->form([
                    Forms\Components\Textarea::make('body')->label('Message')->required()->rows(7)->maxLength(5000)
                        ->helperText('The seller sees this with your first name as "CarYard Support". They get an in-app notification and an email.'),
                    ...self::attachmentField($ticket),
                    Forms\Components\Radio::make('then')->label('Then')->default(TicketStatus::Pending->value)->inline()
                        ->options([
                            TicketStatus::Pending->value => 'Wait for the seller',
                            TicketStatus::Resolved->value => 'Mark resolved',
                            TicketStatus::Closed->value => 'Close',
                        ]),
                ])
                ->action(function (array $data, ReplyToTicket $reply): void {
                    $reply->fromAdmin($this->ticket(), $this->admin(), (string) $data['body'], self::stored($data), then: TicketStatus::from((string) $data['then']));
                    Notification::make()->title('Reply sent to the seller')->success()->send();
                    $this->refreshRecord();
                }),
            Action::make('note')->label('Internal note')->icon('heroicon-o-pencil-square')->color('gray')
                ->modalDescription('Only CarYard admins see notes. The seller isn\'t told and the status doesn\'t change.')
                ->form([
                    Forms\Components\Textarea::make('body')->label('Note')->required()->rows(5)->maxLength(5000),
                    ...self::attachmentField($ticket),
                ])
                ->action(function (array $data, ReplyToTicket $reply): void {
                    $reply->fromAdmin($this->ticket(), $this->admin(), (string) $data['body'], self::stored($data), internal: true);
                    Notification::make()->title('Note added')->success()->send();
                    $this->refreshRecord();
                }),
            Action::make('mine')->label('Assign to me')->icon('heroicon-o-user')->color('gray')
                ->visible(fn () => $this->ticket()->assigned_to !== Auth::id())
                ->action(function (ChangeTicketStatus $change): void {
                    $change->assign($this->ticket(), $this->admin(), $this->admin());
                    Notification::make()->title('Assigned to you')->success()->send();
                    $this->refreshRecord();
                }),
            Action::make('update')->label('Update ticket')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                ->fillForm(fn () => ['status' => $this->ticket()->status->value, 'priority' => $this->ticket()->priority->value, 'assigned_to' => $this->ticket()->assigned_to])
                ->form([
                    Forms\Components\Select::make('status')->required()->selectablePlaceholder(false)
                        ->options(collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $s) => [$s->value => $s->label()]))
                        ->helperText('Resolving or closing tells the seller.'),
                    Forms\Components\Select::make('priority')->required()->selectablePlaceholder(false)
                        ->options(collect(TicketPriority::cases())->mapWithKeys(fn (TicketPriority $p) => [$p->value => $p->label()])),
                    Forms\Components\Select::make('assigned_to')->label('Assigned to')->placeholder('Nobody')
                        ->options(SupportTicketResource::adminOptions()),
                ])
                ->action(function (array $data, ChangeTicketStatus $change): void {
                    $ticket = $this->ticket();
                    $admin = $this->admin();

                    if ((int) ($data['assigned_to'] ?? 0) !== (int) $ticket->assigned_to) {
                        $to = filled($data['assigned_to'] ?? null) ? User::query()->find($data['assigned_to']) : null;
                        $change->assign($ticket, $admin, $to);
                    }
                    if ($data['priority'] !== $ticket->priority->value) {
                        AuditLog::record('support.priority_changed', $ticket, ['before' => $ticket->priority->value, 'after' => $data['priority']], $admin, $ticket->lot_id);
                        $ticket->update(['priority' => TicketPriority::from($data['priority'])]);
                    }
                    $change->byAdmin($ticket->refresh(), $admin, TicketStatus::from($data['status']));

                    Notification::make()->title('Ticket updated')->success()->send();
                    $this->refreshRecord();
                }),
        ];
    }

    /** @return list<Forms\Components\Component> */
    private static function attachmentField(SupportTicket $ticket): array
    {
        return [
            Forms\Components\FileUpload::make('attachment')->label('Attachment (optional)')
                ->disk(SupportMessage::DISK)->directory("support/{$ticket->ulid}")->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(TicketAttachment::MAX_KB)
                ->storeFileNamesIn('attachment_name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{attachment_path: string, attachment_name: string|null}|null
     */
    private static function stored(array $data): ?array
    {
        return filled($data['attachment'] ?? null)
            ? ['attachment_path' => (string) $data['attachment'], 'attachment_name' => isset($data['attachment_name']) ? (string) $data['attachment_name'] : null]
            : null;
    }

    private function ticket(): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->record;

        return $ticket;
    }

    private function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
    }

    private function refreshRecord(): void
    {
        $this->record = SupportTicket::withoutGlobalScopes()->with(['lot', 'assignee', 'opener'])->findOrFail($this->ticket()->id);
    }
}
