<?php

namespace App\Http\Controllers\Api\V1\Fleetra;

use App\Http\Controllers\Api\V1\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/*
| Fleetra (ai.dollartraq.com) does not accept Sanctum tokens: it trusts only a
| short-lived RS256 JWT signed by us, and reads the tenant from it. This swaps
| the broker's Sanctum session for one. The public half of the key lives in
| Fleetra's FLEETRA_JWT_PUBLIC_KEY.
*/
class FleetraTokenController extends BaseController
{
    private const TTL_SECONDS = 900;

    public function issue(): JsonResponse
    {
        $user = auth()->user();

        $path = (string) config('services.fleetra.jwt_private_key_path');
        $key = is_readable($path) ? openssl_pkey_get_private(file_get_contents($path)) : false;
        if ($key === false) {
            Log::error('Fleetra signing key missing or unreadable', ['path' => $path]);
            return response()->json(['message' => 'Fleetra is not configured.'], 503);
        }

        $now = time();
        $claims = [
            'sub' => (string) $user->id,
            'tenant_id' => (string) $user->company_id,
            'role' => $user->roleSlug(),
            'iss' => config('app.url'),
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ];

        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::b64(json_encode($claims)),
        ];
        openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256);
        $segments[] = self::b64($signature);

        return response()->json([
            'token' => implode('.', $segments),
            'expires_in' => self::TTL_SECONDS,
        ]);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
