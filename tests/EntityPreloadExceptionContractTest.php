<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\DBAL\Types\Type as DbalType;
use Kyzegs\DoctrineEntityPreloader\Exception\LogicException;
use Kyzegs\DoctrineEntityPreloader\Preload;
use Kyzegs\DoctrineEntityPreloader\PreloadQueryBuilder;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\PrimaryKey;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Synthetic\EntityWithNoRelations;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function iterator_to_array;

class EntityPreloadExceptionContractTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testUnrelatedSourceEntitiesThrowPackageLogicException(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 1);
        $category = $this->getEntityManager()->getRepository(Category::class)->findAll()[0];

        $unrelatedEntities = self::unrelatedEntities($category);

        self::assertException(
            LogicException::class,
            'Given entities must have a common ancestor',
            function () use ($unrelatedEntities): void {
                $this->getEntityPreloader()->preload($unrelatedEntities, 'articles'); // @phpstan-ignore kyzegs.entityPreloader (source list is heterogeneous on purpose)
            },
        );
    }

    /**
     * Deliberately heterogeneous, and typed as plain objects so the bundled PHPStan rule
     * has no single entity class to resolve 'articles' against.
     *
     * @return list<object>
     */
    private static function unrelatedEntities(Category $category): array
    {
        return [$category, new EntityWithNoRelations()];
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testQueryCustomizerCanBindParameterWithExplicitDbalType(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();
        $wantedArticleId = $this->getEntityManager()->getRepository(Article::class)->findAll()[1]->getId();

        $this->getEntityPreloader()->preload($categories, [
            'articles' => Preload::query(
                static function (PreloadQueryBuilder $query) use ($wantedArticleId): void {
                    $query
                        ->andWhere('entity.id = :wantedArticleId')
                        ->setParameter('wantedArticleId', $wantedArticleId, PrimaryKey::DOCTRINE_TYPE_NAME);
                },
            ),
        ]);

        $preloadedArticles = iterator_to_array($categories[0]->getArticles(), false);
        self::assertCount(1, $preloadedArticles);
        self::assertSame('Article#1', $preloadedArticles[0]->getTitle());
    }

}
