<?php

declare(strict_types=1);

namespace App\Domain\Search\Exceptions;

use RuntimeException;

/** Meilisearch could not answer (down, slow, misconfigured). The API degrades to the database. */
final class SearchUnavailable extends RuntimeException {}
