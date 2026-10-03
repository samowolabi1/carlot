<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        What this server has and what CarYard needs. The same checks run with <code>php artisan lotlink:doctor</code>. Fix anything marked
        <strong>Needs fixing</strong>; <em>Check</em> items work but could be better.
    </p>
    @foreach ($this->groups() as $group => $checks)
        <x-filament::section :heading="$group">
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($checks as $check)
                    <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2 text-sm">
                        <x-filament::badge :color="match ($check['status']) { 'ok' => 'success', 'warn' => 'warning', default => 'danger' }">
                            {{ match ($check['status']) { 'ok' => 'OK', 'warn' => 'Check', default => 'Needs fixing' } }}
                        </x-filament::badge>
                        <span class="font-medium">{{ $check['label'] }}</span>
                        @if ($check['detail'] !== '')
                            <span class="w-full text-gray-500 sm:w-auto dark:text-gray-400">{{ $check['detail'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
