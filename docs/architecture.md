# Backend architecture

This repository contains only the PHP backend/API and the HTML/JavaScript/CSS admin website. The Flutter project was removed at the user's request. A mobile application lives in a separate repository and consumes the documented JSON API.

## Request flow

`public/api.php` loads `private/api.php`, the composition root. It configures HTTP/session helpers, database infrastructure and application services, then dispatches auth, mobile and admin controllers.

Student login/logout follows a dependency boundary:

`HTTP controller -> StudentAuthService -> StudentAuthRepository interface <- PdoStudentAuthRepository`

The application service handles password verification and token generation. The PDO repository handles SQL and token persistence. The controller validates request fields and maps results to HTTP responses. Integration tests exercise this flow with a fresh temporary database.

Existing finance/admin/dashboard controllers still contain SQL and workflow rules. This is an incremental clean-architecture migration, not a claim that all legacy workflows already have independent domain and repository layers. Keep their API contracts stable when extracting further use cases.

## Public and private

Only `public/` can be served. Database, session files, scripts and PHP implementation code are outside the document root. The development router uses explicit entry points and an allowlist of static asset types.

`private/components/public` contains the login template; `private/components/private` contains the admin shell. Folder names organize components; server authentication and permissions protect data. The shell itself contains no authorization boundary.

## Configuration

- `APP_STORAGE_PATH`: alternate database/session directory, used by integration tests.
- `APP_TIMEZONE`: defaults to Asia/Bangkok. Authentication expiry values use UTC for SQLite comparisons.
- `MOBILE_APP_ORIGIN`: exact additional browser origin permitted by CORS.
- `AppTheme.palettes` in `public/assets/js/app-theme.js`: web color palette.

## Local users and data

An explicit CLI provisioning script creates separate local test users with generated passwords and hashed database credentials. It never overwrites existing accounts. Initial web passwords require a change; mobile clients must honor the returned password-change flag.

The existing SQLite database and sessions were preserved and removed from Git tracking. Ignore rules prevent new runtime files being committed. Older Git history may still contain previously committed data. Backup files, automation browser profiles, audit images, the obsolete alternate mobile API and Flutter sources/builds were removed.

## Runtime and remaining work

The official PHP 8.5.12-1 Windows build is installed locally with PDO SQLite, SQLite3 and mbstring. There are no Composer or Flutter dependencies in this backend repository. The large browser JS/CSS files retain existing behavior.

Production deployment still needs deployment-specific credentials/configuration, HTTPS, reviewed payment verification, and enforced student password rotation. Mobile token storage is the responsibility of the separate mobile application. The local PHP server does not configure Windows Firewall automatically.
