<?php

namespace AuthService\Helper\Query;

use AuthService\Helper\Collections\UserCollection;
use AuthService\Helper\Models\User;
use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\Pagination\LengthAwarePaginator;

class UserQueryBuilder
{
    protected AuthServiceClient $client;
    protected array $wheres = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected ?int $page = null;
    protected ?int $perPage = null;
    protected array $select = [];

    public function __construct(AuthServiceClient $client)
    {
        $this->client = $client;
    }

    /**
     * Add a where clause to the query
     *
     * @param string $field Field name
     * @param mixed $operatorOrValue Operator or value if operator is '='
     * @param mixed|null $value Value if operator is provided
     * @return $this
     */
    public function where(string $field, $operatorOrValue, $value = null): self
    {
        // If only 2 arguments, assume '=' operator
        if ($value === null) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'type' => 'and'
        ];

        return $this;
    }

    /**
     * Add an OR where clause to the query
     *
     * @param string $field Field name
     * @param mixed $operatorOrValue Operator or value if operator is '='
     * @param mixed|null $value Value if operator is provided
     * @return $this
     */
    public function orWhere(string $field, $operatorOrValue, $value = null): self
    {
        // If only 2 arguments, assume '=' operator
        if ($value === null) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'type' => 'or'
        ];

        return $this;
    }

    /**
     * Add a where in clause to the query
     *
     * @param string $field Field name
     * @param array $values Array of values
     * @return $this
     */
    public function whereIn(string $field, array $values): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'in',
            'value' => $values,
            'type' => 'and'
        ];

        return $this;
    }

    /**
     * Add a where null clause
     *
     * @param string $field Field name
     * @return $this
     */
    public function whereNull(string $field): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'null',
            'value' => null,
            'type' => 'and'
        ];

        return $this;
    }

    /**
     * Add a where not null clause
     *
     * @param string $field Field name
     * @return $this
     */
    public function whereNotNull(string $field): self
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => 'not_null',
            'value' => null,
            'type' => 'and'
        ];

        return $this;
    }

    /**
     * Set the order by clause
     *
     * @param string $field Field to order by
     * @param string $direction Direction (asc or desc)
     * @return $this
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->orderBy = [
            'field' => $field,
            'direction' => strtolower($direction)
        ];

        return $this;
    }

    /**
     * Set the limit
     *
     * @param int $limit Number of records to return
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the offset
     *
     * @param int $offset Number of records to skip
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for limit
     *
     * @param int $count Number of records to return
     * @return $this
     */
    public function take(int $count): self
    {
        return $this->limit($count);
    }

    /**
     * Alias for offset
     *
     * @param int $count Number of records to skip
     * @return $this
     */
    public function skip(int $count): self
    {
        return $this->offset($count);
    }

    /**
     * Select specific fields
     *
     * @param array|string ...$fields Fields to select
     * @return $this
     */
    public function select(...$fields): self
    {
        if (count($fields) === 1 && is_array($fields[0])) {
            $this->select = $fields[0];
        } else {
            $this->select = $fields;
        }

        return $this;
    }

    /**
     * Build query parameters for API request
     *
     * @return array
     */
    protected function buildQueryParams(): array
    {
        $params = [];

        // Build where conditions
        if (!empty($this->wheres)) {
            // Handle simple single where clauses as query params
            foreach ($this->wheres as $where) {
                $field = $where['field'];
                $operator = $where['operator'];
                $value = $where['value'];

                // Map common fields to API parameters
                if ($operator === '=' && in_array($field, ['email', 'name', 'is_admin', 'created_by_service_id'])) {
                    $params[$field] = $value;
                } elseif ($operator === 'like' && in_array($field, ['email', 'name'])) {
                    // API uses partial match automatically
                    $params[$field] = str_replace('%', '', $value);
                } elseif ($field === 'email_verified_at' && $operator === 'null') {
                    $params['email_verified'] = false;
                } elseif ($field === 'email_verified_at' && $operator === 'not_null') {
                    $params['email_verified'] = true;
                }
            }
        }

        // Add ordering
        if (!empty($this->orderBy)) {
            $params['sort_by'] = $this->orderBy['field'];
            $params['sort_order'] = $this->orderBy['direction'];
        }

        // Add field selection
        if (!empty($this->select)) {
            $params['fields'] = implode(',', $this->select);
        }

        // Add pagination params
        if ($this->page !== null) {
            $params['page'] = $this->page;
        }

        if ($this->perPage !== null) {
            $params['per_page'] = $this->perPage;
        }

        return $params;
    }

    /**
     * Build conditions array for find-by endpoint
     *
     * @return array
     */
    protected function buildFindByConditions(): array
    {
        $conditions = [];

        foreach ($this->wheres as $where) {
            $conditions[] = [
                'field' => $where['field'],
                'operator' => $where['operator'],
                'value' => $where['value']
            ];
        }

        return $conditions;
    }

    /**
     * Determine if query needs find-by endpoint
     *
     * @return bool
     */
    protected function needsFindByEndpoint(): bool
    {
        // Check if we have complex conditions
        foreach ($this->wheres as $where) {
            $field = $where['field'];
            $operator = $where['operator'];

            // If not a simple filterable field with = operator
            if (!in_array($field, ['email', 'name', 'is_admin', 'created_by_service_id', 'email_verified_at'])) {
                return true;
            }

            if (!in_array($operator, ['=', 'like', 'null', 'not_null'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute the query and get results
     *
     * @return UserCollection
     */
    public function get(): UserCollection
    {
        // Use find-by endpoint for complex queries
        if ($this->needsFindByEndpoint() && !empty($this->wheres)) {
            $conditions = $this->buildFindByConditions();

            $response = $this->client->post('users/find-by', [
                'conditions' => $conditions
            ]);

            $users = $response['data']['users'] ?? [];

            // Apply limit/offset manually for find-by endpoint
            if ($this->offset !== null || $this->limit !== null) {
                $users = array_slice($users, $this->offset ?? 0, $this->limit);
            }
        } else {
            // Use standard list endpoint
            $params = $this->buildQueryParams();

            // For get() without pagination, use a high per_page
            if ($this->limit !== null && $this->perPage === null) {
                $params['per_page'] = $this->limit;
            } elseif ($this->perPage === null) {
                $params['per_page'] = 100;
            }

            $response = $this->client->get('users', ['query' => $params]);

            $users = $response['data']['items'] ?? $response['data']['users'] ?? [];
        }

        return new UserCollection($users);
    }

    /**
     * Get the first result
     *
     * @return User|null
     */
    public function first(): ?User
    {
        $results = $this->limit(1)->get();
        return $results->first();
    }

    /**
     * Get paginated results
     *
     * @param int $perPage Number of items per page
     * @param int $page Current page number
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $this->perPage = $perPage;
        $this->page = $page;

        $params = $this->buildQueryParams();

        $response = $this->client->get('users', ['query' => $params]);

        $items = $response['data']['items'] ?? [];
        $pagination = $response['data']['pagination'] ?? [];

        $total = $pagination['total'] ?? count($items);
        $currentPage = $pagination['current_page'] ?? $page;
        $perPageActual = $pagination['per_page'] ?? $perPage;

        $collection = new UserCollection($items);

        return new LengthAwarePaginator(
            $collection,
            $total,
            $perPageActual,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    /**
     * Get the count of results
     *
     * @return int
     */
    public function count(): int
    {
        $params = $this->buildQueryParams();

        // Remove pagination params for count
        unset($params['page'], $params['per_page'], $params['fields']);

        $response = $this->client->get('users/count', ['query' => $params]);

        return $response['data']['count'] ?? 0;
    }

    /**
     * Check if any results exist
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get all results as array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->get()->toArray();
    }
}
