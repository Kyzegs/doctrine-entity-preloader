<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\DBAL\Types\Type as DbalType;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Tag;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function array_map;
use function count;
use function iterator_to_array;

class EntityPreloadBlogManyHasManyOrderedTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testPreloadOrderedManyToManyMatchesLazyLoadedOrder(DbalType $primaryKey): void
    {
        $this->createDummyCategoriesWithTags($primaryKey, categoryCount: 2, tagForEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, 'tags');

        $queryCountBeforeAccess = count($this->getQueryLogger()->getQueries());

        foreach ($categories as $category) {
            self::assertSame(['Tag#2', 'Tag#1', 'Tag#0'], self::labelsOf($category));
        }

        self::assertCount($queryCountBeforeAccess, $this->getQueryLogger()->getQueries());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testPreloadedOrderMatchesDoctrineLazyLoading(DbalType $primaryKey): void
    {
        $this->createDummyCategoriesWithTags($primaryKey, categoryCount: 1, tagForEachCategoryCount: 4);

        $lazyLoaded = self::labelsOf($this->getEntityManager()->getRepository(Category::class)->findAll()[0]);

        $this->getEntityManager()->clear();
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();
        $this->getEntityPreloader()->preload($categories, 'tags');

        self::assertSame($lazyLoaded, self::labelsOf($categories[0]));
    }

    /**
     * @return list<string>
     */
    private static function labelsOf(Category $category): array
    {
        return array_map(
            static fn (Tag $tag): string => $tag->getLabel(),
            iterator_to_array($category->getTags(), false),
        );
    }

    private function createDummyCategoriesWithTags(
        DbalType $primaryKey,
        int $categoryCount,
        int $tagForEachCategoryCount,
    ): void
    {
        $this->initializeEntityManager($primaryKey, $this->getQueryLogger());
        $entityManager = $this->getEntityManager();

        for ($i = 0; $i < $categoryCount; $i++) {
            $category = new Category("Category#{$i}");
            $entityManager->persist($category);

            for ($j = 0; $j < $tagForEachCategoryCount; $j++) {
                $tag = new Tag("Tag#{$j}");
                $entityManager->persist($tag);
                $category->addTag($tag);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
        $this->getQueryLogger()->clear();
    }

}
