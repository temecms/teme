<?php

/**
 * Makes companion clients: one to connect a site, finding where its export
 * API answers, and one to read a site Teme keeps, its address checked again
 * each time, since a name can move.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Network\PublicAddresses;
use SensitiveParameter;

final readonly class CompanionClients
{
    public function __construct(private PublicAddresses $addresses) {}

    /**
     * Finds the export API under the address the person typed (/wp-json/,
     * then ?rest_route=/ for a site on plain permalinks) and probes it with
     * the user name and application password.
     *
     * @throws AddressRefused|SourceRefused
     */
    public function probe(string $address, string $userName, #[SensitiveParameter] string $password): SourceProbe
    {
        $site = $this->addresses->check($address);
        $base = $site->origin().rtrim((string) parse_url($address, PHP_URL_PATH), '/').'/';
        $missing = SourceRefused::notCompanion();

        foreach ([$base.'wp-json/', $base.'?rest_route=/'] as $root) {
            try {
                return (new CompanionClient($site, $root, $userName, $password))->probe();
            } catch (SourceRefused $refusal) {
                if ($refusal->reason !== SourceRefused::NOT_COMPANION) {
                    throw $refusal;
                }

                $missing = $refusal;
            }
        }

        throw $missing;
    }

    /**
     * A reader for a source Teme keeps.
     *
     * @throws AddressRefused when its address now resolves somewhere Teme won't go
     */
    public function for(ImportSource $source): SourceReader
    {
        // Later (I-1718): a database reader is chosen here, by the source's kind.
        return new CompanionClient($this->addresses->check($source->api_root), $source->api_root, (string) $source->user_name, (string) $source->credential);
    }
}
