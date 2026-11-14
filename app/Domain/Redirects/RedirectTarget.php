<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Where a redirect sends readers, read from what someone chose or typed
 * (I-694, I-860): a path on this site, an address on another site, an item
 * or a media file by ID. Nothing keeps the site's own scheme and host
 * (rule 9), and nothing a browser would read as another site gets through.
 */
final readonly class RedirectTarget
{
    public const string ADDRESS = 'address';

    public const string OUTSIDE = 'outside';

    public const string CONTENT = 'content';

    public const string MEDIA = 'media';

    private function __construct(
        public string $kind,
        public string $target,
        public ?string $path,
    ) {}

    /**
     * The target asked for, an item or a file before an address, or a
     * refusal saying why it can't be one.
     *
     * @throws RedirectRefused
     */
    public static function read(string $to, ?string $contentId, ?string $mediaId, RedirectSource $source): self
    {
        if ($contentId !== null) {
            return DB::table('contents')->where('id', $contentId)->exists()
                ? new self(self::CONTENT, $contentId, null)
                : throw new RedirectRefused('item', __('Choose the post or page to send readers to.'));
        }

        if ($mediaId !== null) {
            // A trashed file may be chosen: the redirect waits for it (I-857).
            return DB::table('media')->where('id', $mediaId)->exists()
                ? new self(self::MEDIA, $mediaId, null)
                : throw new RedirectRefused('file', __('Choose the file to send readers to.'));
        }

        $to = trim($to);

        if ($to === '') {
            throw new RedirectRefused('to', __('Enter the address to send readers to.'));
        }

        if (! RedirectSource::readable($to) || ! RedirectSource::readable(rawurldecode($to))) {
            throw new RedirectRefused('to', __('That address holds characters no address can.'));
        }

        if (mb_strlen($to) > RedirectSource::LONGEST) {
            throw new RedirectRefused('to', __('Enter an address of 2,000 characters or fewer.'));
        }

        preg_match_all(RedirectSource::PLACEHOLDER, $to, $used);

        foreach ($used[2] as $name) {
            if (! in_array($name, $source->names, true)) {
                throw new RedirectRefused('to', __('To uses [:name], which From doesn’t have.', ['name' => $name]));
            }
        }

        if (preg_match('#^https?://#i', $to) === 1) {
            $own = RedirectSource::onThisSite($to);

            if ($own === null) {
                return self::outside($to);
            }

            $to = $own;
        }

        // A browser reads both as another site's address (I-694).
        if (preg_match('#^/[/\\\\]#', $to) === 1) {
            throw new RedirectRefused('to', __('An address can’t start with // or /\\, because browsers read those as another site. For another site, write the full address, starting https://.'));
        }

        if (! str_starts_with($to, '/')) {
            throw new RedirectRefused('to', __('Enter a path on this site, starting /, or a full address, starting https://.'));
        }

        return self::onSite($to);
    }

    /**
     * The target as the security log records it: an address, `item:{id}` or `file:{id}`.
     */
    public function described(): string
    {
        return match ($this->kind) {
            self::CONTENT => 'item:'.$this->target,
            self::MEDIA => 'file:'.$this->target,
            default => $this->target,
        };
    }

    /**
     * Another site's address, kept as typed: http or https, printable ASCII,
     * and a real address once each placeholder holds a sample.
     */
    private static function outside(string $to): self
    {
        $checked = RedirectSource::sample($to);

        if (preg_match('/^[\x21-\x7E]+$/', $checked) !== 1 || filter_var($checked, FILTER_VALIDATE_URL) === false) {
            throw new RedirectRefused('to', __('Enter a path on this site, starting /, or a full address, starting https://.'));
        }

        return new self(self::OUTSIDE, $to, null);
    }

    /**
     * A path on this site, kept as it goes in a Location header: each part
     * of the path percent-encoded around its placeholders, a query or
     * fragment kept as typed. An item's address with nothing after it is
     * kept as the item, so the redirect follows it (rule 9); an old address
     * of anything else is refused, naming where it went (I-859).
     */
    private static function onSite(string $to): self
    {
        $cut = strcspn($to, '?#');
        $rawPath = substr($to, 0, $cut);
        $rest = substr($to, $cut);

        if (preg_match('/^[\x21-\x7E]*$/', $rest) !== 1) {
            throw new RedirectRefused('to', __('That address holds characters no address can.'));
        }

        $key = PublicRequestPath::normalise($rawPath);

        if ($rest === '' && ! str_contains($key, '[')) {
            $row = Paths::find($key);

            if ($row !== null && $row->target_type === self::CONTENT) {
                return new self(self::CONTENT, (string) $row->target_id, null);
            }

            $current = $row === null || $row->is_current ? null : Paths::current((string) $row->target_type, (string) $row->target_id);

            if ($current !== null) {
                throw new RedirectRefused('to', __('The address :to has moved to :current. Send readers there directly, so they take one step, not two.', ['to' => Addresses::toUrl($key), 'current' => Addresses::toUrl($current)]));
            }
        }

        $segments = array_map(self::encoded(...), explode('/', rawurldecode($rawPath)));

        return new self(self::ADDRESS, implode('/', $segments).$rest, $key);
    }

    /**
     * One part of a path percent-encoded, NFC first, its placeholders left as typed.
     */
    private static function encoded(string $segment): string
    {
        $pieces = preg_split('/(\[(?:\.\.\.)?[a-z][a-z0-9_]*\])/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$segment];

        return implode('', array_map(
            fn (string $piece, int $index): string => $index % 2 === 1 ? $piece : rawurlencode((string) (Normalizer::normalize($piece, Normalizer::FORM_C) ?: $piece)),
            $pieces,
            array_keys($pieces),
        ));
    }
}
