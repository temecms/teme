<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

/*
 * The core addresses that answer for an item outside its own page speak the
 * item's language (I-3360): a comment's refusal, the password form's and the
 * reply page. A stand-in French file holds the words, so the test reads only
 * what it names.
 */

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->words = sys_get_temp_dir().'/reader-locale-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->words);
    File::put($this->words.'/fr.json', (string) json_encode([
        'Enter your name.' => 'Saisissez votre nom.',
        'Shorten your comment to 5,000 characters or fewer. It has :count.' => 'Raccourcissez votre commentaire à 5 000 caractères au plus. Il en compte :count.',
        'That isn’t the password. Check it and try again.' => 'Ce n’est pas le mot de passe. Vérifiez-le et réessayez.',
        'Reply to :name' => 'Répondre à :name',
        'Preview of an unpublished version' => 'Aperçu d’une version non publiée',
    ], JSON_UNESCAPED_UNICODE));
    // Read after core's own lang/, so the stand-in's words win over the shipped French.
    app('translator')->addPath($this->words);
    $this->site = LanguageSite::build();
    RateLimiter::clear('unlock:203.0.113.9');
});

afterEach(function (): void {
    File::deleteDirectory($this->words);
});

it('refuses a comment on a French post in French, its numbers written the French way', function (): void {
    $post = Content::query()->findOrFail($this->site->postFr);

    $missing = (string) cmPost($post, ['name' => ''])->assertStatus(422)->getContent();
    $long = (string) cmPost($post, ['text' => str_repeat('a', 5001)])->assertStatus(422)->getContent();

    expect($missing)->toContain('Saisissez votre nom.')->not->toContain('Enter your name.')
        ->and($long)->toContain("Il en compte 5\u{202F}001.");
});

it('refuses a comment on an English post in English', function (): void {
    $post = Content::query()->findOrFail($this->site->post);

    expect((string) cmPost($post, ['name' => ''])->assertStatus(422)->getContent())->toContain('Enter your name.')->not->toContain('Saisissez');
});

it('answers a wrong password for a French item in French', function (): void {
    $item = Visibilities::change(Content::query()->findOrFail($this->site->postFr), 'protected', $this->site->editor, 'Harbour 2024');
    $request = Request::create('/_teme/unlock', 'POST', ['path' => '/fr/bonjour/', 'password' => 'wrong'], server: ['REMOTE_ADDR' => '203.0.113.9']);
    $response = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request)->assertStatus(422);

    expect($item->id)->toBe($this->site->postFr)
        ->and((string) $response->getContent())->toContain('Ce n’est pas le mot de passe. Vérifiez-le et réessayez.')
        ->and(headValue(dtDom((string) $response->getContent()), '/html/@lang'))->toBe('fr');
});

it('draws the reply page for a comment on a French post in French', function (): void {
    $comment = cmRow(Content::query()->findOrFail($this->site->postFr));

    $this->get("/_teme/comment/{$comment}/reply")->assertOk()->assertSee('Répondre à', false);
});

it('draws a French item\'s preview in French', function (): void {
    $link = histPreview(Content::query()->findOrFail($this->site->pageFr), $this->site->editor);

    $html = (string) $this->get("/preview/{$link->token}")->assertOk()->getContent();

    expect(headValue(dtDom($html), '/html/@lang'))->toBe('fr')
        ->and($html)->toContain('Aperçu d’une version non publiée')->not->toContain('Preview of an unpublished version');
});

it('draws core\'s words in the site\'s language on a single-language Spanish site', function (): void {
    File::put($this->words.'/es.json', (string) json_encode(['Enter your name.' => 'Escribe tu nombre.'], JSON_UNESCAPED_UNICODE));
    app('translator')->setLoaded([]);
    // One language only: the site's own, Spanish.
    Languages::set([]);
    siteSetLanguage($this->site->admin, 'es');
    $post = cmItem($this->site->editor, 'Hola mundo');

    expect((string) cmPost($post, ['name' => ''])->assertStatus(422)->getContent())->toContain('Escribe tu nombre.');
});

it('draws a reader\'s own waiting comment on a French post with the theme\'s French words', function (): void {
    $words = json_decode((string) file_get_contents(base_path('themes/default/lang/fr.json')), true, flags: JSON_THROW_ON_ERROR);
    cmSubmit(Content::query()->findOrFail($this->site->postFr), 'Un premier mot.');

    $html = (string) cmPending('/fr/bonjour/', 'browser-one-0123456789abcdef')->assertOk()->json('comments.0.html');

    expect($html)->toContain(e($words['Awaiting moderation.']))->not->toContain('Awaiting moderation.');
});
