<?php
declare(strict_types=1);

/**
 * JSON response helpers. Carried over from Friendspace unchanged.
 *
 * Every method that sends a response returns `never` — they exit. That keeps
 * call sites free of `return` boilerplate after an error, which is why the
 * pattern is worth preserving.
 */
final class Response
{
    public static function json($data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['error' => $message], $extra), $status);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error($message, 404);
    }

    /** Parse and return the JSON request body as an array. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::error('Request body must be valid JSON.', 400);
        }
        return $data;
    }
}
