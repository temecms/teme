<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Rule 1, by type: the HTTP and Actions layers may not call a write method on
 * an Eloquent model, builder or relation, or on a query builder — even one
 * they never name, such as `$request->user()`. See docs/architecture/operations.md.
 *
 * @implements Rule<CallLike>
 */
final class NoModelWritesInHttpLayer implements Rule
{
    private const array GUARDED_NAMESPACES = ['App\\Http', 'App\\Actions'];

    private const array GUARDED_TYPES = [
        Model::class,
        EloquentBuilder::class,
        Relation::class,
        QueryBuilder::class,
    ];

    private const array WRITE_METHODS = [
        'save', 'savequietly', 'saveorfail', 'savemany',
        'update', 'updatequietly', 'updateorfail', 'updateorcreate', 'updateorinsert', 'updateexistingpivot',
        'delete', 'deletequietly', 'deleteorfail', 'forcedelete', 'forcedeletequietly', 'destroy', 'truncate',
        'restore', 'restorequietly',
        'fill', 'forcefill',
        'push', 'pushquietly', 'touch',
        'increment', 'decrement', 'incrementquietly', 'decrementquietly',
        'create', 'createquietly', 'createmany', 'forcecreate', 'forcecreatequietly', 'firstorcreate',
        'insert', 'insertorignore', 'insertgetid', 'upsert',
        'attach', 'detach', 'sync', 'syncwithoutdetaching', 'toggle',
    ];

    /**
     * A nullsafe method call (`$x?->save()`) is visited twice by PHPStan's
     * flow analysis — once per branch it splits the null check into — so the
     * same source line would otherwise be reported twice. Keyed by
     * "file:line:method", cleared per rule instance (one per analysis run).
     *
     * @var array<string, true>
     */
    private array $reported = [];

    /**
     * @param  list<string>  $allowedClasses  Classes permitted to write despite
     *                                        their namespace. Each entry is an
     *                                        a maintainer's decision under idea I-100.
     */
    public function __construct(private readonly array $allowedClasses = []) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array(strtolower($method), self::WRITE_METHODS, true) || ! $this->isGuarded($scope)) {
            return [];
        }

        $target = $this->targetType($node, $scope);

        foreach (self::GUARDED_TYPES as $guarded) {
            if ((new ObjectType($guarded))->isSuperTypeOf($target)->yes()) {
                $key = sprintf('%s:%d:%s', $scope->getFile(), $node->getStartLine(), $method);

                if (isset($this->reported[$key])) {
                    return [];
                }

                $this->reported[$key] = true;

                return [
                    RuleErrorBuilder::message(sprintf(
                        '%s() writes %s from the HTTP layer. Call an Operation instead (rule 1, docs/architecture/operations.md).',
                        $method,
                        $target->describe(VerbosityLevel::typeOnly()),
                    ))->identifier('teme.modelWriteInHttpLayer')->build(),
                ];
            }
        }

        return [];
    }

    private function isGuarded(Scope $scope): bool
    {
        $class = $scope->getClassReflection()?->getName();

        if ($class !== null && in_array($class, $this->allowedClasses, true)) {
            return false;
        }

        $namespace = $scope->getNamespace() ?? '';

        foreach (self::GUARDED_NAMESPACES as $guarded) {
            if ($namespace === $guarded || str_starts_with($namespace, $guarded.'\\')) {
                return true;
            }
        }

        return false;
    }

    private function targetType(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): Type
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                ? new ObjectType($scope->resolveName($node->class))
                : $scope->getType($node->class);
        }

        return TypeCombinator::removeNull($scope->getType($node->var));
    }
}
