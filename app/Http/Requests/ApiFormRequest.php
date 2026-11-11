<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every API FormRequest — the only validation site (CLAUDE.md rule 14).
 *
 * Failed validation throws ValidationException as usual; the exception handler in
 * bootstrap/app.php renders it as a `validation-error` problem with `errors[]` of
 * `{ field, message }`. Failed authorisation renders as `forbidden`.
 *
 * Both methods are abstract on purpose: every request must state its
 * authorisation decision (usually a Policy check) and its rules explicitly.
 */
abstract class ApiFormRequest extends FormRequest
{
    abstract public function authorize(): bool;

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;
}
