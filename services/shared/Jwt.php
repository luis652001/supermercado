<?php
declare(strict_types=1);

final class JwtException extends RuntimeException
{
}

/**
 * JWT HS256. Se rechaza cualquier otro algoritmo (incluido "none").
 */
final class Jwt
{
    public const ISSUER = 'supermercado-auth';
    private const LEEWAY_SECONDS = 30;

    public static function encode(array $claims, string $secret): string
    {
        $segments = [
            self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE)),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $secret, true));
        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Formato de token inválido');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new JwtException('Algoritmo no permitido');
        }

        $expected = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
        if (!hash_equals($expected, self::base64UrlDecode($signatureB64))) {
            throw new JwtException('Firma inválida');
        }

        $claims = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($claims)) {
            throw new JwtException('Payload inválido');
        }

        $now = time();
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < $now - self::LEEWAY_SECONDS) {
            throw new JwtException('Token expirado');
        }
        if (isset($claims['nbf']) && $claims['nbf'] > $now + self::LEEWAY_SECONDS) {
            throw new JwtException('Token aún no válido');
        }
        if (($claims['iss'] ?? '') !== self::ISSUER) {
            throw new JwtException('Emisor inválido');
        }
        return $claims;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JwtException('Codificación inválida');
        }
        return $decoded;
    }
}
