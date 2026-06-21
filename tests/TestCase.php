<?php

namespace Tests;

use Laravel\Lumen\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return \Laravel\Lumen\Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        return $app;
    }

    /**
     * Helper: fazer request com Api-Token header
     */
    protected function withApiToken(string $uri, array $headers = [])
    {
        return $this->call('GET', $uri, [], [], [], array_merge([
            'HTTP_Api-Token' => 'test-token',
        ], $headers));
    }

    /**
     * Helper: fazer request POST com Api-Token header
     */
    protected function postWithApiToken(string $uri, array $data = [], array $headers = [])
    {
        return $this->call('POST', $uri, $data, [], [], array_merge([
            'HTTP_Api-Token' => 'test-token',
            'CONTENT_TYPE' => 'application/json',
        ], $headers));
    }
}
