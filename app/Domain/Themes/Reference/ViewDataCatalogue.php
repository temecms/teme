<?php

declare(strict_types=1);

namespace App\Domain\Themes\Reference;

use App\Domain\Site\FieldViews;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\CommentsPageView;
use App\Domain\Site\ViewModels\ErrorView;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\NotFoundView;
use App\Domain\Site\ViewModels\ProtectedView;
use App\Domain\Site\ViewModels\RespondView;
use App\Domain\Site\ViewModels\SiteView;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Everything a template can read, found by reflection from the page models
 * and the site view, with each value's type and a one-line description: the
 * source of docs/reference/theme.md and of the pin (I-3346).
 */
final class ViewDataCatalogue
{
    /** The namespace of the page models, which every type below is shown without. */
    private const string MODELS = 'App\Domain\Site\ViewModels\\';

    /**
     * The class `$page` is for each kind of page, by kind.
     *
     * @var array<string, class-string>
     */
    public const array PAGE_MODELS = [
        'latest' => LatestView::class,
        'single' => ArticleView::class,
        'archive' => ListingView::class,
        'taxonomy' => ListingView::class,
        'author' => ListingView::class,
        'date' => ListingView::class,
        'search' => ListingView::class,
        'notfound' => NotFoundView::class,
        'error' => ErrorView::class,
        'comments' => CommentsPageView::class,
        'respond' => RespondView::class,
        'protected' => ProtectedView::class,
    ];

    /**
     * What every template is given, by name, with its type and description;
     * `$page`'s type is the page models, filled in by variables(). The
     * composers return untyped arrays, so this list is kept by hand.
     *
     * @var array<string, array{type: string, description: string}>
     */
    public const array TEMPLATE_VARIABLES = [
        '$page' => ['type' => '', 'description' => 'The page being drawn: its model is the one its kind of page names.'],
        '$site' => ['type' => 'SiteView', 'description' => 'The site: its name, tagline, home address, language and the current year.'],
        '$navigation' => ['type' => 'array<string, list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null>', 'description' => 'Each of the theme\'s navigation slots with its menu\'s tree, or null when it has none.'],
        '$themeSettings' => ['type' => 'array<string, mixed>', 'description' => 'The active theme\'s own settings, key to value, without its menu places.'],
        '$isFront' => ['type' => 'bool', 'description' => 'True only on the site\'s home page and its numbered pages.'],
    ];

    /**
     * The page models, and every class they reach, by short class name.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        $found = [];
        $queue = [...array_values(self::PAGE_MODELS), SiteView::class];

        while ($queue !== []) {
            $class = array_shift($queue);

            if (isset($found[$class])) {
                continue;
            }

            $found[$class] = true;

            foreach ($this->members($class) as $member) {
                foreach ($this->reached($member['type']) as $next) {
                    $queue[] = $next;
                }
            }
        }

        $classes = array_keys($found);
        usort($classes, fn (string $a, string $b): int => strcmp($this->short($a), $this->short($b)));

        return $classes;
    }

    /**
     * A class's public properties, then its public methods, each in
     * declaration order; the constructor and every method starting with
     * two underscores are left out.
     *
     * @param  class-string  $class
     * @return list<array{name: string, kind: string, type: string, description: string}>
     */
    public function members(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $members = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $members[] = [
                'name' => '$'.$property->getName(),
                'kind' => 'property',
                'type' => $this->propertyType($reflection, $property),
                'description' => $this->propertyDescription($reflection, $property),
            ];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $members[] = [
                'name' => $method->getName().'()',
                'kind' => 'method',
                'type' => $this->methodType($method),
                'description' => $this->firstSentence($method->getDocComment()),
            ];
        }

        return $members;
    }

    /**
     * Every entry, by name, with its type and description, sorted by name.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->classes() as $class) {
            foreach ($this->members($class) as $member) {
                $entries[$this->short($class).'::'.$member['name']] = ['type' => $member['type'], 'description' => $member['description']];
            }
        }

        foreach ($this->variables() as $name => $variable) {
            $entries[$name] = $variable;
        }

        foreach (self::PAGE_MODELS as $kind => $class) {
            $entries["kind {$kind}"] = ['type' => $this->short($class), 'description' => "The model `\$page` is on a {$kind} page."];
        }

        foreach (FieldViews::TYPES as $type => $row) {
            $entries["field {$type}"] = ['type' => $row['value'], 'description' => $row['text']];
        }

        foreach ($this->components() as $component) {
            foreach ($component['attributes'] as $attribute) {
                $entries["<x-teme::{$component['name']}> {$attribute['name']}"] = ['type' => $attribute['type'], 'description' => $attribute['description']];
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * What every template is given, in the order TEMPLATE_VARIABLES lists it.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function variables(): array
    {
        $variables = self::TEMPLATE_VARIABLES;
        $models = array_values(array_unique(array_map(fn (string $class): string => $this->short($class), array_values(self::PAGE_MODELS))));
        $variables['$page']['type'] = implode('|', $models);

        return $variables;
    }

    /**
     * Core's `<x-teme::…>` components, sorted by class name, each with the
     * attributes a template sets: its constructor's scalar parameters and
     * those typed by a page model. A parameter typed by any other class is a
     * service the container gives it.
     *
     * @return list<array{name: string, class: class-string, description: string, attributes: list<array{name: string, type: string, default: string, description: string}>}>
     */
    public function components(): array
    {
        $files = glob(app_path('View/Components/Teme/*.php')) ?: [];
        $names = array_map(fn (string $file): string => basename($file, '.php'), $files);
        sort($names, SORT_STRING);
        $components = [];

        foreach ($names as $name) {
            /** @var class-string $class */
            $class = 'App\View\Components\Teme\\'.$name;
            $reflection = new ReflectionClass($class);
            $attributes = [];

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if (! $this->isAttribute($parameter)) {
                    continue;
                }

                $attributes[] = [
                    'name' => $parameter->getName(),
                    'type' => $this->shown((string) $parameter->getType()),
                    'default' => $this->defaultOf($parameter),
                    'description' => $this->parameterDescription($reflection, $parameter),
                ];
            }

            $components[] = [
                'name' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name)),
                'class' => $class,
                'description' => $this->docText($reflection->getDocComment()),
                'attributes' => $attributes,
            ];
        }

        return $components;
    }

    /** A class's docblock description as one paragraph, or ''. */
    public function classDescription(string $class): string
    {
        return $this->docText((new ReflectionClass($class))->getDocComment());
    }

    /** A class's short name; the page models share one namespace, so these are unique. */
    public function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    /** A type as shown: without the page models' namespace. */
    private function shown(string $type): string
    {
        return str_replace(self::MODELS, '', $type);
    }

    /**
     * The page-model classes a type names, anywhere inside it.
     *
     * @return list<class-string>
     */
    private function reached(string $type): array
    {
        preg_match_all('/\b[A-Z][A-Za-z0-9_]*\b/', $type, $words);
        $classes = [];

        foreach (array_unique($words[0]) as $word) {
            if (class_exists(self::MODELS.$word)) {
                $classes[] = self::MODELS.$word;
            }
        }

        return $classes;
    }

    /** @param  ReflectionClass<object>  $class */
    private function propertyType(ReflectionClass $class, ReflectionProperty $property): string
    {
        $native = $this->shown((string) $property->getType());

        if (! in_array($native, ['array', '?array'], true)) {
            return $native;
        }

        return $this->docblockType($class, $property->getName()) ?? $native;
    }

    /**
     * The type a constructor's @param line, else the property's @var, gives.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function docblockType(ReflectionClass $class, string $name): ?string
    {
        $doc = (string) $class->getConstructor()?->getDocComment();

        if (preg_match('/@param\s+(.+?)\s+\$'.preg_quote($name, '/').'\b/', $doc, $match) === 1) {
            return $this->collapse($match[1]);
        }

        $doc = (string) $class->getProperty($name)->getDocComment();

        if (preg_match('/@var\s+(.+?)(?:\s*\*\/|\s*$)/m', $doc, $match) === 1) {
            return $this->collapse($match[1]);
        }

        return null;
    }

    private function methodType(ReflectionMethod $method): string
    {
        $parameters = array_map(fn (ReflectionParameter $p): string => trim($this->shown((string) $p->getType()).' $'.$p->getName()), $method->getParameters());
        $return = $this->shown((string) $method->getReturnType());

        if (in_array($return, ['array', '?array'], true) && preg_match('/@return\s+(.+)/', (string) $method->getDocComment(), $match) === 1) {
            $return = $this->collapse($match[1]);
        }

        return '('.implode(', ', $parameters).'): '.$return;
    }

    /** @param  ReflectionClass<object>  $class */
    private function propertyDescription(ReflectionClass $class, ReflectionProperty $property): string
    {
        $own = $this->docText($property->getDocComment());

        if ($own !== '' && ! str_starts_with($own, '@')) {
            return $own;
        }

        return $this->paramText($class, $property->getName());
    }

    /** @param  ReflectionClass<object>  $class */
    private function parameterDescription(ReflectionClass $class, ReflectionParameter $parameter): string
    {
        if ($parameter->isPromoted()) {
            $own = $this->docText($class->getProperty($parameter->getName())->getDocComment());

            if ($own !== '' && ! str_starts_with($own, '@')) {
                return $own;
            }
        }

        return $this->paramText($class, $parameter->getName());
    }

    /**
     * The text after the name on a constructor's @param line, or ''.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function paramText(ReflectionClass $class, string $name): string
    {
        $doc = (string) $class->getConstructor()?->getDocComment();

        if (preg_match('/@param\s+.+?\s+\$'.preg_quote($name, '/').'\b[ \t]*(.*)/', $doc, $match) === 1) {
            return $this->collapse(rtrim($match[1], " \t*/"));
        }

        return '';
    }

    private function isAttribute(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        return $type->isBuiltin()
            ? in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)
            : str_starts_with($type->getName(), self::MODELS);
    }

    /** The default as a template would write it, or a dash for none. */
    private function defaultOf(ReflectionParameter $parameter): string
    {
        if (! $parameter->isDefaultValueAvailable()) {
            return '—';
        }

        return match (true) {
            $parameter->getDefaultValue() === null => 'null',
            is_bool($parameter->getDefaultValue()) => $parameter->getDefaultValue() ? 'true' : 'false',
            default => var_export($parameter->getDefaultValue(), true),
        };
    }

    /** A docblock's description lines, without tags, as one paragraph. */
    private function docText(string|false $doc): string
    {
        if ($doc === false) {
            return '';
        }

        $lines = [];

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(\/\*\*|\*\/|\*)\s?/', '', $line));
            $line = trim((string) preg_replace('/\s*\*\/$/', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        return $this->collapse(implode(' ', $lines));
    }

    private function firstSentence(string|false $doc): string
    {
        $text = $this->docText($doc);

        if (preg_match('/^.+?\.(?=\s|$)/', $text, $match) === 1) {
            return $match[0];
        }

        return $text;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
