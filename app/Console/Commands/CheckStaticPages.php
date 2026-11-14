<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cache\PageCache;
use App\Domain\Cache\SignedInMarker;
use App\Domain\Cache\StaticPages;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * `teme:cache:check-static`: asks the web server whether it serves the
 * static-file driver's pages as its settings intend (I-086, D11). It writes
 * a probe page where the driver writes pages, and another at an address the
 * router keeps for itself, asks for them as a reader would and as each kind
 * of request that must reach PHP, then deletes them and asks once more. It
 * says which rules hold, and lists what only a person can check.
 */
final class CheckStaticPages extends Command
{
    /** @var string */
    protected $signature = 'teme:cache:check-static
        {--url= : Where the web server answers, such as http://127.0.0.1:8080 inside the web container; APP_URL by default}';

    /** @var string */
    protected $description = 'Ask the web server whether it serves the page cache\'s plain files as its settings intend';

    /**
     * Headers the web server may add to PHP's pages, as the image's
     * security.conf does, that an answer given from a file must carry too:
     * an add_header in nginx's location drops them unless it says
     * add_header_inherit merge. The page's own content security policy is
     * PHP's, per page, and a file carries it in a <meta>, so it is not
     * compared here (I-3375).
     */
    private const array SHARED_HEADERS = ['strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy'];

    public function handle(StaticPages $files): int
    {
        if (config('page_cache.driver') !== 'static') {
            $this->components->error('The static-file driver is off: set PAGE_CACHE_DRIVER=static, then run this again.');

            return self::FAILURE;
        }

        $url = $this->option('url');
        $base = rtrim(is_string($url) && $url !== '' ? $url : (string) config('app.url'), '/');
        $token = Str::lower(Str::random(24));
        $page = '/teme-static-check-'.$token.'/';
        $reserved = '/_teme/static-check-'.$token.'/';
        $html = '<!doctype html><title>Teme</title><p>'.$token.'</p>';

        try {
            $files->place($page, $html);
            $files->place($reserved, $html);
            $rules = $this->rules($base, $page, $reserved, $html);
        } catch (ConnectionException $unreachable) {
            $this->components->error("Nothing answers at {$base}: {$unreachable->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $failure) {
            // Most often the folder: the page cache can't write the web server's files.
            $this->components->error('The check stopped: '.$failure->getMessage());

            return self::FAILURE;
        } finally {
            $files->discardProbe($page);
            $files->discardProbe($reserved);
        }

        try {
            // Asked again once deleted: a web server that keeps what it opened would still serve it.
            $rules['forgets a file once it is deleted'] = ! self::isProbe(self::askWebServer('GET', $base.$page), $html);
        } catch (ConnectionException $unreachable) {
            $this->components->error("Nothing answers at {$base}: {$unreachable->getMessage()}");

            return self::FAILURE;
        }

        $broken = 0;

        foreach ($rules as $rule => $kept) {
            $this->components->twoColumnDetail($rule, $kept ? 'yes' : 'no');
            $broken += $kept ? 0 : 1;
        }

        if (PageCache::lifetime() <= StaticPages::EARLY) {
            $this->components->warn(sprintf('Pages live %d seconds (PAGE_CACHE_LIFETIME): a page gets a file only with more than %d seconds left, so none will.', PageCache::lifetime(), StaticPages::EARLY));
        }

        $this->components->info('Only you can check the rest:');
        $this->components->bulletList([
            'the queue worker and the scheduler reach the same folder: the Status banner says when they don\'t;',
            'the scheduler runs teme:cache:prune every minute: while it doesn\'t, a file outlives its page;',
            'an article opened twice as a reader answers MISS, then STATIC; published again, MISS, then STATIC, with the change;',
            'with a CDN in front, a signed-in browser passes it by, and a purge by address reaches it;',
            'after any change to the image or its SSL_MODE, this check still passes.',
        ]);

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Each rule of the settings in words, and whether the web server keeps it.
     *
     * @return array<string, bool>
     */
    private function rules(string $base, string $page, string $reserved, string $html): array
    {
        $served = self::askWebServer('GET', $base.$page);
        $head = self::askWebServer('HEAD', $base.$page);
        $drawn = self::askWebServer('GET', $base.$page.'?teme-check=1');

        return [
            'serves the file to a GET from a reader' => self::isProbe($served, $html) && $served->header('X-Teme-Cache') === 'STATIC',
            'and to a HEAD' => $head->status() === 200 && $head->header('X-Teme-Cache') === 'STATIC',
            'as HTML in UTF-8' => str_starts_with(strtolower(str_replace(' ', '', $served->header('Content-Type'))), 'text/html;charset=utf-8'),
            'with the headers it adds to pages from PHP' => self::sameHeaders($drawn, $served),
            'never to a request with a query' => ! self::isProbe($drawn, $html),
            'never to a signed-in browser' => ! self::isProbe(self::askWebServer('GET', $base.$page, ['Cookie' => SignedInMarker::COOKIE.'=1']), $html),
            'never to a POST' => ! self::isProbe(self::askWebServer('POST', $base.$page), $html),
            'never at the address without its trailing slash' => ! self::isProbe(self::askWebServer('GET', $base.rtrim($page, '/')), $html),
            'never at the address in capitals' => ! self::isProbe(self::askWebServer('GET', $base.strtoupper($page)), $html),
            'never at an address the router keeps for itself' => ! self::isProbe(self::askWebServer('GET', $base.$reserved), $html),
        ];
    }

    /**
     * The web server's answer to one request, which follows no redirect and
     * waits two seconds to connect and five for an answer at most.
     *
     * @param  array<string, string>  $headers
     */
    private static function askWebServer(string $method, string $url, array $headers = []): Response
    {
        return Http::withoutRedirecting()->connectTimeout(2)->timeout(5)->withHeaders($headers)->send($method, $url);
    }

    /**
     * Whether this answer is the probe's file, byte for byte: PHP's own page
     * for the address may show its path, and so the token.
     */
    private static function isProbe(Response $answer, string $html): bool
    {
        return $answer->status() === 200 && $answer->body() === $html;
    }

    /**
     * Whether the answer given from a file carries every header of
     * SHARED_HEADERS that PHP's answer carries.
     */
    private static function sameHeaders(Response $drawn, Response $served): bool
    {
        foreach (self::SHARED_HEADERS as $name) {
            if ($drawn->header($name) !== '' && $served->header($name) === '') {
                return false;
            }
        }

        return true;
    }
}
