@php
    /** @var \App\Domain\Helpdesk\Models\SupportTicket $ticket */
    $ticket = $this->record;
    $lot = $ticket->lot;
    $colors = ['open' => 'warning', 'pending' => 'info', 'resolved' => 'success', 'closed' => 'gray'];
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Details</x-slot>
        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Seller</dt>
                <dd style="font-weight: 600;">
                    @if ($lot)
                        <a href="{{ \App\Filament\Resources\LotResource::getUrl('view', ['record' => $lot]) }}" style="text-decoration: underline;">{{ $lot->name }}</a>
                        <div style="font-weight: 400; font-size: .875rem;">{{ collect([$lot->city, $lot->phone])->filter()->implode(' · ') }}</div>
                    @else
                        Deleted seller
                    @endif
                </dd>
            </div>
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Opened by</dt>
                <dd style="font-weight: 600;">
                    {{ $ticket->opener->name ?? 'Unknown' }}
                    <div style="font-weight: 400; font-size: .875rem;">{{ collect([$ticket->opener?->phone, $ticket->opener?->email])->filter()->implode(' · ') }}</div>
                </dd>
            </div>
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Status</dt>
                <dd style="display: flex; gap: .375rem; flex-wrap: wrap; margin-top: .125rem;">
                    <x-filament::badge :color="$colors[$ticket->status->value]">{{ $ticket->status->label() }}</x-filament::badge>
                    <x-filament::badge :color="match ($ticket->priority->value) { 'urgent' => 'danger', 'high' => 'warning', 'normal' => 'info', default => 'gray' }">{{ $ticket->priority->short() }}</x-filament::badge>
                </dd>
            </div>
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Topic</dt>
                <dd style="font-weight: 600;">{{ $ticket->category->label() }}</dd>
            </div>
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Assigned to</dt>
                <dd style="font-weight: 600;">{{ $ticket->assignee->name ?? 'Nobody' }}</dd>
            </div>
            @if ($ticket->vehicle)
                <div>
                    <dt style="font-size: .75rem; opacity: .7;">Car</dt>
                    <dd style="font-weight: 600;">{{ $ticket->vehicle->title() }}</dd>
                </div>
            @endif
            <div>
                <dt style="font-size: .75rem; opacity: .7;">Opened</dt>
                <dd style="font-weight: 600;">{{ $ticket->created_at->setTimezone('Africa/Lagos')->format('j M Y, g:ia') }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <div style="display: flex; flex-direction: column; gap: 1rem;">
        @foreach ($this->thread() as $message)
            <x-filament::section
                :icon="$message->internal ? 'heroicon-o-lock-closed' : ($message->from_admin ? 'heroicon-o-lifebuoy' : 'heroicon-o-building-storefront')"
                :icon-color="$message->internal ? 'warning' : ($message->from_admin ? 'primary' : 'gray')"
                compact
            >
                <x-slot name="heading">
                    {{ $message->author->name ?? ($message->from_admin ? 'CarYard Support' : 'Seller') }}
                    @if ($message->internal)
                        <x-filament::badge color="warning" style="display: inline-flex; margin-left: .375rem;">Internal note</x-filament::badge>
                    @elseif ($message->from_admin)
                        <x-filament::badge color="primary" style="display: inline-flex; margin-left: .375rem;">CarYard</x-filament::badge>
                    @endif
                </x-slot>
                <x-slot name="description">{{ $message->created_at->setTimezone('Africa/Lagos')->format('j M Y, g:ia') }}</x-slot>

                <div style="white-space: pre-line; line-height: 1.55;">{{ $message->body }}</div>

                @if ($message->attachment_path)
                    <div style="margin-top: .75rem;">
                        <x-filament::link :href="$message->attachmentUrl()" target="_blank" icon="heroicon-o-paper-clip">
                            {{ $message->attachment_name ?? 'Attachment' }}
                        </x-filament::link>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
