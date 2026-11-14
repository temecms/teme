<?php

declare(strict_types=1);

use App\Domain\Deployment\SecretFileRefused;
use App\Domain\Deployment\SecretFiles;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Deployment\Environment;
use Tests\Fixtures\Deployment\SecretFile;

beforeEach(function (): void {
    $this->files = [];
});

afterEach(function (): void {
    SecretFile::remove(...$this->files);
});

it('reads a secret from the file its _FILE variable names (I-375)', function (): void {
    $this->files[] = $path = SecretFile::holding("token-from-the-file\n");

    Environment::with(['CLOUDFLARE_API_TOKEN' => null, 'CLOUDFLARE_API_TOKEN_FILE' => $path], function (): void {
        SecretFiles::resolve();

        expect(Env::get('CLOUDFLARE_API_TOKEN'))->toBe('token-from-the-file');
    });
});

it('strips the one line break at the end, and keeps the rest of the secret as written', function (string $contents, string $secret): void {
    $this->files[] = $path = SecretFile::holding($contents);

    Environment::with(['MAIL_PASSWORD' => null, 'MAIL_PASSWORD_FILE' => $path], function () use ($secret): void {
        SecretFiles::resolve();

        expect(Env::getRepository()->get('MAIL_PASSWORD'))->toBe($secret);
    });
})->with([
    'a Windows line break' => ["horse-battery\r\n", 'horse-battery'],
    'no line break' => ['horse-battery', 'horse-battery'],
    'two line breaks' => ["horse-battery\n\n", "horse-battery\n"],
    'spaces of its own' => ['  horse-battery  ', '  horse-battery  '],
]);

it('keeps a secret out of the process environment, so no program Teme starts inherits it', function (): void {
    $this->files[] = $path = SecretFile::holding('the-child-must-not-see-this');

    Environment::with(['REDIS_PASSWORD' => null, 'REDIS_PASSWORD_FILE' => $path], function (): void {
        SecretFiles::resolve();

        $child = new Process([PHP_BINARY, '-r', 'echo getenv("REDIS_PASSWORD") === false ? "absent" : "present";']);
        $child->mustRun();

        expect(Env::get('REDIS_PASSWORD'))->toBe('the-child-must-not-see-this')
            ->and(getenv('REDIS_PASSWORD'))->toBeFalse()
            ->and($_ENV)->not->toHaveKey('REDIS_PASSWORD')
            ->and($child->getOutput())->toBe('absent');
    });
});

it('refuses a variable and its file set to different secrets, naming both and showing neither', function (): void {
    $this->files[] = $path = SecretFile::holding('the-value-in-the-file');

    Environment::with(['DB_PASSWORD' => 'the-value-in-the-variable', 'DB_PASSWORD_FILE' => $path], function (): void {
        expect(fn () => SecretFiles::resolve())->toThrow(function (SecretFileRefused $refusal): void {
            expect($refusal->getMessage())->toBe('DB_PASSWORD and DB_PASSWORD_FILE are both set. Set one or the other.');
        });
    });
});

it('takes a variable and its file holding the same secret as one, as a second start in one process finds them', function (): void {
    $this->files[] = $path = SecretFile::holding("the-same-secret\n");

    Environment::with(['DB_PASSWORD' => 'the-same-secret', 'DB_PASSWORD_FILE' => $path], function (): void {
        expect(fn () => SecretFiles::resolve())->not->toThrow(Throwable::class)
            ->and(Env::get('DB_PASSWORD'))->toBe('the-same-secret');
    });
});

it('counts a variable that holds nothing as unset, as Laravel reads it', function (string $nothing): void {
    $this->files[] = $path = SecretFile::holding('from-the-file');

    Environment::with(['MAIL_PASSWORD' => $nothing, 'MAIL_PASSWORD_FILE' => $path], function (): void {
        SecretFiles::resolve();

        expect(Env::get('MAIL_PASSWORD'))->toBe('from-the-file');
    });
})->with(['', 'null', '(null)', 'empty']);

it('refuses a file that can’t be read, naming the variable and the path', function (string $path): void {
    Environment::with(['TEME_AI_OPENAI_KEY' => null, 'TEME_AI_OPENAI_KEY_FILE' => $path], function () use ($path): void {
        expect(fn () => SecretFiles::resolve())->toThrow(function (SecretFileRefused $refusal) use ($path): void {
            expect($refusal->getMessage())->toBe("TEME_AI_OPENAI_KEY_FILE names {$path}, which can't be read.");
        });
    });
})->with([
    'a missing file' => [fn (): string => sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-secret-'.Str::lower(Str::random(8))],
    'a folder' => [fn (): string => sys_get_temp_dir()],
]);

it('reads only the secrets it names, never another _FILE variable', function (): void {
    $this->files[] = $path = SecretFile::holding('not-a-secret');

    Environment::with(['APP_NAME' => 'Teme', 'APP_NAME_FILE' => $path], function (): void {
        SecretFiles::resolve();

        expect(Env::get('APP_NAME'))->toBe('Teme');
    });
});

it('names every secret the image may take from a file', function (): void {
    expect(SecretFiles::NAMES)->toBe([
        'APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MAIL_PASSWORD', 'CLOUDFLARE_API_TOKEN',
        'TEME_AI_ANTHROPIC_KEY', 'TEME_AI_OPENAI_KEY', 'TEME_AI_DEEPSEEK_KEY', 'TEME_AI_OPENROUTER_KEY',
        'TEME_BACKUP_MACHINE_KEY', 'TEME_VERIFY_DB_PASSWORD', 'TEME_GITHUB_TOKEN', 'TEME_DOKPLOY_TOKEN',
    ]);
});

it('reads the files as soon as the environment is loaded', function (): void {
    $this->files[] = $path = SecretFile::holding('read-at-bootstrap');

    Environment::with(['TEME_AI_DEEPSEEK_KEY' => null, 'TEME_AI_DEEPSEEK_KEY_FILE' => $path], function (): void {
        app('events')->dispatch('bootstrapped: '.LoadEnvironmentVariables::class, [app()]);

        expect(Env::get('TEME_AI_DEEPSEEK_KEY'))->toBe('read-at-bootstrap');
    });
});

it('stops php artisan with one line naming the variables, and writes neither secret anywhere', function (): void {
    $this->files[] = $path = SecretFile::holding('sentinel-in-the-file-7f3a');

    $process = SecretFile::artisanWith(['TEME_AI_ANTHROPIC_KEY' => 'sentinel-in-the-variable-9c1e', 'TEME_AI_ANTHROPIC_KEY_FILE' => $path]);
    $said = $process->getOutput().$process->getErrorOutput();
    $logged = collect(glob(storage_path('logs').'/*.log') ?: [])->map(fn (string $log): string => (string) file_get_contents($log))->implode("\n");

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Teme cannot start: TEME_AI_ANTHROPIC_KEY and TEME_AI_ANTHROPIC_KEY_FILE are both set. Set one or the other.')
        ->and($said.$logged)->not->toContain('sentinel-in-the-file-7f3a')
        ->and($said.$logged)->not->toContain('sentinel-in-the-variable-9c1e');
});

it('stops php artisan when a secret’s file can’t be read', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-secret-'.Str::lower(Str::random(8));

    $process = SecretFile::artisanWith(['TEME_AI_ANTHROPIC_KEY_FILE' => $path]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain("Teme cannot start: TEME_AI_ANTHROPIC_KEY_FILE names {$path}, which can't be read.");
});
