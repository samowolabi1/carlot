<?php

namespace App\Domain\Accounts\Exceptions;

use RuntimeException;

class OtpException extends RuntimeException
{
    public static function tooManySends(int $minutes): self
    {
        return new self("Too many codes sent. Try again in {$minutes} minutes.");
    }

    public static function invalid(): self
    {
        return new self('That code is not right. Check the message and try again.');
    }

    public static function expired(): self
    {
        return new self('That code has expired or been used too many times. Request a new one.');
    }
}
