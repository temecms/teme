<?php

/**
 * The picture pass's last step (#716): says once how many posts still show
 * pictures from the old site, because their files couldn't be copied, so a
 * person knows to bring them again before that site closes.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\RunsInMediaPass;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use Illuminate\Support\Facades\DB;

final readonly class PicturesLeftStep implements RunsInMediaPass, StepPhase
{
    public function key(): string
    {
        return 'pictures-left';
    }

    public function order(): int
    {
        // After the second pass (600), and the addresses and redirects after it.
        return 1270;
    }

    public function label(): string
    {
        return 'Pictures still on the old site';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return false;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $posts = DB::table('import_map')
            ->where('source_id', $context->sourceId)->where('object_type', 'post')->whereNotNull('teme_id')
            ->whereRaw("(facts -> 'waiting_media') <> '[]'::jsonb")
            ->count();

        if ($posts === 0) {
            return StepResult::done();
        }

        $host = OldSiteUrls::hostOf($context->sourceId);
        $words = ($posts === 1 ? '1 post still shows pictures' : number_format($posts).' posts still show pictures')
            ." from {$host}, because their files couldn’t be copied here. They stop showing when that site closes: bring the pictures again from the import screen while it’s up.";

        return StepResult::done([new ImportMessage('pictures.left', $words, ['count' => $posts, 'host' => $host])]);
    }
}
