<?php declare(strict_types = 1);
namespace Doctrine1\PHPStan;

use PhpParser\Node\Arg;
use PHPStan\Type\Type;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\StringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;

abstract class AbstractExtension
{
    /** @param Arg[] $args */
    protected function findArgInMethodCall(array $args, string $name): ?Arg
    {
        foreach ($args as $arg) {
            if ($arg->name?->name === $name) {
                return $arg;
            }
        }
        return null;
    }

    /** @param ParameterReflection[] $parameters */
    protected function findArg(string $name, MethodCall $methodCall, array $parameters): ?Arg
    {
        // find the hydrate_array argument by name or position
        foreach ($methodCall->args as $arg) {
            if ($arg->name?->name === $name) {
                return $arg;
            }
        }

        foreach ($parameters as $position => $param) {
            if ($param->getName() === $name) {
                if (count($methodCall->args) > $position) {
                    return $methodCall->args[$position];
                }
                return null;
            }
        }

        return null;
    }

    protected function getSelfClassNameFromScope(MethodCall $methodCall, Scope $scope): ?string
    {
        $vartype = $scope->getType($methodCall->var);
        if ($vartype instanceof ThisType) {
            // $this
            return $vartype->getBaseClass();
        } elseif ($vartype instanceof ObjectType) {
            // object var
            return $vartype->getClassName();
        }
        return null;
    }
}
