<?php

declare(strict_types=1);

namespace Jcolombo\GranolaApiPhp\Utility;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';

    public function isMutation(): bool
    {
        return $this !== self::GET;
    }
}
