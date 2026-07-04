<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait NormalizesRequestPayload
{
    /**
     * ادغام بدنه JSON خام یا payload تو در تو (data/payload/body) در request.
     * برای کلاینت‌هایی که Content-Type یا ساختار body متفاوت می‌فرستند.
     */
    protected function mergeRequestPayload(Request $request, array $allowedKeys = []): void
    {
        $payload = $this->extractRequestPayload($request);

        if ($payload === []) {
            return;
        }

        if ($allowedKeys !== []) {
            $payload = array_intersect_key($payload, array_flip($allowedKeys));
        }

        if ($payload !== []) {
            $request->merge($payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractRequestPayload(Request $request): array
    {
        if ($request->isJson()) {
            $json = $request->json()->all();
            if (is_array($json) && $json !== []) {
                return $this->unwrapPayload($json);
            }
        }

        $raw = trim((string) $request->getContent());
        if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->unwrapPayload($decoded);
            }
        }

        foreach (['data', 'payload', 'body', 'params', 'input'] as $nested) {
            $value = $request->input($nested);
            if (is_array($value) && $value !== []) {
                return $this->unwrapPayload($value);
            }
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && $decoded !== []) {
                    return $this->unwrapPayload($decoded);
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function unwrapPayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if ($this->isListArray($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $payload[0];
        }

        foreach (['data', 'payload', 'body', 'params', 'input'] as $nested) {
            if (isset($payload[$nested]) && is_array($payload[$nested]) && $payload[$nested] !== []) {
                return $payload[$nested];
            }
        }

        return $payload;
    }

    protected function isListArray(array $payload): bool
    {
        if ($payload === []) {
            return true;
        }

        return array_keys($payload) === range(0, count($payload) - 1);
    }
}
