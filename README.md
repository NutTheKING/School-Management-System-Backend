# School Management Backend

PHP admin website and JSON API for a separately maintained mobile application. Flutter source and its build/dependency files have been removed from this repository.

## Run locally

PHP is installed in `.tools/php/` with PDO SQLite and mbstring. No XAMPP, Composer packages or separate database server is required.

```powershell
./scripts/start-api.ps1
# For a phone on the same Wi-Fi:
./scripts/start-api.ps1 -BindAddress 0.0.0.0
```

Web admin: http://127.0.0.1:8010/

Health check: http://127.0.0.1:8010/api.php?resource=health

On another Windows machine, run `./scripts/install-php.ps1` first. This downloads the official PHP 8.5 build and checks its checksum. To stop the foreground server press Ctrl+C.

## Connect a mobile application

| Client | API base URL |
| --- | --- |
| Android emulator | `http://10.0.2.2:8010/api.php` |
| iOS simulator on the API host | `http://127.0.0.1:8010/api.php` |
| Physical phone | `http://COMPUTER_LAN_IP:8010/api.php` |

Use the same local network for phone and computer. Bind to `0.0.0.0` for a phone; if Windows Firewall blocks the connection, allow TCP 8010 on the trusted private network. Never use `localhost` on the phone to reach this computer. Native apps must permit local HTTP during development; use HTTPS for deployment.

Every request uses `?resource=RESOURCE`. Send JSON with `Content-Type: application/json`.

| Method | Resource | Input / authentication |
| --- | --- | --- |
| GET | `health` | None |
| POST | `mobile-login` | `student_id`, `password` |
| GET | `mobile-dashboard` | Bearer token |
| POST | `mobile-change-password` | Bearer token, `current_password`, `new_password` (8+ characters) |
| POST | `mobile-attendance-scan` | Bearer token, `payload` |
| POST | `mobile-logout` | Bearer token |

Login returns `token`, `expires_at` and `must_change_password`. Send the token using `Authorization: Bearer TOKEN`. Honor the password-change flag in the mobile application. Errors use an HTTP error status and a JSON `error` message.

```javascript
const base = 'http://10.0.2.2:8010/api.php';
const login = await fetch(`${base}?resource=mobile-login`, {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({student_id: 'ST-LOCAL-001', password: 'YOUR_PASSWORD'})
});
if (!login.ok) throw new Error((await login.json()).error);
const {token} = await login.json();
const dashboard = await fetch(`${base}?resource=mobile-dashboard`, {
  headers: {Authorization: `Bearer ${token}`}
});
```

Browser clients are allowed from localhost or the exact `MOBILE_APP_ORIGIN` environment value. Native mobile apps do not require CORS.

## Login accounts

The local setup created web user `local_admin` and mobile student `ST-LOCAL-001`. Their generated passwords are supplied in the setup conversation, not committed to source. Web login requires changing the initial password. Existing accounts were not reset. `scripts/create-local-users.php` creates these accounts on a fresh installation and refuses to overwrite existing accounts.

## Structure and theme

- `public/`: the only web document root; web entry points and static assets.
- `private/Http/`: controllers, JSON request handling, sessions and headers.
- `private/Application/`: application use cases such as student authentication.
- `private/Contracts/`: persistence interfaces consumed by application services.
- `private/Infrastructure/`: SQLite setup and PDO repository implementations.
- `private/Services/`: existing finance, access and student workflows.
- `private/components/public/`: login template.
- `private/components/private/`: signed-in web UI template.
- `storage/`: local database and sessions, ignored by Git and not web-accessible.
- `scripts/`, `tests/`, `docs/`: local setup, verification and architecture notes.

Customize web colors in `AppTheme.palettes` in `public/assets/js/app-theme.js`.

## Verification

```powershell
python ./tests/api_smoke.py
```

The test starts an isolated PHP server and temporary database. It checks login, CSRF, authenticated mobile data, logout and private-file blocking without changing your school database. Python is only needed for the test, not to run the API.

See [architecture notes](docs/architecture.md) for remaining legacy boundaries. The PHP built-in server is for development; configure a production web server with `public/` as its document root for deployment.
