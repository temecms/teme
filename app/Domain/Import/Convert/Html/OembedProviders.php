<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

/**
 * The address patterns WordPress embeds without asking the address's site
 * (wp-includes/class-wp-oembed.php), kept as data, anchored: each starts at
 * the address's start, and a host part is one host, so an address that only
 * mentions a provider's host further on matches nothing. An address alone
 * on a line becomes an Embed card only when one matches (I-336).
 */
final class OembedProviders
{
    private const array PATTERNS = [
        '#^https?://((m|www)\.)?youtube\.com/watch.*#i',
        '#^https?://((m|www)\.)?youtube\.com/playlist.*#i',
        '#^https?://((m|www)\.)?youtube\.com/shorts/*#i',
        '#^https?://((m|www)\.)?youtube\.com/live/*#i',
        '#^https?://youtu\.be/.*#i',
        '#^https?://([^/]+\.)?vimeo\.com/.*#i',
        '#^https?://(www\.)?dailymotion\.com/.*#i',
        '#^https?://dai\.ly/.*#i',
        '#^https?://(www\.)?flickr\.com/.*#i',
        '#^https?://flic\.kr/.*#i',
        '#^https?://([^/]+\.)?smugmug\.com/.*#i',
        '#^https?://(www\.)?scribd\.com/(doc|document)/.*#i',
        '#^https?://wordpress\.tv/.*#i',
        '#^https?://([^/]+\.)?crowdsignal\.net/.*#i',
        '#^https?://([^/]+\.)?polldaddy\.com/.*#i',
        '#^https?://poll\.fm/.*#i',
        '#^https?://([^/]+\.)?survey\.fm/.*#i',
        '#^https?://(www\.)?twitter\.com/\w{1,15}/status(es)?/.*#i',
        '#^https?://(www\.)?twitter\.com/\w{1,15}$#i',
        '#^https?://(www\.)?twitter\.com/\w{1,15}/likes$#i',
        '#^https?://(www\.)?twitter\.com/\w{1,15}/lists/.*#i',
        '#^https?://(www\.)?twitter\.com/\w{1,15}/timelines/.*#i',
        '#^https?://(www\.)?twitter\.com/i/moments/.*#i',
        '#^https?://(www\.)?soundcloud\.com/.*#i',
        '#^https?://(open|play)\.spotify\.com/.*#i',
        '#^https?://([^/]+\.)?imgur\.com/.*#i',
        '#^https?://(www\.)?issuu\.com/.+/docs/.+#i',
        '#^https?://(www\.)?mixcloud\.com/.*#i',
        '#^https?://(www\.|embed\.)?ted\.com/talks/.*#i',
        '#^https?://(www\.)?(animoto|video214)\.com/play/.*#i',
        '#^https?://([^/]+)\.tumblr\.com/.*#i',
        '#^https?://(www\.)?kickstarter\.com/projects/.*#i',
        '#^https?://kck\.st/.*#i',
        '#^https?://cloudup\.com/.*#i',
        '#^https?://(www\.)?reverbnation\.com/.*#i',
        '#^https?://videopress\.com/v/.*#',
        '#^https?://(www\.)?reddit\.com/r/[^/]+/comments/.*#i',
        '#^https?://(www\.)?speakerdeck\.com/.*#i',
        '#^https?://([a-z0-9-]+\.)?amazon\.(com|com\.mx|com\.br|ca)/.*#i',
        '#^https?://([a-z0-9-]+\.)?amazon\.(co\.uk|de|fr|it|es|in|nl|ru)/.*#i',
        '#^https?://([a-z0-9-]+\.)?amazon\.(co\.jp|com\.au)/.*#i',
        '#^https?://([a-z0-9-]+\.)?amazon\.cn/.*#i',
        '#^https?://(www\.)?a\.co/.*#i',
        '#^https?://(www\.)?amzn\.to/.*#i',
        '#^https?://(www\.)?amzn\.eu/.*#i',
        '#^https?://(www\.)?amzn\.in/.*#i',
        '#^https?://(www\.)?amzn\.asia/.*#i',
        '#^https?://(www\.)?z\.cn/.*#i',
        '#^https?://www\.someecards\.com/.+-cards/.+#i',
        '#^https?://www\.someecards\.com/usercards/viewcard/.+#i',
        '#^https?://some\.ly\/.+#i',
        '#^https?://(www\.)?tiktok\.com/.*/video/.*#i',
        '#^https?://(www\.)?tiktok\.com/@.*#i',
        '#^https?://([a-z]{2}|www)\.pinterest\.com(\.(au|mx))?/.*#i',
        '#^https?://(www\.)?wolframcloud\.com/obj/.+#i',
        '#^https?://pca\.st/.+#i',
        '#^https?://((play|www)\.)?anghami\.com/.*#i',
        '#^https?://bsky.app/profile/.*/post/.*#i',
        '#^https?://(www\.)?canva\.com/design/.*/view.*#i',
    ];

    /**
     * Whether a provider WordPress knows embeds this address.
     */
    public static function matches(string $url): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
