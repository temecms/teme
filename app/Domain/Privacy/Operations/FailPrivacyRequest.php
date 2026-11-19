<?php

/**
 * Ends a privacy request that stopped, with why (I-3375). The reason never
 * keeps the request's address, which the record outlives (I-270): it is
 * taken out of the whole reason before the reason is cut to its length. A
 * failed export's files are deleted after commit. A request that already
 * ended is left alone.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Operations;

use App\Domain\Privacy\ExportFile;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Concerns\AuthorizesPrivacyWork;
use App\Domain\Privacy\Operations\Input\FailPrivacyRequestInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;

final class FailPrivacyRequest extends BaseOperation
{
    use AuthorizesPrivacyWork;

    /** The longest reason kept, in characters. */
    public const int MOST_CHARACTERS = 500;

    /** The shortest start of the address taken out where a cut ends the reason. */
    private const int SHORTEST_START = 4;

    protected function validate(OperationInput $input): void
    {
        /** @var FailPrivacyRequestInput $input */
        Validator::make(
            ['request' => $input->requestId, 'failure' => $input->failure],
            [
                'request' => ['required', 'string', Rule::exists('privacy_requests', 'id')],
                'failure' => ['required', 'string'],
            ],
        )->validate();
    }

    /**
     * @return list<string> the export's files to delete after commit
     */
    protected function perform(OperationInput $input): array
    {
        /** @var FailPrivacyRequestInput $input */
        $request = PrivacyRequest::query()->lockForUpdate()->findOrFail($input->requestId);

        if (! in_array($request->status, [PrivacyRequest::PENDING, PrivacyRequest::RUNNING], true)) {
            return [];
        }

        $request->status = PrivacyRequest::FAILED;
        $request->failure = $this->withoutAddress($input->failure, $request->email, self::MOST_CHARACTERS);
        $request->completed_at = now()->toImmutable();
        $request->save();

        return $request->kind === PrivacyRequest::EXPORT ? app(ExportFile::class)->allPaths($request->id, $request->results) : [];
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var list<string> $result */
        app(ExportFile::class)->delete($result);
    }

    /**
     * Returns the reason with the address taken out, cut to at most $most
     * characters. A cut never splits the address's marker, and a reason
     * that ends partway through the address, cut before it came here, loses
     * that part too, marked as the address.
     */
    private function withoutAddress(string $failure, ?string $email, int $most): string
    {
        $most = max(0, $most);

        if ($email === null) {
            return mb_substr($failure, 0, $most);
        }

        $marker = __('[the address]');
        $size = mb_strlen($marker);
        $text = str_ireplace($email, $marker, $failure);

        if (mb_strlen($text) > $most) {
            $cut = mb_substr($text, 0, $most);

            for ($split = $size - 1; $split >= 1; $split--) {
                if (mb_substr($text, $most - $split, $size) === $marker) {
                    $cut = mb_substr($text, 0, min($most - $split, $most - $size)).$marker;

                    break;
                }
            }

            $text = $cut;
        }

        $email = mb_strtolower($email);

        for ($length = min(mb_strlen($email), mb_strlen($text)); $length >= self::SHORTEST_START; $length--) {
            if (mb_strtolower(mb_substr($text, -$length)) === mb_substr($email, 0, $length)) {
                // Cut again, shorter, so the reason and the marker fit: the new end is checked too.
                return $this->withoutAddress(mb_substr($text, 0, -$length), $email, $most - $size).$marker;
            }
        }

        return $text;
    }
}
