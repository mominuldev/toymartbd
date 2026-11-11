<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An RFC 9457 problem document: `{ type, title, status, detail, errors? }`
 * served as `application/problem+json`.
 *
 * Validation `errors` is a flat list of `{ field, message }` — one entry per
 * message, `field` in dot notation (`items.0.qty`). A list rather than a keyed
 * object so it maps onto one simple OpenAPI schema and keeps message order.
 */
final readonly class ProblemDetails implements Responsable
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  list<array{field: string, message: string}>|null  $errors
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $debug  only ever set when app.debug is true
     */
    public function __construct(
        public ProblemType $type,
        public int $status,
        public string $detail,
        public ?array $errors = null,
        public array $headers = [],
        public ?array $debug = null,
    ) {}

    public static function make(ProblemType $type, ?string $detail = null): self
    {
        return new self($type, $type->status(), $detail ?? $type->defaultDetail());
    }

    public static function fromThrowable(Throwable $e): self
    {
        $problem = match (true) {
            $e instanceof ValidationException => new self(
                ProblemType::ValidationError,
                $e->status,
                ProblemType::ValidationError->defaultDetail(),
                self::flattenErrors($e->errors()),
            ),
            $e instanceof AuthenticationException => self::make(ProblemType::Unauthenticated),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::make(ProblemType::ServerError),
        };

        if (config('app.debug') === true && $problem->status >= 500) {
            return new self($problem->type, $problem->status, $problem->detail, $problem->errors, $problem->headers, [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return $problem;
    }

    private static function fromHttpException(HttpExceptionInterface $e): self
    {
        $status = $e->getStatusCode();
        $type = ProblemType::fromStatus($status);

        /** @var array<string, string> $headers */
        $headers = $e->getHeaders();

        return new self($type, $status, self::safeDetail($e, $type), headers: $headers);
    }

    /**
     * Only 4xx messages written deliberately (e.g. a Policy deny reason or an
     * `abort(409, '…')`) are passed through. 5xx and not-found never are:
     * ModelNotFoundException names the model class and ids.
     */
    private static function safeDetail(HttpExceptionInterface $e, ProblemType $type): string
    {
        $message = $e->getMessage();

        if ($message === ''
            || $e->getStatusCode() >= 500
            || $type === ProblemType::NotFound
            || $type === ProblemType::MethodNotAllowed
            || $e->getPrevious() instanceof ModelNotFoundException) {
            return $type->defaultDetail();
        }

        return $message;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return list<array{field: string, message: string}>
     */
    private static function flattenErrors(array $errors): array
    {
        $flat = [];

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = ['field' => (string) $field, 'message' => $message];
            }
        }

        return $flat;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->uri(),
            'title' => $this->type->title(),
            'status' => $this->status,
            'detail' => $this->detail,
            'errors' => $this->errors,
            'debug' => $this->debug,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            $this->toArray(),
            $this->status,
            [...$this->headers, 'Content-Type' => self::CONTENT_TYPE],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
