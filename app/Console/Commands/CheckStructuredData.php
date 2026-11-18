<?php

/**
 * Checks the structured-data terms Teme may write against a schema.org
 * release (I-706), run by hand when schema.org publishes one or the list
 * grows: each term the release doesn't define, or supersedes, is named.
 * Nothing is fetched; the release file is read where it was saved.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\StructuredData\Vocabulary;
use Illuminate\Console\Command;

final class CheckStructuredData extends Command
{
    /** @var string */
    protected $signature = 'teme:structured-data:check {release : schema.org\'s release file, schemaorg-current-https.jsonld, saved locally}';

    /** @var string */
    protected $description = 'Check the structured-data terms Teme may write against a schema.org release';

    public function handle(Vocabulary $vocabulary): int
    {
        $path = (string) $this->argument('release');

        if (! is_file($path)) {
            $this->error("There is no file at {$path}.");

            return self::FAILURE;
        }

        $release = json_decode((string) file_get_contents($path), true);
        $graph = is_array($release) ? ($release['@graph'] ?? null) : null;

        if (! is_array($graph)) {
            $this->error("That isn't a schema.org release file: it has no @graph.");

            return self::FAILURE;
        }

        $defined = [];
        $superseded = [];

        foreach ($graph as $entry) {
            $term = is_array($entry) ? self::termName($entry['@id'] ?? null) : null;

            if ($term === null) {
                continue;
            }

            $defined[$term] = true;
            $by = self::termName(is_array($entry['schema:supersededBy'] ?? null) ? ($entry['schema:supersededBy']['@id'] ?? null) : null);

            if ($by !== null) {
                $superseded[$term] = $by;
            }
        }

        $problems = 0;

        foreach ([...$vocabulary->types(), ...$vocabulary->properties()] as $term) {
            if (! isset($defined[$term])) {
                $this->line("Not in this release: {$term}");
                $problems++;
            } elseif (isset($superseded[$term])) {
                $this->line("Superseded in this release: {$term}, by {$superseded[$term]}");
                $problems++;
            }
        }

        if ($problems > 0) {
            return self::FAILURE;
        }

        $this->info('Every term Teme writes is in this release.');

        return self::SUCCESS;
    }

    /**
     * A term's name from its @id, written `schema:Article` or as its whole
     * address; null for anything else.
     */
    private static function termName(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        foreach (['schema:', 'https://schema.org/', 'http://schema.org/'] as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return substr($id, strlen($prefix));
            }
        }

        return null;
    }
}
