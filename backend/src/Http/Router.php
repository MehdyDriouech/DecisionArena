<?php
namespace Http;

class Router {
    private array $routes = [];

    public function get(string $path, $handler): void {
        $this->routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler];
    }

    public function post(string $path, $handler): void {
        $this->routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler];
    }

    public function delete(string $path, $handler): void {
        $this->routes[] = ['method' => 'DELETE', 'path' => $path, 'handler' => $handler];
    }

    public function put(string $path, $handler): void {
        $this->routes[] = ['method' => 'PUT', 'path' => $path, 'handler' => $handler];
    }

    public function dispatch(Request $request): mixed {
        $method = $request->method();
        $uri = $request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            $params = $this->matchPath($route['path'], $uri);
            if ($params !== null) {
                $request->setParams($params);
                $handler = $route['handler'];
                if (is_callable($handler)) {
                    return $handler($request);
                }
                [$class, $action] = $handler;
                $controller = new $class();
                return $controller->$action($request);
            }
        }
        http_response_code(404);
        return ['error' => true, 'message' => 'Route not found'];
    }

    private function matchPath(string $routePath, string $uri): ?array {
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';
        if (preg_match($pattern, $uri, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        return null;
    }
}
