<?php

/**
 * The VerifiedDirectory door for a plugin granted directory.read: Teme's
 * plugin and theme directory as Teme verified and kept it. It never asks
 * the directory for a new index; a package's detail file is fetched once
 * and checked against the kept index's hash.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Extensions\Directory\DirectoryRefused;
use App\Domain\Extensions\Queries\DirectoryPackages;
use Teme\Contracts\Directory\DirectoryAnswer;
use Teme\Contracts\Directory\VerifiedDirectory;

final readonly class PluginDirectory implements VerifiedDirectory
{
    public function __construct(private DirectoryPackages $packages) {}

    public function index(): DirectoryAnswer
    {
        try {
            $this->packages->usable();
        } catch (DirectoryRefused $refused) {
            return new DirectoryAnswer(null, $refused->getMessage());
        }

        $document = $this->packages->keptDocument();

        return $document === null ? new DirectoryAnswer(null, DirectoryRefused::notYet()->getMessage()) : new DirectoryAnswer($document);
    }

    public function package(string $name): DirectoryAnswer
    {
        try {
            return new DirectoryAnswer($this->packages->detailOf($name));
        } catch (DirectoryRefused $refused) {
            return new DirectoryAnswer(null, $refused->getMessage());
        }
    }
}
