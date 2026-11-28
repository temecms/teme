<?php

/**
 * Outbound requests for a plugin that keeps large files: a HEAD for freshness
 * checks and a download into the plugin's own private folder.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

/**
 * The optional outbound door a plugin with http is given beside OutboundHttp.
 * Both calls follow redirects, at most five a request, and check every hop
 * against the hosts the plugin's consent covers and the addresses they
 * resolve to, exactly as a first request is checked. A download is streamed
 * to a file no answer size limit is exceeded by, the limit the plugin's
 * manifest declares (16 MB when it declares none, 128 MB at most).
 *
 * @since next
 */
interface OutboundDownloads
{
    /**
     * Asks for the headers of an address with no body, for example its size
     * or its last change, and returns the answer with an empty body.
     *
     * @param  array<string, string>  $headers
     *
     * @throws HostRefused|RequestFailed|AnswerTooLarge
     *
     * @since next
     */
    public function head(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer;

    /**
     * Streams the answer to a file in the plugin's own folder and returns it.
     * The file is removed by the plugin when it is done with it, and any left
     * over is removed a day after it was written.
     *
     * @param  array<string, string>  $headers
     *
     * @throws HostRefused|RequestFailed|AnswerTooLarge
     *
     * @since next
     */
    public function download(string $url, array $headers = [], int $timeoutSeconds = 60): HttpDownload;
}
