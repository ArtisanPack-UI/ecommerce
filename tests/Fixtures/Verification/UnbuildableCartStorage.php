<?php

declare( strict_types=1 );

namespace Tests\Fixtures\Verification;

use RuntimeException;

/**
 * A satellite CartStorage stand-in that can't be built without
 * infrastructure (think Redis). Discovery must still report it.
 */
class UnbuildableCartStorage
{
    public function __construct()
    {
        throw new RuntimeException( 'Redis connection refused.' );
    }
}
