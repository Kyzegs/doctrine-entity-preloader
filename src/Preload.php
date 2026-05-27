<?php declare(strict_types = 1);

namespace ShipMonk\DoctrineEntityPreloader;

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

}
