<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Where one restic repository is and the key that opens it (I-359), for one
 * call: the location restic reads, the key, and the backend's own variables
 * and options. Never serialized and never dumped: a queued job carries a
 * place's ID and builds this inside the job.
 */
final readonly class ResticRepository
{
    /**
     * @param  string  $location  restic's own address: a folder, s3:https://host/bucket/path, or sftp:user@host:/path
     * @param  array<string, string>  $environment  the backend's own variables, such as AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY
     * @param  list<string>  $options  restic's -o options for the backend, such as s3.region=eu-west-1; never a secret
     */
    public function __construct(
        public string $location,
        #[SensitiveParameter] private string $key,
        #[SensitiveParameter] private array $environment = [],
        private array $options = [],
    ) {
        if (trim($location) === '' || preg_match('/[\r\n\0]/', $location) === 1) {
            throw new InvalidArgumentException('A restic repository needs a location on one line.');
        }

        if ($key === '') {
            throw new InvalidArgumentException('A restic repository needs a key.');
        }

        foreach (array_keys($environment) as $name) {
            if (preg_match('/^AWS_[A-Z0-9_]+$/', (string) $name) !== 1) {
                throw new InvalidArgumentException("A repository’s own variables are its backend’s AWS_ ones: [{$name}] isn’t one.");
            }
        }

        foreach ($options as $option) {
            if (preg_match('/^[a-z0-9]+\.[a-z0-9.-]+=./', $option) !== 1) {
                throw new InvalidArgumentException("A restic option is key=value: [{$option}].");
            }
        }
    }

    /**
     * The variables that name this repository and its key: RESTIC_REPOSITORY
     * and RESTIC_PASSWORD with the prefix RESTIC_, or the repository a copy
     * reads with RESTIC_FROM_.
     *
     * @return array<string, string>
     */
    public function variables(string $prefix): array
    {
        return [$prefix.'REPOSITORY' => $this->location, $prefix.'PASSWORD' => $this->key];
    }

    /**
     * The backend's own variables.
     *
     * @return array<string, string>
     */
    public function backendVariables(): array
    {
        return $this->environment;
    }

    /**
     * restic's -o options for the backend.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Every value no message may show: the key, and the backend variables
     * whose names say they are secret (AWS_SECRET_ACCESS_KEY, never
     * AWS_DEFAULT_REGION).
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return [$this->key, ...ChildEnvironment::secretValues($this->environment)];
    }

    /**
     * What var_dump() and print_r() show: the location, never the key.
     *
     * @return array{location: string}
     */
    public function __debugInfo(): array
    {
        return ['location' => $this->location];
    }

    /**
     * Refused, so a key never reaches a queue payload or a cache (I-534).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A repository’s key is never queued or stored: carry the place’s ID and build the repository inside the job.');
    }
}
