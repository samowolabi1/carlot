<?php

use App\Domain\Support\PhoneNumber;

it('normalises Nigerian numbers to E.164', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('+2348031234412');
})->with(['08031234412', '0803 123 4412', '+234 803 123 4412', '2348031234412', '(0803) 123-4412']);

it('rejects invalid numbers', function () {
    PhoneNumber::normalize('12345');
})->throws(InvalidArgumentException::class);

it('masks numbers for the dealer UI', function () {
    expect(PhoneNumber::mask('+2348031234412'))->toBe('+234 803 *** 4412');
});
