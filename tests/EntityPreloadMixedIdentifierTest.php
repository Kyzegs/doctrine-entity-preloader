<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\PersistentCollection;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Tag;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\MixedIdentifier\Bookmark;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function count;
use function iterator_to_array;

class EntityPreloadMixedIdentifierTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testManyToManyPreloadUsesTargetIdentifierType(DbalType $primaryKey): void
    {
        $this->createBookmarkData($primaryKey);
        $bookmarks = $this->getEntityManager()->getRepository(Bookmark::class)->findAll();
        $this->getQueryLogger()->clear();

        $tags = $this->getEntityPreloader()->preload($bookmarks, 'tags');

        self::assertCount(2, $tags);

        $queryCountAfterPreload = count($this->getQueryLogger()->getQueries());

        foreach ($bookmarks as $bookmark) {
            $tagsCollection = $bookmark->getTags();
            self::assertInstanceOf(PersistentCollection::class, $tagsCollection);
            self::assertTrue($tagsCollection->isInitialized());

            $labels = [];
            foreach (iterator_to_array($tagsCollection, false) as $tag) {
                self::assertInstanceOf(Tag::class, $tag);
                $labels[] = $tag->getLabel();
            }

            self::assertSame(['Tag#0', 'Tag#1'], $labels);
        }

        self::assertCount($queryCountAfterPreload, $this->getQueryLogger()->getQueries());
    }

    private function createBookmarkData(DbalType $primaryKey): void
    {
        $this->initializeEntityManager($primaryKey, $this->getQueryLogger());
        $entityManager = $this->getEntityManager();

        $tags = [new Tag('Tag#0'), new Tag('Tag#1')];

        foreach ($tags as $tag) {
            $entityManager->persist($tag);
        }

        for ($i = 0; $i < 2; $i++) {
            $bookmark = new Bookmark($i);

            foreach ($tags as $tag) {
                $bookmark->addTag($tag);
            }

            $entityManager->persist($bookmark);
        }

        $entityManager->flush();
        $entityManager->clear();
        $this->getQueryLogger()->clear();
    }

}
