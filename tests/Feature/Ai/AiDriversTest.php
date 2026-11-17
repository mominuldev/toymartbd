<?php

declare(strict_types=1);

use App\Domain\Ai\Drivers\GeminiDriver;
use App\Domain\Ai\Drivers\OpenAiDriver;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\Exceptions\AiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false];

it('sends OpenAI a Responses request with a system message and a strict JSON schema, and reads the answer', function () use ($schema): void {
    Http::fake(['api.openai.com/v1/responses' => Http::response([
        'status' => 'completed', 'model' => 'gpt-6-astra',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"title":"Kids Tricycle"}']]]],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
    ])]);

    $r = app(OpenAiDriver::class)->complete(new AiPrompt('sys', 'user', $schema, 'copy'), 'gpt-6-astra', 'sk-test', 500);

    expect($r->json)->toBe(['title' => 'Kids Tricycle'])->and($r->inputTokens)->toBe(120)->and($r->outputTokens)->toBe(30);
    Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer sk-test')
        && $req['input'][0] === ['role' => 'system', 'content' => 'sys']
        && $req['max_output_tokens'] === 500
        && $req['text']['format'] === ['type' => 'json_schema', 'name' => 'copy', 'schema' => $schema, 'strict' => true]);
});

it('maps OpenAI refusals and rejected keys', function (array $body, int $status, string $reason): void {
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    expect(fn () => app(OpenAiDriver::class)->complete(new AiPrompt('s', 'u'), 'gpt-6-astra', 'k', 100))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe($reason));
})->with([
    'refusal' => [['status' => 'completed', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'no']]]]], 200, AiException::REFUSED],
    'auth' => [['error' => ['message' => 'bad key']], 401, AiException::AUTH],
    'rate' => [[], 429, AiException::RATE_LIMITED],
    'incomplete' => [['status' => 'incomplete', 'output' => []], 200, AiException::INVALID_OUTPUT],
]);

it('sends Gemini generateContent with the key header and a JSON schema, and reads parts and usage', function () use ($schema): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"title":"Teddy"}']]]]],
        'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 5],
    ])]);

    $r = app(GeminiDriver::class)->complete(new AiPrompt('sys', 'user', $schema), 'gemini-4-carbon', 'g-key', 400);

    expect($r->json)->toBe(['title' => 'Teddy'])->and($r->outputTokens)->toBe(25);
    Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v1beta/models/gemini-4-carbon:generateContent')
        && $req->hasHeader('x-goog-api-key', 'g-key')
        && $req['generationConfig'] === ['maxOutputTokens' => 400, 'responseMimeType' => 'application/json', 'responseJsonSchema' => $schema]
        && $req['systemInstruction'] === ['parts' => [['text' => 'sys']]]);
});

it('treats a Gemini safety block as a refusal', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

    expect(fn () => app(GeminiDriver::class)->complete(new AiPrompt('s', 'u'), 'gemini-4-carbon', 'k', 100))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe(AiException::REFUSED));
});

it('reads a Gemini 400 API_KEY_INVALID as a rejected key', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid. Please pass a valid API key.', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)]);

    expect(fn () => app(GeminiDriver::class)->check('bad', 'gemini-4-carbon'))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe(AiException::AUTH));
});

it('checks a Gemini key on the cheapest model, whatever model the features use', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'OK']]]]]])]);

    app(GeminiDriver::class)->check('g-key', 'gemini-4-carbon');

    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/models/gemini-3.5-flash-lite:generateContent'));
});

it('reports a Gemini quota refusal as rate limited', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

    expect(fn () => app(GeminiDriver::class)->check('g-key', 'gemini-4-carbon'))
        ->toThrow(fn (AiException $e) => expect($e->reason)->toBe(AiException::RATE_LIMITED));
});
