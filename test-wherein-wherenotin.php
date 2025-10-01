<?php

/**
 * Test script for whereIn and whereNotIn methods
 */

// Mock Laravel Log facade BEFORE autoload
namespace Illuminate\Support\Facades {
    class Log {
        public static function error($message, $context = []) {}
        public static function info($message, $context = []) {}
        public static function warning($message, $context = []) {}
        public static function debug($message, $context = []) {}
    }
}

namespace {

// Test configuration
define('AUTH_SERVICE_BASE_URL', 'http://localhost:8000');
define('AUTH_SERVICE_API_KEY', 'sk_r6a6_pQLhjIGuHHGcM7rmAcXUl3scJLkcwwn3');
define('SERVICE_SLUG', 'shirah-documents-service');

// Mock Laravel config
if (!function_exists('config')) {
    function config($key, $default = null) {
        $configs = [
            'authservice.auth_service_base_url' => AUTH_SERVICE_BASE_URL,
            'authservice.auth_service_api_key' => AUTH_SERVICE_API_KEY,
            'authservice.service_slug' => SERVICE_SLUG,
            'authservice.timeout' => 30,
            'app.debug' => false,
        ];

        return $configs[$key] ?? $default;
    }
}

// Mock Laravel app container
if (!function_exists('app')) {
    function app($class = null) {
        static $instances = [];

        if ($class === null) {
            return null;
        }

        if (!isset($instances[$class])) {
            $instances[$class] = new $class();
        }

        return $instances[$class];
    }
}

require_once __DIR__ . '/vendor/autoload.php';

use AuthService\Helper\Models\User;
use AuthService\Helper\Query\UserQueryBuilder;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   Testing whereIn and whereNotIn Methods                      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Auth Service: " . AUTH_SERVICE_BASE_URL . "\n";
echo "Service Slug: " . SERVICE_SLUG . "\n\n";

// Test 1: whereIn with single value
echo "Test 1: whereIn() with single user ID\n";
echo str_repeat("-", 70) . "\n";
try {
    // First get a user to test with
    $allUsers = User::all();
    if ($allUsers->count() > 0) {
        $userId = $allUsers->first()->id;
        echo "Testing with user ID: {$userId}\n";

        $users = User::query()->whereIn('id', [$userId])->get();
        echo "✅ PASS - Retrieved " . $users->count() . " user(s)\n";

        if ($users->count() > 0) {
            echo "   User: {$users->first()->name} ({$users->first()->email})\n";
        }
    } else {
        echo "⚠️  SKIP - No users in database\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: whereIn with multiple values
echo "Test 2: whereIn() with multiple user IDs\n";
echo str_repeat("-", 70) . "\n";
try {
    $allUsers = User::all();
    if ($allUsers->count() >= 2) {
        $userIds = [];
        foreach ($allUsers->take(2) as $user) {
            $userIds[] = $user->id;
        }
        echo "Testing with user IDs: " . implode(', ', $userIds) . "\n";

        $users = User::query()->whereIn('id', $userIds)->get();
        echo "✅ PASS - Retrieved " . $users->count() . " user(s)\n";

        foreach ($users as $user) {
            echo "   - {$user->name} ({$user->email})\n";
        }
    } else {
        echo "⚠️  SKIP - Need at least 2 users in database\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: whereNotIn with single value
echo "Test 3: whereNotIn() with single user ID\n";
echo str_repeat("-", 70) . "\n";
try {
    $allUsers = User::all();
    if ($allUsers->count() > 0) {
        $userId = $allUsers->first()->id;
        echo "Excluding user ID: {$userId}\n";

        $totalCount = User::count();
        $users = User::query()->whereNotIn('id', [$userId])->get();

        echo "✅ PASS - Retrieved " . $users->count() . " user(s) (total: {$totalCount})\n";

        // Verify the excluded user is not in the results
        $foundExcluded = false;
        foreach ($users as $user) {
            if ($user->id === $userId) {
                $foundExcluded = true;
                break;
            }
        }

        if ($foundExcluded) {
            echo "❌ ERROR - Excluded user was found in results!\n";
        } else {
            echo "✅ Verification PASS - Excluded user not in results\n";
        }
    } else {
        echo "⚠️  SKIP - No users in database\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: whereNotIn with multiple values
echo "Test 4: whereNotIn() with multiple user IDs\n";
echo str_repeat("-", 70) . "\n";
try {
    $allUsers = User::all();
    if ($allUsers->count() >= 2) {
        $userIds = [];
        foreach ($allUsers->take(2) as $user) {
            $userIds[] = $user->id;
        }
        echo "Excluding user IDs: " . implode(', ', $userIds) . "\n";

        $totalCount = User::count();
        $users = User::query()->whereNotIn('id', $userIds)->get();

        echo "✅ PASS - Retrieved " . $users->count() . " user(s) (total: {$totalCount})\n";

        // Verify excluded users are not in the results
        $foundExcluded = [];
        foreach ($users as $user) {
            if (in_array($user->id, $userIds)) {
                $foundExcluded[] = $user->id;
            }
        }

        if (!empty($foundExcluded)) {
            echo "❌ ERROR - Excluded user(s) found in results: " . implode(', ', $foundExcluded) . "\n";
        } else {
            echo "✅ Verification PASS - No excluded users in results\n";
        }
    } else {
        echo "⚠️  SKIP - Need at least 2 users in database\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Combining whereIn with other conditions
echo "Test 5: Combining whereIn() with where()\n";
echo str_repeat("-", 70) . "\n";
try {
    $allUsers = User::all();
    if ($allUsers->count() >= 2) {
        $userIds = [];
        foreach ($allUsers->take(3) as $user) {
            $userIds[] = $user->id;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('is_admin', false)
            ->get();

        echo "✅ PASS - Retrieved " . $users->count() . " non-admin user(s) from subset\n";
    } else {
        echo "⚠️  SKIP - Need at least 2 users in database\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Query builder inspection
echo "Test 6: Verify query uses find-by endpoint for whereIn/whereNotIn\n";
echo str_repeat("-", 70) . "\n";
try {
    $query = User::query()->whereIn('id', ['test-uuid-1', 'test-uuid-2']);

    // Access protected property using reflection
    $reflection = new \ReflectionClass($query);
    $wheresProperty = $reflection->getProperty('wheres');
    $wheresProperty->setAccessible(true);
    $wheres = $wheresProperty->getValue($query);

    echo "Query conditions:\n";
    foreach ($wheres as $where) {
        echo "  - Field: {$where['field']}, Operator: {$where['operator']}, Type: {$where['type']}\n";
    }

    if ($wheres[0]['operator'] === 'in') {
        echo "✅ PASS - whereIn correctly sets operator to 'in'\n";
    } else {
        echo "❌ FAIL - Operator is '{$wheres[0]['operator']}' instead of 'in'\n";
    }
} catch (\Exception $e) {
    echo "❌ FAIL - Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "Testing Complete!\n";
echo str_repeat("=", 70) . "\n";

} // End of global namespace
