<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Lists;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A local https server with a self-signed certificate (openssl s_server -WWW),
 * so the outbound door's failures can come from the real curl handler: it
 * serves the files given, without a Content-Length, from 127.0.0.1 only.
 */
final class TlsServer
{
    private function __construct(private Process $process, public readonly int $port, private string $folder) {}

    /** @param  array<string, string>  $files  name => contents */
    public static function serving(array $files): self
    {
        if ((new Process(['openssl', 'version']))->run() !== 0) {
            throw new RuntimeException('These tests need the openssl command.');
        }

        $folder = sys_get_temp_dir().'/teme-tls-'.bin2hex(random_bytes(6));
        mkdir($folder);

        foreach ($files as $name => $contents) {
            file_put_contents("{$folder}/{$name}", $contents);
        }

        (new Process(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=api.example.com', '-keyout', 'key.pem', '-out', 'cert.pem'], $folder))->mustRun();

        $port = self::freePort();
        $process = new Process(['openssl', 's_server', '-accept', "127.0.0.1:{$port}", '-cert', 'cert.pem', '-key', 'key.pem', '-WWW', '-quiet'], $folder);
        $process->start();

        for ($tries = 0; $tries < 100; $tries++) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return new self($process, $port, $folder);
            }

            usleep(50_000);
        }

        $process->stop();

        throw new RuntimeException('The local https server didn’t start: '.$process->getErrorOutput());
    }

    public function stop(): void
    {
        $this->process->stop(1);

        foreach (glob("{$this->folder}/*") ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->folder);
    }

    /** Returns a port nothing listens on yet, on 127.0.0.1. */
    public static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
