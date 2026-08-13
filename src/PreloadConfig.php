<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use Closure;
use Doctrine\Common\Collections\Criteria;
use Kyzegs\DoctrineEntityPreloader\Exception\LogicException;

final class PreloadConfig
{

    /**
     * @param array<int|string, string|PreloadConfig> $nestedPreload
     * @param (callable(PreloadQueryBuilder): void)|null $queryCustomizer
     * @param positive-int|null $limitPerParent
     */
    public function __construct(
        private ?Criteria $criteria = null,
        private $queryCustomizer = null,
        private array $nestedPreload = [],
        private bool $replaceInitializedCollection = false,
        private ?PreloadFilterPolicy $filterPolicy = null,
        private ?int $limitPerParent = null,
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

    /**
     * Keeps at most $limit targets per owner collection, where Criteria::setMaxResults() is a global row limit.
     * The database still matches every row; only the survivors are hydrated as entities.
     */
    public function limitPerParent(int $limit): self
    {
        if ($limit < 1) {
            throw new LogicException('Preload limit per parent must be at least 1.');
        }

        $clone = clone $this;
        $clone->limitPerParent = $limit;
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

    /**
     * @return positive-int|null
     */
    public function getLimitPerParent(): ?int
    {
        return $this->limitPerParent;
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
