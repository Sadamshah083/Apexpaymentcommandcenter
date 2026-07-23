<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class MemberJwtService
{
    /**
     * Issue a signed JWT for a workspace team member.
     * Expires after config('jwt.ttl_hours') (default 9 hours).
     */
    public function issue(User $user, ?int $workspaceId = null): string
    {
        $secret = $this->secret();
        $ttlHours = max(1, (int) config('jwt.ttl_hours', 9));
        $now = time();
        $workspaceId = $workspaceId ?: (int) ($user->current_workspace_id ?? 0);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'iss' => (string) config('jwt.issuer', config('app.url')),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlHours * 3600),
            'sub' => (string) $user->id,
            'email' => (string) $user->email,
            'name' => (string) $user->name,
            'wid' => $workspaceId > 0 ? $workspaceId : null,
            'jti' => (string) Str::uuid(),
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $signingInput = $headerB64.'.'.$payloadB64;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $this->secret(), true));

        if (! hash_equals($expected, $signatureB64)) {
            return null;
        }

        try {
            $payload = json_decode($this->base64UrlDecode($payloadB64), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && time() >= $exp) {
            return null;
        }

        return $payload;
    }

    public function ttlHours(): int
    {
        return max(1, (int) config('jwt.ttl_hours', 9));
    }

    protected function secret(): string
    {
        $secret = (string) config('jwt.secret');
        if ($secret === '') {
            // Fall back to APP_KEY so local/dev still works before JWT_SECRET is set.
            $secret = (string) config('app.key');
        }

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured in .env.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $secret;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }
}
