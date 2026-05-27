<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use Closure;
use Doctrine\Common\Collections\Criteria;

final class PreloadConfig
{

    /**
     * @param array<int|string, string|PreloadConfig> $nestedPreload
     * @param (callable(PreloadQueryBuilder): void)|null $queryCustomizer
     */
    public function __construct(
        private ?Criteria $criteria = null,
        private $queryCustomizer = null,
        private array $nestedPreload = [],
        private PreloadHydrationMode $hydrationMode = PreloadHydrationMode::FullAssociation,
        private ?int $perParentLimit = null,
        private bool $replaceInitializedCollection = false,
    )
    {
    }

    public function criteria(Criteria $criteria): self
    {
        $clone = clone $this;
        $clone->criteria = $criteria;
        $clone->hydrationMode = PreloadHydrationMode::PartialCollection;
        return $clone;
    }

    /**
     * @param callable(PreloadQueryBuilder): void $customizer
     */
    public function query(callable $customizer): self
    {
        $clone = clone $this;
        $clone->queryCustomizer = $customizer instanceof Closure ? $customizer : Closure::fromCallable($customizer);
        $clone->hydrationMode = PreloadHydrationMode::PartialCollection;
        return $clone;
    }

    /**
     * @param array<int|string, string|PreloadConfig> $preload
     */
    public function preload(array $preload): self
    {
        $clone = clone $this;
        $clone->nestedPreload = $preload;
        return $clone;
    }

    public function hydrateAsPartialCollection(): self
    {
        return $this->hydrationMode(PreloadHydrationMode::PartialCollection);
    }

    public function hydrationMode(PreloadHydrationMode $hydrationMode): self
    {
        $clone = clone $this;
        $clone->hydrationMode = $hydrationMode;
        return $clone;
    }

    public function perParentLimit(int $limit): self
    {
        $clone = clone $this;
        $clone->perParentLimit = $limit;
        return $clone;
    }

    public function replaceInitializedCollection(): self
    {
        $clone = clone $this;
        $clone->replaceInitializedCollection = true;
        return $clone;
    }

    public function getCriteria(): ?Criteria
    {
        return $this->criteria;
    }

    /**
     * @return (callable(PreloadQueryBuilder): void)|null
     */
    public function getQueryCustomizer(): ?callable
    {
        return $this->queryCustomizer;
    }

    /**
     * @return array<int|string, string|PreloadConfig>
     */
    public function getNestedPreload(): array
    {
        return $this->nestedPreload;
    }

    public function getHydrationMode(): PreloadHydrationMode
    {
        return $this->hydrationMode;
    }

    public function getPerParentLimit(): ?int
    {
        return $this->perParentLimit;
    }

    public function shouldReplaceInitializedCollection(): bool
    {
        return $this->replaceInitializedCollection;
    }

}
