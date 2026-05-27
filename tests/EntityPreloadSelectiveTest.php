<?php declare(strict_types = 1);

namespace ShipMonkTests\DoctrineEntityPreloader;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipMonk\DoctrineEntityPreloader\Exception\DirtyCollectionException;
use ShipMonk\DoctrineEntityPreloader\Exception\InvalidAssociationException;
use ShipMonk\DoctrineEntityPreloader\Exception\UnsafePartialCollectionException;
use ShipMonk\DoctrineEntityPreloader\Exception\UnsupportedPreloadLimitException;
use ShipMonk\DoctrineEntityPreloader\Preload;
use ShipMonk\DoctrineEntityPreloader\PreloadQueryBuilder;
use ShipMonkTests\DoctrineEntityPreloader\Fixtures\Blog\Article;
use ShipMonkTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use ShipMonkTests\DoctrineEntityPreloader\Fixtures\Blog\Comment;
use ShipMonkTests\DoctrineEntityPreloader\Fixtures\Blog\Tag;
use ShipMonkTests\DoctrineEntityPreloader\Lib\TestCase;
use function count;
use function iterator_to_array;

class EntityPreloadSelectiveTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testCriteriaPreloadFiltersOneToManyAndInitializesCollection(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 5);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();
        $firstCategory = $categories[0];
        $articlesCollection = $firstCategory->getArticles();
        self::assertInstanceOf(PersistentCollection::class, $articlesCollection);
        self::assertFalse($articlesCollection->isInitialized());

        $this->getEntityPreloader()->preload($categories, [
            'articles' => Preload::criteria(
                Criteria::create()
                    ->where(Criteria::expr()->eq('title', 'Article#0'))
                    ->orderBy(['id' => Criteria::DESC]),
            ),
        ]);

        self::assertTrue($articlesCollection->isInitialized());
        $filteredArticles = iterator_to_array($firstCategory->getArticles(), false);
        self::assertCount(1, $filteredArticles);
        self::assertSame('Article#0', $filteredArticles[0]->getTitle());

        $aggregated = $this->getQueryLogger()->getAggregatedQueries();
        self::assertCount(2, $aggregated);
        self::assertSame(1, $aggregated[0]['count']);
        self::assertSame(1, $aggregated[1]['count']);

        $uow = $this->getEntityManager()->getUnitOfWork();
        self::assertCount(0, $uow->getScheduledCollectionUpdates());
        self::assertCount(0, $uow->getScheduledCollectionDeletions());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testQueryCustomizerSupportsJoinWhereAndParameter(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, articleInEachCategoryCount: 3, commentForEachArticleCount: 4);
        $articles = $this->getEntityManager()->getRepository(Article::class)->findAll();

        $this->getEntityPreloader()->preload($articles, [
            'comments' => Preload::query(
                static function (PreloadQueryBuilder $query): void {
                    $query
                        ->join('entity.author', 'author')
                        ->andWhere('author.name = :authorName')
                        ->setParameter('authorName', 'User#0')
                        ->addOrderBy('entity.id', 'ASC');
                },
            ),
        ]);

        $commentsCollection = $articles[0]->getComments();
        self::assertInstanceOf(PersistentCollection::class, $commentsCollection);
        self::assertTrue($commentsCollection->isInitialized());
        self::assertGreaterThanOrEqual(1, count($commentsCollection));

        foreach ($commentsCollection as $comment) {
            self::assertInstanceOf(Comment::class, $comment);
            self::assertSame('User#0', $comment->getAuthor()->getName());
        }
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testNestedCustomizedPreloadWorks(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 2, commentForEachArticleCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, [
            'articles' => Preload::criteria(
                Criteria::create()->where(Criteria::expr()->eq('title', 'Article#0')),
            )->preload([
                'comments' => Preload::criteria(
                    Criteria::create()->where(Criteria::expr()->eq('content', 'Comment #0')),
                ),
            ]),
        ]);

        foreach ($categories as $category) {
            foreach ($category->getArticles() as $article) {
                $comments = iterator_to_array($article->getComments(), false);
                self::assertCount(1, $comments);
                self::assertSame('Comment #0', $comments[0]->getContent());
            }
        }
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testSelectiveManyToManyPreloadWorks(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, articleInEachCategoryCount: 2, tagForEachArticleCount: 3);
        $articles = $this->getEntityManager()->getRepository(Article::class)->findAll();

        $this->getEntityPreloader()->preload($articles, [
            'tags' => Preload::criteria(
                Criteria::create()->where(Criteria::expr()->eq('label', 'Tag#1')),
            ),
        ]);

        foreach ($articles as $article) {
            $tagsCollection = $article->getTags();
            self::assertInstanceOf(PersistentCollection::class, $tagsCollection);
            self::assertTrue($tagsCollection->isInitialized());
            $tags = iterator_to_array($tagsCollection, false);
            self::assertCount(1, $tags);
            self::assertInstanceOf(Tag::class, $tags[0]);
            self::assertSame('Tag#1', $tags[0]->getLabel());
        }
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testFullPreloadViaAssociationConfigKeepsLegacyInitialization(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, [
            'articles' => Preload::association(),
        ]);

        $firstCollection = $categories[0]->getArticles();
        self::assertInstanceOf(PersistentCollection::class, $firstCollection);
        self::assertTrue($firstCollection->isInitialized());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testRejectsToManyMaxResultsCriteria(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        self::assertException(
            UnsupportedPreloadLimitException::class,
            'Criteria::setMaxResults() is not supported for to-many selective preloads. It is a global limit, not per-parent limit.',
            function () use ($categories): void {
                $this->getEntityPreloader()->preload($categories, [
                    'articles' => Preload::criteria(
                        Criteria::create()
                            ->where(Criteria::expr()->contains('title', 'Article'))
                            ->setMaxResults(1),
                    ),
                ]);
            },
        );
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testEmptyOwnersReturnEmptyResultWithoutQueries(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 1);
        $this->getQueryLogger()->clear();

        $this->getEntityPreloader()->preload([], [
            'articles' => Preload::association(),
        ]);

        self::assertSame([], $this->getQueryLogger()->getQueries());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testInvalidAssociationSpecificationThrows(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 1);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        self::assertException(
            InvalidAssociationException::class,
            'Numeric preload keys must contain association names as strings.',
            function () use ($categories): void {
                $this->getEntityPreloader()->preload($categories, [
                    Preload::association(),
                ]);
            },
        );
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testDirtyCollectionIsNotOverwritten(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3);
        $category = $this->getEntityManager()->getRepository(Category::class)->findAll()[0];

        $collection = $category->getArticles();
        self::assertInstanceOf(PersistentCollection::class, $collection);
        $collection->add(new Article('Manual', 'manual-content'));
        self::assertTrue($collection->isDirty());

        self::assertException(
            DirtyCollectionException::class,
            "Association 'ShipMonkTests\\DoctrineEntityPreloader\\Fixtures\\Blog\\Category::articles' is dirty and cannot be selectively preloaded.",
            function () use ($category): void {
                $this->getEntityPreloader()->preload([$category], [
                    'articles' => Preload::criteria(
                        Criteria::create()->where(Criteria::expr()->eq('title', 'Article#0')),
                    ),
                ]);
            },
        );
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testInitializedCollectionThrowsUnlessReplaceEnabled(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3);
        $category = $this->getEntityManager()->getRepository(Category::class)->findAll()[0];

        $initialCollection = $category->getArticles();
        self::assertInstanceOf(PersistentCollection::class, $initialCollection);
        self::assertFalse($initialCollection->isInitialized());
        $initialCollection->count();
        self::assertTrue($initialCollection->isInitialized());

        self::assertException(
            UnsafePartialCollectionException::class,
            "Association 'ShipMonkTests\\DoctrineEntityPreloader\\Fixtures\\Blog\\Category::articles' is already initialized. Use replaceInitializedCollection() to allow selective overwrite.",
            function () use ($category): void {
                $this->getEntityPreloader()->preload([$category], [
                    'articles' => Preload::criteria(
                        Criteria::create()->where(Criteria::expr()->eq('title', 'Article#0')),
                    ),
                ]);
            },
        );

        $this->getEntityPreloader()->preload([$category], [
            'articles' => Preload::criteria(
                Criteria::create()->where(Criteria::expr()->eq('title', 'Article#0')),
            )->replaceInitializedCollection(),
        ]);

        $afterReplace = iterator_to_array($category->getArticles(), false);
        self::assertCount(1, $afterReplace);
        self::assertSame('Article#0', $afterReplace[0]->getTitle());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testAccessAfterSelectivePreloadDoesNotTriggerLazyLoadQuery(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 2, articleInEachCategoryCount: 5);
        $categories = $this->getEntityManager()->getRepository(Category::class)->findAll();

        $this->getEntityPreloader()->preload($categories, [
            'articles' => Preload::criteria(
                Criteria::create()->where(Criteria::expr()->eq('title', 'Article#0')),
            ),
        ]);

        $queryCountBeforeAccess = count($this->getQueryLogger()->getQueries());
        foreach ($categories as $category) {
            foreach ($category->getArticles() as $article) {
                $article->getTitle();
            }
        }
        $queryCountAfterAccess = count($this->getQueryLogger()->getQueries());

        self::assertSame($queryCountBeforeAccess, $queryCountAfterAccess);
    }

}
