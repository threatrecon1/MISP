<?php

declare(strict_types=1);

namespace App\Test\TestCase\Api\Feeds;

use App\Test\Fixture\AuthKeysFixture;
use App\Test\Fixture\FeedsFixture;
use App\Test\Helper\ApiTestTrait;
use Cake\Core\Configure;
use Cake\Http\TestSuite\HttpClientTrait;
use Cake\TestSuite\TestCase;

class FetchFromFeedApiTest extends TestCase
{
    use ApiTestTrait;
    use HttpClientTrait;

    protected const ENDPOINT = '/feeds/fetchFromFeed';

    protected $fixtures = [
        'app.Organisations',
        'app.Roles',
        'app.Users',
        'app.AuthKeys',
        'app.Feeds',
        'app.Events',
        'app.Attributes',
    ];

    public function testFetchFromMispFeedById(): void
    {
        $this->skipOpenApiValidations();

        Configure::write('BackgroundJobs.enabled', false);

        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);
        $url = sprintf('%s/%d', self::ENDPOINT, FeedsFixture::FEED_1_ID);

        $headers = [
            'Content-Type: application/json',
            'Connection: close',
        ];

        $manifestBody = json_encode(
            [
                "56bf399d-c46c-4fdb-a9cf-d9bb02de0b81" => [
                    "Orgc" => [
                        "name" => "CIRCL",
                        "uuid" => "55f6ea5e-2c60-40e5-964f-47a8950d210f"
                    ],
                    "Tag" => [
                        [
                            "colour" => "#004646",
                            "local" => "0",
                            "name" => "type:OSINT",
                            "relationship_type" => ""
                        ],
                        [
                            "colour" => "#ffffff",
                            "local" => "0",
                            "name" => "tlp:white",
                            "relationship_type" => ""
                        ]
                    ],
                    "info" => "OSINT - Hunting for Malware with Machine Learning",
                    "date" => "2015-12-18",
                    "analysis" => 2,
                    "threat_level_id" => 3,
                    "timestamp" => 1455373240
                ]
            ]
        );

        // mock the manifest.json request
        $this->mockClientGet(
            FeedsFixture::FEED_1_URL . '/manifest.json',
            $this->newClientResponse(200, $headers, $manifestBody)
        );


        $eventBody = json_encode(
            [
                "Event" => [
                    "analysis" => "2",
                    "date" => "2015-12-18",
                    "extends_uuid" => "",
                    "info" => "OSINT - Hunting for Malware with Machine Learning",
                    "publish_timestamp" => "1455373314",
                    "published" => true,
                    "threat_level_id" => "3",
                    "timestamp" => "1455373240",
                    "uuid" => "56bf399d-c46c-4fdb-a9cf-d9bb02de0b81",
                    "Orgc" => [
                        "name" => "CIRCL",
                        "uuid" => "55f6ea5e-2c60-40e5-964f-47a8950d210f"
                    ],
                    "Tag" => [
                        [
                            "colour" => "#004646",
                            "local" => "0",
                            "name" => "type:OSINT",
                            "relationship_type" => ""
                        ],
                        [
                            "colour" => "#ffffff",
                            "local" => "0",
                            "name" => "tlp:white",
                            "relationship_type" => ""
                        ]
                    ],
                    "Attribute" => [
                        [
                            "category" => "External analysis",
                            "comment" => "",
                            "deleted" => false,
                            "disable_correlation" => false,
                            "timestamp" => "1455372745",
                            "to_ids" => false,
                            "type" => "link",
                            "uuid" => "56bf39c9-c078-4368-9555-6cf802de0b81",
                            "value" => "http://blog.cylance.com/hunting-for-malware-with-machine-learning"
                        ]
                    ]
                ]
            ]
        );

        // mock the event [uuid].json request
        $this->mockClientGet(
            FeedsFixture::FEED_1_URL . '/56bf399d-c46c-4fdb-a9cf-d9bb02de0b81.json',
            $this->newClientResponse(200, $headers, $eventBody)
        );

        $this->post($url);
        $this->assertResponseOk();

        $response = $this->getJsonResponseAsArray();
        $this->assertEquals(
            'Fetching the feed has successfully completed. Downloaded 1 new event(s). Updated 0 event(s).',
            $response['result']
        );

        // check that the event was added
        $this->assertDbRecordExists('Events', ['uuid' => '56bf399d-c46c-4fdb-a9cf-d9bb02de0b81']);
        $this->assertDbRecordExists('Attributes', ['uuid' => '56bf39c9-c078-4368-9555-6cf802de0b81']);

        // TODO: check that the objects were added
        // TODO: check that the event reports were added
        // TODO: check that the sightings were added
        // TODO: check that the tags were added
        // TODO: check that the galaxies were added
        // TODO: check that the cryptographic key were added
    }

    public function testFetchFromFreetextFeedById(): void
    {
        $this->skipOpenApiValidations();

        Configure::write('BackgroundJobs.enabled', false);

        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);
        $url = sprintf('%s/%d', self::ENDPOINT, FeedsFixture::FEED_3_ID);

        $headers = [
            'Content-Type: text/plain',
            'Connection: close',
        ];

        $feedContent = implode(
            " ",
            [
                '8.8.8.8',
                '8.8.4.4',
            ]
        );

        $this->mockClientGet(
            FeedsFixture::FEED_3_URL,
            $this->newClientResponse(200, $headers, $feedContent)
        );

        $this->post($url);
        $this->assertResponseOk();

        $response = $this->getJsonResponseAsArray();
        $this->assertEquals(
            'Fetching the feed has successfully completed.',
            $response['result']
        );

        // check that the event was added
        $this->assertDbRecordExists('Events', ['info' => FeedsFixture::FEED_3_NAME . ' feed']);
        $this->assertDbRecordExists('Attributes', ['value1' => '8.8.8.8']);
        $this->assertDbRecordExists('Attributes', ['value1' => '8.8.4.4']);
    }

    public function testFetchFromCsvFeedById(): void
    {
        $this->skipOpenApiValidations();

        Configure::write('BackgroundJobs.enabled', false);

        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);
        $url = sprintf('%s/%d', self::ENDPOINT, FeedsFixture::FEED_4_ID);

        $headers = [
            'Content-Type: text/plain',
            'Connection: close',
        ];

        $feedContent = implode(
            PHP_EOL,
            [
                '1.1.1.1',
                '1.0.0.1',
            ]
        );

        $this->mockClientGet(
            FeedsFixture::FEED_3_URL,
            $this->newClientResponse(200, $headers, $feedContent)
        );

        $this->post($url);
        $this->assertResponseOk();

        $response = $this->getJsonResponseAsArray();
        $this->assertEquals(
            'Fetching the feed has successfully completed.',
            $response['result']
        );

        // check that the event was added
        $this->assertDbRecordExists('Events', ['info' => FeedsFixture::FEED_4_NAME . ' feed']);
        $this->assertDbRecordExists('Attributes', ['value1' => '1.1.1.1']);
        $this->assertDbRecordExists('Attributes', ['value1' => '1.0.0.1']);
    }
}