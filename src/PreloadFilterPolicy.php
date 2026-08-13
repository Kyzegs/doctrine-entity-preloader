<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use function array_key_exists;

final class PreloadFilterPolicy
{

    /**
     * @param array<string, bool> $filterStates
     * @param array<string, array<string, mixed>> $filterParameters
     */
    public function __construct(
        private array $filterStates = [],
        private array $filterParameters = [],
    )
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function enableFilters(string ...$filterNames): self
    {
        $clone = clone $this;
        foreach ($filterNames as $filterName) {
            $clone->filterStates[$filterName] = true;
        }

        return $clone;
    }

    public function disableFilters(string ...$filterNames): self
    {
        $clone = clone $this;
        foreach ($filterNames as $filterName) {
            $clone->filterStates[$filterName] = false;
        }

        return $clone;
    }

    public function withFilterParameter(
        string $filterName,
        string $parameterName,
        mixed $value,
    ): self
    {
        $clone = clone $this;
        $clone->filterParameters[$filterName] ??= [];
        $clone->filterParameters[$filterName][$parameterName] = $value;
        return $clone;
    }

    public function merge(self $override): self
    {
        $mergedFilterStates = $this->filterStates;
        foreach ($override->filterStates as $filterName => $state) {
            $mergedFilterStates[$filterName] = $state;
        }

        $mergedFilterParameters = $this->filterParameters;
        foreach ($override->filterParameters as $filterName => $parameters) {
            $mergedFilterParameters[$filterName] ??= [];
            foreach ($parameters as $parameterName => $value) {
                $mergedFilterParameters[$filterName][$parameterName] = $value;
            }
        }

        return new self($mergedFilterStates, $mergedFilterParameters);
    }

    /**
     * @return array<string, bool>
     */
    public function getFilterStates(): array
    {
        return $this->filterStates;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getFilterParameters(): array
    {
        return $this->filterParameters;
    }

    public function isEmpty(): bool
    {
        if ($this->filterStates !== []) {
            return false;
        }

        foreach ($this->filterParameters as $filterParameters) {
            if ($filterParameters !== []) {
                return false;
            }
        }

        return true;
    }

    public function hasExplicitState(string $filterName): bool
    {
        return array_key_exists($filterName, $this->filterStates);
    }

}
