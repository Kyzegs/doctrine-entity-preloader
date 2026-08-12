![Doctrine Entity Preloader banner](banner.svg)

# Doctrine Entity Preloader

`kyzegs/doctrine-entity-preloader` is a PHP library designed to tackle the n+1 query problem in Doctrine ORM by efficiently preloading related entities. This library offers a flexible and powerful way to optimize database access patterns, especially in cases with complex entity relationships.

- :rocket: **Performance Boost:** Minimizes n+1 issues by preloading related entities with **constant number of queries**.
- :arrows_counterclockwise: **Flexible:** Supports all associations: `#[OneToOne]`, `#[OneToMany]`, `#[ManyToOne]`, and `#[ManyToMany]`.
- :bulb: **Easy Integration:** Simple to integrate with your existing Doctrine setup (supports both v2 and v3).


## Comparison

|                                                                        | [Default](https://docs.google.com/presentation/d/1sSlZOxmEUVKt0l8zhimex-6lR0ilC001GXh8colaXxg/edit#slide=id.g30998e74a82_0_0) | [Manual](https://docs.google.com/presentation/d/1sSlZOxmEUVKt0l8zhimex-6lR0ilC001GXh8colaXxg/edit#slide=id.g309b68062f4_0_0) | [Fetch Join](https://docs.google.com/presentation/d/1sSlZOxmEUVKt0l8zhimex-6lR0ilC001GXh8colaXxg/edit#slide=id.g309b68062f4_0_15) | [setFetchMode](https://docs.google.com/presentation/d/1sSlZOxmEUVKt0l8zhimex-6lR0ilC001GXh8colaXxg/edit#slide=id.g309b68062f4_0_35) | [**EntityPreloader**](https://docs.google.com/presentation/d/1sSlZOxmEUVKt0l8zhimex-6lR0ilC001GXh8colaXxg/edit#slide=id.g309b68062f4_0_265) |
|------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| [OneToMany](tests/EntityPreloadBlogOneHasManyTest.php)                 | :red_circle: 1 + n                                                                                                            | :green_circle: 2                                                                                                             | :orange_circle: 1, but<br>duplicate rows                                                                                          | :green_circle: 2                                                                                                                    | :green_circle: 2                                                                                                                            |
| [OneToManyDeep](tests/EntityPreloadBlogOneHasManyDeepTest.php)         | :red_circle: 1 + n + n²                                                                                                       | :green_circle: 3                                                                                                             | :orange_circle: 1, but<br>duplicate rows                                                                                          | :red_circle: 2 + n²                                                                                                                 | :green_circle: 3                                                                                                                            |
| [OneToManyAbstract](tests/EntityPreloadBlogOneHasManyAbstractTest.php) | :red_circle: 1 + n + n²                                                                                                       | :orange_circle: 3, but<br>duplicate rows                                                                                     | :orange_circle: 1, but<br>duplicate rows                                                                                          | :red_circle: 2 + n²                                                                                                                 | :orange_circle: 3, but<br>duplicate rows                                                                                                    |
| [ManyToOne](tests/EntityPreloadBlogManyHasOneTest.php)                 | :red_circle: 1 + n                                                                                                            | :green_circle: 2                                                                                                             | :orange_circle: 1, but<br>duplicate rows                                                                                          | :green_circle: 2                                                                                                                    | :green_circle: 2                                                                                                                            |
| [ManyToOneDeep](tests/EntityPreloadBlogManyHasOneDeepTest.php)         | :red_circle: 1 + n + n                                                                                                        | :green_circle: 3                                                                                                             | :orange_circle: 1, but<br>duplicate rows                                                                                          | :red_circle: 2 + n                                                                                                                  | :green_circle: 3                                                                                                                            |
| [ManyToMany](tests/EntityPreloadBlogManyHasManyTest.php)               | :red_circle: 1 + n                                                                                                            | :green_circle: 2                                                                                                             | :orange_circle: 1, but<br>duplicate rows                                                                                          | :red_circle: 1 + n                                                                                                                  | :green_circle: 2                                                                                                                            |


### Comparison vs. Manual Preload

Unlike manual preload, the EntityPreloader does not require writing custom queries for each association.

### Comparison vs. Fetch Join

Unlike fetch joins, the EntityPreloader does not fetches duplicate data, which slows down both the query and the hydration process, except when necessary to prevent additional queries fired by Doctrine during hydration process.

The fetch join scales poorly with the number of associations. With every preloaded association, the number of duplicate rows grows. The EntityPreloader does not have this problem.

### Comparison vs. setFetchMode

Unlike `Doctrine\ORM\AbstractQuery::setFetchMode` it can

* preload nested associations
* preload `#[ManyToMany]` association
* avoid additional queries fired by Doctrine during hydration process


## Installation

To install the library, use Composer:

```sh
composer require kyzegs/doctrine-entity-preloader
```

### PHPStan

This library provides PHPStan integration:

- **extension.neon** - Infers return types for `EntityPreloader::preload()` based on the preloaded association
- **rules.neon** - Validates that the property name passed to `preload()` exists on the entity

If you use [PHPStan](https://phpstan.org/) and have [phpstan/extension-installer](https://github.com/phpstan/extension-installer) installed, the extension and rules are enabled automatically.

Otherwise, add the following to your `phpstan.neon`:

```neon
includes:
    - vendor/kyzegs/doctrine-entity-preloader/extension.neon
    - vendor/kyzegs/doctrine-entity-preloader/rules.neon
```

If [phpstan/phpstan-doctrine](https://github.com/phpstan/phpstan-doctrine) is installed and `objectManagerLoader` is used, all mapping formats become available (xml, phpdoc, yaml). Otherwise, only modern attributes are supported.

## Usage

Below is a basic example demonstrating how to use `EntityPreloader` to preload related entities and avoid the n+1 problem:

```php
use Kyzegs\DoctrineEntityPreloader\EntityPreloader;

$categories = $entityManager->getRepository(Category::class)->findAll();

$preloader = new EntityPreloader($entityManager);
$articles = $preloader->preload($categories, 'articles'); // 1 query to preload articles
$preloader->preload($articles, 'tags'); // 2 queries to preload tags
$preloader->preload($articles, 'comments'); // 1 query to preload comments

// no more queries are needed now
foreach ($categories as $category) {
    foreach ($category->getArticles() as $article) {
        echo $article->getTitle(), "\n";

        foreach ($article->getTags() as $tag) {
            echo $tag->getLabel(), "\n";
        }

        foreach ($article->getComments() as $comment) {
            echo $comment->getText(), "\n";
        }
    }
}
```

Any `iterable` of entities works as the source, so a Doctrine `Collection` can be passed straight through:

```php
$preloader->preload($category->getArticles(), 'comments');
```

## Selective Preloading and Partial Collections

`EntityPreloader` can preload filtered relations into Doctrine association itself without rewriting root query and without root fetch joins. This avoids duplicated root rows and keeps root pagination safe.

Simple preload stays unchanged:

```php
$entityPreloader->preload($orders, [
    'customer',
    'items',
]);
```

Selective preload with Doctrine `Criteria`:

```php
use Doctrine\Common\Collections\Criteria;
use Kyzegs\DoctrineEntityPreloader\Preload;

$entityPreloader->preload($merchants, [
    'transactions' => Preload::criteria(
        Criteria::create()
            ->where(Criteria::expr()->eq('status', TransactionStatus::Paid))
            ->orderBy(['createdAt' => Criteria::DESC])
    ),
]);

foreach ($merchants as $merchant) {
    $paidTransactions = $merchant->getTransactions(); // initialized
}
```

Advanced query customization with `PreloadQueryBuilder`:

```php
use Kyzegs\DoctrineEntityPreloader\Preload;
use Kyzegs\DoctrineEntityPreloader\PreloadQueryBuilder;

$entityPreloader->preload($merchants, [
    'transactions' => Preload::query(
        static function (PreloadQueryBuilder $query): void {
            $query
                ->join('entity.paymentMethod', 'paymentMethod')
                ->andWhere('paymentMethod.code IN (:codes)')
                ->setParameter('codes', ['ideal', 'bancontact'])
                ->addOrderBy('entity.createdAt', 'DESC');
        }
    ),
]);
```

Nested customized preload:

```php
use Doctrine\Common\Collections\Criteria;
use Kyzegs\DoctrineEntityPreloader\Preload;

$entityPreloader->preload($articles, [
    'comments' => Preload::criteria(
        Criteria::create()
            ->where(Criteria::expr()->eq('approved', true))
    )->preload([
        'author',
    ]),
]);
```

### Warning: Partial Collections

Selective preloading initializes a partial Doctrine collection. Collection is considered loaded, but can contain only rows matching preload criteria. This is similar to conditional fetch join behavior. Do not use selective mode in code paths expecting full association content.

Additional rules:

- Dirty collections are rejected with `DirtyCollectionException`.
- Already initialized collections are rejected by default for selective preload. Use `replaceInitializedCollection()` explicitly if overwrite is intended.
- `Criteria::setMaxResults()` is rejected for to-many selective preloads because it is global child limit, not per-parent limit.
- `Criteria::setFirstResult()` is rejected for the same reason: it is a global offset, not a per-parent offset.
- Indexed associations (`indexBy`) are preloaded with their collection keys intact, as long as `indexBy` names a mapped field.
- Selective preload of a to-one association is rejected. A non-matching filter would assign `null` to the association, which Doctrine flushes as `UPDATE ... SET fk = NULL`.
- Root query stays unchanged; relation rows are loaded in separate preload queries, batched by `batchSize` (100 owners per query by default).

## Configuration

`EntityPreloader` allows you to adjust batch sizes and fetch join limits to fit your application's performance needs:

- **Batch Size:** Set a custom batch size for preloading to optimize memory usage.
- **Max Fetch Join Same Field Count:** Define the maximum number of join fetches allowed per field.

```php
$preloader->preload(
    $articles,
    'category',
    batchSize: 20,
    maxFetchJoinSameFieldCount: 5
);
```

### Doctrine ORM filters (e.g. softdeleteable)

Doctrine ORM SQL filters are applied to preload queries the same way they are applied to repository queries.
This is separate from `Criteria`-based selective preloading:

- `Criteria` adds explicit conditions to selective preload query.
- ORM filters are global SQL filters handled by Doctrine (`$entityManager->getFilters()`).

Global default preload filter policy:

```php
use Kyzegs\DoctrineEntityPreloader\EntityPreloader;
use Kyzegs\DoctrineEntityPreloader\PreloadFilterPolicy;

$preloader = new EntityPreloader(
    $entityManager,
    PreloadFilterPolicy::create()->withoutFilters('softdeleteable'),
);
```

Per-association override:

```php
use Kyzegs\DoctrineEntityPreloader\Preload;
use Kyzegs\DoctrineEntityPreloader\PreloadFilterPolicy;

$preloader = new EntityPreloader(
    $entityManager,
    PreloadFilterPolicy::create()->withoutFilters('softdeleteable'),
);

$preloader->preload($categories, [
    'articles' => Preload::association()->enableFilters('softdeleteable'),
]);
```

Temporarily set filter parameters for one preload:

```php
use Kyzegs\DoctrineEntityPreloader\Preload;

$preloader->preload($categories, [
    'articles' => Preload::association()
        ->enableFilters('softdeleteable')
        ->withFilterParameter('softdeleteable', 'deletedValue', 0),
]);
```

Factory helpers are also available:

```php
Preload::enableFilters('softdeleteable');
Preload::withoutFilters('softdeleteable');
Preload::withFilterParameter('softdeleteable', 'deletedValue', 0);
```


## Limitations

- no support for dirty collections
- no support for composite primary keys
