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

        $numberPerPage = $criteria->getLimit();
        if ($numberPerPage !== null) {
            $page = $criteria->getOffset();
            $entitySelect->limit($numberPerPage);
            $entitySelect->offset($numberPerPage * ($page - 1));
            $entityCollection->setOffset($page);
            $entityCollection->setLimit($numberPerPage);
        }
        unset($numberPerPage);

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
            $re = $e->relations[$relation]->relationEntityMap;

            $relationSubQuery = $this->db->sql()->select();

            if (!$criteria->hasRelationPredicates($relation)) {
                continue;
            }

            $relationSubQuery->from($re->table);
            $relationSubQuery->columns([$e->relations[$relation]->remoteIdColumn]);

            foreach ($criteria->getRelationPredicates($relation) as $predicate) {
                $relationSubQuery->where->addPredicate($this->createSqlPredicate("{$re->table}.{$predicate[0]}", $predicate[1], $predicate[2]));
            }
            $relationSubQueries[] = $relationSubQuery;
        }
        unset($relation, $relationSubQuery, $re);

        // find entity relations
        $embeddedRelationEntityMap = [];

        foreach ($relations as $relation) {
            if (!isset($e->relations[$relation]) || $e->relations[$relation]->type !== 'entity') {
                continue;
            }

            $re = $this->map[$e->relations[$relation]->relationEntityMap];

            if (!$re) {
                throw new \InvalidArgumentException("Relation map does not exist for requested relation: $relation");
            }

            $entitySelect->join(
                [$e->relations[$relation]->sqlAlias => $re->table],
                "{$e->table}.{$e->relations[$relation]->localIdColumn} = {$e->relations[$relation]->sqlAlias}.{$e->idColumn}",
                $re->columns,
                Select::JOIN_LEFT
            );

            // set reduction through one-to-one relationship predicates
            if ($criteria->hasRelationPredicates($relation)) {
                foreach ($criteria->getRelationPredicates($relation) as $predicate) {
                    $entitySelect->where->addPredicate($this->createSqlPredicate("{$e->relations[$relation]->sqlAlias}.{$predicate[0]}", $predicate[1], $predicate[2]));
                }
            }

            $embeddedRelationEntityMap[$e->relations[$relation]->sqlAlias] = $re->entityClass;
        }
        unset($relation, $re);

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
            foreach ($embeddedRelationEntityMap as $relationName => $relationEntityClass) {
                if (!in_array($relationEntityClass, $embeddedRelations)) {
                    continue;
                }
                $rowArray[$e->table][$e->relations[$relationEntityClass]->property] = $relEntity = $this->map[$relationEntityClass]->createEntity();
                $this->map[$relationEntityClass]->setEntityState($relEntity, $rowArray[$relationName]->toArray());
            }
            unset($relationName, $relationEntityClass);

            $entity = $this->map[$entityName]->createEntity();
            $dataKey = ($e->table instanceof TableIdentifier) ? $e->table->getTable() : $e->table;
            $this->map[$entityName]->setEntityState($entity, $rowArray[$dataKey]->toArray());
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
            $re = $r->relationEntityMap;

            if (!$re) {
                throw new \RuntimeException('No relation entity map found');
            }

            $relationEntitySelect = $this->db->sql()->select();
            $relationEntitySelect->from($re->table);
            $relationEntitySelect->columns(array_merge($re->columns, [$r->remoteIdColumn]));
            $relationEntitySelect->where->in($r->remoteIdColumn, array_keys($entityIdentityMap));
            $relationEntityStatement = $relationEntitySelect->prepare();

            $relationCollections = [];
            foreach ($relationEntityStatement->execute() as $rowArray) {
                if (!isset($relationCollections[$rowArray[$r->remoteIdColumn]])) {
                    $relationCollections[$rowArray[$r->remoteIdColumn]] = [];
                }
                $relationEntity = $this->map[$re->entityClass]->createEntity();
                $this->map[$re->entityClass]->setEntityState($relationEntity, $rowArray);
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
        $entity = $command->getEntity();
        // $context = $command->getContext();
        switch ($name) {
            case 'insert':
                $entityName = get_class($entity);
                if ($entityName === GenericEntity::class) {
                    throw new \Exception('generic entity commands currently not supported');
                }
                $entityMap = $this->map[$entityName];
                $data = $entityMap->getEntityColumnData($entity);
                if ($data[$entityMap->idColumn] == null) {
                    unset($data[$entityMap->idColumn]);
                }

                // @todo refactor: prefer embedded, but consult context for relation data

                if ($entityMap->relations) {
                    foreach ($entityMap->relations as $relation) {
                        switch ($relation->type) {
                            case 'entity':
                                $relationIdentity = $relation->getRelationEntityIdentity($entity);
                                $data[$relation->localIdColumn] = $relationIdentity;
                                break;
                            default:
                                break;
                        }
                    }
                }

                $insert = $this->db->sql()->insert();
                $insert->into($entityMap->table)->values($data);
                $statement = $insert->prepare();
                $insertResult = $statement->execute();
                $entityMap->setEntityIdentity($entity, $insertResult->getGeneratedValue());
                break;
            case 'update':
                echo 'Updating';
                break;
            case 'delete':
                echo 'Deleting';
                break;
            default:
                throw new \InvalidArgumentException('Command name provided is currently unsupported');
        }
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
