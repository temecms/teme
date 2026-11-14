<?php

declare(strict_types=1);

namespace App\Domain\Users\Privacy;

use App\Domain\Users\Queries\PersonPictures;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * An account's picture (I-3410): exported as its address and file name, and
 * cleared on erasure, while the image itself stays in Media for the site's
 * administrators to delete.
 */
final class PictureHolder implements PersonalDataHolder
{
    public function __construct(private readonly PersonPictures $pictures) {}

    public function key(): string
    {
        return 'picture';
    }

    public function label(): string
    {
        return __('Picture');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        if ($subject->accountId === null) {
            return new ExportPage([], true);
        }

        $address = $this->pictures->of((int) $subject->accountId);
        $fileName = DB::table('users')
            ->join('media', 'media.id', '=', 'users.picture_media_id')
            ->where('users.id', (int) $subject->accountId)
            ->value('media.original_filename');

        if ($address === null || $fileName === null) {
            return new ExportPage([], true);
        }

        return new ExportPage([new ExportGroup(__('Your picture'), [
            __('Picture') => $address,
            __('File name') => (string) $fileName,
        ])], true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        if ($subject->accountId === null) {
            return new ErasePage(0, 0, [], true);
        }

        // The account is kept (AccountHolder); its picture is not needed to keep it.
        $cleared = DB::select('UPDATE users SET picture_media_id = NULL WHERE id = ?::bigint AND picture_media_id IS NOT NULL RETURNING id', [$subject->accountId]);

        return $cleared === []
            ? new ErasePage(0, 0, [], true)
            : new ErasePage(1, 0, [__('The picture is no longer shown. The image itself stays in Media, where the site’s administrators can delete it.')], true);
    }
}
