<?php

/*
|--------------------------------------------------------------------------
| API response customisation
|--------------------------------------------------------------------------
|
| Every JSON response from /api/v1 and the JSON session endpoints is built by
| App\Support\Api\ApiResponse and App\Support\Api\ApiExceptionRenderer using
| the values below. Change wording, error codes or envelope keys here rather
| than in controllers. Messages are shown to users as pop-up notifications,
| so write them in plain language. Placeholders such as :label are replaced
| with values supplied by the controller.
|
*/

return [

    /*
     * Envelope options. "success" is a boolean on every response; "extra" is merged
     * into every response body (for example ['api_version' => 'v1']). The keys
     * data, errors, links and meta are reserved and cannot be overridden here.
     */
    'envelope' => [
        'include_success' => true,
        'include_error_code' => true,
        'extra' => [],
    ],

    /*
     * Include exception class, message and location in 500 responses. Only ever
     * applies when APP_DEBUG is also true, so production responses stay generic.
     */
    'expose_debug' => env('API_EXPOSE_DEBUG', true),

    /*
     * Success messages, grouped by resource and action. Controllers refer to them as
     * "resource.action", so config('api.messages.flight.disabled') overrides work too.
     */
    'messages' => [
        'session' => [
            'login' => 'Welcome back, :label.',
            'logout' => 'You have signed out.',
        ],
        'aircraft_type' => [
            'created' => 'Aircraft type :label added.',
            'updated' => 'Aircraft type :label updated.',
            'deleted' => 'Aircraft type :label removed.',
        ],
        'aircraft' => [
            'created' => ':label registered and marked available.',
            'updated' => ':label updated.',
            'deleted' => ':label removed from the fleet.',
            'status' => ':label is now :status.',
        ],
        'maintenance' => [
            'created' => ':label recorded for :aircraft.',
            'updated' => ':label for :aircraft updated.',
            'deleted' => ':label for :aircraft removed from the maintenance log.',
        ],
        'crew' => [
            'created' => ':label added to the crew directory.',
            'updated' => ':label updated.',
            'deleted' => ':label removed from the crew directory.',
        ],
        'flight' => [
            'created' => 'Flight :label saved.',
            'updated' => 'Flight :label updated.',
            'deleted' => 'Flight :label deleted.',
            'enabled' => 'Flight :label enabled — it will be included in planning.',
            'disabled' => 'Flight :label disabled — it stays on file but will not be planned.',
        ],
        'rules' => [
            'updated' => 'Duty rules saved. Existing roster periods keep their original snapshot.',
        ],
        'roster_period' => [
            'created' => 'Draft roster period for :label created.',
            'exists' => 'A roster period for :label already exists.',
        ],
    ],

    /*
     * Error responses. "code" is a stable machine-readable identifier; "message" is
     * shown to the user. A null validation message uses the first field error, which
     * is usually the most helpful text. :resource is replaced on 404 responses with
     * the missing model's name (for example "aircraft" or "maintenance record").
     */
    'errors' => [
        'validation' => ['code' => 'validation_failed', 'message' => null],
        401 => ['code' => 'unauthenticated', 'message' => 'Your session has ended. Sign in again to continue.'],
        403 => ['code' => 'forbidden', 'message' => 'You do not have permission to do that.'],
        404 => ['code' => 'not_found', 'message' => 'The requested :resource could not be found. It may have been removed.'],
        405 => ['code' => 'method_not_allowed', 'message' => 'That action is not supported here.'],
        409 => ['code' => 'conflict', 'message' => 'This record changed while you were working. Reload and try again.'],
        419 => ['code' => 'session_expired', 'message' => 'Your session has expired. Reload the page and try again.'],
        429 => ['code' => 'too_many_requests', 'message' => 'Too many requests. Wait a moment and try again.'],
        500 => ['code' => 'server_error', 'message' => 'Something went wrong on our side. Try again, or contact support if it keeps happening.'],
        503 => ['code' => 'unavailable', 'message' => 'The system is temporarily unavailable for maintenance. Try again shortly.'],
        'default' => ['code' => 'request_failed', 'message' => 'The request could not be completed.'],
    ],

];
