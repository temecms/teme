<?php

declare(strict_types=1);

namespace App\Domain\SiteCode\Operations;

use App\Domain\Plugins\HostPattern;
use App\Domain\Security\SecurityEventType;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\Concerns\ChangesSiteCode;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\SiteCode;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Adds an entry of site code, or changes one: its name, placement, code,
 * switch and the hosts it also connects to (I-3375), the code kept exactly as
 * it came (I-865). It asks for the password
 * again, is logged with its before and after, and purges every stored page
 * when pages draw it.
 */
final class SaveSiteCode extends BaseOperation implements RequiresElevation
{
    use ChangesSiteCode;

    /** The most hosts one entry may declare. */
    private const int MOST_ORIGINS = 20;

    protected function validate(OperationInput $input): void
    {
        /** @var SaveSiteCodeInput $input */
        $problems = [];
        $name = trim($input->name);

        if ($name === '') {
            $problems['name'] = 'Give the code a name, so everyone can tell what it is.';
        } elseif (mb_strlen($name) > 120) {
            $problems['name'] = 'Keep the name to 120 characters or fewer.';
        }

        if (! array_key_exists($input->placement, SiteCode::PLACES)) {
            $problems['placement'] = 'Choose where the code goes.';
        }

        // PostgreSQL's text holds neither a NUL nor bytes that aren't UTF-8.
        if (! mb_check_encoding($input->code, 'UTF-8') || str_contains($input->code, "\0")) {
            $problems['code'] = 'The code holds characters a page can’t carry. Paste it again from where you copied it.';
        } elseif (trim($input->code) === '') {
            $problems['code'] = 'Paste the code to add, or cancel.';
        } elseif (mb_strlen($input->code) > SiteCode::MAX_LENGTH) {
            $problems['code'] = 'The code is longer than 65,535 characters, the most one entry holds.';
        }

        $origins = self::origins($input->origins);

        if (count($origins) > self::MOST_ORIGINS) {
            $problems['origins'] = __('List 20 hosts at most.');
        } else {
            foreach ($origins as $host) {
                if (preg_match(HostPattern::PATTERN, $host) !== 1) {
                    $problems['origins'] = __('“:value” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.', ['value' => $host]);

                    break;
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * The hosts as given, one or more a line: each trimmed and in lower case,
     * empty lines dropped, each once, in the order first given.
     *
     * @param  list<string>  $given
     *
     * @return list<string>
     */
    private static function origins(array $given): array
    {
        $hosts = [];

        foreach (preg_split('/\R/u', implode("\n", $given)) ?: [] as $line) {
            $host = mb_strtolower(trim($line));

            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @return array{id: string, added: bool, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SaveSiteCodeInput $input */
        $entry = $input->entryId === null
            ? new SiteCodeEntry
            : SiteCodeEntry::query()->lockForUpdate()->find($input->entryId)
                ?? throw ValidationException::withMessages(['entry' => 'This code was deleted while you had it open.']);
        $before = $entry->exists ? $entry->snapshot() : null;

        $entry->fill(['name' => trim($input->name), 'placement' => $input->placement, 'code' => $input->code, 'enabled' => $input->on, 'origins' => self::origins($input->origins)]);

        if ($before !== null && ! $entry->isDirty()) {
            return ['id' => $entry->id, 'added' => false, 'changed' => false];
        }

        $entry->updated_by = (int) $input->actorId;
        $entry->save();
        $after = $entry->snapshot();

        $this->logChange($before === null ? SecurityEventType::SiteCodeAdded : SecurityEventType::SiteCodeChanged, $input, $entry->id, $before, $after);
        $this->purgeIfDrawn($before, $after);

        return ['id' => $entry->id, 'added' => $before === null, 'changed' => true];
    }
}
