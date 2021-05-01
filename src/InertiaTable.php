<?php

namespace harmonic\InertiaTable;

use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class InertiaTable {
    protected $columns;

    /**
     * Generates inertia view data for model.
     *
     * @param Model $model The model to use to retrieve data
     * @param array $columns An array of column names to send to front end (null for all columns)'
     * @param array $filterable A subset of the $columns array containing names of columsn that can be filtered
     * @param string $page The name of the inertia page to render, example "PageName" or "Folder/PageName"
     * @param bool $pagination If the model should paginate or not
     * @param array $aliases An array of column aliases. The key is the column name and the value the alias
     * @return void
     */
    public function render(Model $model, array $columns = null, array $filterable = null, array $route = null, string $page = null, bool $pagination = true, array $aliases = [], array $attach = []) {
        $modelName = class_basename($model);
        $modelPlural = Str::plural($modelName);

        // Show all columns if no columns are specified
        if ($columns === null) {
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);
        }
        $columns = array_unique(['id', ...$columns]);

        // Select which columns are filtrable. All are, if no column is specified.
        $filterable ??= $columns;

        // Order and filter rows
        $model = $model
            ->select($columns)
            ->order(Request::input('orderColumn') ?? $model->getKeyName(), Request::input('orderDirection'))
            ->filter(Request::only('search', 'trashed'), $filterable);

        // Pagination
        if ($pagination) {
            $pagination = $model->paginate(Request::input('pageSize', config('pagination.default.page', 10)))->withQueryString();

            // Get the records
            $records = $pagination->items();

            // Extract the pagination details & add the available pagination sizes
            $paginationDetails = $pagination->toArray();
            unset($paginationDetails['data']);
            $paginationDetails['page_sizes'] = config('pagination.pages.' . $page, config('pagination.default', [5, 10, 20, 50]));
            $paginationDetails['active'] = true;
        } else {
            $records = $model->get();
            $paginationDetails = ['active' => false];
        }

        // Guess the page/component name to render
        $page = $page === null ? $modelPlural . '/Index' : (strpos($page, '/') === false ? "$modelPlural/$page" : $page);

        // Map the aliases to the columns
        $aliased =  [];
        foreach ($columns as $key => $column) {
            $aliased[$column] = $aliases[$column] ?? null;
        }

        $data = array_merge($attach, [
            'table' => [
                'model' => [$modelPlural, $modelName],
                'route' => $route ?? [$modelPlural, $modelName],
                'filters' => Request::all('search', 'trashed'),
                'order' => Request::all('orderColumn', 'orderDirection'),
                'columns' => $aliased,
                'pagination' => $paginationDetails,
                'records' => $records,
            ],
        ]);

        return Inertia::render($page, $data);
    }
}
