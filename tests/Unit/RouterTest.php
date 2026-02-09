<?php

require_once dirname(__DIR__, 2) . '/includes/Router.php';

class RouterTest extends SiloTestCase {
    protected function setUp(): void {
        parent::setUp();
        Router::resetInstance();
    }

    protected function tearDown(): void {
        Router::resetInstance();
        parent::tearDown();
    }

    public function testGetRouteRegistration(): void {
        $router = Router::getInstance();
        $router->get('/test', ['file' => 'test.php'], 'test.route');

        $routes = $router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/test', $routes[0]['pattern']);
    }

    public function testPostRouteRegistration(): void {
        $router = Router::getInstance();
        $router->post('/submit', ['file' => 'submit.php'], 'submit.route');

        $routes = $router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('POST', $routes[0]['method']);
    }

    public function testNamedRouteRegistration(): void {
        $router = Router::getInstance();
        $router->get('/browse', ['file' => 'browse.php'], 'browse');

        $this->assertTrue($router->hasRoute('browse'));
        $this->assertFalse($router->hasRoute('nonexistent'));
    }

    public function testUrlGenerationForNamedRoute(): void {
        $router = Router::getInstance();
        $router->get('/model/{id:\d+}', ['file' => 'model.php', 'map' => ['id' => 'id']], 'model.show');

        $url = Router::url('model.show', ['id' => 123]);
        $this->assertStringContainsString('/model/123', $url);
    }

    public function testUrlGenerationWithQueryParams(): void {
        $router = Router::getInstance();
        $router->get('/browse', ['file' => 'browse.php'], 'browse');

        $url = Router::url('browse', [], ['tag' => 5]);
        $this->assertStringContainsString('/browse', $url);
        $this->assertStringContainsString('tag=5', $url);
    }

    public function testUrlGenerationFallsBackToPath(): void {
        $router = Router::getInstance();

        $url = Router::url('some/path');
        $this->assertStringContainsString('/some/path', $url);
    }

    public function testRouteGroupAppliesPrefix(): void {
        $router = Router::getInstance();
        $router->group(['prefix' => '/admin'], function($r) {
            $r->get('/settings', ['file' => 'settings.php'], 'admin.settings');
        });

        $namedRoutes = $router->getNamedRoutes();
        $this->assertArrayHasKey('admin.settings', $namedRoutes);
        $this->assertEquals('/admin/settings', $namedRoutes['admin.settings']);
    }

    public function testRouteGroupAppliesMiddleware(): void {
        $router = Router::getInstance();
        $router->group(['middleware' => ['auth']], function($r) {
            $r->get('/profile', ['file' => 'profile.php'], 'profile');
        });

        $routes = $router->getRoutes();
        $this->assertContains('auth', $routes[0]['middleware']);
    }

    public function testMiddlewareCanBeAddedToRoute(): void {
        $router = Router::getInstance();
        $router->post('/login', ['file' => 'login.php'], 'login.post')
            ->middleware('ratelimit:5,60,auth');

        $routes = $router->getRoutes();
        $this->assertContains('ratelimit:5,60,auth', $routes[0]['middleware']);
    }

    public function testRoutePatternToRegexMatchesSimplePath(): void {
        $router = Router::getInstance();
        $router->get('/browse', ['file' => 'browse.php'], 'browse');

        $routes = $router->getRoutes();
        $this->assertMatchesRegularExpression($routes[0]['regex'], '/browse');
        $this->assertMatchesRegularExpression($routes[0]['regex'], '/browse/');
    }

    public function testRoutePatternToRegexMatchesParameterizedPath(): void {
        $router = Router::getInstance();
        $router->get('/model/{id:\d+}', ['file' => 'model.php'], 'model.show');

        $routes = $router->getRoutes();
        $this->assertMatchesRegularExpression($routes[0]['regex'], '/model/123');
        $this->assertDoesNotMatchRegularExpression($routes[0]['regex'], '/model/abc');
    }

    public function testDispatchReturnsFalseForNoMatch(): void {
        $router = Router::getInstance();
        $router->get('/existing', ['file' => 'existing.php'], 'existing');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/nonexistent';
        $_GET['route'] = '';

        $result = $router->dispatch();
        $this->assertFalse($result);
    }

    public function testAnyRouteRegistersMultipleMethods(): void {
        $router = Router::getInstance();
        $router->any('/api/test', ['file' => 'test.php'], 'api.test');

        $routes = $router->getRoutes();
        $methods = array_column($routes, 'method');

        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);
        $this->assertContains('PATCH', $methods);
    }

    public function testHasRouteReturnsTrueForExistingRoute(): void {
        $router = Router::getInstance();
        $router->get('/test', ['file' => 'test.php'], 'test');

        $this->assertTrue($router->hasRoute('test'));
    }

    public function testHasRouteReturnsFalseForMissingRoute(): void {
        $router = Router::getInstance();

        $this->assertFalse($router->hasRoute('nonexistent'));
    }

    public function testGetNamedRoutesReturnsAll(): void {
        $router = Router::getInstance();
        $router->get('/a', ['file' => 'a.php'], 'route.a');
        $router->get('/b', ['file' => 'b.php'], 'route.b');

        $named = $router->getNamedRoutes();
        $this->assertCount(2, $named);
        $this->assertArrayHasKey('route.a', $named);
        $this->assertArrayHasKey('route.b', $named);
    }

    public function testNestedGroupsPrefixesCombine(): void {
        $router = Router::getInstance();
        $router->group(['prefix' => '/api'], function($r) {
            $r->group(['prefix' => '/v1'], function($r) {
                $r->get('/models', ['file' => 'models.php'], 'api.v1.models');
            });
        });

        $namedRoutes = $router->getNamedRoutes();
        $this->assertEquals('/api/v1/models', $namedRoutes['api.v1.models']);
    }
}
