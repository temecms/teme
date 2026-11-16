<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Extensions\Operations\Input\EndExtensionRequestInput;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Themes\ActiveTheme;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Ends an open extension request: done, failed or cancelled, with the reason
 * and the installer's report, and deletes its upload. When the change
 * touched an enabled plugin or the active theme, the whole page cache is
 * purged after commit. The operator only: the installer.
 */
final class EndExtensionRequest extends BaseOperation
{
    /** The statuses an open request may end in. */
    private const array ENDINGS = [RequestStatus::Done, RequestStatus::Failed, RequestStatus::Cancelled];

    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the installer, as the operator, records an extension request’s steps.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EndExtensionRequestInput $input */
        if (! in_array($input->status, self::ENDINGS, true)) {
            throw ValidationException::withMessages(['status' => ["{$input->status->value} isn’t how a request ends."]]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var EndExtensionRequestInput $input */
        $row = DB::table('extension_requests')->where('id', $input->requestId)->lockForUpdate()->first();

        if ($row === null || ! RequestStatus::from((string) $row->status)->isOpen()) {
            throw ValidationException::withMessages(['request' => ['There’s no such open request.']]);
        }

        DB::table('extension_requests')->where('id', $input->requestId)->update([
            'status' => $input->status->value,
            'error' => $input->error,
            'report' => json_encode($input->report === [] ? new stdClass : $input->report, JSON_THROW_ON_ERROR),
            'finished_at' => CarbonImmutable::now(),
        ]);

        if ($row->upload_id !== null) {
            DB::table('extension_uploads')->where('id', $row->upload_id)->delete();
        }

        if ($input->status === RequestStatus::Done && $this->touchesWhatRuns($input)) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
        }

        return null;
    }

    /** Whether the change touched an enabled plugin or the active theme, read under the lock. */
    private function touchesWhatRuns(EndExtensionRequestInput $input): bool
    {
        if ($input->plugins !== [] && DB::table('plugins')->whereIn('code', $input->plugins)->where('state', 'enabled')->exists()) {
            return true;
        }

        return $input->themes !== [] && in_array(app(ActiveTheme::class)->chosen(), $input->themes, true);
    }
}
