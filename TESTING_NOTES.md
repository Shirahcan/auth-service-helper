# Auth Service Helper - Comprehensive Test Report

**Test Date:** 2025-10-03 22:32:50
**Auth Service:** http://localhost:8000
**Service Slug:** shirah-documents-service

## Summary

- **Total Tests:** 25
- **Passed:** 24
- **Failed:** 0
- **Errors:** 1
- **Pass Rate:** 96%

## ✅ Passed Tests

- ✅ AuthServiceClient → getUsers()
- ✅ AuthServiceClient → searchUsers()
- ✅ AuthServiceClient → getUserCount()
- ✅ AuthServiceClient → getAdminUsers()
- ✅ AuthServiceClient → getRecentlyActiveUsers()
- ✅ AuthServiceClient → getUnverifiedUsers()
- ✅ AuthServiceClient → exportUsers()
- ✅ User Model → all()
- ✅ User Model → count()
- ✅ User Model → search()
- ✅ User Model → admins()
- ✅ User Model → recent()
- ✅ User Model → unverified()
- ✅ UserQueryBuilder → where()->get()
- ✅ UserQueryBuilder → Multiple where()
- ✅ UserQueryBuilder → whereNull()
- ✅ UserQueryBuilder → whereNotNull()
- ✅ UserQueryBuilder → orderBy()
- ✅ UserQueryBuilder → limit()
- ✅ UserQueryBuilder → first()
- ✅ UserQueryBuilder → count()
- ✅ UserQueryBuilder → exists()
- ✅ UserQueryBuilder → paginate()
- ✅ UserQueryBuilder → select()

## ❌ Failed Tests

No failed tests!

## ⚠️ Errors

### UserQueryBuilder → where() with operator

**Error:** Client error: `GET http://localhost:8000/api/v1/users?email=%40&per_page=100` resulted in a `422 Unprocessable Content` response:
{"success":false,"message":"Validation failed","timestamp":"2025-10-03T22:32:37.832571Z","api_version":"v1","errors":{"e (truncated...)


```
#0 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\guzzle\src\Middleware.php(72): GuzzleHttp\Exception\RequestException::create(Object(GuzzleHttp\Psr7\Request), Object(GuzzleHttp\Psr7\Response), NULL, Array, NULL)
#1 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(209): GuzzleHttp\Middleware::GuzzleHttp\{closure}(Object(GuzzleHttp\Psr7\Response))
#2 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(158): GuzzleHttp\Promise\Promise::callHandler(1, Object(GuzzleHttp\Psr7\Response), NULL)
#3 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\TaskQueue.php(52): GuzzleHttp\Promise\Promise::GuzzleHttp\Promise\{closure}()
#4 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(251): GuzzleHttp\Promise\TaskQueue->run(true)
#5 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(227): GuzzleHttp\Promise\Promise->invokeWaitFn()
#6 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(272): GuzzleHttp\Promise\Promise->waitIfPending()
#7 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(229): GuzzleHttp\Promise\Promise->invokeWaitList()
#8 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\promises\src\Promise.php(69): GuzzleHttp\Promise\Promise->waitIfPending()
#9 C:\Users\benpl\Documents\GitHub\auth-service-helper\vendor\guzzlehttp\guzzle\src\Client.php(189): GuzzleHttp\Promise\Promise->wait()
#10 C:\Users\benpl\Documents\GitHub\auth-service-helper\src\Services\AuthServiceClient.php(86): GuzzleHttp\Client->request('GET', 'http://localhos...', Array)
#11 C:\Users\benpl\Documents\GitHub\auth-service-helper\src\Services\AuthServiceClient.php(136): AuthService\Helper\Services\AuthServiceClient->request('GET', 'users', Array)
#12 C:\Users\benpl\Documents\GitHub\auth-service-helper\src\Query\UserQueryBuilder.php(368): AuthService\Helper\Services\AuthServiceClient->get('users', Array)
#13 C:\Users\benpl\Documents\GitHub\auth-service-helper\test-comprehensive.php(339): AuthService\Helper\Query\UserQueryBuilder->get()
#14 C:\Users\benpl\Documents\GitHub\auth-service-helper\test-comprehensive.php(143): {closure}()
#15 C:\Users\benpl\Documents\GitHub\auth-service-helper\test-comprehensive.php(338): runTest('UserQueryBuilde...', 'where() with op...', Object(Closure))
#16 {main}
```

