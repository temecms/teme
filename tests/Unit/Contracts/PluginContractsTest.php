<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Plugins\HostPattern;
use App\Domain\Themes\ManifestValidator;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\HasPluginArea;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Ai\AiAnswer;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\AiRefused;
use Teme\Contracts\Ai\AiStanding;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemPage;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\Content\LinkAttributes;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\OutboundLink;
use Teme\Contracts\Content\QueuedItem;
use Teme\Contracts\Content\QueuePage;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Editor\CheckedItem;
use Teme\Contracts\Editor\CheckResult;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\HasEditorChecks;
use Teme\Contracts\Editor\HasEditorPanels;
use Teme\Contracts\Editor\PanelView;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Teme\Contracts\Head\HasHeadMeta;
use Teme\Contracts\Head\HeadMeta;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Insertions\PagePosition;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\HasPluginLists;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRow;
use Teme\Contracts\Lists\ListRows;
use Teme\Contracts\Lists\PluginList;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\People\PersonPage;
use Teme\Contracts\People\PersonRef;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\PluginSettingsWriter;
use Teme\Contracts\Site\SiteInfo;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Teme\Contracts\Sitemaps\SitemapEntry;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;
use Teme\Contracts\StructuredData\FaqCard;
use Teme\Contracts\StructuredData\FaqPair;
use Teme\Contracts\StructuredData\GraphNode;
use Teme\Contracts\StructuredData\HasStructuredData;
use Teme\Contracts\StructuredData\Step;
use Teme\Contracts\StructuredData\StepsCard;

it('gives every new contract its @since and nothing from a framework', function (string $class, string $since, array $later = []): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since '.$since);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! $method->isUserDefined()) {
            continue;
        }

        // A method added later carries the release it arrived in (.ai/rules/contracts.md).
        expect($method->getDocComment())->toContain('@since '.($later[$method->getName()] ?? $since));
    }

    $types = [];

    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $types[] = $property->getType();
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $types[] = $method->getReturnType();

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }
    }

    foreach (array_filter($types) as $type) {
        $named = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();

        foreach ($named as $one) {
            if ($one->isBuiltin() || in_array($one->getName(), ['self', 'static', DateTimeImmutable::class], true)) {
                continue;
            }

            expect($one->getName())->toStartWith('Teme\\Contracts\\');
        }
    }
})->with([
    [Capability::class, '0.5.0'],
    [StatusCheck::class, '0.5.0'],
    [StatusNotice::class, '0.5.0'],
    [ChecksStatus::class, '0.5.0'],
    [OutboundHttp::class, '0.5.0', ['postForm' => '1.0.0']],
    [HttpAnswer::class, '0.5.0'],
    [HostRefused::class, '0.5.0'],
    [AnswerTooLarge::class, '0.5.0'],
    [RequestFailed::class, '0.5.0'],
    [ItemRef::class, '0.5.0'],
    [ContentWriter::class, '1.0.0'],
    [NewItem::class, '1.0.0'],
    [ItemChanges::class, '1.0.0'],
    [WriteRefused::class, '1.0.0'],
    [MediaWriter::class, '1.0.0'],
    [HasListColumns::class, '1.0.0'],
    [ListColumn::class, '1.0.0'],
    [ListRows::class, '1.0.0'],
    [ListRow::class, '1.0.0'],
    [Cell::class, '1.0.0'],
    [SiteInfo::class, '1.0.0'],
    [PluginSettingsWriter::class, '1.0.0'],
    [QueueOptions::class, '1.0.0'],
    [HasPluginLists::class, '1.0.0'],
    [PluginList::class, '1.0.0'],
    [PageInsertionContributor::class, '1.0.0'],
    [PageInsertion::class, '1.0.0'],
    [PagePosition::class, '1.0.0'],
    [PageFacts::class, '1.0.0'],
    [InlineScripts::class, '1.0.0'],
    [SiteAi::class, '1.0.0'],
    [AiAsk::class, '1.0.0'],
    [AiAnswer::class, '1.0.0'],
    [AiStanding::class, '1.0.0'],
    [AiRefused::class, '1.0.0'],
    [ContentReader::class, '1.0.0'],
    [ItemQuery::class, '1.0.0'],
    [ItemPage::class, '1.0.0'],
    [ItemSnapshot::class, '1.0.0'],
    [ReadRefused::class, '1.0.0'],
    [QueueQuery::class, '1.0.0'],
    [QueuedItem::class, '1.0.0'],
    [QueuePage::class, '1.0.0'],
    [PeopleReader::class, '1.0.0'],
    [PersonRef::class, '1.0.0'],
    [PersonPage::class, '1.0.0'],
    [HookSender::class, '1.0.0'],
    [ArticleLinks::class, '1.0.0'],
    [LinkAttributes::class, '1.0.0'],
    [OutboundLink::class, '1.0.0'],
    [HasPluginArea::class, '1.0.0'],
    [PluginArea::class, '1.0.0'],
    [AreaContext::class, '1.0.0'],
    [Figure::class, '1.0.0'],
    [Chart::class, '1.0.0'],
    [TableQuery::class, '1.0.0'],
    [RowPage::class, '1.0.0'],
    [Row::class, '1.0.0'],
    [Saved::class, '1.0.0'],
    [HasEditorPanels::class, '1.0.0'],
    [EditorPanel::class, '1.0.0'],
    [EditorPanelContext::class, '1.0.0'],
    [PanelView::class, '1.0.0'],
    [Suggestion::class, '1.0.0'],
    [SuggestedChange::class, '1.0.0'],
    [HasEditorChecks::class, '1.1.0'],
    [CheckedItem::class, '1.1.0'],
    [CheckResult::class, '1.1.0'],
    [HeadPage::class, '1.1.0'],
    [HasHeadMeta::class, '1.1.0'],
    [HeadMeta::class, '1.1.0'],
    [HasStructuredData::class, '1.1.0'],
    [GraphNode::class, '1.1.0'],
    [FaqCard::class, '1.1.0'],
    [FaqPair::class, '1.1.0'],
    [StepsCard::class, '1.1.0'],
    [Step::class, '1.1.0'],
    [HasSitemaps::class, '1.1.0'],
    [PluginSitemap::class, '1.1.0'],
    [SitemapEntry::class, '1.1.0'],
]);

it('does not accept a language on ItemChanges or via on publish and schedule inputs', function (): void {
    expect((new ReflectionClass(ItemChanges::class))->hasProperty('language'))->toBeFalse()
        ->and((new ReflectionClass(PublishRevisionInput::class))->hasProperty('via'))->toBeFalse()
        ->and((new ReflectionClass(SchedulePublicationInput::class))->hasProperty('via'))->toBeFalse();
});

it('names the capabilities a plugin can ask for in the first release', function (): void {
    expect(array_column(Capability::cases(), 'value'))->toBe([
        'content.read',
        'content.write',
        'media.read',
        'media.write',
        'content.insert',
        'http',
        'jobs',
        'routes.public',
        'import.wordpress',
        'fediverse.read',
        'fediverse.send',
        'feeds.links',
        'pages.insert',
        'ai',
        'people.read',
        'notices.send',
        'content.links',
        'pages.head',
        'sitemaps',
    ]);
});

it('gives every jobs contract its @since 1.0.0 and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since 1.0.0');

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! $method->isUserDefined()) {
            continue;
        }

        expect($method->getDocComment())->toContain('@since 1.0.0');
    }

    $types = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $types[] = $method->getReturnType();

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }
    }

    foreach (array_filter($types) as $type) {
        $named = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();

        foreach ($named as $one) {
            if ($one->isBuiltin() || in_array($one->getName(), ['self', 'static'], true)) {
                continue;
            }

            expect($one->getName())->toStartWith('Teme\\Contracts\\');
        }
    }
})->with([
    Jobs::class,
    JobHandler::class,
    JobContext::class,
    JobStopped::class,
    PluginHasJobs::class,
]);

it('decodes an answer’s JSON, and refuses JSON that isn’t', function (): void {
    expect((new HttpAnswer(200, '{"a":1}', []))->json())->toBe(['a' => 1]);

    expect(fn () => (new HttpAnswer(200, 'nope', []))->json())->toThrow(JsonException::class);
});

it('keeps the schema in step with the capability names and the host rule', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($schema['properties']['capabilities']['items']['enum'])->toBe(array_column(Capability::cases(), 'value'))
        ->and('/'.$schema['properties']['hosts']['items']['pattern'].'/D')->toBe(HostPattern::PATTERN)
        ->and($schema['properties']['code']['pattern'])->toBe('^'.ManifestValidator::CODE_PATTERN.'$');
});
