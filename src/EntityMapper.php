<?php

namespace Distill\EntityMapper;

use Distill\Db\Db;
use Distill\Db\Sql\Criteria\Operator;
use Distill\Db\Sql\Expression;
use Distill\Db\Sql\Select;

class EntityMapper
{
    protected $map;
    protected $db;
    protected $queryPlanner;
    protected $entityFactory;

    public function __construct(Db $db, Map $map)
    {
        $this->db = $db;
        $this->map = $map;
    }

    public function get(Criteria $criteria)
    {
        $entityClass = $criteria->getEntity();

        if (!$this->map[$criteria->getEntity()]) {
            throw new \InvalidArgumentException("$entityClass is not a valid mappable entity");
        }

        $e = $this->map[$criteria->getEntity()];

        /** @var Collection $entityCollection */
        $entityCollection = new $e->collectionClass;

        $entitySelect = $this->db->sql()->select();
        $entitySelect->usePrefixInColumnAliases(true);
        $entitySelect->useSplitRows(true);
        $entitySelect->from($e->table);
        $entitySelect->columns($e->columns);

        $numberPerPage = $criteria->getNumberPerPage();
        if ($numberPerPage !== null) {
            $page = $criteria->getPage();
            $entitySelect->limit($numberPerPage);
            $entitySelect->offset($numberPerPage * ($page - 1));
            $entityCollection->setPage($page);
            $entityCollection->setNumberPerPage($numberPerPage);
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
                $entitySelect->where->addPredicate($this->createSqlPredicate("{$e->table}.{$predicate[0]}", $predicate[1], $predicate[2]));
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
            $re = $this->map[$e->relations[$relation]->entityClass];
            if (!$re) {
                throw new \InvalidArgumentException("Relation map does not exist for request relation: $relation");
            }

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

            $re = $this->map[$e->relations[$relation]->entityClass];

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

        foreach ($entityStatement->execute() as $row) {
            foreach ($embeddedRelationEntityMap as $relationName => $relationEntityClass) {
                if (!in_array($relationEntityClass, $embeddedRelations)) {
                    continue;
                }
                $row[$e->table][$e->relations[$relationEntityClass]->property] = $relEntity = $this->createEntity($relationEntityClass);
                $this->mapData($relEntity, $row[$relationName]);
            }
            unset($relationName, $relationEntityClass);

            $entity = $this->createEntity($entityClass);
            $this->mapData($entity, $row[$e->table]);
            $entityCollection->append($entity);
            $entityIdentityMap[$row[$e->table][$e->idColumn]] = $entity;
        }
        unset($row, $entity);

        // get size of full set (count)
        if ($criteria->getNumberPerPage() !== null) {
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
            $re = $this->map[$r->entityClass];

            if (!$re) {
                throw new \RuntimeException('No relation entity map found');
            }

            $relationEntitySelect = $this->db->sql()->select();
            $relationEntitySelect->from($re->table);
            $relationEntitySelect->columns(array_merge($re->columns, [$r->remoteIdColumn]));
            $relationEntitySelect->where->in($r->remoteIdColumn, array_keys($entityIdentityMap));
            $relationEntityStatement = $relationEntitySelect->prepare();

            $relationCollections = [];
            foreach ($relationEntityStatement->execute() as $row) {
                if (!isset($relationCollections[$row[$r->remoteIdColumn]])) {
                    $relationCollections[$row[$r->remoteIdColumn]] = new $re->collectionClass;
                }
                $relationEntity = $this->createEntity($re->entityClass);
                $this->mapData($relationEntity, $row);
                $relationCollections[$row[$r->remoteIdColumn]]->append($relationEntity);
            }
            unset($row);

            foreach ($relationCollections as $reId => $reColl) {
                $this->mapData($entityIdentityMap[$reId], [$r->property => $reColl]);
            }
            unset($reId, $reColl);
        }
        unset($relation, $relationCollections);

        return $entityCollection;
    }

    protected function createEntity($entityClass)
    {
        $refs = $this->map->get($entityClass)->getReflections();
        $entity = $refs['class']->newInstanceWithoutConstructor();
        return $entity;
    }

    protected function mapData($entity, $data)
    {
        $entityClass = get_class($entity);

        $e = $this->map[$entityClass];

        if (!$e) {
            throw new \RuntimeException('Unknown entity expecting to be mapped ' . $entityClass);
        }

        $reflections = $e->getReflections();

        foreach ($e->properties as $index => $property) {
            $column = $e->columns[$index];
            if (!isset($data[$column])) {
                continue;
            }
            $value = $data[$column];

            // @todo this needs to be abstracted
            if (isset($e->transformers[$property])) {
                foreach ($e->transformers[$property] as $t) {
                    switch ($t) {
                        case 'json':
                            $value = json_decode($value, true);
                            break;
                        default:
                            throw new \RuntimeException("Do not currently support $t");
                    }
                }
            }

            $reflections['properties'][$property]->setValue($entity, $value);
        }

        foreach ($e->relations as $name => $r) {
            $property = $r->property;
            if (!isset($data[$property], $reflections['properties'][$r->property])) {
                continue;
            }
            $reflections['properties'][$r->property]->setValue($entity, $data[$property]);
        }
    }

    protected function createSqlPredicate($l, $op, $r)
    {
        // @todo this needs to be abstracted
        switch ($op) {
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
