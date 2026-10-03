<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Mail\LotWelcome;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use App\Domain\Messaging\Messenger;
use App\Domain\Support\PhoneNumber;
use App\Domain\Support\Regions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * An admin signs up a seller for its owner (field sales, phone calls): the owner's account (found or
 * made from their email or WhatsApp number, whichever they'll sign in with), the seller, and a welcome
 * telling them how to sign in. The owner finishes the profile, photos and bank details themselves.
 */
class OnboardLot
{
    public function __construct(private readonly CreateLot $createLot, private readonly Messenger $messenger) {}

    /**
     * @param  array{owner_name: string, sign_in: 'email'|'whatsapp', email?: ?string, phone?: ?string, lot_name: string, lot_phone?: ?string, state: string, city: string, address?: ?string, plan_id?: ?int, approve?: bool}  $data
     * @return array{lot: Lot, owner: User, welcomed: bool}
     */
    public function run(User $admin, array $data): array
    {
        $state = Regions::normalize($data['state']) ?? throw ValidationException::withMessages(['state' => 'Pick a state from the list.']);
        $email = filled($data['email'] ?? null) ? Str::lower(trim((string) $data['email'])) : null;
        $phone = null;

        try {
            $phone = filled($data['phone'] ?? null) ? PhoneNumber::normalize((string) $data['phone']) : null;
            $lotPhone = filled($data['lot_phone'] ?? null) ? PhoneNumber::normalize((string) $data['lot_phone']) : $phone;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        if ($data['sign_in'] === 'email' && $email === null) {
            throw ValidationException::withMessages(['email' => 'Add the email address the owner will sign in with.']);
        }
        if ($data['sign_in'] === 'whatsapp' && $phone === null) {
            throw ValidationException::withMessages(['phone' => 'Add the WhatsApp number the owner will sign in with.']);
        }
        if ($lotPhone === null) {
            throw ValidationException::withMessages(['lot_phone' => 'Add a phone number buyers can call.']);
        }

        [$lot, $owner] = DB::transaction(function () use ($admin, $data, $email, $phone, $lotPhone, $state) {
            $owner = $this->owner($data['sign_in'] === 'email' ? 'email' : 'phone', $data['sign_in'] === 'email' ? (string) $email : (string) $phone, $data['owner_name'], $email, $phone);

            $lot = $this->createLot->run($owner, array_filter([
                'name' => trim($data['lot_name']),
                'phone' => $lotPhone,
                'whatsapp' => $phone ?? $lotPhone,
                'email' => $email,
                'state' => $state,
                'city' => trim($data['city']),
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            ], fn ($v) => $v !== null));

            $lot->forceFill(array_filter([
                'onboarded_by' => $admin->id,
                'plan_id' => $data['plan_id'] ?? null,
                'status' => ($data['approve'] ?? false) ? LotStatus::Active : null,
                'submitted_at' => ($data['approve'] ?? false) ? now() : null,
            ], fn ($v) => $v !== null))->save();

            AuditLog::record('admin.lot_onboarded', $lot, [
                'owner' => $owner->ulid, 'sign_in' => $data['sign_in'], 'state' => $state, 'approved' => (bool) ($data['approve'] ?? false),
            ], $admin, $lot->id);

            return [$lot, $owner];
        });

        return ['lot' => $lot, 'owner' => $owner, 'welcomed' => $this->welcome($lot, $owner, $data['sign_in'], $email, $phone)];
    }

    private function owner(string $field, string $value, string $name, ?string $email, ?string $phone): User
    {
        $owner = User::query()->where($field, $value)->first();

        if ($owner?->isAdmin()) {
            throw ValidationException::withMessages([$field === 'email' ? 'email' : 'phone' => 'That is a CarYard admin account. Use the owner\'s own email or number.']);
        }

        if ($owner === null) {
            // The other contact detail is only kept if nobody else has it.
            $owner = User::query()->create([
                'name' => trim($name),
                'email' => $field === 'email' || ($email !== null && ! User::where('email', $email)->exists()) ? $email : null,
                'phone' => $field === 'phone' || ($phone !== null && ! User::where('phone', $phone)->exists()) ? $phone : null,
                'role' => UserRole::Staff,
            ]);
        } elseif (blank($owner->name)) {
            $owner->update(['name' => trim($name)]);
        }

        return $owner;
    }

    /** Tells the owner their lot is set up and how to sign in (no SMS: email, or WhatsApp only). */
    private function welcome(Lot $lot, User $owner, string $signIn, ?string $email, ?string $phone): bool
    {
        $url = route('login', ['method' => $signIn === 'email' ? 'email' : 'whatsapp']);

        try {
            if ($signIn === 'email' && $email !== null) {
                Mail::to($email)->send(new LotWelcome($lot, (string) $owner->name, $url));

                return true;
            }

            if ($phone !== null) {
                $used = $this->messenger->send($phone, new Message(
                    template: 'lot_welcome',
                    params: [(string) $owner->name, $lot->name],
                    text: "Hi {$owner->name}, {$lot->name} is set up on CarYard. Sign in with this WhatsApp number to add your cars: {$url}",
                    buttonSuffix: Message::suffix($url),
                ), preferWhatsApp: true, smsFallback: false);

                return $used !== 'off';
            }
        } catch (Throwable $e) {
            Log::warning("Welcome message for seller {$lot->id} failed: {$e->getMessage()}");
        }

        return false;
    }
}
