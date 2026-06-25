<?php declare(strict_types = 1);

namespace KyzegsTests\DoctrineEntityPreloader\Fixtures\Blog;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
class Comment extends TestEntityWithCustomPrimaryKey
{

    #[ManyToOne(targetEntity: Article::class, inversedBy: 'comments')]
    private Article $article;

    #[ManyToOne(targetEntity: Contributor::class, inversedBy: 'comments')]
    private Contributor $author;

    #[Column]
    private string $content;

    #[Column]
    private bool $deleted;

    public function __construct(
        Article $article,
        Contributor $author,
        string $content,
    )
    {
        parent::__construct();
        $this->article = $article;
        $this->author = $author;
        $this->content = $content;
        $this->deleted = false;

        $article->addComment($this);
        $author->addComment($this);
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getAuthor(): Contributor
    {
        return $this->author;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function markDeleted(): void
    {
        $this->deleted = true;
    }

}
