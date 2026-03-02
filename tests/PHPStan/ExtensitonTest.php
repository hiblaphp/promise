<?php

declare(strict_types=1);

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\PHPStan\PromiseResolvedReturnTypeExtension;
use Hibla\Promise\Promise;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\VoidType;

function makeMethodReflection(string $name): MethodReflection
{
    $method = Mockery::mock(MethodReflection::class);
    $method->allows('getName')->andReturn($name);

    return $method;
}

function makeStaticCall(array $argExprs): StaticCall
{
    $call = Mockery::mock(StaticCall::class);
    $call->allows('getArgs')->andReturn(
        array_map(fn (Expr $e) => new Arg($e), $argExprs)
    );

    return $call;
}

covers(PromiseResolvedReturnTypeExtension::class);

beforeEach(function (): void {
    $this->extension = new PromiseResolvedReturnTypeExtension();
});

describe('getClass()', function (): void {
    it('returns the Promise class', function (): void {
        expect($this->extension->getClass())->toBe(Promise::class);
    });
});

describe('isStaticMethodSupported()', function (): void {
    it('returns true for resolved', function (): void {
        expect($this->extension->isStaticMethodSupported(makeMethodReflection('resolved')))->toBeTrue();
    });

    it('returns false for other methods', function (string $method): void {
        expect($this->extension->isStaticMethodSupported(makeMethodReflection($method)))->toBeFalse();
    })->with(['rejected', 'all', 'race', 'any', 'resolvedWith']);
});

describe('getTypeFromStaticMethodCall()', function (): void {
    it('returns PromiseInterface<void> when no argument is passed', function (): void {
        $result = $this->extension->getTypeFromStaticMethodCall(
            makeMethodReflection('resolved'),
            makeStaticCall([]),
            Mockery::mock(Scope::class),
        );

        expect($result)->toEqual(new GenericObjectType(PromiseInterface::class, [new VoidType()]));
    });

    it('returns PromiseInterface<null> when explicit null is passed', function (): void {
        $argExpr = Mockery::mock(Expr::class);
        $scope = Mockery::mock(Scope::class);
        $scope->allows('getType')->with($argExpr)->andReturn(new NullType());

        $result = $this->extension->getTypeFromStaticMethodCall(
            makeMethodReflection('resolved'),
            makeStaticCall([$argExpr]),
            $scope,
        );

        expect($result)->toEqual(new GenericObjectType(PromiseInterface::class, [new NullType()]));
    });

    it('returns PromiseInterface<string> when a string type is passed', function (): void {
        $argExpr = Mockery::mock(Expr::class);
        $scope = Mockery::mock(Scope::class);
        $scope->allows('getType')->with($argExpr)->andReturn(new StringType());

        $result = $this->extension->getTypeFromStaticMethodCall(
            makeMethodReflection('resolved'),
            makeStaticCall([$argExpr]),
            $scope,
        );

        expect($result)->toEqual(new GenericObjectType(PromiseInterface::class, [new StringType()]));
    });

    it('returns PromiseInterface<int> when an int type is passed', function (): void {
        $argExpr = Mockery::mock(Expr::class);
        $scope = Mockery::mock(Scope::class);
        $scope->allows('getType')->with($argExpr)->andReturn(new IntegerType());

        $result = $this->extension->getTypeFromStaticMethodCall(
            makeMethodReflection('resolved'),
            makeStaticCall([$argExpr]),
            $scope,
        );

        expect($result)->toEqual(new GenericObjectType(PromiseInterface::class, [new IntegerType()]));
    });
});
