<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\DBAL\Types\Type as DbalType;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function count;

class EntityPreloadIterableSourcesTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testPreloadAcceptsDoctrineCollectionAsSource(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3, commentForEachArticleCount: 2);
        $category = $this->getEntityManager()->getRepository(Category::class)->findAll()[0];

        $this->getEntityPreloader()->preload($category->getArticles(), 'comments');

        $queryCountBeforeAccess = count($this->getQueryLogger()->getQueries());

        foreach ($category->getArticles() as $article) {
            self::assertCount(2, $article->getComments());
        }

        self::assertCount($queryCountBeforeAccess, $this->getQueryLogger()->getQueries());
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testPreloadAcceptsGeneratorAndNonListArrayAsSource(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 3, commentForEachArticleCount: 2);
        $articles = $this->getEntityManager()->getRepository(Article::class)->findAll();

        $this->getEntityPreloader()->preload(
            (static function () use ($articles): iterable {
                yield from $articles;
            })(),
            'comments',
        );

        $this->getEntityManager()->clear();
        $articlesKeyedByTitle = [];

        foreach ($this->getEntityManager()->getRepository(Article::class)->findAll() as $article) {
            $articlesKeyedByTitle[$article->getTitle()] = $article;
        }

        $this->getEntityPreloader()->preload($articlesKeyedByTitle, 'comments');

        $queryCountBeforeAccess = count($this->getQueryLogger()->getQueries());

        foreach ($articlesKeyedByTitle as $article) {
            self::assertCount(2, $article->getComments());
        }

        self::assertCount($queryCountBeforeAccess, $this->getQueryLogger()->getQueries());
    }

}
