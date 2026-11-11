<?php

declare(strict_types=1);

/*
| Foundation strings. `problems.*` are RFC 9457 problem titles, keyed by the
| stable problem `type` slug so the API exception handler can translate the
| title while `type` itself stays locale-independent (CLAUDE.md rule 18).
*/
return [

    'problems' => [
        'validation' => 'Validation failed',
        'unauthenticated' => 'Unauthenticated',
        'forbidden' => 'Forbidden',
        'not-found' => 'Not found',
        'method-not-allowed' => 'Method not allowed',
        'conflict' => 'Conflict',
        'too-many-requests' => 'Too many requests',
        'server-error' => 'Server error',
        'service-unavailable' => 'Service unavailable',
    ],

];
