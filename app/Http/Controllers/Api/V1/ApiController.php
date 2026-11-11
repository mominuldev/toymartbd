<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base for every v1 API controller (docs/SYSTEM-ARCHITECTURE.md §4 "Layer responsibilities").
 *
 * A controller authorises, validates through an ApiFormRequest, calls one Action or
 * Service, and returns an ApiResource. No business logic, no queries. Errors are
 * thrown, never built here: bootstrap/app.php renders them as RFC 9457 problems.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;
}
