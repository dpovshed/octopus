<?php

declare(strict_types=1);

namespace Octopus\Http;

enum StatusCodes: int
{
    /**
     * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.4.2
     */
    case MOVED_PERMANENTLY = 301;

    /**
     * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.4.3
     */
    case FOUND = 302;

    /**
     * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.4.4
     */
    case SEE_OTHER = 303;
    /**
     * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.4.7
     */
    case TEMPORARY_REDIRECT = 307;

    /**
     * @see https://datatracker.ietf.org/doc/html/rfc7538#section-3
     */
    case PERMANENT_REDIRECT = 308;
}
