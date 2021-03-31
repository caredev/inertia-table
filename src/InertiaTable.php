<?php

namespace harmonic\InertiaTable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class InertiaTable
{
    /**
     * Generates inertia view data for model.
     *
     * @param Model $model The model to use to retrieve data
     * @param array $columns An array of column names to send to front end (null for all columns)'
     * @param array $filterable A subset of the $columns array containing names of columsn that can be filtered
     * @param string $page The name of the inertia page to render, example "PageName" or "Folder/PageName"
     * @return void
     */
    public function render(Model $model, array $columns = null, array $filterable = null, string $page = null)
    {
        $modelName = class_basename($model);
        $modelPlural = Str::plural($modelName);
        
        // Show all columns if no columns are specified
        if ($columns === null) { 
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);
        }
        
        // Select which columns are filtrable. All are, if no column is specified.
        $filterable ??= $columns;
       
        return Inertia::render($page === null ? $modelPlural.'/Index' : (strpos($page, '/') === false ? "$modelPlural/$page" : $page), [
            'filters' => Request::all('search', 'trashed'),
            'order' => Request::all('orderColumn', 'orderDirection'),
            strtolower($modelPlural) => $model
                ->order(Request::input('orderColumn') ?? $model->getKeyName(), Request::input('orderDirection'))
                ->filter(Request::only('search', 'trashed'), $filterable)
                ->get()
                ->transform(function ($item) use ($columns) {
                    $data = [];
                    foreach ($columns as $column) {
                        $data[$column] = $item->$column;
                    }

                    return $data;
                }),
        ]);
    }
}
