<?php
declare(strict_types=1);

namespace Acme\Sql\Builder;

use Acme\Sql\Query;
use Acme\Sql\Internal\SelectBuilder;

final class UserQueryFactory
{
    public function __construct(
        private readonly string $tenant,
    ) {
    }

    public function activeUsersQuery(string $role, int $minAge, string $country): Query
    {
        $builder = new SelectBuilder();
        // canonical fluent chain: 4 with-calls + build
        $builder
            ->withTable('users')
            ->withColumns(['id', 'email', 'role'])
            ->withCondition('role = ?', $role)
            ->withCondition('age >= ?', $minAge);

        $query = $builder->build();
        $query->setTag('tenant', $this->tenant);
        $query->setTag('country', $country);
        return $query;
    }

    public function adminsQuery(string $country): Query
    {
        return $this->activeUsersQuery('admin', 18, $country);
    }

    public function staffQuery(string $country): Query
    {
        return $this->activeUsersQuery('staff', 16, $country);
    }
}
