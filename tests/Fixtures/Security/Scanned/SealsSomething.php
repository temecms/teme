<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security\Scanned;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A file the encrypting-call scan reads: three real calls, and the same
 * words in a comment, a string and a method's name, which never count.
 */
final class SealsSomething
{
    // Crypt::encryptString($value) in a comment isn't a call.
    public function seal(string $value): string
    {
        $words = 'encrypt($value) in a string isn’t one either';

        return Crypt::encryptString($value.$words);
    }

    public function sealAgain(string $value): string
    {
        return encrypt($value);
    }

    public function encrypt(string $value): string
    {
        return $value;
    }

    public function castLater(Model $model): void
    {
        $model->mergeCasts(['token' => 'encrypted']);
    }
}
