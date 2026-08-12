<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use function sprintf;

final class SoftDeleteableFilter extends SQLFilter
{

    /**
     * @param ClassMetadata<object> $targetEntity
     * @param string $targetTableAlias Untyped in Doctrine ORM 2.19, so it cannot be narrowed here.
     */
    public function addFilterConstraint(
        ClassMetadata $targetEntity,
        $targetTableAlias,
    ): string
    {
        if (!$targetEntity->hasField('deleted')) {
            return '';
        }

        return sprintf('%s.deleted = %s', $targetTableAlias, $this->getParameter('deletedValue'));
    }

}
