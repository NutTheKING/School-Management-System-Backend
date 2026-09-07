# Fix Mobile App API Connection and Error Messaging

The user is encountering a `{"success": false, "message": "POST required"}` error. This occurs because the `login.php` endpoint only accepts `POST` requests, but it is being accessed via `GET` (likely by opening the URL directly in a web browser).

This plan addresses:
1. Clarifying the usage of the API endpoints.
2. Improving the backend error message for better developer experience.
3. Making the Flutter app's `ApiService` more robust for different environments (Web vs. Emulator).

## User Review Required

> [!IMPORTANT]
> The error in your screenshot is expected when opening the API link in a web browser. The link is designed for the **mobile app** to communicate with, not for direct viewing in a browser.

## Proposed Changes

### [PHP Backend]

#### [MODIFY] [login.php](file:///C:/xampp/htdocs/system_cs/mobile_api/login.php)
Improve the error message when a `GET` request is made to explain that this is an API endpoint.

### [Flutter Mobile App]

#### [MODIFY] [api_service.dart](file:///C:/xampp/htdocs/wg_school_mobile/lib/services/api_service.dart)
Update `baseUrl` to automatically switch between `localhost` (for Web/Desktop) and `10.0.2.2` (for Android Emulator).

## Verification Plan

### Automated Tests
- Run a shell command to verify `login.php` still responds correctly to `POST`.
- Run a shell command to verify `login.php` returns the new message for `GET`.

### Manual Verification
- User should run the Flutter app and attempt login with `admin@scschool.local` / `admin123`.
- User should avoid opening the `.php` links directly in the browser.
