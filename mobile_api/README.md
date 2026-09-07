# system_cs Mobile API

This folder was added for the Flutter mobile application.

Local PHP server URL:

- Web system: `http://127.0.0.1:8010/`
- Android emulator API: `http://10.0.2.2:8010/mobile_api`

Endpoints:

- `POST /mobile_api/login.php`
- `POST /mobile_api/logout.php`
- `GET /mobile_api/profile.php`
- `GET /mobile_api/dashboard.php`
- `GET /mobile_api/students.php`

The API uses the same SQLite database at `storage/northstar.sqlite` and the same existing web demo login:

- Email: `admin@scschool.local`
- Password: `admin123`

Except for login, endpoints require an HTTP header:

`Authorization: Bearer YOUR_TOKEN`

For production, move credentials into a real users table, use HTTPS, restrict CORS, and add role-based permissions.
