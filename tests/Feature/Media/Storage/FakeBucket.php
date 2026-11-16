<?php

declare(strict_types=1);

namespace Tests\Feature\Media\Storage;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;

/**
 * An S3 bucket held in memory, standing in for the AWS SDK's HTTP handler
 * (the SDK's own MockHandler, with state): it keeps what PutObject sends,
 * answers HeadObject, GetObject and DeleteObject from it, and records every
 * command and request, so a test sees exactly what Teme sends with no
 * network. failNext() answers the next command of a name with a provider's
 * error code.
 */
final class FakeBucket
{
    /** @var array<string, string> the objects, by key */
    public array $objects = [];

    /** @var list<array{name: string, params: array<string, mixed>, uri: string, headers: array<string, list<string>>}> */
    public array $sent = [];

    /** @var array<string, array{code: string, status: int}> */
    private array $failures = [];

    /**
     * Puts a fresh fake bucket under the named disk, which keeps the rest of
     * its configuration.
     */
    public static function on(string $disk): self
    {
        $bucket = new self;
        config(["filesystems.disks.{$disk}.handler" => $bucket]);
        Storage::forgetDisk($disk);

        return $bucket;
    }

    public function failNext(string $command, string $code, int $status = 400): self
    {
        $this->failures[$command] = ['code' => $code, 'status' => $status];

        return $this;
    }

    /**
     * The commands sent, by name, in order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->sent, 'name');
    }

    /**
     * The last command of that name.
     *
     * @return array{name: string, params: array<string, mixed>, uri: string, headers: array<string, list<string>>}|null
     */
    public function last(string $name): ?array
    {
        $found = array_values(array_filter($this->sent, fn (array $sent): bool => $sent['name'] === $name));

        return $found === [] ? null : $found[count($found) - 1];
    }

    public function __invoke(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $name = $command->getName();
        /** @var array<string, mixed> $params */
        $params = $command->toArray();
        $this->sent[] = ['name' => $name, 'params' => $params, 'uri' => (string) $request->getUri(), 'headers' => $request->getHeaders()];
        $key = (string) ($params['Key'] ?? '');

        if (isset($this->failures[$name])) {
            $failure = $this->failures[$name];
            unset($this->failures[$name]);

            return Create::rejectionFor($this->error($command, $failure['code'], $failure['status']));
        }

        return match ($name) {
            'PutObject' => $this->put($key, (string) $request->getBody()),
            'HeadObject' => isset($this->objects[$key])
                ? Create::promiseFor(new Result(['ContentLength' => strlen($this->objects[$key]), '@metadata' => ['statusCode' => 200]]))
                : Create::rejectionFor($this->error($command, 'NotFound', 404)),
            'GetObject' => isset($this->objects[$key])
                ? Create::promiseFor(new Result(['Body' => Utils::streamFor($this->objects[$key]), 'ContentLength' => strlen($this->objects[$key]), '@metadata' => ['statusCode' => 200]]))
                : Create::rejectionFor($this->error($command, 'NoSuchKey', 404)),
            'DeleteObject' => $this->delete($key),
            default => Create::promiseFor(new Result(['@metadata' => ['statusCode' => 200]])),
        };
    }

    private function put(string $key, string $body): PromiseInterface
    {
        $this->objects[$key] = $body;

        return Create::promiseFor(new Result(['ETag' => '"'.md5($body).'"', '@metadata' => ['statusCode' => 200]]));
    }

    private function delete(string $key): PromiseInterface
    {
        unset($this->objects[$key]);

        return Create::promiseFor(new Result(['@metadata' => ['statusCode' => 204]]));
    }

    private function error(CommandInterface $command, string $code, int $status): AwsException
    {
        return new S3Exception("The provider answered {$code}.", $command, [
            'code' => $code,
            'response' => new Response($status),
        ]);
    }
}
