<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Exceptions\StaleMediaException;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Operations\RecordMediaVariants;
use App\Domain\Media\Queries\ImagesNeedingVariants;
use App\Domain\Media\Queries\MediaForProcessing;
use App\Domain\Media\Queries\ShareImageUse;
use App\Domain\Media\VariantMaker;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Throwable;

/**
 * teme:media:regenerate — remakes the variants of every image made with an
 * older variant set, in this process, as the operator, a chunk at a time. Safe
 * to stop and run again: finished items are no longer older. It also makes the
 * share images of every image used as a featured or social image that lacks
 * them (I-855).
 */
final class RegenerateMedia extends Command
{
    /** @var string */
    protected $signature = 'teme:media:regenerate {--chunk=100 : Images per chunk}';

    /** @var string */
    protected $description = 'Remake image variants made with an older variant set';

    public function handle(VariantMaker $maker, RecordMediaVariants $record): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $chunk = max(1, (int) $this->option('chunk'));
        [$done, $failed] = [0, 0];

        foreach ([fn (?string $last): array => ImagesNeedingVariants::after($last, $chunk), fn (?string $last): array => ShareImageUse::lacking($last, $chunk)] as $next) {
            $last = null;

            while (($ids = $next($last)) !== []) {
                foreach ($ids as $id) {
                    $last = $id;
                    $version = (int) (MediaForProcessing::find($id)['fileVersion'] ?? 0);

                    try {
                        $variants = $maker->make($id, $version);

                        if ($variants !== null) {
                            $maker->discard($record->execute(new RecordMediaVariantsInput($id, $version, MediaVariants::SET, $variants, null, null))->obsoletePaths);
                            $done++;
                        }
                    } catch (StaleMediaException) {
                        continue;
                    } catch (Throwable $exception) {
                        $record->execute(new RecordMediaVariantsInput($id, $version, MediaVariants::SET, null, $exception->getMessage(), null));
                        $failed++;
                    }
                }
            }
        }

        $this->info("Regenerated {$done} images; {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
