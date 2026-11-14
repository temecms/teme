<?php

declare(strict_types=1);

namespace App\Domain\Cards\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A document that does not fit the schema. Its message is what people read;
 * the path of the part that failed (such as `content.3.attrs.alt`) stays on
 * $path for code that needs it. The error is on the key `document`.
 */
final class InvalidDocumentException extends ValidationException
{
    public string $path = '';

    public static function at(string $path, string $message): self
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('document', $message);

        $exception = new self($validator);
        $exception->path = $path;

        return $exception;
    }
}
