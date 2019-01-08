<?php


namespace Merkeleon\ElasticReader\Elastic;


use Illuminate\Container\Container;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class SearchModelNew
{
    protected $queryBuilder;
    protected $callbacks;
    protected $hitCallback;

    public function __construct($index, callable $hitCallback = null, $callbacks = null)
    {
        $this->index = $index;
        $this->hitCallback = $hitCallback ?: $this->getDefaultHitCallback();
    }

    protected function getDefaultHitCallback()
    {
        $defaultCallback =  function ($hit)
        {
            return $hit;
        };

        return $defaultCallback;
    }

    public function search(QueryBuilder $builder = null, array $callbacks = null)
    {
        $elastic = app(Elastic::class);

        $builderParameters = $builder ? $builder->build() : [];

        $parameters = array_merge(
            [
                'index' => $this->index,
            ],
            $builderParameters
        );

        logger()->info('Merkeleon\ElasticReader\Elastic\SearchModelNew::search with params', $parameters);
        
        $elasticResponse = $elastic->search($parameters);

        return SearchCollection::createFromElasticResponse($elasticResponse, $this->hitCallback, $callbacks);
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
        return $this->search($this->query(), $this->callbacks);
    }

    public function first()
    {
        $this->query()
             ->size(1);

        return $this->get()
                    ->first();
    }

    public function orderBy($orderField, $orderDirection)
    {
        $this->query()
             ->sort($orderField . ':' . $orderDirection);

        return $this;
    }

    public function getTotal()
    {
        $this->query()
             ->size(1);

        return $this->get()
                    ->getTotal();
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

        throw new \Exception('Model not Found');
    }

    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return Container::getInstance()
                        ->makeWith(LengthAwarePaginator::class, compact(
                            'items', 'total', 'perPage', 'currentPage', 'options'
                        ));
    }

    public function create($params)
    {
        $createParams = [
            'index' => $this->index,
            'type' => '_doc',
            'body'  => $params
        ];

        $response = app(Elastic::class)->index($createParams);

        if ($response['result'] != 'created')
        {
            throw new \Exception('Can not create Elastic Model ');
        }

        $hit = app(Elastic::class)->get(
            [
                'index' => $this->index,
                'id'    => $response['_id'],
                'type' => '_doc',
            ]);

        $method = $this->hitCallback;

        return $method($hit);
    }

    public function addCallback(callable $callback)
    {
        $this->callbacks[] = $callback;

        return $this;
    }
}