<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Types\TypesFile;
use Illuminate\Console\Command;

/**
 * teme:types:export — prints the site's own content types as a teme-types.json
 * file, or writes it where told (I-411).
 */
final class ExportTypesFile extends Command
{
    /** @var string */
    protected $signature = 'teme:types:export
        {--output= : Write the file here instead of printing it}';

    /** @var string */
    protected $description = 'Print the site’s own content types as a teme-types.json file, or write it';

    public function handle(): int
    {
        $json = TypesFile::json();

        $output = $this->option('output');

        if ($output === null || $output === '') {
            $this->line(rtrim($json));

            return self::SUCCESS;
        }

        $path = (string) $output;
        $folder = dirname($path);

        if (! is_dir($folder)) {
            $this->error(__(':folder doesn’t exist.', ['folder' => $folder]));

            return self::FAILURE;
        }

        file_put_contents($path, $json);

        $count = count(TypesFile::export()['types']);
        $this->info($count === 1
            ? __('Wrote 1 type to :path.', ['path' => $path])
            : __('Wrote :n types to :path.', ['n' => (string) $count, 'path' => $path]));

        return self::SUCCESS;
    }
}
