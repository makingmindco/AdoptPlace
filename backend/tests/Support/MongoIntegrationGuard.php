<?php

namespace Tests\Support;

final class MongoIntegrationGuard
{
    public const DATABASE = 'adoptplace_test_9d5a_20261113_a7f30c92';

    public const COLLECTIONS = [
        'users', 'organizations', 'pets', 'adoption_requests', 'audit_logs',
        'personal_access_tokens', 'photo_deletions',
        'adoptplace_runtime_sessions', 'adoptplace_runtime_cache',
        'adoptplace_runtime_cache_locks', 'adoptplace_runtime_password_resets',
        'adoptplace_runtime_password_locks', '_test_ownership',
    ];

    public static function validate(array $settings): array
    {
        $name = $settings['TEST_MONGODB_DATABASE'] ?? '';
        $uri = $settings['TEST_MONGODB_URI'] ?? '';
        if ($name !== self::DATABASE
            || ($settings['TEST_MONGODB_CONFIRM_DATABASE'] ?? '') !== $name
            || ($settings['TEST_MONGODB_ISOLATED'] ?? '') !== '1'
            || ! preg_match('~^mongodb(?:\+srv)?://[^\s]+$~D', $uri)) {
            throw new \RuntimeException('Explicit isolated test URI, temporary database name and confirmations are required. No application URI fallback is allowed.');
        }

        return [$uri, $name];
    }

    public static function cleanupCollections(array $names): array
    {
        if (array_diff($names, self::COLLECTIONS)) {
            throw new \RuntimeException('Unexpected collection: all automatic cleanup refused.');
        }

        return array_values(array_filter($names, fn ($name) => $name !== '_test_ownership'));
    }
}
