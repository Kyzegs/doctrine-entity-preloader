<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic\ClassTableInheritanceMultiLevel;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'mutli_level_cti_entity')]
class ConcreteCtiEntityWithOptionalRelationToMiddleLevelParent extends AbstractMiddleLevelCtiEntityWithOptionalRelationToMiddleLevel
{

}
