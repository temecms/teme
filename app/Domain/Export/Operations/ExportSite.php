<?php

/**
 * Writes the site's content, people and media into a new folder as NDJSON
 * files, the originals, the site's own types as teme-types.json and a
 * manifest (I-365), a page at a time: from one
 * moment of the database when it runs as the outermost transaction, as
 * teme:export runs it, and never otherwise. The operator only (I-2407). A
 * failure leaves nothing behind, and no security event says it was made.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Operations;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportedSite;
use App\Domain\Export\ExportFailed;
use App\Domain\Export\ExportFolder;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKinds;
use App\Domain\Export\ExportManifest;
use App\Domain\Export\ExportSnapshot;
use App\Domain\Export\MediaTally;
use App\Domain\Export\Operations\Input\ExportSiteInput;
use App\Domain\Plugins\Exports\PluginKind;
use App\Domain\Plugins\Exports\PluginKindFailed;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\TypesFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class ExportSite extends BaseOperation
{
    public function __construct(
        private readonly ExportKinds $kinds,
        private readonly SecurityLog $log,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ExportSiteInput $input */
        // Every draft and every email address, at the server only (I-2407).
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator exports the site, at the server.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ExportSiteInput $input */
        $folder = self::folder($input->folder);

        if ($folder === '') {
            throw ValidationException::withMessages(['folder' => 'Name a folder for the export.']);
        }

        $parent = dirname($folder);

        if (file_exists($folder) || is_link($folder)) {
            throw ValidationException::withMessages(['folder' => "{$folder} already exists. Name a folder that doesn’t exist yet: an export never writes into one."]);
        }

        if (! is_dir($parent)) {
            throw ValidationException::withMessages(['folder' => "{$parent} doesn’t exist. Make it first, or name a folder inside one that does."]);
        }

        if (! is_writable($parent)) {
            throw ValidationException::withMessages(['folder' => "Teme can’t write in {$parent}."]);
        }

        if ($input->pageSize !== null && ($input->pageSize < 1 || $input->pageSize > 5000)) {
            throw ValidationException::withMessages(['page_size' => 'A page is between 1 and 5,000 rows.']);
        }
    }

    protected function perform(OperationInput $input): ExportedSite
    {
        /** @var ExportSiteInput $input */
        ExportSnapshot::hold(DB::connection());

        $folder = ExportFolder::open(self::folder($input->folder));
        $media = new MediaTally;
        $context = new ExportContext($folder, $media, $input->pageSize);
        $files = [];
        $pluginKinds = [];
        $leftOut = [];
        $names = [];

        try {
            foreach ($this->kinds->all() as $kind) {
                // A plugin's file never stops the export: one that fails is left out, and said so.
                if ($kind instanceof PluginKind) {
                    $file = null;

                    try {
                        if (isset($names[$kind->name()])) {
                            throw $kind->nameTaken();
                        }

                        $file = $folder->ndjson($kind);

                        // Its own savepoint: a failed statement in the plugin's code can't abort the export's transaction.
                        // A failure from the write itself, or at the savepoint's commit, ends the export and names the file.
                        try {
                            DB::transaction(function () use ($kind, $context, $file): void {
                                foreach ($kind->lines($context) as $line) {
                                    $file->write($line);
                                }

                                // Touches the connection after the plugin's lines so a
                                // swallowed statement failure still aborts this savepoint
                                // and is reported as the export's fault with the file named.
                                DB::select('select 1');
                            });
                        } catch (PluginKindFailed $failure) {
                            throw $failure;
                        } catch (Throwable $failed) {
                            throw new RuntimeException('Teme couldn’t write '.$kind->name().'.ndjson.', 0, $failed);
                        }

                        $files[] = $file->close();
                        $pluginKinds[] = $kind->toManifest();
                        $names[$kind->name()] = true;
                    } catch (PluginKindFailed $failure) {
                        if ($file !== null) {
                            $folder->remove($file);
                        }

                        $leftOut[] = $kind->failed($failure);
                    }

                    continue;
                }

                $file = $folder->ndjson($kind);
                $names[$kind->name()] = true;

                foreach ($kind->lines($context) as $line) {
                    $file->write($line);
                }

                $files[] = $file->close();
            }

            $typesFile = $folder->writeTypesFile(TypesFile::json());

            $folder->writeManifest(ExportManifest::make($files, $typesFile, $media, ExportJson::now(), $pluginKinds, $leftOut));

            $counts = [];

            foreach ($files as $written) {
                $counts[$written->kind] = $written->lines;
            }

            // Before the move: a failure from here on rolls the entry back with everything else.
            $this->log->record(SecurityEventType::ExportMade, context: [...$counts, 'media_files' => $media->files]);

            $folder->finish();
        } catch (Throwable $failed) {
            $clean = $folder->discard();

            throw new ExportFailed($failed->getMessage(), $clean ? null : $folder->staging, $failed);
        }

        return new ExportedSite($folder->target, $files, $media);
    }

    /** The folder as given, trimmed, with forward slashes and no trailing one. */
    private static function folder(string $folder): string
    {
        return rtrim(str_replace('\\', '/', trim($folder)), '/');
    }
}
