<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Enums\Interest;
use App\Domain\LotManager\Enums\NextStep;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ProcessOfflineSync (TDD M19: Offline mode): replays a phone's queue in order. Each item
 * carries its client_uuid, so a retry never makes a second row. One bad item doesn't
 * stop the rest; each gets its own result.
 */
class SyncOfflineItems
{
    public const TYPES = ['walk_in', 'order', 'payment'];

    public function __construct(
        private readonly RecordWalkIn $walkIn,
        private readonly CreateOrder $order,
        private readonly RecordPayment $payment,
    ) {}

    /**
     * @param  list<array{type: string, client_uuid: string, data: array<string, mixed>}>  $items
     * @return list<array{client_uuid: string, status: 'ok'|'failed', ulid?: string, message?: string}>
     */
    public function run(Lot $lot, User $user, array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $uuid = $item['client_uuid'];

            try {
                $record = match ($item['type']) {
                    'walk_in' => $this->walkIn->run($lot, $user, [...$this->money($this->validate($item['data'], self::walkInRules()), ['budget_max']), 'client_uuid' => $uuid]),
                    'order' => $this->order->run($lot, $user, [...$this->money($this->validate($item['data'], self::orderRules()), ['agreed_price', 'discount', 'trade_in_value', 'deposit_required']), 'client_uuid' => $uuid]),
                    'payment' => $this->recordPayment($lot, $user, $item['data'], $uuid),
                    default => throw ValidationException::withMessages(['type' => 'Unknown item.']),
                };

                $results[] = ['client_uuid' => $uuid, 'status' => 'ok', 'ulid' => (string) $record->getAttribute('ulid')];
            } catch (ValidationException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'failed', 'message' => $e->validator->errors()->first()];
            } catch (Throwable $e) {
                report($e);
                $results[] = ['client_uuid' => $uuid, 'status' => 'failed', 'message' => 'Could not save this item.'];
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $data */
    private function recordPayment(Lot $lot, User $user, array $data, string $uuid): mixed
    {
        $data = $this->validate($data, ['order' => ['required', 'string', 'max:64'], ...self::paymentRules()]);

        // The order may itself have been made offline, so it is found by ULID or client_uuid.
        $order = SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)
            ->where(fn ($q) => $q->where('ulid', $data['order'])->orWhere('client_uuid', $data['order']))
            ->first() ?? throw ValidationException::withMessages(['order' => 'That order was not found.']);

        return $this->payment->run($order, $user, [...$data, 'amount' => Money::fromMajor($data['amount']), 'client_uuid' => $uuid]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function money(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $data[$key] = Money::fromMajor($data[$key]);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function walkInRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'source' => ['nullable', Rule::enum(CustomerSource::class)],
            'interest' => ['nullable', Rule::enum(Interest::class)],
            'next_step' => ['nullable', Rule::enum(NextStep::class)],
            'vehicles' => ['nullable', 'array', 'max:5'],
            'vehicles.*' => ['string', 'size:26'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent_whatsapp' => ['nullable', 'boolean'],
            'visited_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public static function paymentRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:64'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public static function orderRules(): array
    {
        return [
            'customer' => ['nullable', 'string', 'size:26'],
            'name' => ['required_without:customer', 'nullable', 'string', 'max:80'],
            'phone' => ['required_without:customer', 'nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'consent_whatsapp' => ['nullable', 'boolean'],
            'vehicle' => ['required', 'string', 'size:26'],
            'agreed_price' => ['nullable', 'numeric', 'min:1'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'trade_in_value' => ['nullable', 'numeric', 'min:0'],
            'deposit_required' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
