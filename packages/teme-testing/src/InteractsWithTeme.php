<?php

declare(strict_types=1);

namespace Teme\Testing;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Design\ThemeSettings;
use App\Domain\Hooks\Payloads;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\DesignSettingsGroup;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Updates\TemeVersion;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\SetUpSiteInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\SetUpSite;
use App\Domain\Users\Roles;
use App\Domain\Users\SetupCode;
use Closure;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Teme\Contracts\Content\ItemRef;

/**
 * Everything the kit gives a test, for an author whose test case is their
 * own: people, plugins, themes, content, the outbound fake and single
 * requests, each through the same Operations and doors the admin and a
 * running site use. Laravel calls setUpInteractsWithTeme() before each test.
 */
trait InteractsWithTeme
{
    /** The password every person the kit makes signs in with. */
    private string $temePassword = 'kit-password-for-tests-only-48';

    /** The administrator who set the site up, made with the first person. */
    private ?Person $temeOwner = null;

    /** Whether a test was already handed the owner as its administrator. */
    private bool $temeOwnerGiven = false;

    /** The editor content is written as when no one is named. */
    private ?Person $temeEditor = null;

    /** How many people the kit has made, for their names. */
    private int $temePeople = 0;

    private ?FakeOutbound $temeOutbound = null;

    /**
     * Readies the site for a test: the roles, the Teme version the checkout's
     * contracts offer, and outbound lookups that never leave the machine.
     */
    protected function setUpInteractsWithTeme(): void
    {
        $this->seed(RoleSeeder::class);

        if (config('teme.version') === TemeVersion::UNTAGGED) {
            config(['teme.version' => ContractsVersion::highest()]);
        }

        app()->instance(HostResolver::class, new PublicResolver);
        app(PluginRegistry::class)->flush();
    }

    /**
     * Returns a new person with this role, one of those Teme's roles name:
     * the first person made sets the site up as its administrator.
     */
    public function person(string $role = 'editor'): Person
    {
        if (! in_array($role, array_keys(Roles::LABELS), true)) {
            Assert::fail("Teme has no role named \"{$role}\".");
        }

        $owner = $this->temeOwner ??= $this->temeSetUpSite();

        if ($role === Roles::ADMINISTRATOR && ! $this->temeOwnerGiven) {
            $this->temeOwnerGiven = true;

            return $owner;
        }

        [$name, $email] = $this->temeNextName();

        return $this->temeWithoutBreachCheck(function () use ($owner, $name, $email, $role): Person {
            /** @var array{userId: int, token: string} $invited */
            $invited = (new Acting)->as($owner, fn (): array => app(InviteUser::class)->execute(new InviteUserInput($name, $email, $role, (string) $owner->id, deliver: false)), 'Inviting '.$name);

            app(AcceptInvitation::class)->execute(new AcceptInvitationInput($email, $invited['token'], $this->temePassword, $this->temePassword));

            return new Person($invited['userId'], $name, $email, $role);
        });
    }

    /**
     * Returns the plugin in this folder (one holding teme-plugin.json, added
     * to the folders Teme looks in) or with this Composer name, as Composer
     * installed it. Fails the test with what is wrong when there is none or
     * it can't be used.
     */
    public function plugin(string $nameOrFolder): PluginUnderTest
    {
        $registry = app(PluginRegistry::class);
        $folder = is_file($nameOrFolder.'/teme-plugin.json') ? (string) realpath($nameOrFolder) : null;

        if ($folder !== null) {
            $paths = config('teme.plugin_paths');
            $paths = is_array($paths) ? $paths : [];
            config(['teme.plugin_paths' => array_values(array_unique([...$paths, $folder])), 'teme.plugin_cache' => '']);
            $registry->flush();
        }

        $found = null;

        foreach ($registry->all() as $key => $plugin) {
            if ($folder === null ? $key === $nameOrFolder : realpath($plugin->path) === $folder) {
                $found = $plugin;
            }
        }

        if ($found === null) {
            Assert::fail("No plugin is named \"{$nameOrFolder}\", and no folder there holds a teme-plugin.json.");
        }

        if (! $found->usable()) {
            Assert::fail("{$found->name} can't be used: ".implode(' ', $found->problems ?: ['it has no teme-plugin.json.']));
        }

        return new PluginUnderTest($found->name, $found->code, new Acting, fn (): Person => $this->person(Roles::ADMINISTRATOR));
    }

    /**
     * Returns a new draft with this title and an empty body, written through
     * CreateDraft, as an editor unless someone is named.
     */
    public function draft(string $title, string $type = 'post', ?Person $by = null): ItemRef
    {
        $by ??= $this->temeEditor();

        /** @var Content $content */
        $content = (new Acting)->as($by, fn (): Content => app(CreateDraft::class)->execute(new CreateDraftInput(
            type: $type,
            title: $title,
            document: ['type' => 'doc', 'content' => []],
            fields: [],
            actorId: (string) $by->id,
        )), 'Writing the draft "'.$title.'"');

        return Payloads::item($content);
    }

    /**
     * Returns the item published through PublishRevision, with its address:
     * the draft named, or a new one with this title.
     */
    public function publish(string|ItemRef $titleOrItem, string $type = 'post', ?Person $by = null): ItemRef
    {
        $by ??= $this->temeEditor();
        $item = $titleOrItem instanceof ItemRef ? $titleOrItem : $this->draft($titleOrItem, $type, $by);
        $content = Content::query()->findOrFail($item->id);

        (new Acting)->as($by, fn (): mixed => app(PublishRevision::class)->execute(
            new PublishRevisionInput($content->id, (string) $content->working_revision_id, (string) $by->id),
        ), 'Publishing "'.$item->title.'"');

        return Payloads::item($content->refresh());
    }

    /**
     * Returns the item taken back to a draft through UnpublishContent, with no address.
     */
    public function unpublish(ItemRef $item, ?Person $by = null): ItemRef
    {
        $by ??= $this->temeEditor();

        (new Acting)->as($by, fn (): mixed => app(UnpublishContent::class)->execute(new ContentActionInput($item->id, (string) $by->id)), 'Unpublishing "'.$item->title.'"');

        return Payloads::item(Content::query()->findOrFail($item->id));
    }

    /**
     * Returns the item moved to the trash through TrashContent, with no address.
     */
    public function trash(ItemRef $item, ?Person $by = null): ItemRef
    {
        $by ??= $this->temeEditor();

        (new Acting)->as($by, fn (): mixed => app(TrashContent::class)->execute(new ContentActionInput($item->id, (string) $by->id)), 'Trashing "'.$item->title.'"');

        return Payloads::item(Content::withTrashed()->findOrFail($item->id));
    }

    /**
     * Returns the fake that answers what plugins send through their outbound
     * door from now on, and records it.
     */
    public function outbound(): FakeOutbound
    {
        return $this->temeOutbound ??= new FakeOutbound;
    }

    /**
     * Returns the site's answer to one request for this exact path, the
     * trailing slash kept, through the HTTP kernel as a visitor's would be.
     *
     * @param  array<string, string>  $headers
     *
     * @return TestResponse<Response>
     */
    public function fetch(string $path, string $method = 'GET', array $headers = [], string $body = ''): TestResponse
    {
        $request = Request::create($path, strtoupper($method), content: $body);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
    }

    /**
     * Returns the theme in this folder (one holding teme-theme.json, added to
     * the folders Teme looks in) or with this code. Fails the test when there
     * is none.
     */
    public function theme(string $codeOrFolder): ThemeUnderTest
    {
        $themes = app(ThemeRegistry::class);
        $folder = is_file($codeOrFolder.'/teme-theme.json') ? (string) realpath($codeOrFolder) : null;

        if ($folder !== null) {
            $paths = config('teme.theme_paths');
            $paths = is_array($paths) ? $paths : [];
            config(['teme.theme_paths' => array_values(array_unique([...$paths, $folder]))]);
            $themes->flush();
            DesignSettingsGroup::register(app(SettingsRegistry::class), $themes);
            app(ThemeSettings::class)->register($themes);
        }

        $code = null;

        foreach ($themes->all() as $theme) {
            if ($folder === null ? $theme->code === $codeOrFolder : realpath($theme->path) === $folder) {
                $code = $theme->code;
            }
        }

        if ($code === null) {
            Assert::fail("No theme has the code \"{$codeOrFolder}\", and no folder there holds a teme-theme.json.");
        }

        return new ThemeUnderTest($code, new Acting, fn (): Person => $this->person(Roles::ADMINISTRATOR));
    }

    /**
     * The editor content is written as when no one is named, made the first time.
     */
    private function temeEditor(): Person
    {
        return $this->temeEditor ??= $this->person('editor');
    }

    /**
     * The site set up through SetUpSite with its own setup code, as the first
     * page a new site shows; its administrator.
     */
    private function temeSetUpSite(): Person
    {
        [$name, $email] = $this->temeNextName();

        return $this->temeWithoutBreachCheck(function () use ($name, $email): Person {
            /** @var array{userId: int} $made */
            $made = app(SetUpSite::class)->execute(new SetUpSiteInput(
                app(SetupCode::class)->forThisSite(), 'A Teme site', 'UTC', $name, $email, $this->temePassword, $this->temePassword,
            ));

            return new Person($made['userId'], $name, $email, Roles::ADMINISTRATOR);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function temeNextName(): array
    {
        $this->temePeople++;

        return ['Person '.$this->temePeople, 'person'.$this->temePeople.'@example.com'];
    }

    /**
     * Runs $step with the breached-password check answering "not breached",
     * so making a person sends nothing to Have I Been Pwned.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     *
     * @return T
     */
    private function temeWithoutBreachCheck(Closure $step): mixed
    {
        $verifier = app(UncompromisedVerifier::class);
        app()->instance(UncompromisedVerifier::class, new NotBreached);

        try {
            return $step();
        } finally {
            app()->instance(UncompromisedVerifier::class, $verifier);
        }
    }
}
