<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Operations\Input\RecountContentMonthsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Counts the month rows of content_counts again from the items, in the
 * site's zone (I-3131): after the time zone changes, by whoever changed it
 * or the operator. It holds content_counts while it writes, so no
 * Operation's count lands between its read and its write.
 */
final class RecountContentMonths extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RecountContentMonthsInput $input */
        if ($input->actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to count the months again.');
        }

        $actor = User::query()->find($input->actorId);

        if ($actor === null || ! $actor->can('settings.general.manage')) {
            throw new AuthorizationException('Not permitted to count the months again.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * @return array{months: int}
     */
    protected function perform(OperationInput $input): array
    {
        DB::statement('LOCK TABLE content_counts IN SHARE ROW EXCLUSIVE MODE');
        DB::table('content_counts')->where('bucket', 'like', '%:%')->delete();

        $truth = ContentCounts::monthTruth(app(SettingsReader::class)->string('general', 'timezone'));
        DB::statement("INSERT INTO content_counts (type, bucket, author_id, count) SELECT type, bucket, author_id, count FROM ({$truth}) months");

        return ['months' => DB::table('content_counts')->where('bucket', 'like', '%:%')->count()];
    }
}
