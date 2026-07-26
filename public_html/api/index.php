<?php
declare(strict_types=1);

/**
 * Front controller. Every /api/* request lands here (see ../.htaccess).
 *
 * Router pattern carried over from Friendspace: `{param}` becomes a named
 * regex group, and each route file registers onto $router. The value of the
 * pattern is that CSRF is verified ONCE here, before any routing — a per-route
 * check is one omission away from a hole.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

// Before routing, not per-route. Safe methods pass through.
Csrf::verify();

final class Router
{
    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        // "nutrition/day/{date}" → a regex with a named group per {param}.
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;

        foreach ($this->routes as [$m, $regex, $handler]) {
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            // Keep only the named captures.
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($params);
            return;
        }

        // A path that exists under a different verb is a 405, not a 404 —
        // otherwise a wrong-method bug looks like a missing endpoint.
        if ($pathMatched) {
            Response::error('Method not allowed for this endpoint.', 405);
        }
        Response::notFound('No such endpoint.');
    }
}

$router = new Router();

// Route modules register themselves onto $router.
require YK_SRC . '/routes/health.php';
require YK_SRC . '/routes/auth.php';
require YK_SRC . '/routes/onboarding.php';
require YK_SRC . '/routes/nutrition.php';
// checkin BEFORE training: training.php registers `PUT checkin/{date}` for the
// DAILY check-in, and that pattern would swallow `PUT checkin/weekly` with
// date="weekly". First match wins, so the more specific file goes first.
require YK_SRC . '/routes/checkin.php';
require YK_SRC . '/routes/training.php';
require YK_SRC . '/routes/notifications.php';
require YK_SRC . '/routes/tomorrow.php';
require YK_SRC . '/routes/chat.php';

// ---- Resolve path ----------------------------------------------------------

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Strip the /api/ prefix wherever the app is mounted.
$path = preg_replace('#^.*?/api/?#', '', $uri);
$path = trim((string) $path, '/');

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
