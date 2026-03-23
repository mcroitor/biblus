<?php

namespace Core\Mc;

use Exception;
use Core\Mc\Route;

/**
 * Router supports two modes:
 * - REST mode: dispatch by HTTP method + REQUEST_URI path
 * - Legacy mode: dispatch by GET param (`q` by default)
 *
 * Legacy URL format: http[s]://<domain>/?<route-name>[/params]
 */
class Router
{

    private const ATTRIBUTE_NAME = Route::class;

    private static $routes = [];
    private static $methodRoutes = [];
    private static $templateRoutes = [];
    private static $param = "q";
    private static $default = "/";
    private static $selectedRoute = "/";
    private static $currentPathParams = [];
    private static $currentQueryParams = [];
    private static $currentBody = [];
    private static $isBodyParsed = false;

    /**
        * Set routes.
     * @param array $routes
     */
    public static function init(array $routes = []): void
    {
        self::$routes = [];
        self::$methodRoutes = [];
        self::$templateRoutes = [];
        self::$currentPathParams = [];
        self::$currentQueryParams = [];
        self::$currentBody = [];
        self::$isBodyParsed = false;
        self::$routes[self::$default] = function (): string {
            return "";
        };
        self::match(['GET'], self::$default, self::$routes[self::$default]);
        self::scan_classes();
        self::scan_functions();
        foreach ($routes as $route_name => $route_method) {
            self::register($route_name, $route_method);
        }
    }

    /**
        * Scan classes. Select all static methods and check attributes.
     */
    private static function scan_classes(): void
    {
        $classes = \get_declared_classes();
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_STATIC);
            foreach ($methods as $method) {
                self::register_method($method);
            }
        }
    }

    /**
        * Scan functions. Check attributes.
     */
    private static function scan_functions(): void
    {
        $functions = \get_defined_functions();
        foreach ($functions['user'] as $function) {
            $reflection = new \ReflectionFunction($function);
            self::register_method($reflection);
        }
    }

    /**
      * If method or function has `route` attribute, register it.
      * @param \ReflectionFunctionAbstract $reflection
     */
    private static function register_method($reflection): void
    {
        $attribute = self::get_method_attribute($reflection, self::ATTRIBUTE_NAME);
        if ($attribute != null) {
            /** @var Route $definition */
            $definition = $attribute->newInstance();
            $handler = $reflection->getClosure();

            if (self::isLegacyRouteLabel($definition->path)) {
                self::$routes[$definition->path] = $handler;
            }

            self::match($definition->methods, $definition->path, $handler);
        }
    }

    private static function isLegacyRouteLabel(string $path): bool
    {
        return $path !== '' && strpos($path, '/') !== 0;
    }

    private static function get_method_attribute($method, $attributeName)
    {
        /** @var \ReflectionAttribute $attributes */
        $attributes = $method->getAttributes();
        foreach ($attributes as $attribute) {
            if ($attribute->getName() == $attributeName) {
                return $attribute;
            }
        }
        return null;
    }

    /**
        * Load routes from JSON file.
     */
    public static function load(string $jsonfile = "routes.json"): void
    {
        $routes = json_decode(file_get_contents($jsonfile));
        self::init((array) $routes);
    }

    /**
      * Register a new route.
      * Backward-compatible alias for GET route registration.
      *
      * @param string $route_name
      * @param callable $route_method
      * @return void
     */
    public static function register(string $route_name, callable $route_method): void
    {
        if (is_callable($route_method) === false) {
            throw new Exception("`{$route_method}` is not callable");
        }
        self::$routes[$route_name] = $route_method;
        self::match(["GET"], $route_name, $route_method);
    }

    /**
        * Register GET route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function get(string $path, callable $route_method): void
    {
        self::match(["GET"], $path, $route_method);
    }

    /**
        * Register POST route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function post(string $path, callable $route_method): void
    {
        self::match(["POST"], $path, $route_method);
    }

    /**
        * Register PUT route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function put(string $path, callable $route_method): void
    {
        self::match(["PUT"], $path, $route_method);
    }

    /**
        * Register PATCH route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function patch(string $path, callable $route_method): void
    {
        self::match(["PATCH"], $path, $route_method);
    }

    /**
        * Register DELETE route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function delete(string $path, callable $route_method): void
    {
        self::match(["DELETE"], $path, $route_method);
    }

    /**
        * Register OPTIONS route.
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function options(string $path, callable $route_method): void
    {
        self::match(["OPTIONS"], $path, $route_method);
    }

    /**
        * Register route for one or many HTTP methods.
     * Supports fixed paths (`/api/users`) and templates (`/api/users/{id}`).
     *
     * @param array $methods
     * @param string $path
     * @param callable $route_method
     * @return void
     */
    public static function match(array $methods, string $path, callable $route_method): void
    {
        if (is_callable($route_method) === false) {
            throw new Exception("route method is not callable");
        }
        $normalizedPath = self::normalizePath($path);
        $templateParams = [];
        $isTemplate = self::isTemplatePath($normalizedPath);
        $templateRegex = $isTemplate ? self::compileTemplateRegex($normalizedPath, $templateParams) : null;

        foreach ($methods as $method) {
            $normalizedMethod = strtoupper(trim($method));
            if ($normalizedMethod === "") {
                continue;
            }

            if ($isTemplate) {
                self::$templateRoutes[$normalizedMethod][] = [
                    'path' => $normalizedPath,
                    'regex' => $templateRegex,
                    'params' => $templateParams,
                    'handler' => $route_method
                ];
                continue;
            }

            self::$methodRoutes[$normalizedPath][$normalizedMethod] = $route_method;
        }
    }

    /**
        * Rewrite default param name.
     */
    public static function setParam(string $param): void
    {
        self::$param = $param;
    }

    /**
     * Entry point for routing.
     *
     * Uses REST mode when REQUEST_URI path is present; otherwise falls back
     * to legacy `?q=` routing.
     *
     * @return string
     */
    public static function run(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        self::$currentQueryParams = $_GET ?? [];
        self::$currentPathParams = [];
        self::$currentBody = [];
        self::$isBodyParsed = false;
        $path = self::resolvePath();

        if ($path !== null) {
            return self::runMethodRoute($method, $path);
        }

        $path = $_GET[self::$param]
            ?? filter_input(INPUT_GET, self::$param, FILTER_DEFAULT, ["default" => self::$default]);
        if (empty($path)) {
            $path = self::$default;
        }
        return self::runLegacyRoute($path);
    }

    private static function runLegacyRoute(string $path): string
    {
        $chunks = explode("/", $path);

        // two-word label
        if (count($chunks) > 1 && isset(self::$routes["{$chunks[0]}/{$chunks[1]}"])) {
            self::$selectedRoute = "{$chunks[0]}/{$chunks[1]}";
            array_shift($chunks);
            array_shift($chunks);
            self::$currentPathParams = $chunks;

            return self::invokeHandler(self::$routes[self::$selectedRoute], $chunks);
        }

        // one-word label
        if (isset(self::$routes[$chunks[0]])) {
            self::$selectedRoute = $chunks[0];
            array_shift($chunks);
            self::$currentPathParams = $chunks;

            return self::invokeHandler(self::$routes[self::$selectedRoute], $chunks);
        }
        self::$selectedRoute = self::$default;
        self::$currentPathParams = [];
        return self::invokeHandler(self::$routes[self::$selectedRoute], []);
    }

    private static function runMethodRoute(string $method, string $path): string
    {
        $normalizedPath = self::normalizePath($path);
        $routeMethods = self::$methodRoutes[$normalizedPath] ?? null;

        if ($routeMethods !== null) {
            if (isset($routeMethods[$method])) {
                self::$selectedRoute = $normalizedPath;
                self::$currentPathParams = [];
                return self::invokeHandler($routeMethods[$method], []);
            }

            $allow = implode(', ', array_keys($routeMethods));
            if ($allow !== '') {
                header("Allow: {$allow}");
            }
            http_response_code(405);
            return self::errorPayload(405, 'method_not_allowed', 'Method Not Allowed');
        }

        $templateMatch = self::findTemplateRoute($method, $normalizedPath);
        if ($templateMatch !== null) {
            self::$selectedRoute = $templateMatch['path'];
            self::$currentPathParams = $templateMatch['params'];
            return self::invokeHandler($templateMatch['handler'], $templateMatch['params']);
        }

        $allowedMethods = self::getAllowedMethodsForPath($normalizedPath);
        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', $allowedMethods));
            http_response_code(405);
            return self::errorPayload(405, 'method_not_allowed', 'Method Not Allowed');
        }

        if ($normalizedPath === self::$default && isset(self::$routes[self::$default])) {
            self::$selectedRoute = self::$default;
            self::$currentPathParams = [];
            return self::invokeHandler(self::$routes[self::$default], []);
        }

        http_response_code(404);
        return self::errorPayload(404, 'not_found', 'Not Found');
    }

    private static function invokeHandler(callable $handler, array $params): string
    {
        $closure = \Closure::fromCallable($handler);
        $reflection = new \ReflectionFunction($closure);

        $result = $reflection->getNumberOfParameters() > 0
            ? $closure($params)
            : $closure();

        return is_string($result) ? $result : '';
    }

    private static function isTemplatePath(string $path): bool
    {
        return preg_match('/\{[A-Za-z_][A-Za-z0-9_\-]*\}/', $path) === 1;
    }

    private static function compileTemplateRegex(string $path, array &$paramNames): string
    {
        $paramNames = [];
        if ($path === self::$default) {
            return '#^/$#';
        }

        $segments = explode('/', trim($path, '/'));
        $regexSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_\-]*)\}$/', $segment, $matches) === 1) {
                $paramNames[] = $matches[1];
                $regexSegments[] = '(?P<' . $matches[1] . '>[^/]+)';
                continue;
            }
            $regexSegments[] = preg_quote($segment, '#');
        }

        return '#^/' . implode('/', $regexSegments) . '$#';
    }

    private static function findTemplateRoute(string $method, string $path): ?array
    {
        $routes = self::$templateRoutes[$method] ?? [];

        foreach ($routes as $route) {
            $matches = [];
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = urldecode($matches[$name]);
                }
            }

            return [
                'path' => $route['path'],
                'params' => $params,
                'handler' => $route['handler']
            ];
        }

        return null;
    }

    private static function getAllowedMethodsForPath(string $path): array
    {
        $allowedMethods = [];

        foreach (self::$methodRoutes[$path] ?? [] as $method => $handler) {
            $allowedMethods[] = $method;
        }

        foreach (self::$templateRoutes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowedMethods[] = $method;
                    break;
                }
            }
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        sort($allowedMethods);
        return $allowedMethods;
    }

    /**
        * Get current route path parameters.
     *
     * In template routes returns named params, e.g. `['id' => '42']`.
     * In legacy mode returns positional params.
     *
     * @return array
     */
    public static function getPathParams(): array
    {
        return self::$currentPathParams;
    }

    /**
        * Get current query parameters.
     * @return array
     */
    public static function getQueryParams(): array
    {
        return self::$currentQueryParams;
    }

    /**
        * Parse and return request body.
     *
     * Supported content types:
     * - application/json
     * - application/x-www-form-urlencoded
     *
     * @return array
     */
    public static function getBody(): array
    {
        if (self::$isBodyParsed) {
            return self::$currentBody;
        }

        self::$isBodyParsed = true;
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            self::$currentBody = [];
            return self::$currentBody;
        }

        if (stripos($contentType, 'application/json') === 0) {
            $decoded = json_decode($rawBody, true);
            self::$currentBody = is_array($decoded) ? $decoded : [];
            return self::$currentBody;
        }

        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            $decoded = [];
            parse_str($rawBody, $decoded);
            self::$currentBody = is_array($decoded) ? $decoded : [];
            return self::$currentBody;
        }

        self::$currentBody = [];
        return self::$currentBody;
    }

    /**
        * Encode payload to JSON and set HTTP status/content-type.
     *
     * @param mixed $data
     * @param int $status
     * @return string
     */
    public static function json($data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $encoded = json_encode($data);
        return is_string($encoded) ? $encoded : '{}';
    }

    private static function errorPayload(int $status, string $code, string $message): string
    {
        return self::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status
            ]
        ], $status);
    }

    private static function resolvePath(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return null;
        }

        $uriPath = parse_url($uri, PHP_URL_PATH);
        if (!is_string($uriPath) || $uriPath === '') {
            return null;
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '' && strpos($uriPath, $scriptName) === 0) {
            $uriPath = substr($uriPath, strlen($scriptName));
            if ($uriPath === false) {
                $uriPath = '';
            }
        }

        $normalizedPath = self::normalizePath($uriPath);

        if ($normalizedPath === self::$default) {
            $legacyPath = $_GET[self::$param]
                ?? filter_input(INPUT_GET, self::$param, FILTER_DEFAULT, ["default" => null]);
            if (is_string($legacyPath) && $legacyPath !== '') {
                return null;
            }
        }

        return $normalizedPath;
    }

    private static function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === self::$default) {
            return self::$default;
        }

        $trimmed = '/' . trim($trimmed, '/');
        return preg_replace('#/{2,}#', '/', $trimmed) ?? self::$default;
    }

    /**
        * Get list of routes. If $needle is not empty, return only routes
        * that start with $needle.
     * @param string $needle
     * @return array
     */
    public static function getRoutes(string $needle = ""): array
    {
        $routes = [];
        foreach (self::$routes as $route => $method) {
            if (strpos($route, $needle) === 0) {
                $routes[] = $route;
            }
        }
        return $routes;
    }

    /**
        * Get current route.
     * @return string
     */
    public static function getSelectedRoute(): string
    {
        return self::$selectedRoute;
    }

    /**
        * Redirect to another route.
     * @param string $route
     * @param array $params
     * @return void
     */
    public static function redirect(string $route, array $params = []): void
    {
        $q = self::$param;
        $url = "?{$q}={$route}";
        foreach ($params as $param) {
            $url .= "/{$param}";
        }
        header("Location: {$url}");
        exit();
    }
}
