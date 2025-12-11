<?php

declare(strict_types=1);

namespace Hibla\Promise\PHPStan;

use Hibla\Promise\Promise;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

class PromiseRejectedReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return Promise::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'rejected';
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope
    ): Type {
        $function = $scope->getFunction();

        if ($function !== null) {
            $returnType = $function->getVariants()[0]->getReturnType();

            if ($returnType->isObject()->yes()) {
                $classNames = $returnType->getObjectClassNames();
                foreach ($classNames as $className) {
                    if ($className === 'Hibla\\Promise\\Interfaces\\PromiseInterface') {
                        return $returnType;
                    }
                }
            }
        }

        return $methodReflection->getVariants()[0]->getReturnType();
    }
}
