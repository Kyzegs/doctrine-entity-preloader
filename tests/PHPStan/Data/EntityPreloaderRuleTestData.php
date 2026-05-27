<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\PHPStan\Data;

use Doctrine\ORM\EntityManagerInterface;
use Kyzegs\DoctrineEntityPreloader\EntityPreloader;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Bot;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\BotPromptVersion;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Contributor;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\PasswordVerifier;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Tag;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\User;
use KyzegsTests\DoctrineEntityPreloader\Fixtures\Issue37\Employee;
use function PHPStan\Testing\assertType;

final class EntityPreloaderRuleTestData
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityPreloader $entityPreloader,
    )
    {
    }

    public function preloadVariablePropertyName(string $propertyName): void
    {
        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        assertType('list<object>', $this->entityPreloader->preload($categories, $propertyName)); // error: Second argument to function EntityPreloader::preload() must be constant string
    }

    public function preloadOneHasMany(): void
    {
        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article>', $this->entityPreloader->preload($categories, 'articles'));
        assertType('list<object>', $this->entityPreloader->preload($categories, 'notFound')); // error: Property 'KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category::$notFound' not found.
        assertType('list<object>', $this->entityPreloader->preload($categories, 'name')); // error: Property 'KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category::$name' is not a valid Doctrine association.
        assertType('list<object>', $this->entityPreloader->preload($categories, 'id')); // error: Property 'KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category::$id' is not a valid Doctrine association.

        $bots = $this->entityManager->getRepository(Bot::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment>', $this->entityPreloader->preload($bots, 'comments'));
    }

    public function preloadOneHasManySelfReferencing(): void
    {
        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category>', $this->entityPreloader->preload($categories, 'children'));

        $articles = $this->entityManager->getRepository(Article::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment>', $this->entityPreloader->preload($articles, 'comments'));
    }

    public function preloadManyHasOne(): void
    {
        $articles = $this->entityManager->getRepository(Article::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category>', $this->entityPreloader->preload($articles, 'category'));

        $comments = $this->entityManager->getRepository(Comment::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article>', $this->entityPreloader->preload($comments, 'article'));
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Contributor>', $this->entityPreloader->preload($comments, 'author'));

        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Category>', $this->entityPreloader->preload($categories, 'parent'));
    }

    public function preloadManyHasMany(): void
    {
        $articles = $this->entityManager->getRepository(Article::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Tag>', $this->entityPreloader->preload($articles, 'tags'));

        $tags = $this->entityManager->getRepository(Tag::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article>', $this->entityPreloader->preload($tags, 'articles'));
    }

    public function preloadOneHasOne(): void
    {
        $bots = $this->entityManager->getRepository(Bot::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\BotPromptVersion>', $this->entityPreloader->preload($bots, 'activePrompt'));

        $botPromptVersions = $this->entityManager->getRepository(BotPromptVersion::class)->findAll();
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\BotPromptVersion>', $this->entityPreloader->preload($botPromptVersions, 'prevVersion'));
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\BotPromptVersion>', $this->entityPreloader->preload($botPromptVersions, 'nextVersion'));
    }

    public function preloadWithUnionTypes(): void
    {
        $bots = $this->entityManager->getRepository(Bot::class)->findAll();
        $users = $this->entityManager->getRepository(User::class)->findAll();
        $union = [...$bots, ...$users];
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment>', $this->entityPreloader->preload($union, 'comments'));

        $articles = $this->entityManager->getRepository(Article::class)->findAll();
        $comments = $this->entityManager->getRepository(Comment::class)->findAll();
        $union = [...$articles, ...$comments];
        assertType('list<object>', $this->entityPreloader->preload($union, 'foobar')); // error: Property 'KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Article::$foobar' not found.
        assertType('list<object>', $this->entityPreloader->preload($union, 'tags')); // error: Property 'KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment::$tags' not found.
    }

    /**
     * @param list<Contributor & PasswordVerifier> $entities
     */
    public function preloadWithInteractionTypes(array $entities): void
    {
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog\Comment>', $this->entityPreloader->preload($entities, 'comments'));
    }

    /**
     * @param list<object> $entities
     */
    public function preloadWithObject(array $entities): void
    {
        assertType('list<object>', $this->entityPreloader->preload($entities, 'foo')); // error: Property 'object::$foo' not found.
    }

    /**
     * @see https://github.com/shipmonk-rnd/doctrine-entity-preloader/issues/37
     */
    public function preloadWithoutExplicitTargetEntity(): void
    {
        $employees = $this->entityManager->getRepository(Employee::class)->findAll();

        // ManyToOne WITHOUT targetEntity attribute
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Issue37\Employee>', $this->entityPreloader->preload($employees, 'supervisor'));

        // OneToOne WITHOUT targetEntity attribute
        assertType('list<KyzegsTests\DoctrineEntityPreloader\Fixtures\Issue37\EmployeeSettings>', $this->entityPreloader->preload($employees, 'settings'));
    }

}
