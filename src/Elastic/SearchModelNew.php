<?php


namespace Merkeleon\ElasticReader\Elastic;


use Illuminate\Container\Container;
use Illuminate\Pagination\LengthAwarePaginator;
use Merkeleon\ElasticReader\Events\BeforeSearchInElasticSearchEvent;


class SearchModelNew
{
    protected $queryBuilder;
    protected $callbacks;
    protected $hitCallback;
    protected $defaultSorting = [];

    public function __construct($index, callable $hitCallback = null)
    {
        $this->index       = $index;
        $this->hitCallback = $hitCallback ?: $this->getDefaultHitCallback();
    }

    protected function getDefaultHitCallback()
    {
        $defaultCallback = function ($hit) {
            return $hit;
        };

        return $defaultCallback;
    }

    /**
     * @param QueryBuilder|null $builder
     * @param array|null $callbacks
     * @return SearchCollection
     */
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
        event(new BeforeSearchInElasticSearchEvent($parameters));
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
            $this->queryBuilder->setDefaultSorting($this->defaultSorting);
        }

        return $this->queryBuilder;
    }

    /**
     * @return SearchCollection
     */
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
             ->setTrackTotalHits()
             ->size(1);

        return $this->get()
                    ->getTotal();
    }

    public function paginate($perPage)
    {
        /** @var Paginator $paginator */
        $paginator = resolve(Paginator::class);

        try
        {
            $results = $paginator->paginate($this, $perPage);
        }
        catch (PaginatorException $exception)
        {
            return $paginator->getEmtyPaginator();
        }

        return $results;
    }

    public function chunkById($count, callable $callback)
    {
        $searchAfter = null;
        do
        {
            $this->query()
                 ->from(0)
                 ->searchAfter($searchAfter)
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

            $searchAfter = $results->getLastSort();

            unset($results);

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
            'type'  => '_doc',
            'body'  => $params
        ];

        $response = app(Elastic::class)->index($createParams);

        if ($response['result'] != 'created')
        {
            throw new \Exception('Can not create Elastic Model ');
        }

        return $response['_id'];
    }

    public function bulkCreate(array $params)
    {
        $i = 0;
        foreach ($params as $logData)
        {
            $createParams['body'][] = [
                'index' => [
                    '_index' => $this->index,
                    '_type'  => '_doc',
                ]
            ];

            $createParams['body'][] = $logData;

            $i++;

            if ($i % 1000 == 0)
            {
                app(Elastic::class)->bulk($createParams);

                $createParams = ['body' => []];
            }
        }

        if (!empty($createParams['body']))
        {
            app(Elastic::class)->bulk($createParams);
        }
    }

    public function addCallback(callable $callback)
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    public function setDefaultSorting(array $defaultSorting)
    {
        $this->defaultSorting = $defaultSorting;

        return $this;
    }

    public function __clone()
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }
}
