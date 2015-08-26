<?php

namespace Distill\EntityMapper;

use Distill\Db\Db;
use Distill\Db\Sql\Criteria\Like;
use Distill\Db\Sql\Criteria\Operator;
use Distill\Db\Sql\Expression;
use Distill\Db\Sql\Select;
use Distill\Db\Sql\TableIdentifier;

class EntityMapper
{
    /** @var Map */
    protected $map;
    protected $db;
    protected $commandBus;

    public function __construct(Db $db, Map $map)
    {
        $this->db = $db;
        $this->map = $map;
        $this->commandBus = [
            'entity_save' => [$this, 'commandEntitySave'],
            'entity_delete' => [$this, 'commandEntityDelete'],
        ];
    }

    public function getDb()
    {
        return $this->db;
    }

    public function getMap()
    {
        return $this->map;
    }

    /*
    public function queryOne($criteria)
    {
        $entities = $this->query($criteria);
        if ($entities->count() !== 1) {
            return false;
        }
        return $entities->getIterator()->current();
    }
    */

    /**
     * @param $criteria
     * @return Collection
     */
    public function query($criteria)
    {
        if (!$criteria instanceof Criteria) {
            if (!is_array($criteria)) {
                throw new \InvalidArgumentException('$criteria must be an array or Criteria');
            } else {
                $criteria = Criteria::createFromArray($criteria);
            }
        }

        $entityName = $criteria->getEntity();

        if (!$this->map[$criteria->getEntity()]) {
            throw new \InvalidArgumentException("$entityName is not a valid mappable entity");
        }

        $e = $this->map[$criteria->getEntity()];

        $entityCollection = new Collection();
        $entityCollection->setCriteria($criteria);

        $entitySelect = $this->db->sql()->select();
        $entitySelect->usePrefixInColumnAliases(true);
        $entitySelect->useSplitRows(true);
        $entitySelect->from($e->table);
        $entitySelect->columns($e->columns);

        $limit = $criteria->getLimit();
        if ($limit !== null) {
            $offset = $criteria->getOffset();
            $entitySelect->limit($limit);
            $entitySelect->offset($offset);
            $entityCollection->setOffset($offset);
            $entityCollection->setLimit($limit);
        }
        unset($limit);

        if ($criteria->hasOrder()) {
            foreach ($criteria->getOrder() as $order) {
                $entitySelect->order("{$e->table}.$order");
            }
            unset($order);
        }

        // set reduction through entity predicates
        if ($criteria->hasEntityPredicates()) {
            foreach ($criteria->getEntityPredicates() as $predicate) {
                $entityTable = ($e->table instanceof TableIdentifier) ? $e->table->getTable() : $e->table;
                $entitySelect->where->addPredicate($this->createSqlPredicate("{$entityTable}.{$predicate[0]}", $predicate[1], $predicate[2]));
                unset($entityTable);
            }
            unset($predicate);
        }

        $relations = $criteria->getRelations();

        $relationSubQueries = [];

        // set reduction through one-to-many relation predicates
        foreach ($relations as $relation) {
            if (!isset($e->relations[$relation]) || $e->relations[$relation]->type !== 'collection') {
                continue;
            }
            //$re = $this->map[$e->relations[$relation]];
            //if (!$re) {
            //    throw new \InvalidArgumentException("Relation map does not exist for request relation: $relation");
            //}
            $relationMap = $e->relations[$relation]->relationEntityMap;

            $relationSubQuery = $this->db->sql()->select();

            if (!$criteria->hasRelationPredicates($relation)) {
                continue;
            }

            $relationSubQuery->from($relationMap->table);
            $relationSubQuery->columns([$e->relations[$relation]->remoteIdColumn]);

            foreach ($criteria->getRelationPredicates($relation) as $predicate) {
                $relationSubQuery->where->addPredicate($this->createSqlPredicate("{$relationMap->table}.{$predicate[0]}", $predicate[1], $predicate[2]));
            }
            $relationSubQueries[] = $relationSubQuery;
        }
        unset($relation, $relationSubQuery, $relationMap);

        // find entity relations
        $embeddedRelationEntityMap = [];

        foreach ($relations as $relation) {
            if (!isset($e->relations[$relation]) || $e->relations[$relation]->type !== 'entity') {
                continue;
            }

            $relationMap = $e->relations[$relation];
            $relationEntityMap = $e->relations[$relation]->relationEntityMap;

            if (!$relationMap) {
                throw new \InvalidArgumentException("Relation map does not exist for requested relation: $relation");
            }

            //$entityTable = ($relationEntityMap->table instanceof TableIdentifier) ? $relationEntityMap->table->getExpressionData()[0] : $relationEntityMap->table;
            $entitySelect->join(
                [$relationMap->name => $relationEntityMap->table],
                "{$relationMap->localIdColumn} = {$relationMap->name}.{$e->idColumn}",
                $relationEntityMap->columns,
                Select::JOIN_LEFT
            );

            // set reduction through one-to-one relationship predicates
            if ($criteria->hasRelationPredicates($relation)) {
                foreach ($criteria->getRelationPredicates($relation) as $predicate) {
                    $entitySelect->where->addPredicate($this->createSqlPredicate("{$relationMap->name}.{$predicate[0]}", $predicate[1], $predicate[2]));
                }
            }

            $embeddedRelationEntityMap[$relationMap->name] = $relationEntityMap->name;
        }
        unset($relation, $relationMap, $relationEntityMap);

        if (isset($relationSubQueries)) {
            foreach ($relationSubQueries as $relationSubQuery) {
                $entitySelect->where->in("{$e->table}.{$e->idColumn}", $relationSubQuery);
            }
            unset($relationSubQuery);
        }

        $embeddedRelations = $criteria->getEmbeddedRelations();

        $entityIdentityMap = [];
        $entityStatement = $entitySelect->prepare();

        foreach ($entityStatement->execute() as $rowArray) {
            $entityRelationsState = [];
            foreach ($embeddedRelationEntityMap as $relationName => $relationEntityName) {
                if (!in_array($relationName, $embeddedRelations)) {
                    continue;
                }
                $re = $this->map[$relationEntityName];
                if ($rowArray[$relationName][$re->idColumn] !== null) {
                    $entityRelationsState[$relationName] = $relEntity = $this->map[$relationEntityName]->createEntity();
                    $this->map[$relationEntityName]->setEntityState($relEntity, $rowArray[$relationName]->toArray());
                }
            }
            unset($relationName, $relationEntityName);

            $entity = $e->createEntity();
            $dataKey = ($e->table instanceof TableIdentifier) ? $e->table->getTable() : $e->table;
            $e->setEntityState($entity, $rowArray[$dataKey]->toArray());

            foreach ($entityRelationsState as $relationName => $relationState) {
                $e->relations[$relationName]->setRelationState($entity, $relationState);
            }

            $entityCollection->append($entity);
            $entityIdentityMap[$rowArray[$dataKey][$e->idColumn]] = $entity;
        }
        unset($rowArray, $entity);

        // return early as there are no entities found matching criteria
        if (!$entityIdentityMap) {
            if ($criteria->getLimit() === 1) {
                return false;
            }
            return $entityCollection;
        }

        // get size of full set (count)
        if ($criteria->getLimit() > 1) {
            $entityCountSelect = $this->db->sql()->select();
            $entitySelectSubQuery = clone $entitySelect;
            $entitySelectSubQuery->reset(Select::LIMIT);
            $entitySelectSubQuery->reset(Select::OFFSET);
            $entityCountSelect->columns(['count' => new Expression('COUNT(1)')])->from(['query' => $entitySelectSubQuery]);
            $statement = $entityCountSelect->prepare();
            $totalRow = $statement->execute()->current();
            $entityCollection->setTotal($totalRow['count']);
            unset($entityCountSelect, $entitySelectSubQuery, $statement, $totalRow);
        }

        // find collection relations
        foreach ($embeddedRelations as $relation) {
            if (!isset($e->relations[$relation]) || $e->relations[$relation]->type !== 'collection') {
                continue;
            }

            $r = $e->relations[$relation];
            $relationMap = $r->relationEntityMap;

            if (!$relationMap) {
                throw new \RuntimeException('No relation entity map found');
            }

            $relationEntitySelect = $this->db->sql()->select();
            $relationEntitySelect->from($relationMap->table);
            $relationEntitySelect->columns(array_merge($relationMap->columns, [$r->remoteIdColumn]));
            $relationEntitySelect->where->in($r->remoteIdColumn, array_keys($entityIdentityMap));
            $relationEntityStatement = $relationEntitySelect->prepare();

            $relationCollections = [];
            foreach ($relationEntityStatement->execute() as $rowArray) {
                if (!isset($relationCollections[$rowArray[$r->remoteIdColumn]])) {
                    $relationCollections[$rowArray[$r->remoteIdColumn]] = [];
                }
                $relationEntity = $this->map[$relationMap->entityClass]->createEntity();
                $this->map[$relationMap->entityClass]->setEntityState($relationEntity, $rowArray);
                $relationCollections[$rowArray[$r->remoteIdColumn]][] = $relationEntity;
            }
            unset($rowArray);

            foreach ($relationCollections as $reId => $reColl) {
                //$this->map[get_class($entityIdentityMap[$reId])]->setEntityRelationState($entityIdentityMap[$reId], $relation, $reColl);
                $e->relations[$relation]->setRelationState($entityIdentityMap[$reId], $reColl);
            }
            unset($reId, $reColl);
        }
        unset($relation, $relationCollections);

        if ($criteria->getLimit() === 1) {
            if ($entityCollection->count() === 1) {
                return $entityCollection->getIterator()->current();
            } else {
                return false;
            }
        }

        return $entityCollection;
    }

    public function command($command)
    {
        if (!$command instanceof Command) {
            if (is_array($command)) {
                $command = Command::createFromArray($command);
            } else {
                throw new \InvalidArgumentException('$command must be a Command object or an array');
            }
        }

        $name = $command->getName();
        if (!isset($this->commandBus[$name])) {
            throw new \RuntimeException('A handler is not registered for command ' . $name);
        }

        $handler = $this->commandBus[$name];
        $handler($command->getParameters());
    }

    protected function commandEntitySave(array $parameters)
    {
        if (!isset($parameters['entity'])) {
            throw new \RuntimeException('"entity" is required to be passed as a parameter for this command');
        }
        $entity = $parameters['entity'];
        $entityName = get_class($entity);
        if ($entityName === GenericEntity::class) {
            throw new \Exception('generic entity commands currently not supported');
        }
        $entityMap = $this->map[$entityName];
        $data = $entityMap->getEntityColumnData($entity);

        $id = $data[$entityMap->idColumn];
        unset($data[$entityMap->idColumn]);

        $originalData = [];

        // get original data
        if ($id) {
            $columns = $entityMap->columns;
            foreach ($entityMap->relations as $relationName => $relation) {
                if ($relation->type == 'entity') {
                    $columns[] = $relation->localIdColumn;
                }
            }
            $select = $this->db->sql()->select()
                ->columns($columns)
                ->from($entityMap->table)
                ->where([$entityMap->idColumn => $id]);
            $stmt = $select->prepare();
            $result = $stmt->execute();
            $originalData = $result->current();
        }

        if ($entityMap->relations) {
            foreach ($entityMap->relations as $relation) {
                switch ($relation->type) {
                    case 'entity':
                        $relationIdentity = $relation->getRelationEntityIdentity($entity);
                        // don't update unprovided relation entities (@todo figure this out later if this is the right behavior)
                        if ($relationIdentity !== null) {
                            $data[$relation->localIdColumn] = $relationIdentity;
                        } else {
                            unset($originalData[$relation->localIdColumn]);
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        if ($id !== null) {
            foreach ($originalData as $n => $v) {
                if (isset($data[$n]) && $originalData[$n] == $data[$n]) {
                    unset($data[$n]);
                }
            }
            $op = $this->db->sql()->update();
            $op->table($entityMap->table)->set($data)->where(['id' => $id]); // @todo this id should not be hard-coded
        } else {
            $op = $this->db->sql()->insert();
            $op->into($entityMap->table)->values($data);
        }
        $stmt = $op->prepare();
        $result = $stmt->execute();

        if ($id === null) {
            $entityMap->setEntityState($entity, [$entityMap->idColumn => $result->getGeneratedValue()]);
        }
    }

    protected function commandEntityDelete(array $parameters)
    {
        if (!isset($parameters['entity'])) {
            throw new \RuntimeException('"entity" is required to be passed as a parameter for this command');
        }
        $entity = $parameters['entity'];
        $entityName = get_class($entity);
        if ($entityName === GenericEntity::class) {
            throw new \Exception('generic entity commands currently not supported');
        }
        $entityMap = $this->map[$entityName];
        $data = $entityMap->getEntityColumnData($entity);

        $id = $data[$entityMap->idColumn];
        $op = $this->db->sql()->delete();
        $op->from($entityMap->table)->where(['id' => $id]); // @todo this id should not be hard-coded

        $stmt = $op->prepare();
        $stmt->execute();
    }

    protected function createSqlPredicate($l, $op, $r)
    {
        // @todo this needs to be abstracted
        switch ($op) {
            case 'like':
            case '≈':
                return new Like($l, $r);
            case '!=':
            case '>':
            case '<':
            case '>=':
            case '<=':
            case '=':
                return new Operator($l, $op, $r);
            default:
                throw new \InvalidArgumentException('Not a valid predicate');
        }
    }


}
