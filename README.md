# Tazflow — API

The Laravel half of Tazflow, a project management app. The Vue SPA lives in `../tazflow-frontend`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the MySQL database named in DB_DATABASE (tazflow_app by default), then:
php artisan migrate --seed
php artisan storage:link
php artisan serve
php artisan queue:work   # in a second terminal: emails go through the queue
```

Seeded logins: `admin@example.com`, `rupash.das.202@gmail.com` and others; every password is `Pass123#`.

## How a request inside a workspace runs

1. The SPA sends `X-Workspace: <slug>` with every request inside a workspace.
2. `ResolveWorkspace` (middleware) checks the caller is an active member and puts the workspace and their membership in `CurrentWorkspace`. It runs before route model binding, so `{role}` from another workspace is not found.
3. `capability:<name>` (middleware) asks `CurrentWorkspace::allows()`. The owner passes every check in their own workspace; everyone else gets what their role there allows.
4. Models using `BelongsToWorkspace` (roles, invitations, memberships) are filtered to the current workspace automatically and stamped with it on create.

Capabilities live in code (`app/Support/CapabilityRegistry.php`) and are workspace-level only. New people join only through invitations. Admins manage memberships, never accounts.

Design: `../docs/superpowers/specs/2026-09-19-multi-tenant-workspaces-design.md`.
