<?php

namespace harmonic\InertiaTable;

use Inertia\Inertia;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use \Doctrine\DBAL\Types\Types;
use App\Helpers\DoctrineHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\ConnectionInterface;

class InertiaTable {
    protected $columns;
    private const MODEL_CAST_TYPES = ['object' => 'json', 'array' => 'json', 'collection' => 'json', 'float' => 'decimal'];
    private const CAST_TYPES = [
        Types::ARRAY => 'json',
        Types::BIGINT => 'int',
        Types::BINARY => 'string',
        Types::BLOB => 'string',
        Types::BOOLEAN => 'boolean',
        Types::DATE_MUTABLE => 'date',
        Types::DATE_IMMUTABLE => 'date',
        Types::DATEINTERVAL => 'string',
        Types::DATETIME_MUTABLE => 'datetime',
        Types::DATETIME_IMMUTABLE => 'datetime',
        Types::DATETIMETZ_MUTABLE => 'datetime',
        Types::DATETIMETZ_IMMUTABLE => 'datetime',
        Types::DECIMAL => 'decimal',
        Types::FLOAT => 'decimal',
        Types::GUID => 'string',
        Types::INTEGER => 'int',
        Types::JSON => 'json',
        Types::OBJECT => 'json',
        Types::SIMPLE_ARRAY => 'json',
        Types::SMALLINT => 'int',
        Types::STRING => 'string',
        Types::TEXT => 'text',
        Types::TIME_MUTABLE => 'time',
        Types::TIME_IMMUTABLE => 'time',
        'enum' => 'enum',
        'year' => 'year'
    ];

    private static function getCastFromColumn(Model $model, $column): ?string {
        if (isset($model->invokerCasts) && isset($model->invokerCasts[$column->getName()])) {
            return $model->invokerCasts[$column->getName()];
        }
        if (isset($model->getCasts()[$column->getName()])) {
            $cast = Arr::first(\explode(':', $model->getCasts()[$column->getName()]));
            return self::MODEL_CAST_TYPES[$cast] ?? $cast;
        }
        return self::CAST_TYPES[$column->getType()->getName()];
    }

    private static function getColumnTypes(Model $model): array {
        $items = [];
        $columns = self::getColumns(get_class($model));
        foreach ($columns as $column) {
            $items[$column->getName()] = self::getCastFromColumn($model, $column);
        }
        return $items;
    }

    public static function getColumns(string $className): array {
        $baseModel = new $className();
        DoctrineHelper::registerDoctrineMappings($baseModel);
        return $baseModel->getConnection()->getDoctrineSchemaManager()->listTableColumns($baseModel->getConnection()->getTablePrefix() . $baseModel->getTable());
    }

    public static function getEnumValues(ConnectionInterface $connection, Model $model, $column) {
        $table = $model->getTable();
        $type = $connection->select(DB::raw("SHOW COLUMNS FROM `$table` WHERE Field = :column"), ['column' => $column])[0]->Type;
        preg_match('/^enum\((.*)\)$/', $type, $matches);
        $values = array();
        foreach (explode(',', $matches[1]) as $value) {
            $values[] = trim($value, "'");
        }
        return $values;
    }

    /**
     * Generates inertia view data for model.
     *
     * @param Model $model The model to use to retrieve data
     * @param array $columns An array of column names to send to front end (null for all columns)'
     * @param array $filterable A subset of the $columns array containing names of columsn that can be filtered
     * @param string $view The name of the inertia page to render, example "PageName" or "Folder/PageName"
     * @param bool $pagination If the model should paginate or not
     * @param array $aliases An array of column aliases. The key is the column name and the value the alias
     * @return void
     */
    public function render(
        Builder|string $builder,
        array $columns = null,
        array $filterable = null,
        array $route = null,
        string $view = null,
        bool $pagination = true,
        array $aliases = [],
        array $attach = [],
        ?callable $query = null,
        ?callable $transform = null,
    ) {
        if (is_string($builder) && method_exists($builder, 'query')) {
            $builder = $builder::query();
        }

        $model = $builder->getModel();
        $modelName = class_basename($model);
        $modelPlural = Str::plural($modelName);
        $connection = DB::connection($model->getConnectionName());


        // Show all columns if no columns are specified
        $modelColumnDefinitions = $this->getColumnTypes($model, $columns);
        $enums = [];

        if ($columns === null) {
            $columns = $modelColumnDefinitions;
        } else {
            $cols = [];
            foreach ($columns as $name => $type) {
                if (is_numeric($name)) {
                    $name = $type;
                    $cols[$name] = array_key_exists($name, $modelColumnDefinitions) ? $modelColumnDefinitions[$name] : 'unknown';
                    $type = $cols[$type];
                } else {
                    $type = array_key_exists($name, $modelColumnDefinitions) ? $modelColumnDefinitions[$name] : $type;
                    $cols[$name] = $type;
                }
            }
            $columns = $cols;
        }

        foreach ($columns as $name => $type) {
            // Get enum options for this column
            if ($type === 'enum') {
                $enums[$name] = $this->getEnumValues($connection, $model, $name);
            }
        }

        // Select which columns are filtrable. All are, if no column is specified.
        $filterable ??= array_keys($columns);

        // Order and filter rows
        $builder = $builder
            ->select(array_keys($columns))
            ->order(Request::input('orderColumn') ?? $model->getKeyName(), Request::input('orderDirection'))
            ->filter(Request::only('search', 'trashed'), $filterable);

        if ($query !== null) {
            $builder = $query($builder);
        }

        // Pagination
        if ($pagination) {
            $pagination = $builder->paginate(Request::input('pageSize', config('pagination.default.page', 10)))->withQueryString();

            // Get the records
            $records = $pagination->items();

            // Extract the pagination details & add the available pagination sizes
            $paginationDetails = $pagination->toArray();
            unset($paginationDetails['data']);
            $paginationDetails['page_sizes'] = config('pagination.pages.' . $view, config('pagination.default', [5, 10, 20, 50]));
            $paginationDetails['active'] = true;
        } else {
            $records = $builder->get();
            $paginationDetails = ['active' => false];
        }

        // Guess the page/component name to render
        $view = $view === null ? $modelPlural . '/Index' : (strpos($view, '/') === false ? "$modelPlural/$view" : $view);

        // Map the aliases to the columns
        $aliased =  [];
        foreach ($columns as $name => $type) {
            $aliased[$name] = $aliases[$name] ?? null;
        }

        $data = array_merge($attach, [
            'table' => [
                'model' => [$modelPlural, $modelName],
                'route' => $route ?? [$modelPlural, $modelName],
                'filters' => Request::all('search', 'trashed', 'query'),
                'order' => Request::all('orderColumn', 'orderDirection'),
                'types' => $columns,
                'enums' => $enums,
                'columns' => $aliased,
                'pagination' => $paginationDetails,
                'records' => $transform !== null ? $transform($records) : $records,
            ],
        ]);

        return Inertia::render($view, $data);
    }
}
