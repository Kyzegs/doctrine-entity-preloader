<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use ArrayAccess;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessor;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\QueryBuilder;
use Kyzegs\DoctrineEntityPreloader\Exception\DirtyCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\InvalidAssociationException;
use Kyzegs\DoctrineEntityPreloader\Exception\LogicException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsafePartialCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedAssociationException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedCompositeIdentifierException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedIndexedCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedPreloadLimitException;
use ReflectionProperty;
use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function get_debug_type;
use function get_parent_class;
use function is_a;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function iterator_to_array;
use function method_exists;
use function spl_object_id;

class EntityPreloader
{

    private const PRELOAD_ENTITY_DEFAULT_BATCH_SIZE = 1_000;
    private const PRELOAD_COLLECTION_DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PreloadFilterPolicy $defaultFilterPolicy = null,
    )
    {
    }

    /**
     * @param iterable<object> $sourceEntities
     * @param literal-string|array<int|string, string|PreloadConfig> $sourcePropertyName
     * @param positive-int|null $batchSize
     * @param non-negative-int|null $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    public function preload(
        iterable $sourceEntities,
        string|array $sourcePropertyName,
        ?int $batchSize = null,
        ?int $maxFetchJoinSameFieldCount = null,
    ): array
    {
        $sourceEntityList = is_array($sourceEntities) ? array_values($sourceEntities) : iterator_to_array($sourceEntities, false);

        if (is_string($sourcePropertyName)) {
            return $this->preloadAssociation(
                sourceEntities: $sourceEntityList,
                sourcePropertyName: $sourcePropertyName,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
            );
        }

        return $this->preloadConfiguredAssociations(
            sourceEntities: $sourceEntityList,
            preload: $sourcePropertyName,
            batchSize: $batchSize,
            maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
        );
    }

    /**
     * @param list<object> $sourceEntities
     * @param array<int|string, string|PreloadConfig> $preload
     * @param positive-int|null $batchSize
     * @param non-negative-int|null $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function preloadConfiguredAssociations(
        array $sourceEntities,
        array $preload,
        ?int $batchSize = null,
        ?int $maxFetchJoinSameFieldCount = null,
        ?PreloadFilterPolicy $parentFilterPolicy = null,
    ): array
    {
        $sourceEntitiesCommonAncestor = $this->getCommonAncestor($sourceEntities);

        if ($sourceEntitiesCommonAncestor === null) {
            return [];
        }

        /** @var ClassMetadata<object> $sourceClassMetadata */
        $sourceClassMetadata = $this->entityManager->getClassMetadata($sourceEntitiesCommonAncestor);
        $maxFetchJoinSameFieldCount ??= 1;
        $sourceEntities = $this->loadProxies(
            classMetadata: $sourceClassMetadata,
            entities: $sourceEntities,
            batchSize: $batchSize ?? self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE,
            maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
            filterPolicy: $this->resolveFilterPolicy($parentFilterPolicy),
        );
        $normalizedPreload = $this->normalizePreloadSpecification($preload);
        $loadedTargetLists = [];

        foreach ($normalizedPreload as $association => $config) {
            $loadedTargetLists[] = $this->preloadConfiguredAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $association,
                preloadConfig: $config,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                parentFilterPolicy: $parentFilterPolicy,
            );
        }

        return $this->dedupeEntities(...$loadedTargetLists);
    }

    /**
     * @param list<object> $sourceEntities
     * @param positive-int|null $batchSize
     * @param non-negative-int|null $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function preloadAssociation(
        array $sourceEntities,
        string $sourcePropertyName,
        ?int $batchSize = null,
        ?int $maxFetchJoinSameFieldCount = null,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $sourceEntitiesCommonAncestor = $this->getCommonAncestor($sourceEntities);

        if ($sourceEntitiesCommonAncestor === null) {
            return [];
        }

        $sourceClassMetadata = $this->entityManager->getClassMetadata($sourceEntitiesCommonAncestor);
        $this->assertAssociationIsMapped($sourceClassMetadata, $sourcePropertyName);
        $associationMapping = $sourceClassMetadata->getAssociationMapping($sourcePropertyName);

        /** @var ClassMetadata<object> $targetClassMetadata */
        $targetClassMetadata = $this->entityManager->getClassMetadata($associationMapping['targetEntity']);

        $maxFetchJoinSameFieldCount ??= 1;
        $sourceEntities = $this->loadProxies(
            classMetadata: $sourceClassMetadata,
            entities: $sourceEntities,
            batchSize: $batchSize ?? self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE,
            maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
            filterPolicy: $filterPolicy,
        );

        $preloader = match ($associationMapping['type']) {
            ClassMetadata::ONE_TO_ONE, ClassMetadata::MANY_TO_ONE => $this->preloadToOne(...),
            ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY => $this->preloadToMany(...),
            default => throw new UnsupportedAssociationException("Unsupported association mapping type {$associationMapping['type']}."),
        };

        return $preloader($sourceEntities, $sourceClassMetadata, $sourcePropertyName, $targetClassMetadata, $batchSize, $maxFetchJoinSameFieldCount, $filterPolicy);
    }

    /**
     * @param array<int|string, string|PreloadConfig> $preload
     * @return array<string, PreloadConfig>
     */
    private function normalizePreloadSpecification(array $preload): array
    {
        $normalized = [];

        foreach ($preload as $association => $config) {
            if (is_int($association)) {
                if (!is_string($config)) {
                    throw new InvalidAssociationException('Numeric preload keys must contain association names as strings.');
                }

                $normalized[$config] = Preload::association();
                continue;
            }

            if (!$config instanceof PreloadConfig) {
                throw new InvalidAssociationException("Association '{$association}' must use string shorthand or PreloadConfig.");
            }

            $normalized[$association] = $config;
        }

        return $normalized;
    }

    /**
     * @param list<object> $sourceEntities
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param positive-int|null $batchSize
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function preloadConfiguredAssociation(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        PreloadConfig $preloadConfig,
        ?int $batchSize,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $parentFilterPolicy = null,
    ): array
    {
        $effectiveFilterPolicy = $this->resolveFilterPolicy($parentFilterPolicy, $preloadConfig->getFilterPolicy());
        $this->assertAssociationIsMapped($sourceClassMetadata, $sourcePropertyName);
        $associationMapping = $sourceClassMetadata->getAssociationMapping($sourcePropertyName);
        /** @var ClassMetadata<object> $targetClassMetadata */
        $targetClassMetadata = $this->entityManager->getClassMetadata($associationMapping['targetEntity']);

        if ($this->isSelectiveConfig($preloadConfig)) {
            $grouped = $this->preloadSelectiveAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                targetClassMetadata: $targetClassMetadata,
                associationMapping: $associationMapping,
                preloadConfig: $preloadConfig,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                filterPolicy: $effectiveFilterPolicy,
            );

            $this->hydrateSelectiveAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                groupedResultsByOwnerId: $grouped,
                preloadConfig: $preloadConfig,
                indexByAccessor: $this->getIndexByAccessor($targetClassMetadata, $associationMapping),
            );

            $loadedTargets = $this->dedupeEntities(...array_values($grouped));

        } else {
            $loadedTargets = $this->preloadAssociation(
                sourceEntities: $sourceEntities,
                sourcePropertyName: $sourcePropertyName,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                filterPolicy: $effectiveFilterPolicy,
            );
        }

        if (count($preloadConfig->getNestedPreload()) === 0) {
            return $loadedTargets;
        }

        if (count($loadedTargets) === 0) {
            return $loadedTargets;
        }

        // The declared target class is only an upper bound: with inheritance the loaded targets can be
        // subclasses carrying associations the declared class does not know about.
        /** @var ClassMetadata<object> $nestedOwnerMetadata */
        $nestedOwnerMetadata = $this->entityManager->getClassMetadata(
            $this->getCommonAncestor($loadedTargets) ?? $targetClassMetadata->getName(),
        );
        $nestedPreload = $this->normalizePreloadSpecification($preloadConfig->getNestedPreload());
        $loadedTargetLists = [$loadedTargets];

        foreach ($nestedPreload as $nestedAssociation => $nestedConfig) {
            $loadedTargetLists[] = $this->preloadConfiguredAssociation(
                sourceEntities: $loadedTargets,
                sourceClassMetadata: $nestedOwnerMetadata,
                sourcePropertyName: $nestedAssociation,
                preloadConfig: $nestedConfig,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                parentFilterPolicy: $effectiveFilterPolicy,
            );
        }

        return $this->dedupeEntities(...$loadedTargetLists);
    }

    /**
     * @param list<object> ...$entityLists
     * @return list<object>
     */
    private function dedupeEntities(array ...$entityLists): array
    {
        $uniqueEntities = [];

        foreach ($entityLists as $entityList) {
            foreach ($entityList as $entity) {
                $uniqueEntities[spl_object_id($entity)] = $entity;
            }
        }

        return array_values($uniqueEntities);
    }

    private function isSelectiveConfig(PreloadConfig $preloadConfig): bool
    {
        return $preloadConfig->getCriteria() !== null
            || $preloadConfig->getQueryCustomizer() !== null
            || $preloadConfig->getLimitPerParent() !== null;
    }

    /**
     * @param list<object> $sourceEntities
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     * @param positive-int|null $batchSize
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return array<string, list<object>>
     */
    private function preloadSelectiveAssociation(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        array|ArrayAccess $associationMapping,
        PreloadConfig $preloadConfig,
        ?int $batchSize,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        if (count($sourceClassMetadata->getIdentifierFieldNames()) > 1 || count($targetClassMetadata->getIdentifierFieldNames()) > 1) {
            throw new UnsupportedCompositeIdentifierException('Selective preload currently supports only single-column identifiers.');
        }

        if (($associationMapping['type'] & ClassMetadata::TO_MANY) === 0) {
            // A non-matching filter would write null, which the UnitOfWork flushes as "UPDATE ... SET fk = NULL".
            throw new UnsupportedAssociationException("Association '{$sourceClassMetadata->getName()}::{$sourcePropertyName}' is to-one and cannot be selectively preloaded.");
        }

        $criteria = $preloadConfig->getCriteria();
        $ownerIdentifierType = $this->getIdentifierFieldType($sourceClassMetadata);

        $ownerIdentifierAccessor = $this->getSingleIdPropertyAccessor($sourceClassMetadata);
        $ownerIds = [];

        foreach ($sourceEntities as $sourceEntity) {
            $ownerIds[] = $ownerIdentifierAccessor->getValue($sourceEntity);
        }

        if (count($ownerIds) === 0) {
            return [];
        }

        if ($criteria !== null && $criteria->getMaxResults() !== null) {
            throw new UnsupportedPreloadLimitException('Criteria::setMaxResults() is not supported for to-many selective preloads. It is a global limit, not per-parent limit.');
        }

        if ($criteria !== null && ($criteria->getFirstResult() ?? 0) > 0) {
            throw new UnsupportedPreloadLimitException('Criteria::setFirstResult() is not supported for to-many selective preloads. It is a global offset, not per-parent offset.');
        }

        $batchSize ??= self::PRELOAD_COLLECTION_DEFAULT_BATCH_SIZE;
        $limitPerParent = $preloadConfig->getLimitPerParent();
        $targetsByOwnerAndObjectId = [];

        foreach (array_chunk($ownerIds, $batchSize) as $ownerIdsChunk) {
            $queryBuilder = $this->createSelectiveQueryBuilder(
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                targetClassMetadata: $targetClassMetadata,
                associationMapping: $associationMapping,
                ownerIds: $ownerIdsChunk,
                ownerIdentifierType: $ownerIdentifierType,
                identifiersOnly: $limitPerParent !== null,
            );

            if ($criteria !== null) {
                $queryBuilder->addCriteria($criteria);
            }

            foreach ($associationMapping['orderBy'] ?? [] as $field => $direction) {
                $queryBuilder->addOrderBy("entity.{$field}", $direction);
            }

            if ($limitPerParent === null) {
                $this->addFetchJoinsToPreventFetchDuringHydration('entity', $queryBuilder, $targetClassMetadata, $maxFetchJoinSameFieldCount);
            }

            if ($preloadConfig->getQueryCustomizer() !== null) {
                $wrappedBuilder = new PreloadQueryBuilder($queryBuilder);
                ($preloadConfig->getQueryCustomizer())($wrappedBuilder);
            }

            $rows = $this->executeRowQuery($queryBuilder, $filterPolicy);

            foreach ($rows as $row) {
                if (!array_key_exists('ownerId', $row)) {
                    throw new UnsupportedAssociationException("Unable to determine owner id for selective preload '{$sourcePropertyName}'.");
                }
            }

            if ($limitPerParent !== null) {
                $limitedTargetsByOwner = $this->loadLimitedTargetsPerOwner(
                    rows: $rows,
                    targetClassMetadata: $targetClassMetadata,
                    limitPerParent: $limitPerParent,
                    maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                    filterPolicy: $filterPolicy,
                );

                foreach ($limitedTargetsByOwner as $ownerKey => $ownerTargets) {
                    $targetsByOwnerAndObjectId[$ownerKey] ??= [];

                    foreach ($ownerTargets as $ownerTarget) {
                        $targetsByOwnerAndObjectId[$ownerKey][spl_object_id($ownerTarget)] = $ownerTarget;
                    }
                }

                continue;
            }

            foreach ($rows as $row) {
                $entity = $row['entity'] ?? $row[0] ?? null;
                if (!is_object($entity)) {
                    continue;
                }

                $ownerKey = (string) $row['ownerId'];
                $targetsByOwnerAndObjectId[$ownerKey] ??= [];
                $targetsByOwnerAndObjectId[$ownerKey][spl_object_id($entity)] = $entity;
            }
        }

        $grouped = [];

        foreach ($targetsByOwnerAndObjectId as $ownerKey => $entitiesByObjectId) {
            $grouped[$ownerKey] = array_values($entitiesByObjectId);
        }

        return $grouped;
    }

    /**
     * Cuts on identifier pairs first, costing one extra query per batch but hydrating only the survivors.
     *
     * ponytail: all matching rows still cross the wire as (ownerId, targetId) pairs, only hydration is
     * bounded. Move to ROW_NUMBER() OVER (PARTITION BY ...) if the transferred row count itself hurts.
     *
     * @param list<array<array-key, mixed>> $rows
     * @param ClassMetadata<object> $targetClassMetadata
     * @param positive-int $limitPerParent
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return array<string, list<object>>
     */
    private function loadLimitedTargetsPerOwner(
        array $rows,
        ClassMetadata $targetClassMetadata,
        int $limitPerParent,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy,
    ): array
    {
        $targetIdentifierAccessor = $this->getSingleIdPropertyAccessor($targetClassMetadata);

        /** @var array<string, array<string, mixed>> $targetIdsByOwner */
        $targetIdsByOwner = [];

        foreach ($rows as $row) {
            if (!array_key_exists('targetId', $row)) {
                throw new LogicException('Identifier-only selective preload query must select a target id.');
            }

            $ownerKey = (string) $row['ownerId'];
            $targetIdsByOwner[$ownerKey] ??= [];

            // Query order is the collection order, and the string key deduplicates rows fanned out by joins.
            $targetIdsByOwner[$ownerKey][(string) $row['targetId']] ??= $row['targetId'];
        }

        $targetIdsToLoad = [];

        foreach ($targetIdsByOwner as $ownerKey => $targetIds) {
            $targetIdsByOwner[$ownerKey] = array_slice($targetIds, 0, $limitPerParent, preserve_keys: true);

            foreach ($targetIdsByOwner[$ownerKey] as $targetIdKey => $targetId) {
                $targetIdsToLoad[$targetIdKey] = $targetId;
            }
        }

        $targetEntitiesById = [];

        foreach (array_chunk($targetIdsToLoad, self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE) as $targetIdsChunk) {
            foreach ($this->loadEntitiesBy(
                $targetClassMetadata,
                $targetClassMetadata->getSingleIdentifierFieldName(),
                $targetClassMetadata,
                $targetIdsChunk,
                $maxFetchJoinSameFieldCount,
                filterPolicy: $filterPolicy,
            ) as $targetEntity) {
                $targetEntitiesById[(string) $targetIdentifierAccessor->getValue($targetEntity)] = $targetEntity;
            }
        }

        $limitedTargetsByOwner = [];

        foreach ($targetIdsByOwner as $ownerKey => $targetIds) {
            $ownerTargets = [];

            foreach (array_keys($targetIds) as $targetIdKey) {
                if (!array_key_exists($targetIdKey, $targetEntitiesById)) {
                    continue;
                }

                $ownerTargets[] = $targetEntitiesById[$targetIdKey];
            }

            $limitedTargetsByOwner[$ownerKey] = $ownerTargets;
        }

        return $limitedTargetsByOwner;
    }

    /**
     * @param list<mixed> $ownerIds
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     */
    private function createSelectiveQueryBuilder(
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        array|ArrayAccess $associationMapping,
        array $ownerIds,
        Type $ownerIdentifierType,
        bool $identifiersOnly = false,
    ): QueryBuilder
    {
        $targetSelect = $identifiersOnly
            ? "entity.{$targetClassMetadata->getSingleIdentifierFieldName()} AS targetId"
            : 'entity';

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select("owner.{$sourceClassMetadata->getSingleIdentifierFieldName()} AS ownerId", $targetSelect)
            ->from($targetClassMetadata->getName(), 'entity');

        $ownerRelation = $this->resolveOwnerRelationForSelectiveQuery($sourceClassMetadata, $sourcePropertyName, $associationMapping);

        if ($ownerRelation !== null) {
            $queryBuilder->join("entity.{$ownerRelation}", 'owner');

        } else {
            // The owner is not reachable from the target, so pair them through a correlated MEMBER OF.
            // 'entity' must stay the first root: that is what addCriteria() resolves unqualified fields against.
            // ponytail: leans on the planner rewriting the EXISTS into a semi-join. Move to the two-query
            // id-pairing shape used by preloadManyToManyInner() if that ever shows up in a profile.
            $queryBuilder
                ->from($sourceClassMetadata->getName(), 'owner')
                ->andWhere("entity MEMBER OF owner.{$sourcePropertyName}");
        }

        $queryBuilder
            ->andWhere('owner IN (:ownerIds)')
            ->setParameter(
                'ownerIds',
                $this->convertFieldValuesToDatabaseValues($ownerIdentifierType, $ownerIds),
                $this->deduceArrayParameterType($ownerIdentifierType),
            );

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     * @param ClassMetadata<object> $sourceClassMetadata
     */
    private function resolveOwnerRelationForSelectiveQuery(
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        array|ArrayAccess $associationMapping,
    ): ?string
    {
        if ($associationMapping['type'] === ClassMetadata::ONE_TO_MANY) {
            return $associationMapping['mappedBy'];
        }

        if ($associationMapping['type'] === ClassMetadata::MANY_TO_MANY) {
            if ($associationMapping['isOwningSide'] === true) {
                return $associationMapping['inversedBy'] ?? null;
            }

            return $associationMapping['mappedBy'] ?? null;
        }

        return null;
    }

    /**
     * @param list<object> $sourceEntities
     * @param array<string, list<object>> $groupedResultsByOwnerId
     * @param ClassMetadata<object> $sourceClassMetadata
     */
    private function hydrateSelectiveAssociation(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        array $groupedResultsByOwnerId,
        PreloadConfig $preloadConfig,
        PropertyAccessor|ReflectionProperty|null $indexByAccessor,
    ): void
    {
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName);

        foreach ($sourceEntities as $sourceEntity) {
            $ownerKey = $this->normalizeEntityIdentifier($sourceEntity);

            $this->hydrateSelectiveToManyCollection(
                sourceEntity: $sourceEntity,
                sourcePropertyName: $sourcePropertyName,
                sourcePropertyAccessor: $sourcePropertyAccessor,
                matchedTargets: $groupedResultsByOwnerId[$ownerKey] ?? [],
                preloadConfig: $preloadConfig,
                indexByAccessor: $indexByAccessor,
            );
        }
    }

    /**
     * @param list<object> $matchedTargets
     */
    private function hydrateSelectiveToManyCollection(
        object $sourceEntity,
        string $sourcePropertyName,
        PropertyAccessor|ReflectionProperty $sourcePropertyAccessor,
        array $matchedTargets,
        PreloadConfig $preloadConfig,
        PropertyAccessor|ReflectionProperty|null $indexByAccessor,
    ): void
    {
        $collection = $sourcePropertyAccessor->getValue($sourceEntity);
        if (!$collection instanceof PersistentCollection) {
            throw new UnsupportedAssociationException('Association \'' . $sourceEntity::class . "::{$sourcePropertyName}' is expected to be PersistentCollection.");
        }

        if ($collection->isDirty()) {
            throw new DirtyCollectionException('Association \'' . $sourceEntity::class . "::{$sourcePropertyName}' is dirty and cannot be selectively preloaded.");
        }

        if ($collection->isInitialized() && !$preloadConfig->shouldReplaceInitializedCollection()) {
            throw new UnsafePartialCollectionException('Association \'' . $sourceEntity::class . "::{$sourcePropertyName}' is already initialized. Use replaceInitializedCollection() to allow selective overwrite.");
        }

        if ($collection->isInitialized()) {
            // PersistentCollection::clear() schedules a deletion (and orphan removal) that takeSnapshot() does not undo.
            $collection->unwrap()->clear();
        }

        foreach ($matchedTargets as $targetEntity) {
            $this->addToPreloadedCollection($collection, $targetEntity, $indexByAccessor);
        }

        $collection->setInitialized(true);
        $collection->takeSnapshot();
    }

    private function normalizeEntityIdentifier(object $entity): string
    {
        $entityClassMetadata = $this->entityManager->getClassMetadata($entity::class);
        if (count($entityClassMetadata->getIdentifierFieldNames()) > 1) {
            throw new UnsupportedCompositeIdentifierException('Entity \'' . $entity::class . '\' has composite identifier.');
        }

        return (string) $this->getSingleIdPropertyAccessor($entityClassMetadata)->getValue($entity);
    }

    /**
     * @param list<object> $entities
     * @return class-string<object>|null
     */
    private function getCommonAncestor(array $entities): ?string
    {
        $commonAncestor = null;

        foreach ($entities as $entity) {
            $entityClassName = $entity::class;

            if ($commonAncestor === null) {
                $commonAncestor = $entityClassName;
                continue;
            }

            while (!is_a($entityClassName, $commonAncestor, true)) {
                $commonAncestor = get_parent_class($commonAncestor);

                if ($commonAncestor === false) {
                    throw new LogicException('Given entities must have a common ancestor');
                }
            }
        }

        return $commonAncestor;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     * @param list<object> $entities
     * @param positive-int $batchSize
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function loadProxies(
        ClassMetadata $classMetadata,
        array $entities,
        int $batchSize,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $identifierAccessor = $this->getSingleIdPropertyAccessor($classMetadata);
        $identifierName = $classMetadata->getSingleIdentifierFieldName();

        $uniqueEntities = [];
        $uninitializedIds = [];

        foreach ($entities as $entity) {
            $entityId = $identifierAccessor->getValue($entity);
            $entityKey = (string) $entityId;
            $uniqueEntities[$entityKey] = $entity;

            if ($this->entityManager->isUninitializedObject($entity)) {
                $uninitializedIds[$entityKey] = $entityId;
            }
        }

        foreach (array_chunk($uninitializedIds, $batchSize) as $idsChunk) {
            $this->loadEntitiesBy($classMetadata, $identifierName, $classMetadata, $idsChunk, $maxFetchJoinSameFieldCount, filterPolicy: $filterPolicy);
        }

        return array_values($uniqueEntities);
    }

    /**
     * @param list<object> $sourceEntities
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param positive-int|null $batchSize
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function preloadToMany(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        ?int $batchSize,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $sourceIdentifierAccessor = $this->getSingleIdPropertyAccessor($sourceClassMetadata);
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName);
        $targetIdentifierAccessor = $this->getSingleIdPropertyAccessor($targetClassMetadata);

        $batchSize ??= self::PRELOAD_COLLECTION_DEFAULT_BATCH_SIZE;
        $targetEntities = [];
        $uninitializedSourceEntityIds = [];
        $uninitializedCollections = [];

        foreach ($sourceEntities as $sourceEntity) {
            $sourceEntityId = $sourceIdentifierAccessor->getValue($sourceEntity);
            $sourceEntityKey = (string) $sourceEntityId;
            $sourceEntityCollection = $sourcePropertyAccessor->getValue($sourceEntity);

            if (
                $sourceEntityCollection instanceof PersistentCollection
                && !$sourceEntityCollection->isInitialized()
                && !$sourceEntityCollection->isDirty() // preloading dirty collection is too hard to handle
            ) {
                $uninitializedSourceEntityIds[$sourceEntityKey] = $sourceEntityId;
                $uninitializedCollections[$sourceEntityKey] = $sourceEntityCollection;
                continue;
            }

            foreach ($sourceEntityCollection as $targetEntity) {
                $targetEntityKey = (string) $targetIdentifierAccessor->getValue($targetEntity);
                $targetEntities[$targetEntityKey] = $targetEntity;
            }
        }

        $this->assertAssociationIsMapped($sourceClassMetadata, $sourcePropertyName);
        $associationMapping = $sourceClassMetadata->getAssociationMapping($sourcePropertyName);

        $innerLoader = match ($associationMapping['type']) {
            ClassMetadata::ONE_TO_MANY => $this->preloadOneToManyInner(...),
            ClassMetadata::MANY_TO_MANY => $this->preloadManyToManyInner(...),
            default => throw new UnsupportedAssociationException('Unsupported association mapping type.'),
        };

        foreach (array_chunk($uninitializedSourceEntityIds, $batchSize, preserve_keys: true) as $uninitializedSourceEntityIdsChunk) {
            $targetEntitiesChunk = $innerLoader(
                associationMapping: $associationMapping,
                sourceClassMetadata: $sourceClassMetadata,
                sourceIdentifierAccessor: $sourceIdentifierAccessor,
                sourcePropertyName: $sourcePropertyName,
                targetClassMetadata: $targetClassMetadata,
                targetIdentifierAccessor: $targetIdentifierAccessor,
                uninitializedSourceEntityIdsChunk: array_values($uninitializedSourceEntityIdsChunk),
                uninitializedCollections: $uninitializedCollections,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                filterPolicy: $filterPolicy,
            );

            foreach ($targetEntitiesChunk as $targetEntityKey => $targetEntity) {
                $targetEntities[$targetEntityKey] = $targetEntity;
            }
        }

        foreach ($uninitializedCollections as $sourceEntityCollection) {
            $sourceEntityCollection->setInitialized(true);
            $sourceEntityCollection->takeSnapshot();
        }

        return array_values($targetEntities);
    }

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param list<mixed> $uninitializedSourceEntityIdsChunk
     * @param array<string, PersistentCollection<int|string, object>> $uninitializedCollections
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return array<string, object>
     */
    private function preloadOneToManyInner(
        array|ArrayAccess $associationMapping,
        ClassMetadata $sourceClassMetadata,
        PropertyAccessor|ReflectionProperty $sourceIdentifierAccessor,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        PropertyAccessor|ReflectionProperty $targetIdentifierAccessor,
        array $uninitializedSourceEntityIdsChunk,
        array $uninitializedCollections,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $targetPropertyName = $sourceClassMetadata->getAssociationMappedByTargetField($sourcePropertyName);
        $targetPropertyAccessor = $this->getPropertyAccessor($targetClassMetadata, $targetPropertyName);
        $targetEntities = [];

        $indexByAccessor = $this->getIndexByAccessor($targetClassMetadata, $associationMapping);

        $targetEntitiesList = $this->loadEntitiesBy(
            $targetClassMetadata,
            $targetPropertyName,
            $sourceClassMetadata,
            $uninitializedSourceEntityIdsChunk,
            $maxFetchJoinSameFieldCount,
            $associationMapping['orderBy'] ?? [],
            $filterPolicy,
        );

        foreach ($targetEntitiesList as $targetEntity) {
            $sourceEntity = $targetPropertyAccessor->getValue($targetEntity);
            $sourceEntityKey = (string) $sourceIdentifierAccessor->getValue($sourceEntity);
            $this->addToPreloadedCollection($uninitializedCollections[$sourceEntityKey], $targetEntity, $indexByAccessor);

            $targetEntityKey = (string) $targetIdentifierAccessor->getValue($targetEntity);
            $targetEntities[$targetEntityKey] = $targetEntity;
        }

        return $targetEntities;
    }

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param list<mixed> $uninitializedSourceEntityIdsChunk
     * @param array<string, PersistentCollection<int|string, object>> $uninitializedCollections
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return array<string, object>
     */
    private function preloadManyToManyInner(
        array|ArrayAccess $associationMapping,
        ClassMetadata $sourceClassMetadata,
        PropertyAccessor|ReflectionProperty $sourceIdentifierAccessor,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        PropertyAccessor|ReflectionProperty $targetIdentifierAccessor,
        array $uninitializedSourceEntityIdsChunk,
        array $uninitializedCollections,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $indexByAccessor = $this->getIndexByAccessor($targetClassMetadata, $associationMapping);
        $sourceIdentifierName = $sourceClassMetadata->getSingleIdentifierFieldName();
        $targetIdentifierName = $targetClassMetadata->getSingleIdentifierFieldName();

        $sourceIdentifierType = $this->getIdentifierFieldType($sourceClassMetadata);

        $manyToManyQueryBuilder = $this->entityManager->createQueryBuilder()
            ->select("source.{$sourceIdentifierName} AS sourceId", "target.{$targetIdentifierName} AS targetId")
            ->from($sourceClassMetadata->getName(), 'source')
            ->join("source.{$sourcePropertyName}", 'target')
            ->andWhere('source IN (:sourceEntityIds)')
            ->setParameter(
                'sourceEntityIds',
                $this->convertFieldValuesToDatabaseValues($sourceIdentifierType, $uninitializedSourceEntityIdsChunk),
                $this->deduceArrayParameterType($sourceIdentifierType),
            );

        // Rows are consumed in query order, so ordering the pair query fills each collection in that order.
        foreach ($associationMapping['orderBy'] ?? [] as $field => $direction) {
            $manyToManyQueryBuilder->addOrderBy("target.{$field}", $direction);
        }

        $manyToManyRows = $this->executeRowQuery($manyToManyQueryBuilder, $filterPolicy);

        $targetEntities = [];
        $uninitializedTargetEntityIds = [];

        foreach ($manyToManyRows as $manyToManyRow) {
            $targetEntityId = $manyToManyRow['targetId'];
            $targetEntityKey = (string) $targetEntityId;

            /** @var object|false $targetEntity */
            $targetEntity = $this->entityManager->getUnitOfWork()->tryGetById($targetEntityId, $targetClassMetadata->getName());

            if ($targetEntity !== false && !$this->entityManager->isUninitializedObject($targetEntity)) {
                $targetEntities[$targetEntityKey] = $targetEntity;
                continue;
            }

            $uninitializedTargetEntityIds[$targetEntityKey] = $targetEntityId;
        }

        foreach ($this->loadEntitiesBy($targetClassMetadata, $targetIdentifierName, $targetClassMetadata, array_values($uninitializedTargetEntityIds), $maxFetchJoinSameFieldCount, filterPolicy: $filterPolicy) as $targetEntity) {
            $targetEntityKey = (string) $targetIdentifierAccessor->getValue($targetEntity);
            $targetEntities[$targetEntityKey] = $targetEntity;
        }

        foreach ($manyToManyRows as $manyToManyRow) {
            $sourceEntityKey = (string) $manyToManyRow['sourceId'];
            $targetEntityKey = (string) $manyToManyRow['targetId'];
            $this->addToPreloadedCollection($uninitializedCollections[$sourceEntityKey], $targetEntities[$targetEntityKey], $indexByAccessor);
        }

        return $targetEntities;
    }

    /**
     * @param list<object> $sourceEntities
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param positive-int|null $batchSize
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    private function preloadToOne(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        ClassMetadata $targetClassMetadata,
        ?int $batchSize,
        int $maxFetchJoinSameFieldCount,
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName);

        $batchSize ??= self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE;
        $targetEntities = [];

        foreach ($sourceEntities as $sourceEntity) {
            $targetEntity = $sourcePropertyAccessor->getValue($sourceEntity);

            if ($targetEntity === null) {
                continue;
            }

            $targetEntities[] = $targetEntity;
        }

        return $this->loadProxies($targetClassMetadata, $targetEntities, $batchSize, $maxFetchJoinSameFieldCount, $filterPolicy);
    }

    /**
     * @param ClassMetadata<object> $targetClassMetadata
     * @param list<mixed> $fieldValues
     * @param ClassMetadata<object> $referencedClassMetadata
     * @param non-negative-int $maxFetchJoinSameFieldCount
     * @param array<string, 'asc'|'desc'> $orderBy
     * @return list<object>
     */
    private function loadEntitiesBy(
        ClassMetadata $targetClassMetadata,
        string $fieldName,
        ClassMetadata $referencedClassMetadata,
        array $fieldValues,
        int $maxFetchJoinSameFieldCount,
        array $orderBy = [],
        ?PreloadFilterPolicy $filterPolicy = null,
    ): array
    {
        if (count($fieldValues) === 0) {
            return [];
        }

        $referencedType = $this->getIdentifierFieldType($referencedClassMetadata);
        $rootLevelAlias = 'e';

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select($rootLevelAlias)
            ->from($targetClassMetadata->getName(), $rootLevelAlias)
            ->andWhere("{$rootLevelAlias}.{$fieldName} IN (:fieldValues)")
            ->setParameter(
                'fieldValues',
                $this->convertFieldValuesToDatabaseValues($referencedType, $fieldValues),
                $this->deduceArrayParameterType($referencedType),
            );

        $this->addFetchJoinsToPreventFetchDuringHydration($rootLevelAlias, $queryBuilder, $targetClassMetadata, $maxFetchJoinSameFieldCount);

        foreach ($orderBy as $field => $direction) {
            $queryBuilder->addOrderBy("{$rootLevelAlias}.{$field}", $direction);
        }

        return $this->executeEntityQuery($queryBuilder, $filterPolicy);
    }

    /**
     * @return list<object>
     */
    private function executeEntityQuery(
        QueryBuilder $queryBuilder,
        ?PreloadFilterPolicy $filterPolicy,
    ): array
    {
        $entities = [];

        foreach ($this->executeQueryWithFilterPolicy($queryBuilder, $filterPolicy) as $result) {
            if (is_object($result)) {
                $entities[] = $result;
            }
        }

        return $entities;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function executeRowQuery(
        QueryBuilder $queryBuilder,
        ?PreloadFilterPolicy $filterPolicy,
    ): array
    {
        $rows = [];

        foreach ($this->executeQueryWithFilterPolicy($queryBuilder, $filterPolicy) as $result) {
            if (is_array($result)) {
                $rows[] = $result;
            }
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private function executeQueryWithFilterPolicy(
        QueryBuilder $queryBuilder,
        ?PreloadFilterPolicy $filterPolicy,
    ): array
    {
        $effectiveFilterPolicy = $this->resolveFilterPolicy($filterPolicy);
        if ($effectiveFilterPolicy === null || $effectiveFilterPolicy->isEmpty()) {
            return $queryBuilder->getQuery()->getResult();
        }

        $filterCollection = $this->entityManager->getFilters();

        /** @var array<string, array{wasEnabled: bool, parameters: array<string, mixed>}> $snapshots */
        $snapshots = [];

        $affectedFilterNames = array_keys($effectiveFilterPolicy->getFilterStates());
        foreach ($effectiveFilterPolicy->getFilterParameters() as $filterName => $parameters) {
            if ($parameters === []) {
                continue;
            }

            $affectedFilterNames[$filterName] = $filterName;
        }

        foreach ($affectedFilterNames as $affectedFilterName) {
            $isEnabled = $filterCollection->isEnabled($affectedFilterName);
            $snapshots[$affectedFilterName] = [
                'wasEnabled' => $isEnabled,
                'parameters' => $isEnabled ? $this->extractFilterParameters($filterCollection->getFilter($affectedFilterName)) : [],
            ];
        }

        try {
            foreach ($effectiveFilterPolicy->getFilterStates() as $filterName => $shouldEnable) {
                if ($shouldEnable) {
                    if (!$filterCollection->isEnabled($filterName)) {
                        $filterCollection->enable($filterName);
                    }
                    continue;
                }

                if ($filterCollection->isEnabled($filterName)) {
                    $filterCollection->disable($filterName);
                }
            }

            foreach ($effectiveFilterPolicy->getFilterParameters() as $filterName => $parameters) {
                if (!$filterCollection->isEnabled($filterName)) {
                    if ($effectiveFilterPolicy->hasExplicitState($filterName)) {
                        continue;
                    }

                    $filterCollection->enable($filterName);
                }

                $filter = $filterCollection->getFilter($filterName);
                $this->restoreFilterParameters($filter, $parameters);
            }

            return $queryBuilder->getQuery()->getResult();

        } finally {
            foreach ($snapshots as $filterName => $snapshot) {
                if (!$snapshot['wasEnabled']) {
                    if ($filterCollection->isEnabled($filterName)) {
                        $filterCollection->disable($filterName);
                    }
                    continue;
                }

                if ($filterCollection->isEnabled($filterName)) {
                    $filterCollection->disable($filterName);
                }

                $filter = $filterCollection->enable($filterName);
                $this->restoreFilterParameters($filter, $snapshot['parameters']);
            }
        }
    }

    private function resolveFilterPolicy(?PreloadFilterPolicy ...$filterPolicies): ?PreloadFilterPolicy
    {
        $effectiveFilterPolicy = $this->defaultFilterPolicy;

        foreach ($filterPolicies as $filterPolicy) {
            if ($filterPolicy === null) {
                continue;
            }

            $effectiveFilterPolicy = $effectiveFilterPolicy?->merge($filterPolicy) ?? $filterPolicy;
        }

        return $effectiveFilterPolicy;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilterParameters(SQLFilter $filter): array
    {
        $parametersProperty = new ReflectionProperty(SQLFilter::class, 'parameters');
        $parametersProperty->setAccessible(true);
        $parameters = $parametersProperty->getValue($filter);

        if (!is_array($parameters)) {
            return [];
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function restoreFilterParameters(
        SQLFilter $filter,
        array $parameters,
    ): void
    {
        foreach ($parameters as $parameterName => $parameterState) {
            if (is_object($parameterState) && $parameterState::class === 'Doctrine\ORM\Query\Filter\Parameter') {
                $valueProperty = new ReflectionProperty($parameterState, 'value');
                $valueProperty->setAccessible(true);
                $parameterValue = $valueProperty->getValue($parameterState);

                $typeProperty = new ReflectionProperty($parameterState, 'type');
                $typeProperty->setAccessible(true);
                $parameterType = $typeProperty->getValue($parameterState);

                if (is_string($parameterType)) {
                    $filter->setParameter($parameterName, $parameterValue, $parameterType);
                } else {
                    $filter->setParameter($parameterName, $parameterValue);
                }

                continue;
            }

            if (is_array($parameterState) && array_key_exists('value', $parameterState)) {
                $parameterType = $parameterState['type'] ?? null;
                if (is_string($parameterType)) {
                    $filter->setParameter($parameterName, $parameterState['value'], $parameterType);
                    continue;
                }

                $filter->setParameter($parameterName, $parameterState['value']);
                continue;
            }

            $filter->setParameter($parameterName, $parameterState);
        }
    }

    private function deduceArrayParameterType(Type $dbalType): ArrayParameterType|int|null // @phpstan-ignore return.unusedType (old dbal compat)
    {
        return match ($dbalType->getBindingType()) {
            ParameterType::INTEGER => ArrayParameterType::INTEGER,
            ParameterType::STRING => ArrayParameterType::STRING,
            ParameterType::ASCII => ArrayParameterType::ASCII,
            ParameterType::BINARY => ArrayParameterType::BINARY,
            default => null,
        };
    }

    /**
     * @param array<mixed> $fieldValues
     * @return list<mixed>
     */
    private function convertFieldValuesToDatabaseValues(
        Type $dbalType,
        array $fieldValues,
    ): array
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        $convertedValues = [];
        foreach ($fieldValues as $value) {
            $convertedValues[] = $dbalType->convertToDatabaseValue($value, $platform);
        }

        return $convertedValues;
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function getIdentifierFieldType(ClassMetadata $classMetadata): Type
    {
        $identifierName = $classMetadata->getSingleIdentifierFieldName();
        $sourceIdTypeName = $classMetadata->getTypeOfField($identifierName);

        if ($sourceIdTypeName === null) {
            throw new LogicException("Identifier field '{$identifierName}' for class '{$classMetadata->getName()}' has unknown field type.");
        }

        return Type::getType($sourceIdTypeName);
    }

    /**
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param array<string, array<string, int>> $alreadyPreloadedJoins
     */
    private function addFetchJoinsToPreventFetchDuringHydration(
        string $alias,
        QueryBuilder $queryBuilder,
        ClassMetadata $sourceClassMetadata,
        int $maxFetchJoinSameFieldCount,
        array $alreadyPreloadedJoins = [],
    ): void
    {
        $sourceClassName = $sourceClassMetadata->getName();

        foreach ($sourceClassMetadata->getAssociationMappings() as $fieldName => $associationMapping) {
            $alreadyPreloadedJoins[$sourceClassName][$fieldName] ??= 0;

            if ($alreadyPreloadedJoins[$sourceClassName][$fieldName] >= $maxFetchJoinSameFieldCount) {
                continue;
            }

            /** @var ClassMetadata<object> $targetClassMetadata */
            $targetClassMetadata = $this->entityManager->getClassMetadata($associationMapping['targetEntity']);

            $isToOne = ($associationMapping['type'] & ClassMetadata::TO_ONE) !== 0;
            $isToOneInversed = $isToOne && $associationMapping['isOwningSide'] === false;
            $isToOneAbstract = $isToOne && $associationMapping['isOwningSide'] === true && count($targetClassMetadata->subClasses) > 0;

            if (!$isToOneInversed && !$isToOneAbstract) {
                continue;
            }

            $targetRelationAlias = "{$alias}_{$fieldName}";

            $queryBuilder->addSelect($targetRelationAlias);
            $queryBuilder->leftJoin("{$alias}.{$fieldName}", $targetRelationAlias);
            $alreadyPreloadedJoins[$sourceClassName][$fieldName]++;

            $this->addFetchJoinsToPreventFetchDuringHydration($targetRelationAlias, $queryBuilder, $targetClassMetadata, $maxFetchJoinSameFieldCount, $alreadyPreloadedJoins);
        }
    }

    /**
     * @param ClassMetadata<object> $targetClassMetadata
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     */
    private function getIndexByAccessor(
        ClassMetadata $targetClassMetadata,
        array|ArrayAccess $associationMapping,
    ): PropertyAccessor|ReflectionProperty|null
    {
        $indexByFieldName = $associationMapping['indexBy'] ?? null;

        if ($indexByFieldName === null) {
            return null;
        }

        if (!is_string($indexByFieldName) || !$targetClassMetadata->hasField($indexByFieldName)) {
            throw new UnsupportedIndexedCollectionException("Association indexed by '{$targetClassMetadata->getName()}::\${$indexByFieldName}' cannot be preloaded because it is not a mapped field.");
        }

        return $this->getPropertyAccessor($targetClassMetadata, $indexByFieldName);
    }

    /**
     * @param PersistentCollection<int|string, object> $collection
     */
    private function addToPreloadedCollection(
        PersistentCollection $collection,
        object $targetEntity,
        PropertyAccessor|ReflectionProperty|null $indexByAccessor,
    ): void
    {
        if ($indexByAccessor === null) {
            $collection->add($targetEntity);
            return;
        }

        $indexValue = $indexByAccessor->getValue($targetEntity);

        if (!is_int($indexValue) && !is_string($indexValue)) {
            throw new UnsupportedIndexedCollectionException('Association \'' . $targetEntity::class . "' is indexed by a value of type '" . get_debug_type($indexValue) . "', which cannot be used as a collection key.");
        }

        // PersistentCollection::set() initializes the collection first, which is exactly what preloading avoids.
        $collection->unwrap()->set($indexValue, $targetEntity);
    }

    /**
     * Doctrine throws its own MappingException for an unmapped property; the package promises its own types.
     *
     * @param ClassMetadata<object> $classMetadata
     */
    private function assertAssociationIsMapped(
        ClassMetadata $classMetadata,
        string $sourcePropertyName,
    ): void
    {
        if ($classMetadata->hasAssociation($sourcePropertyName)) {
            return;
        }

        throw new InvalidAssociationException("Association '{$classMetadata->getName()}::\${$sourcePropertyName}' is not mapped.");
    }

    /**
     * Doctrine resolves every mapped property through RuntimeReflectionService, which never returns null.
     *
     * @param ClassMetadata<object> $classMetadata
     */
    private function getSingleIdPropertyAccessor(ClassMetadata $classMetadata): PropertyAccessor|ReflectionProperty
    {
        $accessor = method_exists($classMetadata, 'getSingleIdPropertyAccessor')
            ? $classMetadata->getSingleIdPropertyAccessor()
            : $classMetadata->getSingleIdReflectionProperty();

        return $accessor ?? throw new LogicException("Identifier of '{$classMetadata->getName()}' is not accessible.");
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function getPropertyAccessor(
        ClassMetadata $classMetadata,
        string $property,
    ): PropertyAccessor|ReflectionProperty
    {
        $accessor = method_exists($classMetadata, 'getPropertyAccessor')
            ? $classMetadata->getPropertyAccessor($property)
            : $classMetadata->getReflectionProperty($property);

        return $accessor ?? throw new LogicException("Property '{$classMetadata->getName()}::\${$property}' is not accessible.");
    }

}
