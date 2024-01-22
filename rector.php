<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\StaticArrowFunctionRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Assign\NullCoalescingOperatorRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php74\Rector\FuncCall\ArrayKeyExistsOnPropertyRector;
use Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php81\Rector\ClassConst\FinalizePublicClassConstantRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddMethodCallBasedStrictParamTypeRector;
use Rector\ValueObject\PhpVersion;

return static function (RectorConfig $rectorConfig) : void {
    $rectorConfig->rule(StaticArrowFunctionRector::class);
    $rectorConfig->rule(NullCoalescingOperatorRector::class);
    $rectorConfig->rule(ClosureToArrowFunctionRector::class);
    $rectorConfig->rule(ArrayKeyExistsOnPropertyRector::class);
    $rectorConfig->rule(ParenthesizeNestedTernaryRector::class);
    $rectorConfig->rule(FirstClassCallableRector::class);
    $rectorConfig->rule(FinalizePublicClassConstantRector::class);
    $rectorConfig->rule(ReadOnlyPropertyRector::class);
    $rectorConfig->rule(AddTypeToConstRector::class);
    $rectorConfig->rule(AddArrowFunctionReturnTypeRector::class);
    $rectorConfig->rule(AddMethodCallBasedStrictParamTypeRector::class); //Seems to not consider re-using imported types / aliases

    $rectorConfig->paths([
        __DIR__ . '/src',
    ]);

    $rectorConfig->phpVersion(PhpVersion::PHP_83);
    $rectorConfig->parallel(600);
};
