<?php

/**
 * `teme:theme:resolve {url}`: explains which template a public address
 * would use, and why: its kind, whether it is the home page, and every
 * candidate with whether it exists, without rendering the page. A URL that
 * redirects says where it goes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Site\PathResolver;
use App\Domain\Themes\TemplateResolver;
use App\Domain\Themes\ViewIntent;
use Illuminate\Console\Command;

final class ResolveTemplate extends Command
{
    /** @var string */
    protected $signature = 'teme:theme:resolve {url : A public address, such as /about/ or a full URL} {--json : Print the answer as JSON}';

    /** @var string */
    protected $description = 'Explain which template a public address uses, and why';

    public function handle(TemplateResolver $resolver): int
    {
        $parts = parse_url((string) $this->argument('url'));
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $answer = PathResolver::resolve($path, $parts['query'] ?? '');

        if ($answer['kind'] === 'redirect') {
            $this->option('json')
                ? $this->json(['redirect' => $answer['redirect'] ?? '/'])
                : $this->components->info('This address redirects (301) to '.($answer['redirect'] ?? '/').'.');

            return self::SUCCESS;
        }

        $intent = $answer['intent'] ?? new ViewIntent('notfound');
        $resolution = $resolver->resolve($intent);

        if ($this->option('json')) {
            $this->json([
                'kind' => $intent->kind,
                'isFront' => $intent->isFront,
                'template' => $resolution->view,
                'fallback' => $resolution->fallback,
                'candidates' => $resolution->candidates,
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Kind', $intent->kind);
        $this->components->twoColumnDetail('Home page', $intent->isFront ? 'yes' : 'no');
        $this->components->twoColumnDetail('Template', $resolution->view.($resolution->fallback ? ' (core\'s fallback)' : ''));

        $this->table(['Candidate', 'Exists', 'Winner'], array_map(fn (array $candidate): array => [
            $candidate['name'],
            $candidate['exists'] ? 'yes' : 'no',
            'theme::'.$candidate['name'] === $resolution->view ? 'yes' : '',
        ], $resolution->candidates));

        return self::SUCCESS;
    }

    /**
     * One JSON object, each key on its own line with its value compact, so
     * a tool reads the whole and a person, or a test, finds one key a line.
     *
     * @param  array<string, mixed>  $answer
     */
    private function json(array $answer): void
    {
        $keys = array_keys($answer);

        $this->line('{');

        foreach ($keys as $i => $key) {
            $this->line(json_encode($key).':'.json_encode($answer[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).($i < count($keys) - 1 ? ',' : ''));
        }

        $this->line('}');
    }
}
