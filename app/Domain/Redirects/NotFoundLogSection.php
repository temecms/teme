<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Search and social's section "Addresses with nothing at them", the 404 log (I-874): how many addresses the
 * log keeps, and which it ignores. The minute's run reads both and applies
 * them, never a reader's request, so a stored "not found" page is still
 * served without the database (I-298).
 */
final class NotFoundLogSection implements SearchAndSocialSection
{
    public const string SIZE = 'not_found_log_size';

    public const string IGNORE = 'not_found_log_ignore';

    public function order(): int
    {
        return 800;
    }

    public function key(): string
    {
        return 'not-found-log';
    }

    public function label(): string
    {
        return 'Addresses with nothing at them';
    }

    public function settings(): array
    {
        return [
            Setting::number(self::SIZE, 'Addresses the 404 log keeps', 'When the log is full, the address seen least recently makes room. From 1,000 to 100,000.', '0.1.0')
                ->default(NotFoundLog::KEEP)->min(1000)->max(100000)->unit('addresses'),
            Setting::textarea(self::IGNORE, 'Addresses the 404 log ignores', 'One per line, as the address after the site’s, and * stands for anything, slashes included. Bots probe every site for PHP files and names starting with a dot, so those are ignored to begin with.', '0.1.0')
                ->default(implode("\n", NotFoundLog::IGNORED))->maxLength(5000),
        ];
    }

    /**
     * How many addresses the log keeps.
     */
    public static function size(): int
    {
        return app(SettingsReader::class)->int(SearchAndSocial::KEY, self::SIZE);
    }

    /**
     * The patterns the log ignores, each in the stored form of the paths it
     * is matched against, blank lines and repeats left out.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', app(SettingsReader::class)->string(SearchAndSocial::KEY, self::IGNORE)) ?: [];
        $patterns = array_filter(array_map(fn (string $line): string => PublicRequestPath::normalise(trim($line)), $lines), fn (string $pattern): bool => $pattern !== '');

        return array_values(array_unique($patterns));
    }
}
