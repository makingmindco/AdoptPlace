<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\MongoIntegrationGuard;

class MongoIntegrationGuardTest extends TestCase
{
    public function test_only_explicitly_confirmed_temporary_database_is_accepted(): void
    {
        $name = 'adoptplace_test_9d5a_20261113_a7f30c92';
        $settings = ['TEST_MONGODB_DATABASE' => $name, 'TEST_MONGODB_URI' => 'mongodb://localhost:27017',
            'TEST_MONGODB_CONFIRM_DATABASE' => $name, 'TEST_MONGODB_ISOLATED' => '1'];
        $this->assertSame([$settings['TEST_MONGODB_URI'], $name], MongoIntegrationGuard::validate($settings));
        foreach (['TEST_MONGODB_DATABASE' => 'adoptplace', 'TEST_MONGODB_URI' => '', 'TEST_MONGODB_CONFIRM_DATABASE' => 'wrong', 'TEST_MONGODB_ISOLATED' => '0'] as $key => $value) {
            try {
                MongoIntegrationGuard::validate(array_replace($settings, [$key => $value]));
                $this->fail('Unsafe configuration accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString($settings['TEST_MONGODB_URI'], $e->getMessage());
            }
        }
    }

    public function test_cleanup_refuses_unknown_collections_and_preserves_marker_until_last(): void
    {
        $this->assertSame(['pets'], MongoIntegrationGuard::cleanupCollections(['pets', '_test_ownership']));
        $this->expectException(\RuntimeException::class);
        MongoIntegrationGuard::cleanupCollections(['pets', 'unknown_real_data']);
    }
}
