<?php

use App\Domain\Support\Fields;
use App\Rules\FieldPattern;
use Illuminate\Support\Facades\Validator;

$passes = fn (array $rules, mixed $value): bool => Validator::make(['v' => $value], ['v' => $rules])->passes();

it('accepts real names and rejects digits and symbols', function () use ($passes) {
    foreach (['Chioma Okafor', "O'Neil", 'Adé-Bàyọ̀ Ọlá', 'Dr. Tunde Bakare', 'Ngozi'] as $ok) {
        expect($passes(Fields::personName(), $ok))->toBeTrue("{$ok} should pass");
    }
    foreach (['Chi0ma', 'Ada $$', '<b>Ada</b>', 'A', '-Ada', str_repeat('a', 81), 'ada@example.com'] as $bad) {
        expect($passes(Fields::personName(), $bad))->toBeFalse("{$bad} should fail");
    }
});

it('accepts business, place and model names in their own shapes', function () use ($passes) {
    expect($passes(Fields::businessName(), 'AutoHub 24/7 Ltd'))->toBeTrue()
        ->and($passes(Fields::businessName(), 'Kemi (workshop)'))->toBeTrue()
        ->and($passes(Fields::businessName(), '12345'))->toBeFalse()   // needs a letter
        ->and($passes(Fields::businessName(), 'Cars <script>'))->toBeFalse()
        ->and($passes(Fields::place(), 'Port Harcourt'))->toBeTrue()
        ->and($passes(Fields::place(), 'Ado-Ekiti'))->toBeTrue()
        ->and($passes(Fields::place(), 'Lekki 2'))->toBeFalse()
        ->and($passes(Fields::model(), 'RAV4'))->toBeTrue()
        ->and($passes(Fields::model(), 'C-Class'))->toBeTrue()
        ->and($passes(Fields::model(), 'E 350 4Matic'))->toBeTrue()
        ->and($passes(Fields::model(), 'Corolla; DROP'))->toBeFalse();
});

it('checks phone numbers are real numbers, not just digits', function () use ($passes) {
    foreach (['08031234567', '0803 123 4567', '+234 803 123 4567', '(0803) 123-4567'] as $ok) {
        expect($passes(Fields::phone(), $ok))->toBeTrue("{$ok} should pass");
    }
    foreach (['123', '0803 12', 'abc', '0803123456789012345', '+234 803 abc 4567'] as $bad) {
        expect($passes(Fields::phone(), $bad))->toBeFalse("{$bad} should fail");
    }
    $messages = Validator::make(['phone' => 'abc'], ['phone' => Fields::phone()])->errors()->get('phone');
    expect($messages[0])->toBe('Phone number must look like 0803 123 4567 or +234 803 123 4567.');
});

it('checks emails, codes and references', function () use ($passes) {
    expect($passes(Fields::email(), 'ada@example.com'))->toBeTrue()
        ->and($passes(Fields::email(), 'ada@example'))->toBeFalse()
        ->and($passes(Fields::email(), 'ada example.com'))->toBeFalse()
        ->and($passes(Fields::email(), str_repeat('a', 185).'@x.com'))->toBeFalse()
        ->and($passes(Fields::code(), 'KANO-MEETUP'))->toBeTrue()
        ->and($passes(Fields::code(), 'LAUNCH 3'))->toBeFalse()
        ->and($passes(Fields::reference(), 'TRF/2026/00341'))->toBeTrue()
        ->and($passes(Fields::reference(), 'ref<1>'))->toBeFalse();
});

it('reads money as people type it but never turns kobo into naira', function () use ($passes) {
    expect(Fields::cleanMoney('₦1,500,000'))->toBe('1500000')
        ->and(Fields::cleanMoney('NGN 1 500 000'))->toBe('1500000')
        ->and(Fields::cleanMoney('1,500,000.00'))->toBe('1500000')
        ->and(Fields::cleanMoney('1500.50'))->toBe('1500.50')   // left for `integer` to reject
        ->and(Fields::cleanMoney('12abc3'))->toBe('12abc3')
        ->and(Fields::cleanMoney(''))->toBeNull()
        ->and($passes(Fields::money(), Fields::cleanMoney('1500.50')))->toBeFalse()
        ->and($passes(Fields::money(), Fields::cleanMoney('₦12,300,000')))->toBeTrue()
        ->and($passes(Fields::money(), Fields::MONEY_MAX + 1))->toBeFalse();
});

it('needs new passwords of 8 to 72 characters with letters and numbers', function () {
    $check = fn (string $p) => Validator::make(['password' => $p, 'password_confirmation' => $p], ['password' => Fields::newPassword()])->passes();

    expect($check('lotlink2026'))->toBeTrue()
        ->and($check('short1'))->toBeFalse()
        ->and($check('onlyletters'))->toBeFalse()
        ->and($check('12345678'))->toBeFalse()
        ->and($check(str_repeat('a1', 37)))->toBeFalse(); // 74 characters
});

it('keeps the browser rules in step with the server', function () {
    $ts = file_get_contents(resource_path('js/lib/fields.ts'));

    // Every pattern, written once in PHP, appears unchanged in lib/fields.ts.
    foreach (FieldPattern::KINDS as $kind => [$pattern, $message]) {
        $source = substr($pattern, 1, strrpos($pattern, '/') - 1);
        expect($ts)->toContain("pattern: /{$source}/u")
            ->and($ts)->toContain($message);
    }

    expect($ts)->toContain('export const MONEY_MAX = '.number_format(Fields::MONEY_MAX, 0, '', '_').';')
        ->and($ts)->toContain('max: '.Fields::PASSWORD_MAX.',');
});
