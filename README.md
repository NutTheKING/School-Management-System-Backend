# SC Smart School

A dependency-free PHP school management prototype reconstructed from the supplied SC Smart School workflow recording.

## Run

Use the bundled XAMPP PHP runtime from this directory:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8010 router.php
```

Then open `http://127.0.0.1:8010/`. The router prevents direct web access to database and session files.

Demo login:

- Email: `admin@scschool.local`
- Password: `admin123`

## Included workflows

- Operational dashboard and activity overview
- Admission inquiry pipeline with approval-to-enrollment conversion
- Student directory with search, filters, create, edit, delete, and CSV export
- Card-oriented daily attendance entry with manual adjustments
- Tuition, material, transport, daycare, and other fee payment recording
- Classes, subjects, weekly schedule, exams, and results
- Staff/payroll, inventory, and transport registers
- Leadership reports and school settings
- Provider-neutral AI operations workspace with common school workflows

## Architecture

PHP 8, PDO, and SQLite provide the server layer. The API initializes its schema and demo records on first run, uses prepared statements and database transactions, validates CSRF tokens, and stores sessions under the protected `storage` directory. The browser retains local storage only as an offline fallback.

For production, add user authentication and role permissions, migrate SQLite to MySQL/PostgreSQL, and store uploaded files outside the public document root. AI requests should go through a server-side gateway that applies authorization, redaction, audit logging, and human review. Provider API keys must never be shipped to the browser.

## Source limitations

The video exposes screens and user flows, but not the original source code, database schema, validation rules, permissions, or external integrations. This build therefore recreates the observable product behavior as a working prototype; production parity requires those underlying specifications and APIs.
