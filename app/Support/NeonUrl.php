<?php

namespace App\Support;

class NeonUrl
{
    /**
     * Neon routes connections by the TLS SNI hostname. Postgres client libraries
     * without SNI support (as in the Vercel PHP runtime) fail with "Endpoint ID is
     * not specified", so Neon accepts the endpoint ID in the password instead:
     * `endpoint=<endpoint-id>$<password>`.
     *
     * @see https://neon.tech/docs/connect/connection-errors#the-endpoint-id-is-not-specified
     */
    public static function withEndpointInPassword(mixed $url): mixed
    {
        // Raw env() values: anything that isn't a non-empty string passes through untouched.
        $parts = is_string($url) && $url !== '' ? parse_url($url) : false;

        if (! $parts || ! isset($parts['host'], $parts['pass']) || ! str_ends_with($parts['host'], '.neon.tech')) {
            return $url;
        }

        if (str_starts_with(rawurldecode($parts['pass']), 'endpoint=')) {
            return $url;
        }

        $endpoint = preg_replace('/-pooler$/', '', explode('.', $parts['host'])[0]);
        $prefix = rawurlencode("endpoint={$endpoint}\$");

        return preg_replace(
            '#^([a-z]+://[^:/@]+:)#i',
            '${1}'.$prefix,
            $url,
            1,
        );
    }
}
