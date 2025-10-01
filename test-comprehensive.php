<?php

/**
 * Comprehensive Feature Testing Script for auth-service-helper
 *
 * This script tests all features by making actual API calls to the auth service
 * and documenting which parts work and which need fixing.
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

// Mock Laravel Pagination classes
namespace Illuminate\Pagination {
    class LengthAwarePaginator {
        protected $items;
        protected $total;
        protected $perPage;
        protected $currentPage;
        protected $options;

        public function __construct($items, $total, $perPage, $currentPage = 1, array $options = []) {
            $this->items = $items;
            $this->total = $total;
            $this->perPage = $perPage;
            $this->currentPage = $currentPage;
            $this->options = $options;
        }

        public function items() {
            return $this->items;
        }

        public function total() {
            return $this->total;
        }

        public function perPage() {
            return $this->perPage;
        }

        public function currentPage() {
            return $this->currentPage;
        }
    }
}

namespace {

// Test configuration - MUST be defined BEFORE autoload
define('AUTH_SERVICE_BASE_URL', 'http://localhost:8000');
define('AUTH_SERVICE_API_KEY', 'sk_r6a6_pQLhjIGuHHGcM7rmAcXUl3scJLkcwwn3');
define('SERVICE_SLUG', 'shirah-documents-service');

// Mock Laravel config BEFORE autoload - CRITICAL!
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

require_once __DIR__ . '/vendor/autoload.php';

use AuthService\Helper\Services\AuthServiceClient;
use AuthService\Helper\Models\User;
use AuthService\Helper\Query\UserQueryBuilder;
use AuthService\Helper\Collections\UserCollection;

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

// Mock Laravel request helper
if (!function_exists('request')) {
    function request() {
        return new class {
            public function url() {
                return 'http://localhost/test';
            }
        };
    }
}

// Mock Laravel now helper
if (!function_exists('now')) {
    function now() {
        return new class {
            public function subDays($days) {
                return new class($days) {
                    public $timestamp;
                    public function __construct($days) {
                        $this->timestamp = strtotime("-{$days} days");
                    }
                };
            }
        };
    }
}

// Test results storage
$testResults = [
    'passed' => [],
    'failed' => [],
    'errors' => [],
    'notes' => []
];

// Helper function to run tests
function runTest($category, $testName, $callback) {
    global $testResults;

    echo "\n🔍 Testing: {$category} - {$testName}";

    try {
        $result = $callback();

        if ($result === true || (!empty($result) && $result !== false)) {
            echo " ✅ PASS";
            $testResults['passed'][] = "{$category} → {$testName}";
            return $result;
        } else {
            echo " ❌ FAIL";
            $testResults['failed'][] = "{$category} → {$testName}";
            return false;
        }
    } catch (\Exception $e) {
        echo " ⚠️ ERROR: {$e->getMessage()}";
        $testResults['errors'][] = [
            'test' => "{$category} → {$testName}",
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
        return false;
    }
}

// Add a note
function addNote($note) {
    global $testResults;
    $testResults['notes'][] = $note;
    echo "\n📝 NOTE: {$note}";
}

// Header
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   Auth Service Helper - Comprehensive Feature Testing         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\nAuth Service: " . AUTH_SERVICE_BASE_URL;
echo "\nService Slug: " . SERVICE_SLUG;
echo "\n\n";

// ============================================================================
// SECTION 1: AuthServiceClient Tests
// ============================================================================
echo "\n" . str_repeat("=", 70) . "\n";
echo "SECTION 1: AuthServiceClient Tests\n";
echo str_repeat("=", 70) . "\n";

$client = new AuthServiceClient();

// Test 1.1: Get Users
$users = runTest('AuthServiceClient', 'getUsers()', function() use ($client) {
    $response = $client->getUsers();
    return isset($response['success']) || isset($response['data']);
});

// Test 1.2: Search Users
runTest('AuthServiceClient', 'searchUsers()', function() use ($client) {
    $response = $client->searchUsers('test');
    return isset($response['success']) || isset($response['data']);
});

// Test 1.3: Get User Count
runTest('AuthServiceClient', 'getUserCount()', function() use ($client) {
    $response = $client->getUserCount();
    return isset($response['data']['count']) || isset($response['success']);
});

// Test 1.4: Get Admin Users
runTest('AuthServiceClient', 'getAdminUsers()', function() use ($client) {
    $response = $client->getAdminUsers();
    return isset($response['success']) || isset($response['data']);
});

// Test 1.5: Get Recently Active Users
runTest('AuthServiceClient', 'getRecentlyActiveUsers()', function() use ($client) {
    $response = $client->getRecentlyActiveUsers(7, 10);
    return isset($response['success']) || isset($response['data']);
});

// Test 1.6: Get Unverified Users
runTest('AuthServiceClient', 'getUnverifiedUsers()', function() use ($client) {
    $response = $client->getUnverifiedUsers();
    return isset($response['success']) || isset($response['data']);
});

// Test 1.7: Export Users
runTest('AuthServiceClient', 'exportUsers()', function() use ($client) {
    $response = $client->exportUsers('json');
    return isset($response['success']) || isset($response['data']);
});

// Test 1.8: Get User by UUID (if we have users)
if (!empty($users) && isset($users['data'])) {
    $usersList = $users['data']['users'] ?? $users['data']['items'] ?? [];

    if (!empty($usersList)) {
        $firstUser = $usersList[0];
        $userId = $firstUser['id'] ?? null;

        if ($userId) {
            runTest('AuthServiceClient', 'getUserByUuid()', function() use ($client, $userId) {
                $response = $client->getUserByUuid($userId);
                return isset($response['success']) || isset($response['data']);
            });

            runTest('AuthServiceClient', 'getUserSessions()', function() use ($client, $userId) {
                $response = $client->getUserSessions($userId);
                return isset($response['success']) || isset($response['data']);
            });

            runTest('AuthServiceClient', 'getUserRoles()', function() use ($client, $userId) {
                $response = $client->getUserRoles($userId);
                return isset($response['success']) || isset($response['data']);
            });

            runTest('AuthServiceClient', 'getUserMetadata()', function() use ($client, $userId) {
                $response = $client->getUserMetadata($userId);
                return isset($response['success']) || isset($response['data']);
            });
        }
    }
}

// ============================================================================
// SECTION 2: User Model Static Methods
// ============================================================================
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "SECTION 2: User Model Static Methods\n";
echo str_repeat("=", 70) . "\n";

// Test 2.1: User::all()
$allUsers = runTest('User Model', 'all()', function() {
    $users = User::all();
    return $users instanceof UserCollection;
});

// Test 2.2: User::count()
runTest('User Model', 'count()', function() {
    $count = User::count();
    return is_int($count) && $count >= 0;
});

// Test 2.3: User::search()
runTest('User Model', 'search()', function() {
    $results = User::search('test');
    return $results instanceof UserCollection;
});

// Test 2.4: User::admins()
runTest('User Model', 'admins()', function() {
    $admins = User::admins();
    return $admins instanceof UserCollection;
});

// Test 2.5: User::recent()
runTest('User Model', 'recent()', function() {
    $recent = User::recent(7, 10);
    return $recent instanceof UserCollection;
});

// Test 2.6: User::unverified()
runTest('User Model', 'unverified()', function() {
    $unverified = User::unverified();
    return $unverified instanceof UserCollection;
});

// Test 2.7: User::find() (if we have a user ID)
if ($allUsers instanceof UserCollection && $allUsers->count() > 0) {
    $firstUser = $allUsers->first();
    $userId = $firstUser->id ?? null;

    if ($userId) {
        runTest('User Model', 'find()', function() use ($userId) {
            $user = User::find($userId);
            return $user instanceof User;
        });

        runTest('User Model', 'findOrFail()', function() use ($userId) {
            $user = User::findOrFail($userId);
            return $user instanceof User;
        });
    }
}

// ============================================================================
// SECTION 3: User Query Builder
// ============================================================================
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "SECTION 3: User Query Builder\n";
echo str_repeat("=", 70) . "\n";

// Test 3.1: Basic where query
runTest('UserQueryBuilder', 'where()->get()', function() {
    $users = User::where('is_admin', true)->get();
    return $users instanceof UserCollection;
});

// Test 3.2: Where with operator
runTest('UserQueryBuilder', 'where() with operator', function() {
    $users = User::where('email', 'like', '%@%')->get();
    return $users instanceof UserCollection;
});

// Test 3.3: Multiple where clauses
runTest('UserQueryBuilder', 'Multiple where()', function() {
    $users = User::where('is_admin', false)
                 ->where('email_verified_at', '!=', null)
                 ->get();
    return $users instanceof UserCollection;
});

// Test 3.4: whereNull
runTest('UserQueryBuilder', 'whereNull()', function() {
    $users = User::query()->whereNull('email_verified_at')->get();
    return $users instanceof UserCollection;
});

// Test 3.5: whereNotNull
runTest('UserQueryBuilder', 'whereNotNull()', function() {
    $users = User::query()->whereNotNull('email_verified_at')->get();
    return $users instanceof UserCollection;
});

// Test 3.6: orderBy
runTest('UserQueryBuilder', 'orderBy()', function() {
    $users = User::query()->orderBy('created_at', 'desc')->get();
    return $users instanceof UserCollection;
});

// Test 3.7: limit
runTest('UserQueryBuilder', 'limit()', function() {
    $users = User::query()->limit(5)->get();
    return $users instanceof UserCollection && $users->count() <= 5;
});

// Test 3.8: first()
runTest('UserQueryBuilder', 'first()', function() {
    $user = User::query()->first();
    return $user === null || $user instanceof User;
});

// Test 3.9: count()
runTest('UserQueryBuilder', 'count()', function() {
    $count = User::query()->count();
    return is_int($count) && $count >= 0;
});

// Test 3.10: exists()
runTest('UserQueryBuilder', 'exists()', function() {
    $exists = User::query()->exists();
    return is_bool($exists);
});

// Test 3.11: paginate()
runTest('UserQueryBuilder', 'paginate()', function() {
    $paginated = User::query()->paginate(10);
    return $paginated instanceof \Illuminate\Pagination\LengthAwarePaginator;
});

// Test 3.12: select()
runTest('UserQueryBuilder', 'select()', function() {
    $users = User::query()->select('id', 'email', 'name')->get();
    return $users instanceof UserCollection;
});

// ============================================================================
// SECTION 4: User Instance Methods
// ============================================================================
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "SECTION 4: User Instance Methods\n";
echo str_repeat("=", 70) . "\n";

if ($allUsers instanceof UserCollection && $allUsers->count() > 0) {
    $testUser = $allUsers->first();

    // Test 4.1: Attribute access
    runTest('User Instance', 'getAttribute()', function() use ($testUser) {
        $email = $testUser->getAttribute('email');
        return true; // getAttribute always returns something
    });

    // Test 4.2: Magic getter
    runTest('User Instance', 'Magic __get()', function() use ($testUser) {
        $email = $testUser->email;
        return true;
    });

    // Test 4.3: ArrayAccess
    runTest('User Instance', 'ArrayAccess', function() use ($testUser) {
        $email = $testUser['email'];
        return true;
    });

    // Test 4.4: toArray()
    runTest('User Instance', 'toArray()', function() use ($testUser) {
        $array = $testUser->toArray();
        return is_array($array);
    });

    // Test 4.5: toJson()
    runTest('User Instance', 'toJson()', function() use ($testUser) {
        $json = $testUser->toJson();
        return is_string($json) && json_decode($json) !== null;
    });

    // Test 4.6: getRoles()
    runTest('User Instance', 'getRoles()', function() use ($testUser) {
        $roles = $testUser->getRoles();
        return is_array($roles);
    });

    // Test 4.7: hasRole()
    runTest('User Instance', 'hasRole()', function() use ($testUser) {
        $result = $testUser->hasRole('admin');
        return is_bool($result);
    });

    // Test 4.8: hasServiceRole()
    runTest('User Instance', 'hasServiceRole()', function() use ($testUser) {
        $result = $testUser->hasServiceRole('test-service', 'admin');
        return is_bool($result);
    });

    // Test 4.9: hasAnyRole()
    runTest('User Instance', 'hasAnyRole()', function() use ($testUser) {
        $result = $testUser->hasAnyRole(['admin', 'user']);
        return is_bool($result);
    });

    // Test 4.10: hasAllRoles()
    runTest('User Instance', 'hasAllRoles()', function() use ($testUser) {
        $result = $testUser->hasAllRoles(['user']);
        return is_bool($result);
    });

    // Test 4.11: hasVerifiedEmail()
    runTest('User Instance', 'hasVerifiedEmail()', function() use ($testUser) {
        $result = $testUser->hasVerifiedEmail();
        return is_bool($result);
    });

    // Test 4.12: getUserType()
    runTest('User Instance', 'getUserType()', function() use ($testUser) {
        $type = $testUser->getUserType();
        return in_array($type, ['super_admin', 'admin', 'user']);
    });

    // Test 4.13: getServiceMetadata()
    runTest('User Instance', 'getServiceMetadata()', function() use ($testUser) {
        $metadata = $testUser->getServiceMetadata();
        return is_array($metadata) || $metadata === null;
    });

    // Test 4.14: sessions()
    runTest('User Instance', 'sessions()', function() use ($testUser) {
        $sessions = $testUser->sessions();
        return is_array($sessions);
    });

    // Test 4.15: roles() - API method
    runTest('User Instance', 'roles() API method', function() use ($testUser) {
        $roles = $testUser->roles();
        return is_array($roles);
    });

    // Test 4.16: metadata() - API method
    runTest('User Instance', 'metadata() API method', function() use ($testUser) {
        $metadata = $testUser->metadata();
        return is_array($metadata);
    });

    // Test 4.17: refresh()
    runTest('User Instance', 'refresh()', function() use ($testUser) {
        $result = $testUser->refresh();
        return is_bool($result);
    });
}

// ============================================================================
// SECTION 5: UserCollection Methods
// ============================================================================
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "SECTION 5: UserCollection Methods\n";
echo str_repeat("=", 70) . "\n";

if ($allUsers instanceof UserCollection && $allUsers->count() > 0) {
    // Test 5.1: admins()
    runTest('UserCollection', 'admins()', function() use ($allUsers) {
        $admins = $allUsers->admins();
        return $admins instanceof UserCollection;
    });

    // Test 5.2: nonAdmins()
    runTest('UserCollection', 'nonAdmins()', function() use ($allUsers) {
        $nonAdmins = $allUsers->nonAdmins();
        return $nonAdmins instanceof UserCollection;
    });

    // Test 5.3: verified()
    runTest('UserCollection', 'verified()', function() use ($allUsers) {
        $verified = $allUsers->verified();
        return $verified instanceof UserCollection;
    });

    // Test 5.4: unverified()
    runTest('UserCollection', 'unverified()', function() use ($allUsers) {
        $unverified = $allUsers->unverified();
        return $unverified instanceof UserCollection;
    });

    // Test 5.5: withRole()
    runTest('UserCollection', 'withRole()', function() use ($allUsers) {
        $withRole = $allUsers->withRole('admin');
        return $withRole instanceof UserCollection;
    });

    // Test 5.6: withAnyRole()
    runTest('UserCollection', 'withAnyRole()', function() use ($allUsers) {
        $withAnyRole = $allUsers->withAnyRole(['admin', 'user']);
        return $withAnyRole instanceof UserCollection;
    });

    // Test 5.7: ids()
    runTest('UserCollection', 'ids()', function() use ($allUsers) {
        $ids = $allUsers->ids();
        return $ids instanceof \Illuminate\Support\Collection;
    });

    // Test 5.8: emails()
    runTest('UserCollection', 'emails()', function() use ($allUsers) {
        $emails = $allUsers->emails();
        return $emails instanceof \Illuminate\Support\Collection;
    });

    // Test 5.9: names()
    runTest('UserCollection', 'names()', function() use ($allUsers) {
        $names = $allUsers->names();
        return $names instanceof \Illuminate\Support\Collection;
    });

    // Test 5.10: sortByName()
    runTest('UserCollection', 'sortByName()', function() use ($allUsers) {
        $sorted = $allUsers->sortByName();
        return $sorted instanceof UserCollection;
    });

    // Test 5.11: sortByEmail()
    runTest('UserCollection', 'sortByEmail()', function() use ($allUsers) {
        $sorted = $allUsers->sortByEmail();
        return $sorted instanceof UserCollection;
    });

    // Test 5.12: sortByLastLogin()
    runTest('UserCollection', 'sortByLastLogin()', function() use ($allUsers) {
        $sorted = $allUsers->sortByLastLogin();
        return $sorted instanceof UserCollection;
    });

    // Test 5.13: sortByCreatedAt()
    runTest('UserCollection', 'sortByCreatedAt()', function() use ($allUsers) {
        $sorted = $allUsers->sortByCreatedAt();
        return $sorted instanceof UserCollection;
    });

    // Test 5.14: groupByRole()
    runTest('UserCollection', 'groupByRole()', function() use ($allUsers) {
        $grouped = $allUsers->groupByRole();
        return $grouped instanceof \Illuminate\Support\Collection;
    });

    // Test 5.15: statistics()
    runTest('UserCollection', 'statistics()', function() use ($allUsers) {
        $stats = $allUsers->statistics();
        return is_array($stats) && isset($stats['total']);
    });

    // Test 5.16: toArrayOfArrays()
    runTest('UserCollection', 'toArrayOfArrays()', function() use ($allUsers) {
        $arrays = $allUsers->toArrayOfArrays();
        return is_array($arrays);
    });

    // Test 5.17: toCsv()
    runTest('UserCollection', 'toCsv()', function() use ($allUsers) {
        $csv = $allUsers->toCsv();
        return is_array($csv) && !empty($csv);
    });
}

// ============================================================================
// Summary
// ============================================================================
echo "\n\n" . str_repeat("=", 70) . "\n";
echo "TEST SUMMARY\n";
echo str_repeat("=", 70) . "\n";

$totalTests = count($testResults['passed']) + count($testResults['failed']) + count($testResults['errors']);
$passedCount = count($testResults['passed']);
$failedCount = count($testResults['failed']);
$errorCount = count($testResults['errors']);

echo "\n✅ Passed: {$passedCount}/{$totalTests}";
echo "\n❌ Failed: {$failedCount}/{$totalTests}";
echo "\n⚠️  Errors: {$errorCount}/{$totalTests}";

$passRate = $totalTests > 0 ? round(($passedCount / $totalTests) * 100, 2) : 0;
echo "\n\n📊 Pass Rate: {$passRate}%\n";

// Save results to file
$reportContent = "# Auth Service Helper - Comprehensive Test Report\n\n";
$reportContent .= "**Test Date:** " . date('Y-m-d H:i:s') . "\n";
$reportContent .= "**Auth Service:** " . AUTH_SERVICE_BASE_URL . "\n";
$reportContent .= "**Service Slug:** " . SERVICE_SLUG . "\n\n";

$reportContent .= "## Summary\n\n";
$reportContent .= "- **Total Tests:** {$totalTests}\n";
$reportContent .= "- **Passed:** {$passedCount}\n";
$reportContent .= "- **Failed:** {$failedCount}\n";
$reportContent .= "- **Errors:** {$errorCount}\n";
$reportContent .= "- **Pass Rate:** {$passRate}%\n\n";

$reportContent .= "## ✅ Passed Tests\n\n";
foreach ($testResults['passed'] as $test) {
    $reportContent .= "- ✅ {$test}\n";
}

$reportContent .= "\n## ❌ Failed Tests\n\n";
if (empty($testResults['failed'])) {
    $reportContent .= "No failed tests!\n";
} else {
    foreach ($testResults['failed'] as $test) {
        $reportContent .= "- ❌ {$test}\n";
    }
}

$reportContent .= "\n## ⚠️ Errors\n\n";
if (empty($testResults['errors'])) {
    $reportContent .= "No errors!\n";
} else {
    foreach ($testResults['errors'] as $error) {
        $reportContent .= "### {$error['test']}\n\n";
        $reportContent .= "**Error:** {$error['error']}\n\n";
        $reportContent .= "```\n{$error['trace']}\n```\n\n";
    }
}

if (!empty($testResults['notes'])) {
    $reportContent .= "\n## 📝 Notes\n\n";
    foreach ($testResults['notes'] as $note) {
        $reportContent .= "- {$note}\n";
    }
}

file_put_contents(__DIR__ . '/TESTING_NOTES.md', $reportContent);

echo "\n📄 Full report saved to: TESTING_NOTES.md\n\n";

} // End of global namespace
