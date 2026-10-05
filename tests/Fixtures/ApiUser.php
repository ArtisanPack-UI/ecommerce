<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;

/**
 * A Sanctum-capable user that needs no table: tests build it in memory and
 * authenticate it with `Sanctum::actingAs()`.
 */
class ApiUser extends User
{
    use HasApiTokens;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Builds an in-memory user with the given id.
     */
    public static function make( int $id ): self
    {
        $user         = new self( [ 'id' => $id, 'name' => 'User ' . $id ] );
        $user->exists = true;

        return $user;
    }
}
