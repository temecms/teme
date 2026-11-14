<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\DB;

/**
 * Makes the default social image's 1200×630 card when someone chooses it, as
 * a featured image's is made at its first use (I-855): queued as the person
 * who chose it, after the change has committed. A change no person made
 * queues nothing; teme:media:regenerate makes every card a used image lacks.
 */
final class DefaultImageCard
{
    public function handle(SettingsChanged $change): void
    {
        if ($change->group !== SearchAndSocial::KEY || ! in_array(SocialCards::DEFAULT_IMAGE, $change->keys, true) || $change->actorId === null) {
            return;
        }

        $id = SocialCards::defaultImage();

        if ($id === null) {
            return;
        }

        $image = DB::table('media')->where('id', $id)->where('kind', 'image')->whereNull('deleted_at')->whereNull('variants->social')->first(['id', 'file_version']);

        if ($image !== null) {
            GenerateMediaVariants::dispatch((string) $image->id, (int) $image->file_version, JobActor::person($change->actorId));
        }
    }
}
