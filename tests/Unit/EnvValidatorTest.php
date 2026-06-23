<?php

namespace Tests\Unit;

use Tests\TestCase;

class EnvValidatorTest extends TestCase
{
    public function testValidatorHasRequiredVars()
    {
        $validator = new \ReflectionClass(\App\Helpers\EnvValidator::class);
        $required = $validator->getProperty('required');
        $required->setAccessible(true);
        $values = $required->getValue();

        $this->assertContains('APP_KEY', $values);
        $this->assertContains('JWT_SECRET', $values);
    }

    public function testValidatorHasProductionOnlyVars()
    {
        $validator = new \ReflectionClass(\App\Helpers\EnvValidator::class);
        $prod = $validator->getProperty('requiredProduction');
        $prod->setAccessible(true);
        $values = $prod->getValue();

        $this->assertContains('API_TOKEN', $values);
    }

    public function testCheckMethodExists()
    {
        $this->assertTrue(method_exists(\App\Helpers\EnvValidator::class, 'check'));
    }

    public function testValidatorAcceptsAllVarsSet()
    {
        // In test env, APP_KEY and JWT_SECRET are set via phpunit.xml
        // This should not throw
        \App\Helpers\EnvValidator::check();
        $this->assertTrue(true); // Reached = no exception
    }
}
