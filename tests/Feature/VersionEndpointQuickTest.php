
<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersionEndpointTest extends TestCase
{
    public function testVersionEndpointReturns200()
    {
        $this->get(/version);
        $this->seeStatusCode(200);
    }

    public function testVersionEndpointReturnsJsonStructure()
    {
        $this->get(/version);
        $this->seeJsonStructure([
            api_version,
            min_client_version, php_version,
            lumen_version
        ]);
    }

    public function testVersionEndpointDoesNotRequireAuth()
    {
        $this->get(/version);
        $this->seeStatusCode(200);
    }
}

