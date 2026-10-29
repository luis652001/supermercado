<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

final class Http
{
    private const MAX_BODY_BYTES = 65536;

    public static function run(callable $handler): void
    {
        header_remove('X-Powered-By');
        try {
            $handler();
        } catch (HttpError $e) {
            self::json($e->status, ['error' => $e->getMessage()] + $e->extra);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[%s] %s en %s:%d',
                getenv('SERVICE_NAME') ?: 'servicio',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            self::json(500, ['error' => 'Error interno del servidor']);
        }
    }

    public static function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return rtrim($path, '/') ?: '/';
    }

    /** Cuerpo crudo (necesario para verificar la firma de webhooks de Stripe). */
    public static function rawBody(int $maxBytes = self::MAX_BODY_BYTES): string
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false) {
            return '';
        }
        if (strlen($raw) > $maxBytes) {
            throw new HttpError(413, 'Cuerpo de la petición demasiado grande');
        }
        return $raw;
    }

    public static function body(): array
    {
        $raw = self::rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpError(400, 'JSON inválido');
        }
        return $data;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        return preg_match('/^Bearer\s+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', $header, $m) ? $m[1] : null;
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
