<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic\OneToOne\OneToOneUnidirectionalAbstract;

use Doctrine\ORM\Mapping\Entity;

#[Entity]
class EntityWithOneToOneUnidirectionalAbstractTargetChildB extends EntityWithOneToOneUnidirectionalAbstractTarget
{

}
