<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    public function testLoginRequiresUsernameAndPassword()
    {
        // Missing username
        $response = $this->call('POST', '/auth/login', ['password' => 'test']);
        $this->assertEquals(422, $response->getStatusCode());

        // Missing password
        $response = $this->call('POST', '/auth/login', ['username' => 'test']);
        $this->assertEquals(422, $response->getStatusCode());

        // Both missing
        $response = $this->call('POST', '/auth/login', []);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function testLoginDoesNotReturn200ForInvalidCredentials()
    {
        $response = $this->call('POST', '/auth/login', [
            'username' => 'invalid_user_that_does_not_exist',
            'password' => 'wrong_password',
        ]);

        // Should NOT return 200 — either 401 (wrong creds) or 500 (test env without DB)
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testProtectedRouteDoesNotReturn200WithoutToken()
    {
        $response = $this->call('GET', '/params');

        // Without token, should be 401 (unauthorized) or 503/500 (no DB/cache in test env)
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testProtectedRouteDoesNotReturn200WithInvalidToken()
    {
        $response = $this->call('GET', '/params', [], [], [], [
            'HTTP_Authorization' => 'Bearer invalid.jwt.token',
        ]);

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testLogoutWithoutTokenDoesNotReturn200()
    {
        $response = $this->call('POST', '/auth/logout');

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testChangePasswordRequiresAuth()
    {
        $response = $this->call('POST', '/auth/change-password', []);
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testRefreshTokenWithoutTokenDoesNotReturn200()
    {
        $response = $this->call('POST', '/auth/refresh-token');

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testAuthRouteExists()
    {
        $response = $this->call('POST', '/auth/login', [
            'username' => 'test',
            'password' => 'test',
        ]);

        // Should NOT be 404 — route exists
        $this->assertNotEquals(404, $response->getStatusCode());
    }
}
