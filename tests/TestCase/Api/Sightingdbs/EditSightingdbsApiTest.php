<?php

declare(strict_types=1);

namespace App\Test\TestCase\Api\Sightingdbs;

use App\Test\Fixture\AuthKeysFixture;
use App\Test\Fixture\SightingdbsFixture;
use App\Test\Helper\ApiTestTrait;
use Cake\TestSuite\TestCase;

class EditSightingdbsApiTest extends TestCase
{
    use ApiTestTrait;

    protected const ENDPOINT = '/sightingdbs/edit';

    protected $fixtures = [
        'app.Sightingdbs',
        'app.Organisations',
        'app.Roles',
        'app.Users',
        'app.AuthKeys'
    ];

    public function testEditSightingdb(): void
    {
        $this->skipOpenApiValidations();
        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);
        $url = sprintf('%s/%s', self::ENDPOINT, SightingdbsFixture::SDB_2_ID);
        $this->post(
            $url,
            [
                'name' => 'sightingdbsuccess',
                'port' => 27017,
            ],
        );
        $this->assertResponseOk();
        $this->assertDbRecordExists('Sightingdbs', ['name' => 'sightingdbsuccess']);
    }
}
