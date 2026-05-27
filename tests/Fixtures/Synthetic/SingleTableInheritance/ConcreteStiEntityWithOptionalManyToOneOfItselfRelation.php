<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic\SingleTableInheritance;

use Doctrine\ORM\Mapping\Entity;

#[Entity]
class ConcreteStiEntityWithOptionalManyToOneOfItselfRelation extends AbstractStiEntityWithOptionalManyToOneItselfRelation
{

}
