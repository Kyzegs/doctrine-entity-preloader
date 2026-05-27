<?php declare(strict_types = 1);

namespace Kyzegs\DoctrineEntityPreloader;

use ArrayAccess;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessor;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use ReflectionProperty;
use Kyzegs\DoctrineEntityPreloader\Exception\DirtyCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\InvalidAssociationException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsafePartialCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedAssociationException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedCompositeIdentifierException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedIndexedCollectionException;
use Kyzegs\DoctrineEntityPreloader\Exception\UnsupportedPreloadLimitException;
use function array_chunk;
use function array_key_exists;
use function array_values;
use function count;
use function get_parent_class;
use function is_a;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function spl_object_id;

class EntityPreloader
{

    private const PRELOAD_ENTITY_DEFAULT_BATCH_SIZE = 1_000;
    private const PRELOAD_COLLECTION_DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
    )
    {
    }

    /**
     * @param list<object> $sourceEntities
     * @param literal-string|array<int|string, string|PreloadConfig> $sourcePropertyName
     * @param positive-int|null $batchSize
     * @param non-negative-int|null $maxFetchJoinSameFieldCount
     * @return list<object>
     */
    public function preload(
        array $sourceEntities,
        string|array $sourcePropertyName,
        ?int $batchSize = null,
        ?int $maxFetchJoinSameFieldCount = null,
    ): array
    {
        if (is_string($sourcePropertyName)) {
            return $this->preloadAssociation(
                sourceEntities: $sourceEntities,
                sourcePropertyName: $sourcePropertyName,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
            );
        }

        return $this->preloadConfiguredAssociations(
            sourceEntities: $sourceEntities,
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
    ): array
    {
        $sourceEntitiesCommonAncestor = $this->getCommonAncestor($sourceEntities);

        if ($sourceEntitiesCommonAncestor === null) {
            return [];
        }

        /** @var ClassMetadata<object> $sourceClassMetadata */
        $sourceClassMetadata = $this->entityManager->getClassMetadata($sourceEntitiesCommonAncestor);
        $maxFetchJoinSameFieldCount ??= 1;
        $sourceEntities = $this->loadProxies($sourceClassMetadata, $sourceEntities, $batchSize ?? self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE, $maxFetchJoinSameFieldCount);
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

        if (isset($associationMapping['indexBy'])) {
            throw new LogicException('Preloading of indexed associations is not supported');
        }

        $maxFetchJoinSameFieldCount ??= 1;
        $sourceEntities = $this->loadProxies($sourceClassMetadata, $sourceEntities, $batchSize ?? self::PRELOAD_ENTITY_DEFAULT_BATCH_SIZE, $maxFetchJoinSameFieldCount);

        $preloader = match ($associationMapping['type']) {
            ClassMetadata::ONE_TO_ONE, ClassMetadata::MANY_TO_ONE => $this->preloadToOne(...),
            ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY => $this->preloadToMany(...),
            default => throw new LogicException("Unsupported association mapping type {$associationMapping['type']}"),
        };

        return $preloader($sourceEntities, $sourceClassMetadata, $sourcePropertyName, $targetClassMetadata, $batchSize, $maxFetchJoinSameFieldCount);
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
    ): array
    {
        $associationMapping = $sourceClassMetadata->getAssociationMapping($sourcePropertyName);
        /** @var ClassMetadata<object> $targetClassMetadata */
        $targetClassMetadata = $this->entityManager->getClassMetadata($associationMapping['targetEntity']);

        if (isset($associationMapping['indexBy'])) {
            throw new UnsupportedIndexedCollectionException("Association '{$sourceClassMetadata->getName()}::{$sourcePropertyName}' is indexed and cannot be selectively preloaded.");
        }

        if ($this->isSelectiveConfig($preloadConfig)) {
            $grouped = $this->preloadSelectiveAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                targetClassMetadata: $targetClassMetadata,
                associationMapping: $associationMapping,
                preloadConfig: $preloadConfig,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
            );

            $this->hydrateSelectiveAssociation(
                sourceEntities: $sourceEntities,
                sourceClassMetadata: $sourceClassMetadata,
                sourcePropertyName: $sourcePropertyName,
                groupedResultsByOwnerId: $grouped,
                associationMapping: $associationMapping,
                preloadConfig: $preloadConfig,
            );

            $loadedTargets = $this->flattenGroupedSelectiveResults($grouped);

        } else {
            $loadedTargets = $this->preloadAssociation(
                sourceEntities: $sourceEntities,
                sourcePropertyName: $sourcePropertyName,
                batchSize: $batchSize,
                maxFetchJoinSameFieldCount: $maxFetchJoinSameFieldCount,
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
            );

            foreach ($nestedLoadedTargets as $nestedLoadedTarget) {
                $allLoadedTargets[spl_object_id($nestedLoadedTarget)] = $nestedLoadedTarget;
            }
        }

        return array_values($allLoadedTargets);
    }

    private function isSelectiveConfig(PreloadConfig $preloadConfig): bool
    {
        return $preloadConfig->getCriteria() !== null || $preloadConfig->getQueryCustomizer() !== null;
    }

    /**
     * @param list<object> $sourceEntities
     * @param ClassMetadata<object> $sourceClassMetadata
     * @param ClassMetadata<object> $targetClassMetadata
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
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
        int $maxFetchJoinSameFieldCount,
    ): array
    {
        if (count($sourceClassMetadata->getIdentifierFieldNames()) > 1 || count($targetClassMetadata->getIdentifierFieldNames()) > 1) {
            throw new UnsupportedCompositeIdentifierException('Selective preload currently supports only single-column identifiers.');
        }

        $isToMany = ($associationMapping['type'] & ClassMetadata::TO_MANY) !== 0;
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

        if ($isToMany && $criteria !== null && $criteria->getMaxResults() !== null) {
            throw new UnsupportedPreloadLimitException('Criteria::setMaxResults() is not supported for to-many selective preloads. It is a global limit, not per-parent limit.');
        }

        $queryBuilder = $this->createSelectiveQueryBuilder(
            sourceClassMetadata: $sourceClassMetadata,
            sourcePropertyName: $sourcePropertyName,
            targetClassMetadata: $targetClassMetadata,
            associationMapping: $associationMapping,
            ownerIds: $ownerIds,
            ownerIdentifierType: $ownerIdentifierType,
        );

        if ($criteria !== null) {
            $this->applyCriteriaToSelectiveQuery($queryBuilder, $criteria, $isToMany);
        }

        if (count($associationMapping['orderBy'] ?? []) > 0) {
            foreach ($associationMapping['orderBy'] as $field => $direction) {
                $queryBuilder->addOrderBy("entity.{$field}", $direction);
            }
        }

        $this->addFetchJoinsToPreventFetchDuringHydration('entity', $queryBuilder, $targetClassMetadata, $maxFetchJoinSameFieldCount);

        if ($preloadConfig->getQueryCustomizer() !== null) {
            $wrappedBuilder = new PreloadQueryBuilder($queryBuilder);
            ($preloadConfig->getQueryCustomizer())($wrappedBuilder);
        }

        $hydratedRows = $queryBuilder->getQuery()->getResult();
        $grouped = [];

        foreach ($hydratedRows as $row) {
            if (!is_array($row) || !array_key_exists('ownerId', $row)) {
                throw new UnsupportedAssociationException("Unable to determine owner id for selective preload '{$sourcePropertyName}'.");
            }

            $entity = $row['entity'] ?? $row[0] ?? null;
            if (!is_object($entity)) {
                continue;
            }

            $ownerKey = (string) $row['ownerId'];
            $grouped[$ownerKey] ??= [];
            $grouped[$ownerKey][spl_object_id($entity)] = $entity;
        }

        foreach ($grouped as $ownerKey => $entitiesByObjectId) {
            $grouped[$ownerKey] = array_values($entitiesByObjectId);
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
            $queryBuilder
                ->join("entity.{$ownerRelation}", 'owner')
                ->andWhere('owner IN (:ownerIds)')
                ->setParameter(
                    'ownerIds',
                    $this->convertFieldValuesToDatabaseValues($ownerIdentifierType, $ownerIds),
                    $this->deduceArrayParameterType($ownerIdentifierType),
                );

        } else {
            throw new UnsupportedAssociationException("Association '{$sourceClassMetadata->getName()}::{$sourcePropertyName}' cannot be selectively preloaded because owner relation is not navigable from target entity.");
        }

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

        if (($associationMapping['type'] & ClassMetadata::TO_ONE) !== 0 && $associationMapping['isOwningSide'] === false) {
            return $associationMapping['mappedBy'] ?? null;
        }

        if (($associationMapping['type'] & ClassMetadata::TO_ONE) !== 0 && $associationMapping['isOwningSide'] === true) {
            return $associationMapping['inversedBy'] ?? null;
        }

        return null;
    }

    private function applyCriteriaToSelectiveQuery(
        QueryBuilder $queryBuilder,
        Criteria $criteria,
        bool $isToMany,
    ): void
    {
        $queryBuilder->addCriteria($criteria);

        if ($isToMany && $criteria->getFirstResult() !== null) {
            // Keep explicit: first result is global for whole child result set.
            $queryBuilder->setFirstResult($criteria->getFirstResult());
        }
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
     * @param array<string, mixed>|ArrayAccess<string, mixed> $associationMapping
     */
    private function hydrateSelectiveAssociation(
        array $sourceEntities,
        ClassMetadata $sourceClassMetadata,
        string $sourcePropertyName,
        array $groupedResultsByOwnerId,
        array|ArrayAccess $associationMapping,
        PreloadConfig $preloadConfig,
    ): void
    {
        $sourcePropertyAccessor = $this->getPropertyAccessor($sourceClassMetadata, $sourcePropertyName);
        if ($sourcePropertyAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

        $isToMany = ($associationMapping['type'] & ClassMetadata::TO_MANY) !== 0;
        foreach ($sourceEntities as $sourceEntity) {
            $ownerKey = $this->normalizeEntityIdentifier($sourceEntity);
            $matchedTargets = $groupedResultsByOwnerId[$ownerKey] ?? [];

            if ($isToMany) {
                $this->hydrateSelectiveToManyCollection(
                    sourceEntity: $sourceEntity,
                    sourcePropertyName: $sourcePropertyName,
                    sourcePropertyAccessor: $sourcePropertyAccessor,
                    matchedTargets: $matchedTargets,
                    preloadConfig: $preloadConfig,
                );

                continue;
            }

            $matchedTarget = $matchedTargets[0] ?? null;
            $sourcePropertyAccessor->setValue($sourceEntity, $matchedTarget);
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
            $collection->clear();
        }

        foreach ($matchedTargets as $targetEntity) {
            $collection->add($targetEntity);
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
            $this->loadEntitiesBy($classMetadata, $identifierName, $classMetadata, $idsChunk, $maxFetchJoinSameFieldCount);
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
            default => throw new LogicException('Unsupported association mapping type'),
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
     * @param array<string, PersistentCollection<int, object>> $uninitializedCollections
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
    ): array
    {
        $targetPropertyName = $sourceClassMetadata->getAssociationMappedByTargetField($sourcePropertyName); // e.g. 'order'
        $targetPropertyAccessor = $this->getPropertyAccessor($targetClassMetadata, $targetPropertyName); // e.g. Item::$order reflection
        $targetEntities = [];

        if ($targetPropertyAccessor === null) {
            throw new LogicException('Doctrine should use RuntimeReflectionService which never returns null.');
        }

        $targetEntitiesList = $this->loadEntitiesBy(
            $targetClassMetadata,
            $targetPropertyName,
            $sourceClassMetadata,
            $uninitializedSourceEntityIdsChunk,
            $maxFetchJoinSameFieldCount,
            $associationMapping['orderBy'] ?? [],
        );

        foreach ($targetEntitiesList as $targetEntity) {
            $sourceEntity = $targetPropertyAccessor->getValue($targetEntity);
            $sourceEntityKey = (string) $sourceIdentifierAccessor->getValue($sourceEntity);
            $uninitializedCollections[$sourceEntityKey]->add($targetEntity);

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
     * @param array<string, PersistentCollection<int, object>> $uninitializedCollections
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
    ): array
    {
        if (count($associationMapping['orderBy'] ?? []) > 0) {
            throw new LogicException('Many-to-many associations with order by are not supported');
        }

        $sourceIdentifierName = $sourceClassMetadata->getSingleIdentifierFieldName();
        $targetIdentifierName = $targetClassMetadata->getSingleIdentifierFieldName();

        $sourceIdentifierType = $this->getIdentifierFieldType($sourceClassMetadata);

        $manyToManyRows = $this->entityManager->createQueryBuilder()
            ->select("source.{$sourceIdentifierName} AS sourceId", "target.{$targetIdentifierName} AS targetId")
            ->from($sourceClassMetadata->getName(), 'source')
            ->join("source.{$sourcePropertyName}", 'target')
            ->andWhere('source IN (:sourceEntityIds)')
            ->setParameter(
                'sourceEntityIds',
                $this->convertFieldValuesToDatabaseValues($sourceIdentifierType, $uninitializedSourceEntityIdsChunk),
                $this->deduceArrayParameterType($sourceIdentifierType),
            )
            ->getQuery()
            ->getResult();

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

        foreach ($this->loadEntitiesBy($targetClassMetadata, $targetIdentifierName, $sourceClassMetadata, array_values($uninitializedTargetEntityIds), $maxFetchJoinSameFieldCount) as $targetEntity) {
            $targetEntityKey = (string) $targetIdentifierAccessor->getValue($targetEntity);
            $targetEntities[$targetEntityKey] = $targetEntity;
        }

        foreach ($manyToManyRows as $manyToManyRow) {
            $sourceEntityKey = (string) $manyToManyRow['sourceId'];
            $targetEntityKey = (string) $manyToManyRow['targetId'];
            $uninitializedCollections[$sourceEntityKey]->add($targetEntities[$targetEntityKey]);
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

        return $this->loadProxies($targetClassMetadata, $targetEntities, $batchSize, $maxFetchJoinSameFieldCount);
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

        return $queryBuilder->getQuery()->getResult();
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
