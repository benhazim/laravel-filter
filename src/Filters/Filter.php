<?php

namespace Abbasudo\Purity\Filters;

use Illuminate\Database\Eloquent\Builder;

abstract class Filter implements \Abbasudo\Purity\Contracts\Filter
{
    protected Builder $query;
    protected string  $column;
    protected array   $values;
    protected bool $forceTrashed;

    public function __construct(Builder $query, string $column, array $values, $forceTrashed = false)
    {
        $this->query = $query;
        $this->column = $column;
        $this->values = $values;
        $this->forceTrashed = $forceTrashed;
    }

    /**
     * @return string
     */
    public static function operator(): string
    {
        return static::$operator;
    }
}
