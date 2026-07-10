<?php

namespace App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class Controller
{
    /**
     * Validate an AJAX/JSON request, returning the validated data — OR short-circuit with a 422
     * JSON error response. bootstrap/app.php restricts Laravel's AUTOMATIC exception-to-JSON
     * rendering to `api/*` routes (`shouldRenderJsonWhen`), so a plain `$request->validate()` on
     * any other route redirects instead of returning JSON, even for an axios/XHR caller that sent
     * `Accept: application/json`. Endpoints outside `api/*` that must always answer in JSON
     * (2026-07-11 auth-hardening's registration/email-verify steps) use this instead.
     */
    protected function jsonValidate(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $this->jsonFail($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * Short-circuit an AJAX/JSON endpoint with a 422 validation-shaped error response — the same
     * `{message, errors: {field: [...]}}` shape Laravel produces automatically on `api/*` routes.
     *
     * @param  array<string, string|array<int, string>>  $errors
     */
    protected function jsonFail(array $errors): never
    {
        $errors = array_map(fn ($v) => is_array($v) ? array_values($v) : [$v], $errors);
        $first = reset($errors);

        throw new HttpResponseException(response()->json([
            'message' => $first[0] ?? 'The given data was invalid.',
            'errors' => $errors,
        ], 422));
    }
}
