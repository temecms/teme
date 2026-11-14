<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Check;

use App\Domain\Addresses\Paths;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Redirects\Queries\SiteAnswers;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Str;

/**
 * Before the switch (I-339): every address the old site answers, from the
 * companion's urls, asked of this site in-process through SiteAnswers, as a
 * reader's request would meet it; each one that doesn't answer listed, an
 * error that holds up "Ready to switch". Fifty seconds a job, carried on
 * after the last address done; started again, it starts its messages again.
 */
final readonly class CheckStep implements StepPhase
{
    /** Seconds a job checks for before it hands on to the next. */
    public const int BUDGET = 50;

    /** How many more steps a redirect on this site is followed: its canonical form, then an old address's own. */
    public const int HOPS = 2;

    /** The statuses that send readers on. */
    private const array ONWARD = [301, 302, 307, 308];

    public function __construct(private OldSiteUrls $urls, private SiteAnswers $answers, private ContentTypeRegistry $types) {}

    public function key(): string
    {
        return 'check';
    }

    public function order(): int
    {
        return 1100;
    }

    public function label(): string
    {
        return 'Address check';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'check.', $context->actorId()));
        }

        $host = OldSiteUrls::hostOf($context->sourceId);
        $started = microtime(true);
        $messages = [];
        $others = [];
        $position = $cursor;

        while (true) {
            $page = $this->urls->page($reader, OldSiteUrls::KINDS, $position);
            $position = $page['cursor'];

            foreach ($page['items'] as $item) {
                $messages = [...$messages, ...$this->item($page['kind'], $item, $host, $others)];

                // Out of time inside a page: carry on after this item. The home page and the archives come whole.
                if (microtime(true) - $started >= self::BUDGET && $page['kind'] !== 'archive') {
                    $position = $page['kind'].':'.(int) ($item['id'] ?? 0);

                    break 2;
                }
            }

            if ($position === null || microtime(true) - $started >= self::BUDGET) {
                break;
            }
        }

        $messages = [...$messages, ...$this->otherHosts($context->runId, $others)];

        return $position === null ? StepResult::done($messages) : StepResult::continueAt($position, $messages);
    }

    /**
     * One item's addresses checked: its own, or each file of an attachment.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $others  another host, and the first of its addresses met
     *
     * @return list<ImportMessage>
     */
    private function item(string $kind, array $item, string $host, array &$others): array
    {
        $addresses = $kind === 'attachment_file'
            ? array_values(array_unique(array_filter((array) ($item['files'] ?? []), is_string(...))))
            : [is_string($item['url'] ?? null) ? $item['url'] : ''];
        [$what, $brought] = $this->what($kind, $item);
        $messages = [];

        foreach ($addresses as $address) {
            if ($address === '') {
                continue;
            }

            $own = OldSiteUrls::own($address, $host);

            if ($own === null) {
                $other = parse_url(str_starts_with($address, '//') ? 'https:'.$address : $address, PHP_URL_HOST);

                if (is_string($other) && $other !== '') {
                    $others[OldSiteUrls::bare($other)] ??= $address;
                }

                continue;
            }

            $message = $this->check($own, $kind, (int) ($item['id'] ?? 0), $what, $brought);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * What this site answers at one old address, as a message when it is
     * neither a page nor a redirect that reaches one, another site or the
     * site's own files.
     *
     * @param  array{path: string, query: string}  $own
     */
    private function check(array $own, string $kind, int $id, string $what, bool $brought): ?ImportMessage
    {
        $address = $own['path'].($own['query'] === '' ? '' : '?'.$own['query']);
        $context = ['address' => mb_substr($address, 0, 500), 'kind' => $kind, 'id' => $id];
        // Cut short in words: a message holds at most 1,000 characters.
        $shown = Str::limit($address, 300);

        if ($own['query'] !== '') {
            return ImportMessage::error('check.query', "{$shown}, {$what} on the old site, differs from other addresses only by what follows the ?, which Teme doesn’t tell apart, so readers following it reach the home page.", $context);
        }

        $answer = RedirectSource::readable(rawurldecode($own['path'])) ? $this->answers->at($own['path']) : null;
        $location = null;

        for ($hop = 0; $answer !== null && $hop < self::HOPS && in_array($answer->status, self::ONWARD, true); $hop++) {
            $next = $answer->location === null ? null : RedirectSource::onThisSite($answer->location);

            // Another site's, or the site's own files: nothing more to ask here.
            if ($next === null || ! str_starts_with($next, '/') || Paths::taken(explode('/', trim($next, '/'))[0])) {
                return null;
            }

            $location = Str::limit((string) $answer->location, 300);
            $answer = $this->answers->at($next);
        }

        if ($answer !== null && ($answer->status === 200 || in_array($answer->status, self::ONWARD, true))) {
            return null;
        }

        if ($answer === null || $answer->status === 404) {
            if ($location !== null) {
                return ImportMessage::error('check.redirect-not-found', "{$shown}, {$what} on the old site, is sent on to {$location}, which answers “not found”. Change where it leads in Tools › Redirects.", $context);
            }

            if ($brought) {
                return ImportMessage::error('check.not-found', "{$shown}, {$what} on the old site, answers “not found” here. Bring what it showed, or add a redirect for it in Tools › Redirects.", $context);
            }

            $part = Str::limit(explode('/', trim($own['path'], '/'))[0], 100);

            return ImportMessage::error('check.not-brought', "{$shown}, {$what} on the old site, answers “not found” here, because Teme doesn’t bring that kind of thing. Add a redirect for it in Tools › Redirects: one pattern, such as /{$part}/[...rest], covers every address under one part.", $context);
        }

        return new ImportMessage('check.other-status', "{$shown}, {$what} on the old site, answers {$answer->status} here.", $context);
    }

    /**
     * What the address was on the old site, in words, and whether the
     * import brings that kind of thing.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{0: string, 1: bool}
     */
    private function what(string $kind, array $item): array
    {
        $type = is_string($item['type'] ?? null) ? $item['type'] : '';

        if ($kind === 'term') {
            $named = is_string($item['taxonomy'] ?? null) ? $item['taxonomy'] : '';
            $taxonomy = WordPressAddresses::temeTaxonomy($named);

            return $taxonomy === null ? ["a term of {$named}", false] : ['the '.mb_strtolower($taxonomy->singular), true];
        }

        if ($kind === 'author') {
            return ['an author’s page', true];
        }

        if ($kind === 'attachment_page') {
            return ['an attachment page', true];
        }

        if ($kind === 'attachment_file') {
            return ['a file of the media library', true];
        }

        if ($kind === 'archive') {
            return ($item['kind'] ?? null) === 'home' ? ['the home page', true] : ["the archive of {$type}", false];
        }

        $teme = WordPressAddresses::temeType($type);

        if ($teme === null) {
            return ["an item of {$type}", false];
        }

        $noun = mb_strtolower($this->types->get($teme)->singular);

        return [(preg_match('/^[aeiou]/', $noun) === 1 ? 'an ' : 'a ').$noun, true];
    }

    /**
     * One warning for each other host met, unless this run has said it.
     *
     * @param  array<string, string>  $others
     *
     * @return list<ImportMessage>
     */
    private function otherHosts(string $runId, array $others): array
    {
        $messages = [];

        foreach ($others as $host => $address) {
            $host = (string) $host;
            $said = ImportMessageRecord::query()->where('run_id', $runId)->where('code', 'check.other-host')->whereRaw("context->>'host' = ?", [$host])->exists();

            if (! $said) {
                $shown = Str::limit($address, 300);
                $messages[] = new ImportMessage('check.other-host', "Addresses on {$host}, such as {$shown}, belong to another site, so Teme didn’t check them.", ['host' => $host, 'address' => mb_substr($address, 0, 500)]);
            }
        }

        return $messages;
    }
}
