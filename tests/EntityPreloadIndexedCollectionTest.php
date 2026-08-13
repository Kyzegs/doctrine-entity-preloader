<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Type as DbalType;
use Kyzegs\DoctrineEntityPreloader\Preload;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_keys;
use function count;
use function iterator_to_array;
use function sort;

class EntityPreloadIndexedCollectionTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testPreloadKeepsIndexedCollectionKeys(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, 'articlesByTitle');

        $queryCountBeforeAccess = count($this->getQueryLogger()->getQueries());

        foreach ($categories as $category) {
            $articlesByTitle = $category->getArticlesByTitle();

            // The association has no orderBy, so row order is up to the platform - only the key set matters.
            $titles = array_keys(iterator_to_array($articlesByTitle, true));
            sort($titles);

            self::assertSame(['Article#0', 'Article#1', 'Article#2'], $titles);
            self::assertSame('Article#1', $articlesByTitle->get('Article#1')?->getTitle());
        }

        self::assertCount($queryCountBeforeAccess, $this->getQueryLogger()->getQueries());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testSelectivePreloadKeepsIndexedCollectionKeys(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, [
            'articlesByTitle' => Preload::criteria(
                Criteria::create(true)->where(Criteria::expr()->eq('title', 'Article#1')),
            ),
        ]);

        foreach ($categories as $category) {
            $articlesByTitle = $category->getArticlesByTitle();
            self::assertSame(['Article#1'], array_keys(iterator_to_array($articlesByTitle, true)));
            self::assertSame('Article#1', $articlesByTitle->get('Article#1')?->getTitle());
        }
    }

}
