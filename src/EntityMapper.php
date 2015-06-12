<?php

namespace Distill\EntityMapper;

use Distill\Db\Db;
use Distill\Db\Sql\Criteria\Like;
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

        $entityCollection = new Collection\PaginatedCollection();
        $entityCollection->setCriteria($criteria);

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

        foreach ($entityStatement->execute() as $rowArray) {
            foreach ($embeddedRelationEntityMap as $relationName => $relationEntityClass) {
                if (!in_array($relationEntityClass, $embeddedRelations)) {
                    continue;
                }
                $rowArray[$e->table][$e->relations[$relationEntityClass]->property] = $relEntity = $this->createEntity($relationEntityClass);
                $this->setEntityState($relEntity, $rowArray[$relationName]->toArray());
            }
            unset($relationName, $relationEntityClass);

            $entity = $this->createEntity($entityClass);
            $this->setEntityState($entity, $rowArray[$e->table]->toArray());
            $entityCollection->append($entity);
            $entityIdentityMap[$rowArray[$e->table][$e->idColumn]] = $entity;
        }
        unset($rowArray, $entity);

        // return early as there are no entities found matching criteria
        if (!$entityIdentityMap) {
            return $entityCollection;
        }

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
            foreach ($relationEntityStatement->execute() as $rowArray) {
                if (!isset($relationCollections[$rowArray[$r->remoteIdColumn]])) {
                    $relationCollections[$rowArray[$r->remoteIdColumn]] = new Collection\Collection();
                }
                $relationEntity = $this->createEntity($re->entityClass);
                $this->setEntityState($relationEntity, $rowArray);
                $relationCollections[$rowArray[$r->remoteIdColumn]]->append($relationEntity);
            }
            unset($rowArray);

            foreach ($relationCollections as $reId => $reColl) {
                $this->setEntityState($entityIdentityMap[$reId], [$r->property => $reColl]);
            }
            unset($reId, $reColl);
        }
        unset($relation, $relationCollections);

        return $entityCollection;
    }

    protected function createEntity($entityClass)
    {
        if (method_exists($entityClass, 'createEntity')) {
            return $entityClass::createEntity();
        }
        /** @var \ReflectionClass $ref */
        $ref = $this->map->get($entityClass)->getReflections()['class'];
        return $ref->newInstanceWithoutConstructor();

    }

    protected function setEntityState($entity, $data)
    {
        $entityClass = get_class($entity);
        if (!method_exists($entityClass, 'setEntityState')) {
            throw new \RuntimeException("$entityClass must implement static function createEntityFromDataSourceArray()");
        }
        $entityClass::setEntityState($entity, $data);
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
