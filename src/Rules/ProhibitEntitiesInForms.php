<?php declare(strict_types=1);

namespace Dsnet\PhpstanProhibitEntitiesInForms\Rules;

use Doctrine\ORM\Mapping\Entity;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Form\AbstractType;


/**
 * @implements Rule<ClassMethod>
 */
readonly class ProhibitEntitiesInForms implements Rule
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof ClassMethod);

        if ($node->name->toString() !== 'configureOptions') {
            return [];
        }

        $abstractTypeClassReflection = $this->reflectionProvider->getClass(AbstractType::class);
        if (!$scope->getClassReflection()?->isSubclassOfClass($abstractTypeClassReflection)) {
            return [];
        }

        $statements = $node->getStmts();
        if (!$statements) {
            return [];
        }
        foreach ($statements as $statement) {
            if (!$statement instanceof Node\Stmt\Expression) {
                continue;
            }
            $expression = $statement->expr;
            if (!$expression instanceof MethodCall) {
            continue;
            }
            $processed = $this->processStatement($expression, $scope);
            if ($processed) {
            return $processed;
            }
        }
        return [];
    }

    /**
     * @return ?list<IdentifierRuleError>
     */
    private function processStatement(MethodCall $expression, Scope &$scope): ?array
    {
        $identifier = $expression->name;
        if (!$identifier instanceof Node\Identifier) {
            return null;
        }
        $name = $identifier->toString();
        if ($name === 'setDefault') {
            $args = $expression->args;
            if (isset($args[0], $args[1]) && $args[0] instanceof Node\Arg && $args[0]->value instanceof String_ && $args[0]->value == 'data_class' && $args[1] instanceof Node\Arg) {
                return $this->processDataClass($args[1]->value, $scope);
            }
        }
        if ($name === 'setDefaults') {
            $args = $expression->args;
            if (!isset($args[0]) || !$args[0] instanceof Node\Arg || !$args[0]->value instanceof Array_) {
                return null;
            }
            $value = $args[0]->value;
            $items = $value->items;
            foreach ($items as $item) {
                $processedArrayItem = $this->processArrayItem($item, $scope);
                if ($processedArrayItem === null) {
                    continue;
                }
                return $processedArrayItem;
            }
        }
        return null;
    }

    /**
     * @return ?list<IdentifierRuleError>
     */
    private function processArrayItem(mixed $item, Scope &$scope): ?array
    {
        if ($item instanceof ArrayItem &&
            $item->key instanceof String_ &&
            $item->key->value === 'data_class'
        ) {
            return $this->processDataClass($item->value, $scope);
        }
        return null;
    }

    /**
     * @return ?list<IdentifierRuleError>
     */
    private function processDataClass(Node\Expr $dataClass, Scope &$scope): ?array
    {
        $className = $this->resolveClassName($dataClass, $scope);
        if ($className && $this->isDoctrineEntity($className)) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Cannot use Entity (%s) as a Form data class, use DTO',
                        $className
                    ),
                )->identifier('dsnet.noEntityAsFormDataClass')->build(),

            ];
        }
        return null;
    }

    private function resolveClassName(Node $node, Scope $scope): ?string
    {
        if ($node instanceof ClassConstFetch &&
            $node->class instanceof Node\Name &&
            $node->name instanceof Node\Identifier &&
            $node->name->toString() === 'class'
        ) {
            return $scope->resolveName($node->class);
        }

        if ($node instanceof String_) {
            return $node->value;
        }

        return null;
    }

    private function isDoctrineEntity(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        $ref = $this->reflectionProvider->getClass($className);
        return array_any($ref->getAttributes(), fn(AttributeReflection $attr) => $attr->getName() === Entity::class);
    }
}
