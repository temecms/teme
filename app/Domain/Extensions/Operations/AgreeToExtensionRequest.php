<?php

/**
 * Agrees to what the installer found and waits on (I-211): files that run on every request, a Composer
 * plugin that won't run, a different source, a replaced version. It records exactly the list the person
 * was shown, and refuses when the installer has found something else since; the installer then checks
 * again and goes on. A person who holds extensions.install, after their password, or the operator.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\AgreeToExtensionRequestInput;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class AgreeToExtensionRequest extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var AgreeToExtensionRequestInput $input */
        $row = DB::table('extension_requests')->where('id', $input->requestId)->lockForUpdate()->first();

        if ($row === null) {
            throw ValidationException::withMessages(['request' => [__('There’s no such request.')]]);
        }

        if ($row->status !== RequestStatus::Waiting->value) {
            throw ValidationException::withMessages(['request' => [__('This request isn’t waiting for your agreement.')]]);
        }

        $needs = json_decode((string) $row->needs, true);

        if (! is_array($needs) || $needs === [] || self::canonical($needs) !== self::canonical($input->needs)) {
            throw ValidationException::withMessages(['request' => [__('What the installer found has changed. Look again.')]]);
        }

        DB::table('extension_requests')->where('id', $input->requestId)->update([
            'agreed' => json_encode($needs, JSON_THROW_ON_ERROR),
            'agreed_by' => $input->actorId() === null ? null : (int) $input->actorId(),
        ]);

        return null;
    }

    /**
     * The list as JSON with every object's keys in order, so the same list read back from JSONB, whose
     * key order PostgreSQL chooses, compares equal.
     *
     * @param  array<mixed>  $value
     */
    private static function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
