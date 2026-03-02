<?php

declare(strict_types=1);

namespace Hibla\Promise\PHPStan;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VoidType;

final class PromiseResolvedReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return Promise::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'resolved';
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): Type {
        $args = $methodCall->getArgs();

        if (\count($args) === 0) {
            return new GenericObjectType(PromiseInterface::class, [new VoidType()]);
        }

        $argType = $scope->getType($args[0]->value);

        // Promise::resolved(null) — explicit null → PromiseInterface<null>
        if ($argType->isNull()->yes()) {
            return new GenericObjectType(PromiseInterface::class, [$argType]);
        }

        // Promise::resolved($value) — any other value → PromiseInterface<T>
        return new GenericObjectType(PromiseInterface::class, [$argType]);
    }
}
