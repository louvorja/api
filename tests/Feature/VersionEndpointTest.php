<?php

namespace Tests\Unit;

use Tests\TestCase;

class VersionCheckTest extends TestCase
{
    public function testVersionConfigExists()
    {
        $this->assertNotNull(config('version.version'));
        $this->assertNotNull(config('version.min_client_version'));
    }
}

