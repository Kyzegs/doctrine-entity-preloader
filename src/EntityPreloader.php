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
        $allLoadedTargets = [];

        foreach ($normalizedPreload as $association => $config) {
            $loadedTargets = $this->preloadConfiguredAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $association,
                preloadConfig: $config,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                parentFilterPolicy: $parentFilterPolicy,
            );

            foreach ($loadedTargets as $loadedTarget) {
                $allLoadedTargets[spl_object_id($loadedTarget)] = $loadedTarget;
            }
        }

        return array_values($allLoadedTargets);
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

            $loadedTargets = $this->flattenGroupedSelectiveResults($grouped);

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

        $nestedOwnerMetadata = $targetClassMetadata;
        $nestedPreload = $this->normalizePreloadSpecification($preloadConfig->getNestedPreload());
        $allLoadedTargets = [];

        foreach ($loadedTargets as $loadedTarget) {
            $allLoadedTargets[spl_object_id($loadedTarget)] = $loadedTarget;
        }

        foreach ($nestedPreload as $nestedAssociation => $nestedConfig) {
            $nestedLoadedTargets = $this->preloadConfiguredAssociation(
                sourceEntities: $loadedTargets,
                sourceClassMetadata: $nestedOwnerMetadata,
                sourcePropertyName: $nestedAssociation,
                preloadConfig: $nestedConfig,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
                parentFilterPolicy: $effectiveFilterPolicy,
            );

            foreach ($nestedLoadedTargets as $nestedLoadedTarget) {
                $allLoadedTargets[spl_object_id($nestedLoadedTarget)] = $nestedLoadedTarget;
            }
        }

        return array_values($allLoadedTargets);
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
            // Writing a filtered result into a to-one association means writing null whenever nothing matched,
            // which the UnitOfWork sees as a real change and flushes as "UPDATE ... SET fk = NULL".
            throw new UnsupportedAssociationException("Association '{$sourceClassMetadata->getName()}::{$sourcePropertyName}' is to-one and cannot be selectively preloaded.");
        }

        $criteria = $preloadConfig->getCriteria();
        $ownerIdentifierType = $this->getIdentifierFieldType($sourceClassMetadata);

        $ownerIdentifierAccessor = $this->getSingleIdPropertyAccessor($sourceClassMetadata);
        if ($ownerIdentifierAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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
        $targetsByOwnerAndObjectId = [];

        foreach (array_chunk($ownerIds, $batchSize) as $ownerIdsChunk) {
            $queryBuilder = $this->createSelectiveQueryBuilder(
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                targetClassMetadata: $targetClassMetadata,
                associationMapping: $associationMapping,
                ownerIds: $ownerIdsChunk,
                ownerIdentifierType: $ownerIdentifierType,
            );

            if ($criteria !== null) {
                $queryBuilder->addCriteria($criteria);
            }

            foreach ($associationMapping['orderBy'] ?? [] as $field => $direction) {
                $queryBuilder->addOrderBy("entity.{$field}", $direction);
            }

            $this->addFetchJoinsToPreventFetchDuringHydration('entity', $queryBuilder, $targetClassMetadata, $maxFetchJoinSameFieldCount);

            if ($preloadConfig->getQueryCustomizer() !== null) {
                $wrappedBuilder = new PreloadQueryBuilder($queryBuilder);
                ($preloadConfig->getQueryCustomizer())($wrappedBuilder);
            }

            foreach ($this->executeRowQuery($queryBuilder, $filterPolicy) as $row) {
                if (!array_key_exists('ownerId', $row)) {
                    throw new UnsupportedAssociationException("Unable to determine owner id for selective preload '{$sourcePropertyName}'.");
                }

                $entity = $row['entity'] ?? $row[0] ?? null;
                if (!is_object($entity)) {
                    continue;
                }

                $ownerKey = (string) $row['ownerId'];
                $targetsByOwnerAndObjectId[$ownerKey] ??= [];
                $targetsByOwnerAndObjectId[$ownerKey][spl_object_id($entity)] = $entity;
            }
        }

        $limitPerParent = $preloadConfig->getLimitPerParent();
        $grouped = [];

        foreach ($targetsByOwnerAndObjectId as $ownerKey => $entitiesByObjectId) {
            $ownerTargets = array_values($entitiesByObjectId);

            // ponytail: every matching row is fetched and the cut happens in PHP, so the limit bounds the
            // collection, not the query. Move to ROW_NUMBER() OVER (PARTITION BY ...) if row volume hurts.
            $grouped[$ownerKey] = $limitPerParent === null ? $ownerTargets : array_slice($ownerTargets, 0, $limitPerParent);
        }

        return $grouped;
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
    ): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select("owner.{$sourceClassMetadata->getSingleIdentifierFieldName()} AS ownerId", 'entity')
            ->from($targetClassMetadata->getName(), 'entity');

        $ownerRelation = $this->resolveOwnerRelationForSelectiveQuery($sourceClassMetadata, $sourcePropertyName, $associationMapping);

        if ($ownerRelation !== null) {
            $queryBuilder->join("entity.{$ownerRelation}", 'owner');

        } else {
            // Unidirectional owning side: the owner is not reachable from the target, so owners are paired
            // through a correlated MEMBER OF instead of a join. 'entity' stays the first root, which is what
            // QueryBuilder::addCriteria() resolves unqualified criteria fields against.
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
     * @param array<string, list<object>> $groupedResultsByOwnerId
     * @return list<object>
     */
    private function flattenGroupedSelectiveResults(array $groupedResultsByOwnerId): array
    {
        $flattened = [];
        foreach ($groupedResultsByOwnerId as $groupedResult) {
            foreach ($groupedResult as $targetEntity) {
                $flattened[spl_object_id($targetEntity)] = $targetEntity;
            }
        }

        return array_values($flattened);
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
        if ($sourcePropertyAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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
            // PersistentCollection::clear() schedules a collection deletion (and orphan removal) in the UnitOfWork,
            // which the takeSnapshot() below does not undo. Empty the backing collection instead.
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

        $identifierAccessor = $this->getSingleIdPropertyAccessor($entityClassMetadata);
        if ($identifierAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

        return (string) $identifierAccessor->getValue($entity);
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
        $identifierAccessor = $this->getSingleIdPropertyAccessor($classMetadata); // e.g. Order::$id reflection
        $identifierName = $classMetadata->getSingleIdentifierFieldName(); // e.g. 'id'

        if ($identifierAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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
        $sourceIdentifierAccessor = $this->getSingleIdPropertyAccessor($sourceClassMetadata); // e.g. Order::$id reflection
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName); // e.g. Order::$items reflection
        $targetIdentifierAccessor = $this->getSingleIdPropertyAccessor($targetClassMetadata);

        if ($sourceIdentifierAccessor === null || $sourcePropertyAccessor === null || $targetIdentifierAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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
        $targetPropertyName = $sourceClassMetadata->getAssociationMappedByTargetField($sourcePropertyName); // e.g. 'order'
        $targetPropertyAccessor = $this->getPropertyAccessor($targetClassMetadata, $targetPropertyName); // e.g. Item::$order reflection
        $targetEntities = [];

        if ($targetPropertyAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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

        // Ordering the pair query by the target fields is enough: rows are consumed in query order,
        // so each source collection ends up filled in that order too.
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
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName); // e.g. Item::$order reflection

        if ($sourcePropertyAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

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

        $indexByAccessor = $this->getPropertyAccessor($targetClassMetadata, $indexByFieldName);

        if ($indexByAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

        return $indexByAccessor;
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
     * @param ClassMetadata<object> $classMetadata
     */
    private function getSingleIdPropertyAccessor(ClassMetadata $classMetadata): PropertyAccessor|ReflectionProperty|null
    {
        if (method_exists($classMetadata, 'getSingleIdPropertyAccessor')) {
            return $classMetadata->getSingleIdPropertyAccessor();
        }

        return $classMetadata->getSingleIdReflectionProperty();
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    private function getPropertyAccessor(
        ClassMetadata $classMetadata,
        string $property,
    ): PropertyAccessor|ReflectionProperty|null
    {
        if (method_exists($classMetadata, 'getPropertyAccessor')) {
            return $classMetadata->getPropertyAccessor($property);
        }

        return $classMetadata->getReflectionProperty($property);
    }

}
