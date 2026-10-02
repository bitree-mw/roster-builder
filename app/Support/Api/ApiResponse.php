<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Builds every JSON response with one envelope so the frontend can rely on a consistent shape.
 *
 * Success: {"success": true, "message": "...", "data": {...}, "links"/"meta" for paginated lists}
 * Error:   {"success": false, "message": "...", "code": "not_found", "errors": {...}}
 *
 * Wording, codes and envelope options live in config/api.php.
 */
class ApiResponse
{
    /** Keys owned by resources/pagination that configured extras must never overwrite. */
    private const RESERVED = ['data', 'errors', 'links', 'meta'];

    /**
     * Wrap an API Resource (single or collection) with the success envelope and an optional message.
     *
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function resource(JsonResource $resource, ?string $messageKey = null, array $replace = [], int $status = 200): JsonResponse
    {
        return $resource->additional(self::envelope(true, $messageKey ? self::message($messageKey, $replace) : null))
            ->response()->setStatusCode($status);
    }

    /**
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function created(JsonResource $resource, string $messageKey, array $replace = []): JsonResponse
    {
        return self::resource($resource, $messageKey, $replace, 201);
    }

    /**
     * A success response without a resource, for example after a deletion or sign-out.
     *
     * @param  array<string, string|int|float|null>  $replace
     * @param  array<string, mixed>  $data
     */
    public static function success(string $messageKey, array $replace = [], array $data = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([...self::envelope(true, self::message($messageKey, $replace)), ...$data], $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, int $status, string $code, array $errors = [], array $extra = [], array $headers = []): JsonResponse
    {
        $body = self::envelope(false, $message);
        if (config('api.envelope.include_error_code', true)) {
            $body['code'] = $code;
        }
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse([...$body, ...$extra], $status, $headers);
    }

    /**
     * Resolve a configured message and replace :placeholders. Unknown keys fall back to the key itself
     * so a missing translation is visible during development rather than silently blank.
     *
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function message(string $key, array $replace = []): string
    {
        $message = config("api.messages.{$key}");
        $message = is_string($message) ? $message : $key;

        return self::replace($message, $replace);
    }

    /**
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function replace(string $message, array $replace): string
    {
        uksort($replace, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($replace as $placeholder => $value) {
            $message = str_replace(':'.$placeholder, (string) $value, $message);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    private static function envelope(bool $success, ?string $message): array
    {
        $body = config('api.envelope.include_success', true) ? ['success' => $success] : [];
        if ($message !== null && $message !== '') {
            $body['message'] = $message;
        }

        return [...Arr::except((array) config('api.envelope.extra', []), self::RESERVED), ...$body];
    }
}
