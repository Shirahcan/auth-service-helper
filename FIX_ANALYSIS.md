# Multi-Account Session Synchronization Fix - Analysis

## Problem Summary
When adding a second account to an existing session, the Laravel `session['auth_user']` and `auth()->user()` continue to show the FIRST account's data instead of updating to the newly added SECOND account (which becomes the active account).

## Test Results

### Test 1: Initial Login ✅ PASSED
- Logged in with User 1 (admin@documents.shirah.co)
- Session data correctly shows User 1
- `auth()->user()` returns User 1
- Account switcher shows User 1

### Test 2: Add Second Account ❌ FAILED
- Added User 2 (admin2@documents.shirah.co) to session
- **Account switcher correctly shows User 2 as active** (SA initials)
- **BUT session `auth_user` still shows User 1 data**
- **AND `auth()->user()` still returns User 1**
- Page shows "Logged in as" User 1 instead of User 2

## Root Cause

The `AuthController::handleCallback()` method:

1. Validates token with `/me` endpoint → gets user data
2. Calls `getSessionAccounts()` to fetch active account
3. **SHOULD** update `$userData` with active account from session accounts
4. Stores `$userData` in Laravel session

**The Issue**: The logic to extract active account data from `getSessionAccounts()` response is not working correctly.

### Current Fix Implementation (Lines 199-231)

```php
$sessionAccountsResponse = $this->authServiceClient->getSessionAccounts($authToken);

if (($sessionAccountsResponse['success'] ?? false) && isset($sessionAccountsResponse['data']['session'])) {
    $session = $sessionAccountsResponse['data']['session'];
    $activeAccountRef = $session['active_account'] ?? null;
    $accounts = $session['accounts'] ?? [];

    // Find the active account in the accounts array using the UUID
    if ($activeAccountRef && isset($activeAccountRef['uuid'])) {
        $activeAccountData = collect($accounts)->firstWhere('id', $activeAccountRef['uuid']);

        if ($activeAccountData) {
            // Convert active account data to the format expected by User model
            $userData = [
                'id' => $activeAccountData['id'],
                'name' => $activeAccountData['name'],
                'email' => $activeAccountData['email'],
                // ... other fields
            ];

            $user = User::createFromSession($userData);
        }
    }
}
```

### Why Account Switcher Works

The `AccountSwitcher` component (lines 406-410 in blade):
```php
$activeAccountData = collect($accounts)->firstWhere('id', $activeAccount['uuid']);
$avatar = $activeAccountData['avatar'] ?? null;
$name = $activeAccountData['name'] ?? 'User';
```

This SAME logic works in the blade component but fails in AuthController.

## Possible Issues

1. **Timing**: Auth service might not immediately update session structure when second account is added
2. **Response Structure**: The `getSessionAccounts()` might return different structure for different scenarios
3. **Empty Accounts Array**: For single-account sessions, accounts array might be empty
4. **Conditional Failure**: One of the nested conditions fails silently, leaving original `$userData` unchanged

## Testing Evidence

From browser snapshot:
- Account switcher button shows "SA" (User 2's initials)
- "Logged in as" text shows "Shirah Documents Service Administrator (admin@documents.shirah.co)" (User 1)
- Session data shows User 1:
  ```json
  "auth_user": {
      "id": "77b3d295-ae9e-4b91-87ca-3ed2241a8e32",
      "name": "Shirah Documents Service Administrator",
      "email": "admin@documents.shirah.co",
      ...
  }
  ```

This proves:
1. The auth service correctly made User 2 active (account switcher reflects this)
2. The AuthController callback DID NOT update Laravel session with User 2 data
3. The `getSessionAccounts()` call either failed or returned unexpected structure

## Next Steps

Need to debug why the active account extraction fails in AuthController when it works in AccountSwitcher component. Options:

1. **Add Debug Logging**: Log the full `$sessionAccountsResponse` to see actual structure
2. **Simplify Logic**: Remove nested conditionals, make extraction more robust
3. **Fallback Strategy**: If accounts array is empty, use the /me response as-is
4. **Force Refresh**: After session update, ensure guard's cached user is cleared

## Recommended Solution

The user suggested: "Since the account switcher is working correctly, why not fetch the accounts/sessions info just as the account switcher does?"

This means we should:
1. Always call `getSessionAccounts()` after token validation
2. If it returns active_account data, extract and use it
3. If extraction fails OR accounts is empty, fall back to /me data
4. Ensure this works for BOTH single-account (first login) AND multi-account (add account) scenarios
