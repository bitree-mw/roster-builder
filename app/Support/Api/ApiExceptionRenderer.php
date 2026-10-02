<?php

namespace App\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders exceptions for JSON requests through ApiResponse so errors share the success envelope.
 * Registered in bootstrap/app.php; returns null for browser page requests so Laravel's HTML pages still apply.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => $this->validation($exception),
            $exception instanceof AuthenticationException => $this->fromStatus(401),
            $exception instanceof HttpExceptionInterface => $this->http($exception),
            default => $this->serverError($exception),
        };
    }

    private function validation(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $message = config('api.errors.validation.message') ?? (collect($errors)->flatten()->first() ?? 'Some details need attention.');

        return ApiResponse::error($message, $exception->status, (string) config('api.errors.validation.code', 'validation_failed'), $errors);
    }

    private function http(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $replace = ['resource' => $this->missingResource($exception)];

        return $this->fromStatus($status, $replace, $exception->getHeaders(), $exception->getMessage());
    }

    private function serverError(Throwable $exception): JsonResponse
    {
        $extra = config('app.debug') && config('api.expose_debug') ? ['debug' => [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'location' => $exception->getFile().':'.$exception->getLine(),
        ]] : [];
        $error = config('api.errors.500');

        return ApiResponse::error($error['message'], 500, $error['code'], extra: $extra);
    }

    /**
     * Configured statuses always use configured wording. For other statuses the exception's own
     * message is used when present (for example abort(409, '...')), else the default entry.
     *
     * @param  array<string, string>  $replace
     * @param  array<string, string>  $headers
     */
    private function fromStatus(int $status, array $replace = [], array $headers = [], string $fallbackMessage = ''): JsonResponse
    {
        $configured = config("api.errors.{$status}");
        $error = $configured ?? config('api.errors.default');
        $message = $configured === null && $fallbackMessage !== '' ? $fallbackMessage : $error['message'];

        return ApiResponse::error(ApiResponse::replace($message, $replace), $status, $error['code'], headers: $headers);
    }

    private function missingResource(Throwable $exception): string
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof ModelNotFoundException && $previous->getModel()) {
            return Str::of(class_basename($previous->getModel()))->snake(' ')->lower()->toString();
        }

        return 'record';
    }
}
