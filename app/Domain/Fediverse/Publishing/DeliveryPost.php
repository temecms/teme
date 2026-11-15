<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Signatures\Signer;
use App\Domain\Support\Network\AddressRefused;
use App\Domain\Support\Network\PublicAddresses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Posts one activity to one inbox, as the fetcher reaches a server (D3 of
 * docs/plans/fediverse-actors.md): https only, the address checked public
 * and pinned, no redirect followed, no proxy, five seconds at most; signed
 * by the sending actor, whose key never leaves the Signer.
 */
final readonly class DeliveryPost
{
    public function __construct(
        private PublicAddresses $addresses,
        private Signer $signer,
    ) {}

    /**
     * Sends the activity, and says what came of it.
     *
     * @param  array<string, mixed>  $activity
     */
    public function send(string $inbox, array $activity, FediverseActor $actor): DeliveryOutcome
    {
        if (strtolower((string) parse_url($inbox, PHP_URL_SCHEME)) !== 'https') {
            return new DeliveryOutcome(DeliveryOutcome::REFUSED, null, 'Only https inboxes are delivered to.');
        }

        try {
            $checked = $this->addresses->check($inbox);
        } catch (AddressRefused) {
            return new DeliveryOutcome(DeliveryOutcome::REFUSED, null, 'The inbox is not at a public address.');
        }

        $body = json_encode($activity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => ActivityPub::CONTENT_TYPE, 'Accept' => ActivityPub::CONTENT_TYPE];
        $options = ['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']];
        $pin = $checked->curlResolve();

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        try {
            $response = Http::withOptions($options)
                ->connectTimeout(RemoteFetcher::SECONDS)
                ->timeout(RemoteFetcher::SECONDS)
                ->withUserAgent('Teme')
                ->withHeaders([...$headers, ...$this->signer->sign('POST', $inbox, [], $body, $actor)])
                ->withBody($body, ActivityPub::CONTENT_TYPE)
                ->post($inbox);
        } catch (ConnectionException|RequestException) {
            return new DeliveryOutcome(DeliveryOutcome::RETRY, null, 'The server could not be reached in time.');
        }

        return DeliveryOutcome::fromStatus($response->status());
    }
}
