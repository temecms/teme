<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\RecoveryKey;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A second server for a restore to move a site onto: a scratch database of
 * its own that knows nothing of backups, empty media folders, its own
 * APP_KEY and address; and the recovery key and the place an operator
 * would name on its command line.
 */
final class NewServer
{
    public static function open(string $appKey, string $url = 'http://localhost'): ScratchSite
    {
        $site = ScratchSite::open();
        self::useAppKey($appKey);
        // The media's address follows APP_URL on a real server, read when it boots.
        config(['app.url' => $url, 'filesystems.disks.media.url' => $url.'/storage/media']);
        URL::forceRootUrl($url);
        Storage::fake('media');
        Storage::fake('media-originals');
        app()->forgetInstance(MaintenanceModeManager::class);

        return $site;
    }

    public static function useAppKey(string $key): void
    {
        config(['app.key' => $key, 'app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    /**
     * A recovery key that really opens place $index's repository, as setting up does.
     */
    public static function recoveryKey(RunnableBackups $backups, int $index = 0): RecoveryKey
    {
        $key = RecoveryKey::generate();
        app(BackupKeys::class)->add($backups->repositories[$index]->repository, $key->value(), 'Place '.($index + 1));

        return $key;
    }

    /**
     * Place $index's folder, as an operator names it with --kind=folder.
     */
    public static function folder(RunnableBackups $backups, int $index = 0): NewPlace
    {
        return NewPlace::folder('the place you named', $backups->repositories[$index]->folder);
    }
}
