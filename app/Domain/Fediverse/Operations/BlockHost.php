<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\BlockHostInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Blocks a server and every domain below it (proposal, Settled 8): its
 * requests are refused at the inbox before anything else, and nothing is
 * sent to it. For whoever holds settings.fediverse.manage, or the
 * operator; returns the host as stored, lower-cased.
 */
final class BlockHost extends BaseOperation
{
    use AuthorizesFediverseWork;

    /** A host name of two labels or more, as DNS allows them. */
    private const string HOST = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to block servers.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var BlockHostInput $input */
        $host = self::normalized($input->host);

        if (preg_match(self::HOST, $host) !== 1) {
            throw ValidationException::withMessages(['host' => __('“:host” isn’t a server name. Write it as spam.example, without https:// or a path.', ['host' => trim($input->host)])]);
        }

        if ($host === strtolower((string) parse_url(url('/'), PHP_URL_HOST))) {
            throw ValidationException::withMessages(['host' => __('That is this site.')]);
        }

        if (mb_strlen((string) $input->reason) > 255) {
            throw ValidationException::withMessages(['reason' => __('Keep the reason to 255 characters or fewer.')]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var BlockHostInput $input */
        $host = self::normalized($input->host);
        $reason = trim((string) $input->reason);

        BlockedHost::query()->updateOrCreate(['host' => $host], [
            'reason' => $reason === '' ? null : $reason,
            'created_at' => now(),
            'created_by' => is_numeric($input->actorId()) ? (int) $input->actorId() : null,
        ]);

        return $host;
    }

    /**
     * The host as typed, lower-cased, without spaces or a final dot.
     */
    public static function normalized(string $host): string
    {
        return strtolower(trim(trim($host), '.'));
    }
}
