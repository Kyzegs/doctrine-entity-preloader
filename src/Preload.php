<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use Doctrine\Common\Collections\Criteria;

final class Preload
{

    private function __construct()
    {
    }

    public static function association(): PreloadConfig
    {
        return new PreloadConfig();
    }

    public static function criteria(Criteria $criteria): PreloadConfig
    {
        return self::association()->criteria($criteria);
    }

    /**
     * @param callable(PreloadQueryBuilder): void $customizer
     */
    public static function query(callable $customizer): PreloadConfig
    {
        return self::association()->query($customizer);
    }

    public static function limitPerParent(int $limit): PreloadConfig
    {
        return self::association()->limitPerParent($limit);
    }

    public static function withFilterPolicy(PreloadFilterPolicy $filterPolicy): PreloadConfig
    {
        return self::association()->withFilterPolicy($filterPolicy);
    }

    public static function enableFilters(string ...$filterNames): PreloadConfig
    {
        return self::association()->enableFilters(...$filterNames);
    }

    public static function disableFilters(string ...$filterNames): PreloadConfig
    {
        return self::association()->disableFilters(...$filterNames);
    }

    public static function withoutFilters(string ...$filterNames): PreloadConfig
    {
        return self::association()->withoutFilters(...$filterNames);
    }

    public static function withFilterParameter(
        string $filterName,
        string $parameterName,
        mixed $value,
    ): PreloadConfig
    {
        return self::association()->withFilterParameter($filterName, $parameterName, $value);
    }

}
