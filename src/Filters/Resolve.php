<?php

namespace Abbasudo\Purity\Filters;

use Abbasudo\Purity\Exceptions\FieldNotSupported;
use Abbasudo\Purity\Exceptions\NoOperatorMatch;
use Abbasudo\Purity\Exceptions\OperatorNotSupported;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

class Resolve
{
    /**
     * List of relations and the column.
     *
     * @var array
     */
    private array $fields = [];

    /**
     * Filter field name "column".
     *
     * @var string
     */
    private string $filterFiled = '';

    /**
     * List of available filters.
     *
     * @var filterList
     */
    private FilterList $filterList;

    private Model $model;

    private array $previousModels = [];

    /**
     * @param FilterList $filterList
     * @param Model      $model
     */
    public function __construct(FilterList $filterList, Model $model)
    {
        $this->filterList = $filterList;
        $this->model = $model;
    }

    /**
     * @param Builder      $query
     * @param string       $field
     * @param array|string $values
     *
     * @throws Exception
     * @throws Exception
     *
     * @return void
     */
    public function apply(Builder $query, string $field, array|string $values): void
    {
        if (!$this->safe(fn() => $this->validate([$field => $values]))) {
            return;
        }

        $this->filter($query, $field, $values);
    }

    /**
     * run functions with or without exception.
     *
     * @param Closure $closure
     *
     * @throws Exception
     * @throws Exception
     *
     * @return bool
     */
    private function safe(Closure $closure): bool
    {
        try {
            $closure();

            return true;
        } catch (Exception $exception) {
            if (config('purity.silent')) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @param array|string $values
     *
     * @return void
     */
    private function validate(array|string $values = [])
    {
        if (empty($values) || is_string($values)) {
            throw NoOperatorMatch::create($this->filterList->keys());
        }

        if (!in_array(key($values), $this->filterList->keys())) {
            $this->validate(array_values($values)[0]);
        }
    }

    /**
     * Apply a single filter to the query builder instance.
     *
     * @param Builder           $query
     * @param string            $field
     * @param array|string|null $filters
     *
     * @throws Exception
     * @throws Exception
     *
     * @return void
     */
    private function filter(Builder $query, string $field, array|string|null $filters): void
    {
        // Ensure that the filter is an array
        $filters = is_array($filters) ? $filters : [$filters];

        // Resolve the filter using the appropriate strategy
        if ($this->filterList->get($field) !== null) {
            //call apply method of the appropriate filter class
            $this->safe(fn() => $this->applyFilterStrategy($query, $field, $filters));
        } else {
            // If the field is not recognized as a filter strategy, it is treated as a relation
            $this->safe(fn() => $this->applyRelationFilter($query, $field, $filters));
        }
    }

    /**
     * @param Builder $query
     * @param string  $operator
     * @param array   $filters
     *
     * @return void
     */
    private function applyFilterStrategy(Builder $query, string $operator, array $filters): void
    {
        $filter = $this->filterList->get($operator);

        $this->filterFiled = $field = end($this->fields);

        $callback = (new $filter($query, $field, $filters))->apply();

        $this->filterRelations($query, $callback);
    }

    /**
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function filterRelations(Builder $query, Closure $callback): void
    {
        array_pop($this->fields);

        $this->applyRelations($query, $callback);
    }

    /**
     * Resolve nested relations if any.
     *
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function applyRelations(Builder $query, Closure $callback): void
    {
        if (empty($this->fields)) {
            // If there are no more filterable fields to resolve, apply the closure to the query builder instance
            $callback($query);
        } else {
            // If there are still filterable fields to resolve, apply the closure to a sub-query
            $this->relation($query, $callback);
        }
    }

    /**
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function relation(Builder $query, Closure $callback)
    {
        // remove the last field until its empty
        $field = array_shift($this->fields);

        // get the relation
        if (is_string($field)) {
            $relation = Relation::noConstraints(function () use ($query, $field) {
                return $query->getModel()->{$field}();
            });
        }

        // Check if the field is a morphTo relation
        if (isset($relation) && $relation instanceof MorphTo) {
            $types = $query->getModel()->newModelQuery()->distinct()->pluck($relation->getMorphType())->filter()->all();
            foreach ($types as $key => &$type) {
                $type = Relation::getMorphedModel($type) ?? $type;
                // Check if the field exists in the morph query model
                if (!Schema::hasColumn((new $type)->getTable(), $this->filterFiled)) {
                    unset($types[$key]);
                }
            }

            $types = array_values($types);

            if ($types) {
                $query->whereHasMorph($field, $types, function ($subQuery) use ($callback) {
                    $this->applyRelations($subQuery, $callback);
                });
            }

            return $query;
        }

        return $query->whereHas($field, function ($subQuery) use ($callback) {
            // Check if the field exists in the sub-query model
            if (Schema::hasColumn($subQuery->getModel()
                ->getTable(), $this->filterFiled)) {
                $this->applyRelations($subQuery, $callback);
            }
        });
    }

    /**
     * @param Builder $query
     * @param string  $field
     * @param array   $filters
     *
     * @throws Exception
     *
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $field, array $filters): void
    {
        foreach ($filters as $subField => $subFilter) {
            $this->prepareModelForRelation($field);
            $this->validateField($field);
            $this->validateOperator($field, $subField);

            $this->fields[] = $this->model->getField($field);
            $this->filter($query, $subField, $subFilter);
        }
        $this->restorePreviousModel();
    }

    private function prepareModelForRelation(string $field): void
    {
        $relation = end($this->fields);
        if ($relation !== false) {
            $this->previousModels[] = $this->model;

            $this->model = $this->model->$relation()->getRelated();
        }
    }

    private function restorePreviousModel(): void
    {
        array_pop($this->fields);
        if (!empty($this->previousModels)) {
            $this->model = array_pop($this->previousModels);
        }
    }

    /**
     * @param string $field
     *
     * @return void
     */
    private function validateField(string $field): void
    {
        $availableFields = $this->model->availableFields();

        if (!in_array($field, $availableFields)) {
            throw FieldNotSupported::create($field, $this->model::class, $availableFields);
        }
    }

    /**
     * @param string $field
     * @param string $operator
     *
     * @return void
     */
    private function validateOperator(string $field, string $operator): void
    {
        $availableFilters = $this->model->getAvailableFiltersFor($field);

        if (!$availableFilters || in_array($operator, $availableFilters)) {
            return;
        }

        throw OperatorNotSupported::create($field, $operator, $availableFilters);
    }
}
