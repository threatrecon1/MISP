<?php

declare(strict_types=1);

namespace App\Test\TestCase\Api\Events;

use App\Test\Fixture\AuthKeysFixture;
use App\Test\Fixture\EventsFixture;
use App\Test\Helper\ApiTestTrait;
use Cake\TestSuite\TestCase;

class RestSearchEventApiTest extends TestCase
{
    use ApiTestTrait;

    protected const ENDPOINT = '/events/restSearch';

    protected $fixtures = [
        'app.Organisations',
        'app.Roles',
        'app.Users',
        'app.AuthKeys',
        'app.Events',
    ];

    public function testRestSearchByUuid(): void
    {
        $this->skipOpenApiValidations();

        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);

        $url = sprintf('%s/%d', self::ENDPOINT, EventsFixture::EVENT_1_ID);

        $this->post(
            self::ENDPOINT,
            [
                'uuid' => EventsFixture::EVENT_1_UUID
            ]
        );
        $this->assertResponseOk();

        $results = $this->getJsonResponseAsArray();

        $this->assertEquals(EventsFixture::EVENT_1_ID, $results['response'][0]['Event']['id']);
        $this->assertEquals(EventsFixture::EVENT_1_UUID, $results['response'][0]['Event']['uuid']);
        $this->assertEquals(1, count($results['response']));
    }

    public function testRestSearchByInfo(): void
    {
        $this->skipOpenApiValidations();

        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);

        $url = sprintf('%s/%d', self::ENDPOINT, EventsFixture::EVENT_1_ID);

        $this->post(
            self::ENDPOINT,
            [
                'eventinfo' => 'Event 1'
            ]
        );
        $this->assertResponseOk();

        $results = $this->getJsonResponseAsArray();

        $this->assertEquals(EventsFixture::EVENT_1_ID, $results['response'][0]['Event']['id']);
        $this->assertEquals(EventsFixture::EVENT_1_UUID, $results['response'][0]['Event']['uuid']);
        $this->assertEquals(1, count($results['response']));
    }
}
