<?php


namespace Merkeleon\ElasticReader\Elastic;


use Illuminate\Container\Container;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class SearchModel
{
    protected static $index;
    protected static $type;

    protected $queryBuilder;
    protected $callbacks;

    public static function search(QueryBuilder $builder = null, array $callbacks = null)
    {
        $elastic = app(Elastic::class);

        $builderParameters = $builder ? $builder->build() : [];

        $parameters = array_merge(
            [
                'index' => static::$index,
                'type'  => static::$type,
            ],
            $builderParameters
        );

        $elasticResponse = $elastic->search($parameters);

        return SearchCollection::createFromElasticResponse($elasticResponse, static::class . '::prepareHit', $callbacks);
    }

    public function query(QueryBuilder $queryBuilder = null)
    {
        if ($queryBuilder)
        {
            $this->queryBuilder = $queryBuilder;
        }
        elseif (!$this->queryBuilder)
        {
            $this->queryBuilder = new QueryBuilder();
        }

        return $this->queryBuilder;
    }

    public function get()
    {
        return static::search($this->query(), $this->callbacks);
    }

    public function first()
    {
        $this->query()
             ->size(1);

        return $this->get()->first();
    }

    public function orderBy($orderField, $orderDirection)
    {
        $this->query()
             ->sort($orderField . ':' . $orderDirection);
    }

    public function paginate($perPage)
    {
        $page = Paginator::resolveCurrentPage();

        $offSet = max(0, ($page - 1) * $perPage);

        $this->query()
             ->from($offSet)
             ->size($perPage);

        $results = $this->get();

        return $this->paginator($results, $results->getTotal(), $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function chunkById($count, callable $callback)
    {
        $offset = 0;
        do
        {
            $this->query()
                 ->from($offset)
                 ->size($count);

            $results = $this->get();

            $countResults = $results->count();

            if ($countResults == 0)
            {
                break;
            }

            if ($callback($results) === false)
            {
                return false;
            }

            unset($results);

            $offset += $count;

        } while ($countResults == $count);

        return true;
    }

    public function firstOrFail()
    {
        if ($first = $this->first())
        {
            return $first;
        }

        throw new \Exception('Model ' . static::class . 'not Found');
    }

    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return Container::getInstance()
                        ->makeWith(LengthAwarePaginator::class, compact(
                            'items', 'total', 'perPage', 'currentPage', 'options'
                        ));
    }

    public static function prepareHit($hit)
    {
        return $hit;
    }

    public static function create($params)
    {
        $createParams = [
            'index' => static::$index,
            'type'  => static::$type,
            'body'  => $params
        ];

        return app(Elastic::class)->index($createParams);
    }

    public static function prepareItems(array $items)
    {
        return $items;
    }

    public function addCallback(callable $callback)
    {
        $this->callbacks[] = $callback;

        return $this;
    }
}