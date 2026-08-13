<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader;

use Doctrine\DBAL\Types\Type as DbalType;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kyzegs\DoctrineEntityPreloader\Preload;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Bot;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment;
use KyzegsTests\DoctrineEntityPreloader\Lib\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EntityPreloadBlogOneHasManyAbstractTest extends TestCase
{

    #[DataProvider('providePrimaryKeyTypes')]
    public function testOneHasManyAbstractUnoptimized(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 5, commentForEachArticleCount: 5);

        $articles = $this->getEntityManager()->getRepository(Article::class)->findAll();

        $this->readComments($articles);

        self::assertAggregatedQueries([
            ['count' => 1, 'query' => 'SELECT * FROM article t0'],
            ['count' => 5, 'query' => 'SELECT * FROM comment t0 WHERE t0.article_id = ? ORDER BY t0.id DESC'],
            ['count' => 25, 'query' => 'SELECT * FROM contributor t0 WHERE t0.id = ? AND t0.dtype IN (?)'],
        ]);
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testOneHasManyAbstractWithFetchJoin(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 5, commentForEachArticleCount: 5);

        $articles = $this->getEntityManager()->createQueryBuilder()
            ->select('article', 'comment', 'author')
            ->from(Article::class, 'article')
            ->leftJoin('article.comments', 'comment')
            ->leftJoin('comment.author', 'author')
            ->getQuery()
            ->getResult();

        $this->readComments($articles);

        self::assertAggregatedQueries([
            ['count' => 1, 'query' => 'SELECT * FROM article a0_ LEFT JOIN comment c1_ ON a0_.id = c1_.article_id LEFT JOIN contributor c2_ ON c1_.author_id = c2_.id AND c2_.dtype IN (?) ORDER BY c1_.id DESC'],
        ]);
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testOneHasManyAbstractWithEagerFetchMode(DbalType $primaryKey): void
    {
        $this->skipIfDoctrineOrmHasBrokenUnhandledMatchCase();
        $this->skipIfDoctrineOrmHasBrokenEagerFetch($primaryKey);
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 5, commentForEachArticleCount: 5);

        $articles = $this->getEntityManager()->createQueryBuilder()
            ->select('article')
            ->from(Article::class, 'article')
            ->getQuery()
            ->setFetchMode(Article::class, 'comments', ClassMetadata::FETCH_EAGER)
            ->setFetchMode(Comment::class, 'author', ClassMetadata::FETCH_EAGER)
            ->getResult();

        $this->readComments($articles);

        self::assertAggregatedQueries([
            ['count' => 1, 'query' => 'SELECT * FROM article a0_'],
            ['count' => 1, 'query' => 'SELECT * FROM comment t0 WHERE t0.article_id IN (?, ?, ?, ?, ?) ORDER BY t0.id DESC'],
            ['count' => 25, 'query' => 'SELECT * FROM contributor t0 WHERE t0.id = ? AND t0.dtype IN (?)'],
        ]);
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testOneHasManyAbstractWithPreload(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 5, commentForEachArticleCount: 5);

        $articles = $this->getEntityManager()->getRepository(Article::class)->findAll();
        $this->getEntityPreloader()->preload($articles, 'comments');

        $this->readComments($articles);

        self::assertAggregatedQueries([
            ['count' => 1, 'query' => 'SELECT * FROM article t0'],
            ['count' => 1, 'query' => 'SELECT * FROM comment c0_ LEFT JOIN contributor c1_ ON c0_.author_id = c1_.id AND c1_.dtype IN (?) WHERE c0_.article_id IN (?, ?, ?, ?, ?) ORDER BY c0_.id DESC'],
        ]);
    }

    #[DataProvider('providePrimaryKeyTypes')]
    public function testNestedPreloadResolvesAssociationDeclaredOnSubclass(DbalType $primaryKey): void
    {
        $this->createDummyBlogData($primaryKey, categoryCount: 1, articleInEachCategoryCount: 2, commentForEachArticleCount: 2);

        $botComments = $this->loadBotComments();
        self::assertNotSame([], $botComments);

        // The declared target of Comment::$author is the abstract Contributor, which has no 'activePrompt'.
        $this->getEntityPreloader()->preload($botComments, [
            'author' => Preload::association()->preload(['activePrompt']),
        ]);

        foreach ($botComments as $botComment) {
            $author = $botComment->getAuthor();
            self::assertInstanceOf(Bot::class, $author);
            self::assertFalse($this->getEntityManager()->isUninitializedObject($author->getActivePrompt()));
        }
    }

    /**
     * @return list<Comment>
     */
    private function loadBotComments(): array
    {
        $comments = $this->getEntityManager()->createQueryBuilder()
            ->select('comment')
            ->from(Comment::class, 'comment')
            ->getQuery()
            ->getResult();

        $botComments = [];

        foreach ($comments as $comment) {
            if (!$comment instanceof Comment) {
                continue;
            }

            if ($comment->getAuthor() instanceof Bot) {
                $botComments[] = $comment;
            }
        }

        return $botComments;
    }

    /**
     * @param array<Article> $articles
     */
    private function readComments(array $articles): void
    {
        foreach ($articles as $article) {
            foreach ($article->getComments() as $comment) {
                $comment->getContent();
            }
        }
    }

}
