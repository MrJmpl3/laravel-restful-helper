<?php
namespace MrJmpl3\LaravelRestfulHelper;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\ArrayShape;
use Throwable;

/**
 * @deprecated
 */
class LaravelRestfulHelperLegacy extends RestfulHelper
{
    public function toModel(): Model|null
    {
        $query = $this->queryBuilder;

        if ($this->canFields) {
            $query = $this->executeFields($query);
        }

        if ($this->canFilters) {
            $query = $this->executeFilter($query);
        }

        return $query->first();
    }

    /**
     * Get the fields of the request and get the original columns value using the transformers.
     */
    public function getFieldsRequest(): \Illuminate\Support\Collection
    {
        $columns = Schema::getColumnListing($this->model->getTable());

        return collect(explode(',', request()->input('fields')))
            ->filter(fn ($item) => ! empty($item))
            ->map(function ($item) {
                if ($this->existsTransformedColumn($item)) {
                    return $this->getOriginalColumn($item);
                }

                return $item;
            })
            ->filter(fn ($item) => \in_array($item, $columns));
    }

    /**
     * @throws Throwable
     */
    public function toCollection(): Collection|LengthAwarePaginator
    {
        $query = $this->queryBuilder;

        if ($this->canFields) {
            $query = $this->executeFields($query);
        }

        if ($this->canFilters) {
            $query = $this->executeFilter($query);
        }

        if ($this->canSorts) {
            $query = $this->executeSort($query);
        }

        return $this->executePaginate($query);
    }

    public function existInFieldsRequest($key): bool
    {
        $fields = $this->getFieldsRequest();

        if ($fields->isNotEmpty()) {
            return $fields->contains($key);
        }

        return true;
    }

    private function executeFields(Builder $queryBuilder): Builder
    {
        $fieldRequest = $this->getFieldsRequest();

        return $queryBuilder->select($fieldRequest->isEmpty() ? ['*'] : $fieldRequest->all());
    }

    private function executeFilter(Builder $queryBuilder): Builder
    {
        $columns = Schema::getColumnListing($this->model->getTable());

        collect(request()->all())
            ->map(function ($item, $key) {
                if ($key === 'sort' || $key === 'fields' || $key === 'embed' || $key === 'paginate') {
                    return [];
                }

                if ($this->existsTransformedColumn($key)) {
                    return [
                        'column' => $this->getOriginalColumn($key),
                        'value' => $item,
                    ];
                }

                return [
                    'column' => $key,
                    'value' => $item,
                ];
            })
            ->filter(fn ($item) => ! empty($item))
            ->filter(fn ($item) => \in_array($item['column'], $columns))
            ->filter(fn ($item) => \in_array($item['column'], $this->allowedFilters))
            ->each(fn ($item) => $queryBuilder->where($item['column'], '=', $item['value']));

        return $queryBuilder;
    }

    private function executeSort(Builder $queryBuilder): Builder
    {
        collect(explode(',', request()->input('sort')))
            ->filter(fn ($item) => ! empty($item))
            ->map(function ($item) {
                if (Str::startsWith($item, '-')) {
                    $column = mb_substr($item, 1, mb_strlen($item));
                    $type = 'desc';
                } else {
                    $column = $item;
                    $type = 'asc';
                }

                if ($this->existsTransformedColumn($column)) {
                    return [
                        'column' => $this->getOriginalColumn($column),
                        'type' => $type,
                    ];
                }

                return [
                    'column' => $column,
                    'type' => $type,
                ];
            })
            ->filter(fn ($item) => \in_array($item['column'], $this->allowedSorts))
            ->each(fn ($item) => $queryBuilder->orderBy($item['column'], $item['type']));

        return $queryBuilder;
    }

    private function executePaginate(Builder $queryBuilder): Collection|LengthAwarePaginator
    {
        if ( ! $this->canPaginate) {
            return $queryBuilder->get();
        }

        $paginateArr = $this->getPaginateRequest();

        return Arr::get($paginateArr, 'paginate', true)
            ? $queryBuilder->paginate(Arr::get($paginateArr, 'per_page', 15))->appends(request()->all())
            : $queryBuilder->get();
    }

    #[ArrayShape(['paginate' => 'bool', 'per_page' => 'int'])]
    private function getPaginateRequest(): array
    {
        $perPage = 15;
        if (request()->has('per_page')) {
            Validator::validate(request()->all(), [
                'per_page' => 'integer|min:2|max:200',
            ]);

            $perPage = (int) request()->get('per_page');
        }

        $paginate = ! request()->has('paginate') || request()->input('paginate') === 'true';

        return [
            'paginate' => $paginate,
            'per_page' => $perPage,
        ];
    }
}