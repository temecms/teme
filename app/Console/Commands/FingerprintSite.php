<?php

/**
 * teme:site:fingerprint — writes what this site holds and what its addresses
 * answer, so another server can be compared with it after a restore (I-385).
 * It changes nothing, and refuses while the site is in maintenance mode.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deployment\FingerprintComparison;
use App\Domain\Deployment\SiteFingerprint;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class FingerprintSite extends Command
{
    /** @var string */
    protected $signature = 'teme:site:fingerprint
        {--sample=200 : How many of the site’s own addresses to ask after the fixed four, from 0 to 10,000; with --compare, the other fingerprint’s addresses are asked instead}
        {--compare= : Another server’s fingerprint, as a file on this server, to compare this site with}';

    /** @var string */
    protected $description = 'Write what this site holds and what its addresses answer, so another server can be compared with it line by line';

    public function handle(SiteFingerprint $fingerprint): int
    {
        $sample = (string) $this->option('sample');

        if (preg_match('/^\d+$/', $sample) !== 1 || (int) $sample > 10000) {
            $this->error('--sample must be a whole number from 0 to 10,000.');

            return 1;
        }

        $file = (string) $this->option('compare');
        $there = null;

        if ($file !== '') {
            $text = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

            if ($text === false) {
                $this->error("Teme can’t read {$file}.");

                return 1;
            }

            if (! FingerprintComparison::isFingerprint($text)) {
                $this->error("{$file} isn’t a fingerprint: its first line should begin “# Teme site fingerprint”.");

                return 1;
            }

            $there = $text;
        }

        if ($this->laravel->isDownForMaintenance()) {
            $this->error('The site is in maintenance mode, so every address would answer 503. Bring it up first (php artisan up), then run this again.');

            return 1;
        }

        $printed = [];
        $say = function (string $line, bool $raw = false) use (&$printed): void {
            $printed[] = $line;

            if ($raw) {
                $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
            } else {
                $this->line($line);
            }
        };

        $say('# Teme site fingerprint: '.SiteFingerprint::authority((string) config('app.url')).', '.now()->utc()->toIso8601String());
        $say('# Lines that begin "migration", "table" or "address" are compared; "activity" lines are this server’s own work, and aren’t.');
        $say('migration '.($fingerprint->migration() ?? 'none'));

        foreach ($fingerprint->tables() as $table) {
            $say(($table['activity'] ? 'activity ' : 'table ').$table['name'].' '.$table['rows']);
        }

        $addresses = $there === null
            ? [...SiteFingerprint::FIXED, ...$fingerprint->sample((int) $sample)]
            : FingerprintComparison::addresses($there);

        foreach ($addresses as $address) {
            $say("address {$address} ".$fingerprint->answer($address), true);
        }

        if ($there === null) {
            return 0;
        }

        $result = FingerprintComparison::between(implode("\n", $printed), $there);

        $this->newLine();
        $this->line("# Compared with {$file}: ".FingerprintComparison::origin($there));

        foreach ($result['differs'] as $sentence) {
            $this->output->writeln($sentence, OutputInterface::OUTPUT_RAW);
        }

        foreach ($result['activity'] as $sentence) {
            $this->line($sentence);
        }

        $differences = count($result['differs']);

        if ($differences === 0) {
            $tables = $result['tables'];
            $addressCount = $result['addresses'];
            $this->line('The two sites match: '.number_format($tables).($tables === 1 ? ' table' : ' tables').' and '.number_format($addressCount).($addressCount === 1 ? ' address' : ' addresses').' compared.');

            return 0;
        }

        $this->line($differences === 1 ? '1 difference: it is listed above.' : "{$differences} differences: each is listed above.");

        return 1;
    }
}
