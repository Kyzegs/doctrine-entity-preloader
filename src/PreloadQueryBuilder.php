<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use Doctrine\ORM\QueryBuilder;

final class PreloadQueryBuilder
{

    public function __construct(
        private QueryBuilder $queryBuilder,
    )
    {
    }

    public function andWhere(string $dql): self
    {
        $this->queryBuilder->andWhere($dql);
        return $this;
    }

    public function orWhere(string $dql): self
    {
        $this->queryBuilder->orWhere($dql);
        return $this;
    }

    public function join(
        string $association,
        string $alias,
        ?string $condition = null,
    ): self
    {
        if ($condition !== null) {
            $this->queryBuilder->join($association, $alias, 'WITH', $condition);
        } else {
            $this->queryBuilder->join($association, $alias);
        }

        return $this;
    }

    public function leftJoin(
        string $association,
        string $alias,
        ?string $condition = null,
    ): self
    {
        if ($condition !== null) {
            $this->queryBuilder->leftJoin($association, $alias, 'WITH', $condition);
        } else {
            $this->queryBuilder->leftJoin($association, $alias);
        }

        return $this;
    }

    public function setParameter(
        string $name,
        mixed $value,
    ): self
    {
        $this->queryBuilder->setParameter($name, $value);
        return $this;
    }

    public function addOrderBy(
        string $sort,
        string $order = 'ASC',
    ): self
    {
        $this->queryBuilder->addOrderBy($sort, $order);
        return $this;
    }

    public function getDoctrineQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

}
