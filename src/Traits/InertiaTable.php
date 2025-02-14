<?php

namespace harmonic\InertiaTable\Traits;

use Illuminate\Support\Str;

trait InertiaTable {
    public function resolveRouteBinding($value) {
        return in_array(SoftDeletes::class, class_uses($this))
            ? $this->where($this->getRouteKeyName(), $value)->withTrashed()->first()
            : parent::resolveRouteBinding($value);
    }

    /**
     * Sort a table by a column asc/desc.
     *
     * @param [type] $query
     * @param string $column The name of the column to order by
     * @param string $order asc or desc
     * @return void
     */
    public function scopeOrder($query, $column, $order = 'asc') {
        if (Str::contains($column, ',')) {
            $columns = explode(',', $column);
            foreach ($columns as $column) {
                preg_match_all('/(\w+)\s*(asc|desc)?/i', $column, $matches, PREG_SET_ORDER, 0);
                if (!empty($matches)) {
                    $column = $matches[0][1];
                    $order = $matches[0][2] ?? $order;
                }

                $query->orderBy($column, $order !== 'asc' ? 'desc' : 'asc');
            }
        } else {
            $query->orderBy($column, $order !== 'asc' ? 'desc' : 'asc');
        }
    }

    /**
     * Search filters.
     *
     * @param [type] $query
     * @param array $filters
     * @param array $searchColumns An array of column names that can be searched
     * @return void
     */
    public function scopeFilter($query, array $filters, array $searchColumns) {
        $query->when($filters['search'] ?? null, function ($query, $search) use ($searchColumns) {
            $query->where(function ($query) use ($search, $searchColumns) {
                if (!empty($searchColumns)) {
                    $query->where(array_shift($searchColumns), 'like', '%' . $search . '%');
                    foreach ($searchColumns as $columnName) {
                        $query->orWhere($columnName, 'like', '%' . $search . '%');
                    }
                }
            });
        })->when($filters['trashed'] ?? null, function ($query, $trashed) {
            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }
        });
    }
}
