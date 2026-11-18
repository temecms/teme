<?php

/**
 * teme:import:walk, the proof that an imported site keeps its addresses
 * (I-339): asks this site for every old address the WordPress site lists, as
 * a reader would, and prints those that answer neither a page nor a redirect
 * that reaches one. It exits 1 when any doesn’t.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Queries\AddressWalk;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Console\Command;

final class WalkImportAddresses extends Command
{
    /** @var string */
    protected $signature = 'teme:import:walk
        {address : The WordPress site’s address, as Tools › Import connected it}';

    /** @var string */
    protected $description = 'Ask this site for every old address of an imported WordPress site, as a reader would';

    public function handle(ImportSources $sources, AddressWalk $walk): int
    {
        $address = (string) $this->argument('address');
        $source = $sources->forAddress($address);

        if ($source === null) {
            $this->error("Teme doesn’t know {$address}. Connect it first, in Tools › Import or with php artisan teme:import {$address} --user=NAME.");

            return self::FAILURE;
        }

        if ($source->credential === null) {
            $this->error("Teme no longer keeps the application password for {$source->address}. Connect it again first.");

            return self::FAILURE;
        }

        $this->line('Asking this site for every old address of '.OldSiteUrls::hostOf((string) $source->id).', as a reader would…');

        try {
            $counts = $walk->walk($source, function (string $old, string $answer, ?int $status, ?string $location): void {
                $this->line($answer === 'query'
                    ? "{$old}: Teme doesn’t tell addresses apart by what follows the ?, so readers reach the home page."
                    : "{$status} {$old}".($location === null ? '' : " → {$location}"));
            });
        } catch (SourceRefused|AddressRefused $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $total = $counts['direct'] + $counts['moved'] + $counts['away'] + $counts['bad'];
        $this->line(sprintf(
            '%d old %s: %d %s with a page, %d with a redirect to a page here, %d with a redirect to a file or another site; %d %s.',
            $total,
            $total === 1 ? 'address' : 'addresses',
            $counts['direct'],
            $counts['direct'] === 1 ? 'answers' : 'answer',
            $counts['moved'],
            $counts['away'],
            $counts['bad'],
            $counts['bad'] === 1 ? 'doesn’t' : 'don’t',
        ));

        if ($counts['others'] > 0) {
            $this->line($counts['others'] === 1
                ? '1 address listed on another host wasn’t asked.'
                : "{$counts['others']} addresses listed on another host weren’t asked.");
        }

        if ($counts['bad'] === 0) {
            $this->info('Every old address answers.');

            return self::SUCCESS;
        }

        $this->error($counts['bad'] === 1
            ? '1 old address doesn’t answer: it is listed above.'
            : "{$counts['bad']} old addresses don’t answer: each is listed above.");

        return self::FAILURE;
    }
}
