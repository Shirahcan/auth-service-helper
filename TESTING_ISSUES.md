# Account Switcher Testing Issues

## Test Date
2025-10-01

## Test Environment
- Server: localhost:8100
- Test Page: /test-account-switcher
- Laravel Session Driver: (will be captured)

## Test Credentials
- User 1: admin@documents.shirah.co / My_shirah_7218
- User 2: admin2@documents.shirah.co / My_shirah_7218

---

## Test Results

### Test 1: Initial Login
**Status:** ✅ PASSED

**Steps:**
1. Navigate to localhost:8100/test-account-switcher
2. Log in with User 1 (admin@documents.shirah.co)
3. Verify account switcher, session data, and auth()->user()

**Expected:**
- Account switcher shows User 1
- Session `auth_user` contains User 1 data
- Page displays User 1 name/email from auth()->user()

**Actual:**
- ✅ Account switcher shows "SD" button (correct initials)
- ✅ Session `auth_user` contains User 1 data (id: 77b3d295-ae9e-4b91-87ca-3ed2241a8e32, email: admin@documents.shirah.co)
- ✅ Page displays "Logged in as Shirah Documents Service Administrator (admin@documents.shirah.co)"

**Issues Found:**
- None

---

### Test 2: Add Second Account (CRITICAL)
**Status:** ❌ FAILED

**Steps:**
1. Starting with User 1 logged in
2. Click "Add another account" in account switcher
3. Log in with User 2 (admin2@documents.shirah.co)
4. After redirect, verify all data sources

**Expected:**
- Account switcher shows User 2 as active account
- Session `auth_user` contains User 2 data
- Page displays User 2 name/email from auth()->user()
- Both accounts visible in switcher

**Actual:**
- ✅ Account switcher shows "SA" button and displays "Hi, SDS!" with "admin2@documents.shirah.co" - **CORRECT**
- ✅ Switcher shows "Show 1 more account" - both accounts are present - **CORRECT**
- ❌ Session `auth_user` still contains **User 1** data (id: 77b3d295-ae9e-4b91-87ca-3ed2241a8e32, email: admin@documents.shirah.co) - **WRONG**
- ❌ Page displays "Logged in as **Shirah Documents Service Administrator** (admin@documents.shirah.co)" - User 1, not User 2 - **WRONG**
- ❌ Session `auth_token` is still the OLD token from the initial login - **WRONG**

**Issues Found:**
- **CRITICAL BUG**: After adding a second account via callback, the Laravel session (`auth_user` and `auth_token`) is NOT updated to reflect the new active account
- The account switcher component correctly shows the active account because it fetches fresh data from the auth service
- But `auth()->user()` returns stale data from the session

---

### Test 3: Switch Accounts
**Status:** ✅ PASSED

**Steps:**
1. Starting with User 2 as active (but session showing User 1 due to bug)
2. Use account switcher to switch back to User 1
3. Verify all data sources update

**Expected:**
- Account switcher shows User 1 as active
- Session `auth_user` contains User 1 data
- Page displays User 1 name/email from auth()->user()

**Actual:**
- ✅ Account switcher shows "SD" button (User 1)
- ✅ Session `auth_user` contains User 1 data - **CORRECTLY UPDATED**
- ✅ Page displays "Logged in as Shirah Documents Service Administrator (admin@documents.shirah.co)" - **CORRECT**
- ✅ Session `auth_token` is a **NEW** token that includes both accounts
- ✅ `last_activity` timestamp updated correctly

**Issues Found:**
- None - switching works perfectly and updates the session correctly

---

## Summary of Issues

### Critical Issues

**Issue #1: Add Account Does Not Update Laravel Session**
- **Location**: [AuthController.php:129-226](src/Http/Controllers/AuthController.php#L129)
- **Description**: When adding a second account via the callback, the Laravel session (`auth_user` and `auth_token`) is not updated with the newly added account's information
- **Root Cause**: The `handleCallback()` method validates the token by calling `/me` API, which returns user data. However, when the `action` is "add-account", this `/me` call happens BEFORE the account is actually added to the multi-account session. The method stores the validated user data and token in the session, but these represent the account that was just authenticated, not necessarily the active account in the multi-account session.
- **Impact**: HIGH - After adding an account, `auth()->user()` returns incorrect user data, causing application logic to operate with wrong user context
- **Comparison**: The `AccountSwitcherController::switchAccount()` method correctly updates the session by using the user data returned from the switch-account API response

### Minor Issues
- None identified

---

## Fixes Applied

### Fix #1: Update handleCallback to Fetch Active Account for Add-Account Action
**File**: [src/Http/Controllers/AuthController.php](src/Http/Controllers/AuthController.php)
**Lines**: 199-226

**Change Description**:
Modified the `handleCallback()` method to fetch session accounts when the action is "add-account". After validating the token with `/me`, we now call `getSessionAccounts()` to retrieve the active account information from the multi-account session. This ensures that the Laravel session is updated with the correct active account data, not just the authenticated account data.

**Code Changes**:
- Added check for `action === 'add-account'` after token validation
- Call `$this->authServiceClient->getSessionAccounts($authToken)` to get session state
- Extract `active_account` from the response
- Update `$userData` with the active account's information
- Recreate `$user` instance with correct active account data
- Session is then stored with the correct active user information

**Why This Works**:
- In a multi-account session, the auth service maintains which account is "active"
- When adding an account, the newly added account becomes the active one
- The `/me` endpoint returns the authenticated user, but in multi-account context, we need the "active" account
- The `getSessionAccounts()` endpoint returns the full session state including the active account
- This matches the pattern used in `AccountSwitcherController::switchAccount()` which also updates session from API response

---

## Final Verification

(To be completed after all fixes)
