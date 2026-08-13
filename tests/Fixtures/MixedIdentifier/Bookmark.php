<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\MixedIdentifier;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ReadableCollection;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToMany;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Tag;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic\TestEntityWithId;

/**
 * Owner with a plain integer identifier pointing at a target with a custom identifier type.
 */
#[Entity]
class Bookmark extends TestEntityWithId
{

    /**
     * @var Collection<int, Tag>
     */
    #[ManyToMany(targetEntity: Tag::class)]
    private Collection $tags;

    public function __construct(?int $number = null)
    {
        $this->number = $number;
        $this->tags = new ArrayCollection();
    }

    /**
     * @return ReadableCollection<int, Tag>
     */
    public function getTags(): ReadableCollection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

}
