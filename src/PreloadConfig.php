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
        private bool $replaceInitializedCollection = false,
        private ?PreloadFilterPolicy $filterPolicy = null,
    )
    {
    }

    public function criteria(Criteria $criteria): self
    {
        $clone = clone $this;
        $clone->criteria = $criteria;
        return $clone;
    }

    /**
     * @param callable(PreloadQueryBuilder): void $customizer
     */
    public function query(callable $customizer): self
    {
        $clone = clone $this;
        $clone->queryCustomizer = $customizer instanceof Closure ? $customizer : Closure::fromCallable($customizer);
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

    public function replaceInitializedCollection(): self
    {
        $clone = clone $this;
        $clone->replaceInitializedCollection = true;
        return $clone;
    }

    public function withFilterPolicy(PreloadFilterPolicy $filterPolicy): self
    {
        $clone = clone $this;
        $clone->filterPolicy = $filterPolicy;
        return $clone;
    }

    public function enableFilters(string ...$filterNames): self
    {
        $filterPolicy = $this->filterPolicy ?? PreloadFilterPolicy::create();
        return $this->withFilterPolicy($filterPolicy->enableFilters(...$filterNames));
    }

    public function disableFilters(string ...$filterNames): self
    {
        $filterPolicy = $this->filterPolicy ?? PreloadFilterPolicy::create();
        return $this->withFilterPolicy($filterPolicy->disableFilters(...$filterNames));
    }

    public function withoutFilters(string ...$filterNames): self
    {
        return $this->disableFilters(...$filterNames);
    }

    public function withFilterParameter(
        string $filterName,
        string $parameterName,
        mixed $value,
    ): self
    {
        $filterPolicy = $this->filterPolicy ?? PreloadFilterPolicy::create();
        return $this->withFilterPolicy($filterPolicy->withFilterParameter($filterName, $parameterName, $value));
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

    public function shouldReplaceInitializedCollection(): bool
    {
        return $this->replaceInitializedCollection;
    }

    public function getFilterPolicy(): ?PreloadFilterPolicy
    {
        return $this->filterPolicy;
    }

}
