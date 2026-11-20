<?php

/**
 * A plugin's content.submissions door: keeps what visitors send from its
 * public forms as items waiting for review, through ReceiveSubmission,
 * within an hour's limit per visitor address and per plugin.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Operations\Input\ReceiveSubmissionInput;
use App\Domain\Contents\Operations\ReceiveSubmission;
use App\Domain\Hooks\Payloads;
use App\Domain\Taxonomy\Models\Term;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\Submission;
use Teme\Contracts\Content\Submissions;
use Teme\Contracts\Content\WriteRefused;

/**
 * A plugin's content.submissions door: keeps what visitors send from its
 * public forms as items waiting for review, written as the site itself.
 * Counts each try against an hour's limit per visitor address and per plugin.
 */
final readonly class PluginSubmissions implements Submissions
{
    /** Submissions one visitor address may send in an hour, through any plugin. */
    public const int MOST_PER_VISITOR = 10;

    /** Submissions one plugin may hand over in an hour, from every visitor. */
    public const int MOST_PER_PLUGIN = 200;

    private const int HOUR = 3600;

    public function __construct(private string $code) {}

    public function submit(Submission $submission): ItemRef
    {
        $this->limit();

        try {
            $content = app(ReceiveSubmission::class)->execute(new ReceiveSubmissionInput(
                plugin: $this->code,
                type: $submission->type,
                title: $submission->title,
                document: $submission->body,
                fields: PluginContentWriter::keysFor($submission->type, $submission->fields),
                terms: $this->terms($submission->terms),
                from: $submission->from,
            ));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The submission was refused.'), previous: $refused);
        }

        return Payloads::item($content);
    }

    /**
     * Counts this try, and refuses it when the visitor's address or the
     * plugin has had its hour's share. A refused try counts too, so a
     * form flooded with bad submissions is slowed as much as one with good.
     *
     * @throws WriteRefused
     */
    private function limit(): void
    {
        $visitor = 'plugin-submissions-from:'.sha1((string) request()->ip());
        $plugin = 'plugin-submissions:'.$this->code;

        if (RateLimiter::tooManyAttempts($plugin, self::MOST_PER_PLUGIN)) {
            throw new WriteRefused(__('This form has taken too many submissions this hour. Try again later.'));
        }

        if (RateLimiter::tooManyAttempts($visitor, self::MOST_PER_VISITOR)) {
            throw new WriteRefused(__('Too many submissions from this visitor. Try again in an hour.'));
        }

        RateLimiter::hit($plugin, self::HOUR);
        RateLimiter::hit($visitor, self::HOUR);
    }

    /**
     * The IDs of terms the site already has, each named by its slug or its
     * name; a submission never makes one.
     *
     * @param  array<string, list<string>>  $byTaxonomy
     *
     * @return list<string>
     *
     * @throws WriteRefused
     */
    private function terms(array $byTaxonomy): array
    {
        $ids = [];

        foreach ($byTaxonomy as $taxonomy => $labels) {
            foreach ($labels as $label) {
                $id = $label === '' ? null : (Term::query()->where('taxonomy', $taxonomy)->where('slug', $label)->value('id')
                    ?? Term::query()->where('taxonomy', $taxonomy)->where('name', $label)->value('id'));

                if ($id === null) {
                    throw new WriteRefused(__('There is no :term in :taxonomy.', ['term' => $label, 'taxonomy' => $taxonomy]));
                }

                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
