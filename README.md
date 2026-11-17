# KEIFO High School Results & Management System (KEHS)

A web-based system for managing continuous assessments (CA), examination
results, student performance, staff, and school documents — built for
Admins, Teaching Staff, Parents, and Students.

**Website:** www.kehs.ac.tz
**Developed & deployed by:** G7 DevOps

## Tech Stack

| Layer      | Technology                          |
|------------|--------------------------------------|
| Frontend   | HTML5, CSS3, Bootstrap 5, JavaScript |
| Backend    | PHP 8, layered architecture for Auth (Controller → Service → Repository), PDO for database |
| Database   | MySQL / MariaDB                      |
| Server     | Apache (XAMPP recommended for local dev) |

See **[docs/ARCHITECTURE.md](ARCHITECTURE.md)** for the layered design,
RBAC model, and how SOLID/MVP/ISO-25010 principles are applied.

## Who Can Do What

| Role        | Key Functions |
|-------------|----------------|
| **Admin**   | Add/edit/remove staff, assign roles, manage users (students & staff), manage departments, manage parent accounts, upload/manage joining instructions & documents, send announcements, manage RBAC permissions, view reports & statistics, configure system settings |
| **Staff (Teacher)** | View/update own profile, view assigned subjects & classes, upload CAs, upload student results, view timetable, receive announcements, upload/access teaching documents, change own info |
| **Parent**  | View linked children, view CA/exam results per subject once published, download a printable result slip, download school documents |
| **Student** | Optionally log in to view their own published results |

## Login

Login is by **User ID / Registration Number**, not email (email is
optional, for notifications only). **Passwords must be changed at least
every 30 days** — the system enforces this automatically.

## Project Structure

```
school_results_system/
├── app/                  Layered architecture (Auth module reference implementation)
│   ├── Core/              Database connection
│   ├── Repositories/      Data access (UserRepository)
│   ├── Services/          Business rules (AuthService)
│   ├── Controllers/       HTTP handling (AuthController)
│   └── Middleware/        Route guards (AuthMiddleware - login/role/permission)
├── admin/                Admin dashboard & management pages
├── teacher/               Staff dashboard & marks entry
├── parent/                Parent dashboard & result slips
├── student/                Student self-view dashboard
├── auth/                  Login / logout
├── config/                Database & app configuration (not web-accessible)
├── includes/               Shared PHP includes (functions, header/footer, sidebars)
├── profile.php             Shared "My Profile" page (all roles)
├── assets/
│   ├── css/style.css       Custom styling
│   └── js/script.js        Client-side interactivity
├── uploads/
│   ├── documents/          Uploaded school documents
│   └── results/            Uploaded result files (PDF etc.)
├── database/
│   ├── schema.sql          Full database schema
│   ├── seed.sql             Demo/sample data
│   └── set_admin_zulu.sql   Standalone script to (re)create the admin login
├── docs/                   Documentation (this folder)
└── index.php               Entry point (redirects to login or dashboard)
```

## Quick Start

See **[docs/INSTALLATION.md](INSTALLATION.md)** for full setup instructions.

1. Copy the project into your XAMPP `htdocs` folder.
2. Import `database/schema.sql`, then `database/seed.sql`, into MySQL.
3. Update `config/database.php` if your MySQL credentials differ.
4. Visit `http://localhost/school_results_system/` in your browser.
5. Log in with one of the demo accounts (see below).

## Demo Accounts

| Role    | User ID    | Password    |
|---------|------------|-------------|
| Admin   | `zulu`     | `zulu@123`  |
| Teacher | `jmushi`   | Password123 |
| Parent  | `P-1001`   | Password123 |

Teacher login IDs are character-based (derived from the teacher's name,
e.g. `jmushi`), separate from their Staff No. (`T-001`, an internal
reference code). Student admission numbers/login IDs follow
`{SCHOOL_CODE}.NNNN` (see `SCHOOL_CODE` in `config/config.php`), e.g.
`S4652.0001`.

## Results Workflow

```
Teacher enters marks  →  draft
Teacher submits        →  submitted
Admin approves          →  approved   (marks now locked from teacher edits)
Admin publishes          →  published  (visible to parents & students)
```

## RBAC (Role-Based Access Control)

Beyond basic role checks, Admin can fine-tune exactly which permissions
each role has from **Admin → RBAC Permission Matrix**, without editing
code. See `docs/ARCHITECTURE.md` for details.

## Further Reading

- [docs/INSTALLATION.md](INSTALLATION.md) — step-by-step setup guide
- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — layered design, RBAC, design principles
- [docs/DATABASE.md](DATABASE.md) — schema reference
- [docs/USER_GUIDE.md](USER_GUIDE.md) — how to use each dashboard
- [docs/SECURITY_NOTES.md](SECURITY_NOTES.md) — security measures, HTTPS/SSL-TLS, password hashing, and recommendations before going live
- [docs/CHANGELOG.md](CHANGELOG.md) — version history
