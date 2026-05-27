<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
class EntityWithManyToOneAbstractEntityWithNoRelations extends TestEntityWithId
{

    #[ManyToOne(targetEntity: AbstractEntityWithNoRelations::class)]
    #[JoinColumn(nullable: false)]
    private AbstractEntityWithNoRelations $abstractEntityWithNoRelations;

    public function __construct(AbstractEntityWithNoRelations $abstractEntityWithNoRelations)
    {
        $this->abstractEntityWithNoRelations = $abstractEntityWithNoRelations;
    }

}
