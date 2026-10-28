# Vault Intelligence Agency

> A private intelligence and security system for managing case files, person profiles, and organizational records. Files can be uploaded manually or through a key-protected API endpoint and are stored on private storage.

**Application name:** `Vault Intelligence Agency` · **Short name:** `Vault` · **Acronym:** `VIA` · **Symbol:** `V`
**Stack:** Laravel 12 · Blade + Livewire 3 · Alpine.js (provided by Livewire) · Echo/Pusher · configured database driver · Remix Icon
**Design language:** Federal-intelligence-style command console: dark, authoritative, restrained, animated, glass-morphism overlays.
**Departmental rollout:** The public department selector, separate login entry points, Admin identity, and admin-controlled staff lifecycle are implemented. The Case File Management workspace now supports case creation, viewing, editing, evidence uploads, and owner-scoped background-transfer tracking. PM and FM feature workspaces remain in the next approved stages.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Feature Scope (v1)](#2-feature-scope-v1)
3. [Tech Stack & Requirements](#3-tech-stack--requirements)
4. [Architecture Overview](#4-architecture-overview)
5. [Project Structure](#5-project-structure)
6. [Installation & Setup](#6-installation--setup)
7. [Database Design](#7-database-design)
8. [Private Storage Strategy](#8-private-storage-strategy)
9. [Authentication and Departmental Access](#9-authentication-and-departmental-access)
10. [Upload API Endpoint](#10-upload-api-endpoint)
11. [Routes Map](#11-routes-map)
12. [Dashboard Specification](#12-dashboard-specification)
13. [File Viewing & Preview Rules](#13-file-viewing--preview-rules)
14. [File Editing & Save-Back](#14-file-editing--save-back)
15. [Design System](#15-design-system)
16. [Typography](#16-typography)
17. [Login Page Specification](#17-login-page-specification)
18. [Animation & Glass-Morphism Spec](#18-animation--glass-morphism-spec)
19. [Icons](#19-icons)
20. [Extensibility Rules](#20-extensibility-rules-no-redesign-later)
21. [Security Checklist](#21-security-checklist)
22. [Server Configuration](#22-server-configuration)
23. [Testing](#23-testing)
24. [Build Order / Milestones](#24-build-order--milestones)
25. [Future Roadmap](#25-future-roadmap)
[Vault Shell Command Reference](COMMAND.md)

---

## 1. Project Overview

Vault Intelligence Agency is a private intelligence and security system. Administrators can manage case files, person profiles, settings, and the organizational archive. External systems can upload through the key-protected API endpoint. Files are stored privately on the server and can be browsed and previewed through authenticated tools.

**Core principles**

- **Private by default.** No file is ever reachable by a public URL.
- **Central administration.** Admins manage agency accounts and control access to department-specific workspaces.
- **Departmental separation.** Case File Management, Profile Management, and File Management receive distinct routes and least-privilege access; rollout is staged and not yet complete.
- **Gated ingestion.** Uploads are impossible without the correct `auth` key, which the admin can reset at any time from the dashboard.
- **Chronological organization.** Files are grouped by month and year (e.g. **October 2026**).
- **Design that scales.** A token-driven design system so new modules slot in without touching the visual foundation.

---

## 2. Feature Scope (v1)

| # | Feature | Notes |
|---|---------|-------|
| 1 | Admin login / logout | Separate Admin model/guard, throttled session-based access |
| 2 | Upload endpoint | `POST /api/upload?auth=KEY`, any file type |
| 3 | Upload key management | View, copy, reset to any custom string |
| 4 | Dashboard file listing | All files, grouped by "Month Year" |
| 5 | File content access | In-dashboard preview modal (text, code, image, PDF, audio, video, fallback download) |
| 6 | File editing | Edit text-based files and save back to storage |
| 7 | Download / delete | Basic necessary actions |
| 8 | Search + type filter | Light client/server filtering so the dashboard stays usable |
| 9 | Summary stat cards | Total files, total size, uploads this month, last activity |
| 10 | Settings page | Upload key, endpoint info + cURL sample, admin password change |
| 11 | Person records | Optional person dossiers, private main/gallery images, linked cases, and public IDs |
| 12 | Case files | Classified case dossiers with grouped image evidence and linked people |
| 13 | Avatar models | Authenticated configuration of private Male, Female, Group, Unknown, Suspect, and Verified GLB models |
| 14 | Agency staff management | Admin-created department accounts, username login, password reset, suspend/disable, and removal |

Department roles and account management are part of the staged agency rollout. CFM case management is implemented; PM and FM feature workspaces remain in progress. Sharing links, folders, tags, versions UI, and bulk operations remain outside the current scope.

---

## 3. Tech Stack & Requirements

- **PHP** 8.2+
- **Laravel** 12.x
- **MySQL** 8.0+
- **SQLite** is also supported by the current application configuration and test suite
- **Composer**, **Node 18+** (Vite for asset bundling)
- **Remix Icon** (`remixicon` npm package)
- **Fonts:** IBM Plex Sans, IBM Plex Mono, Cinzel (see [Typography](#16-typography))
- **Livewire** 3.x; its bundled Alpine instance is used (do not load Alpine a second time)
- **Laravel Echo** and **Pusher JS/PHP** for optional realtime broadcasting
- **Optional editor:** CodeMirror 6 for syntax-highlighted editing

---

## 4. Architecture Overview

```
 External client ──POST /api/upload?auth=KEY──▶ UploadController
                                                   │ validates key (hash_equals)
                                                   ▼
                                       Private disk: storage/app/private/vault/YYYY/MM/<uuid>.<ext>
                                                   │
                                                   ▼
                                      Configured database: files table (metadata)

 Agency landing ──department choice──▶ separate login
      │                                 ├── Admin ──▶ /admin/
      │                                 ├── CFM   ──▶ /cfm/
      │                                 ├── PM    ──▶ /pm/
      │                                 └── FM    ──▶ /fm/
      └── department cards use a connected-dot server diagram

 Admin session ──▶ central account control + full archive workspace
 Staff session ──▶ department-checked routes and read-only identity profile
 File browser ──▶ authenticated stream route ──▶ private disk
```

Key rule: **the browser never receives a storage path.** Every byte is served through an authenticated Laravel route.

---

## 5. Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Auth/LoginController.php
│   │   ├── VaultShellController.php
│   │   ├── DashboardController.php
│   │   ├── FileController.php          # show, stream, download, update, destroy
│   │   ├── SettingsController.php      # upload key + password
│   │   └── Api/UploadController.php    # public-but-key-gated endpoint
│   ├── Middleware/
│   │   └── VerifyUploadKey.php
│   └── Requests/
│       ├── UploadRequest.php
│       └── UpdateFileRequest.php
├── Models/
│   ├── File.php
│   ├── Setting.php
│   └── User.php
├── Services/
│   ├── FileStorageService.php          # store / read / overwrite / delete
│   ├── SettingService.php              # cached get/set, encrypted values
│   └── VaultShellService.php           # private-root directory and file access
└── Support/FileTypes.php               # mime → category + editable map
config/vault.php                         # editable extensions, preview limits
database/
├── migrations/
└── seeders/AdminSeeder.php
resources/
├── css/
│   ├── tokens.css                      # design tokens (single source of truth)
│   ├── base.css
│   ├── components.css                  # buttons, cards, glass, modals, tables
│   └── pages/{login,dashboard}.css
├── js/
│   ├── app.js
│   ├── modal.js
│   ├── editor.js
│   └── preview.js
└── views/
    ├── layouts/{app,department,guest}.blade.php
    ├── components/                     # sidebar, topbar, stat-card, modal, file-row...
    ├── auth/login.blade.php
    ├── dashboard/index.blade.php
    └── settings/index.blade.php
routes/{web,api}.php
```

---

## 6. Installation & Setup

```bash
composer create-project laravel/laravel vault
cd vault

npm install remixicon
npm install

cp .env.example .env
php artisan key:generate
```

`.env`:

```env
APP_NAME="Vault Intelligence Agency"
APP_SHORT_NAME=Vault
APP_ACRONYM=VIA
APP_SYMBOL=V
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vault
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=60

# Initial central administrator credentials (use a unique password)
ADMIN_NAME="Administrator"
ADMIN_EMAIL=admin@vault.local
ADMIN_PASSWORD=replace-with-a-unique-password-before-seeding

# Optional initial upload key. If omitted, a secure key is generated and
# displayed in the authenticated Settings page.
INITIAL_UPLOAD_KEY=
```

Set a unique `ADMIN_PASSWORD` of at least 12 characters before running the seeder. `APP_NAME` is used in page titles, `APP_SHORT_NAME` is the wordmark, and `APP_ACRONYM` is used in system labels and the Vault Shell prompt.

```bash
php artisan migrate --seed
npm run dev          # or: npm run build
php artisan serve
```

**Important:** do **not** run `php artisan storage:link` for this project. The private disk must never be symlinked into `public/`. Set PHP's `upload_max_filesize` and `post_max_size` high enough for the configured file limit.

Branding is controlled by `APP_NAME`, `APP_SHORT_NAME`, and `APP_ACRONYM`. Upload defaults are `VAULT_MAX_UPLOAD_MB` (100 MB per file) and `VAULT_API_UPLOAD_ENABLED` (`true`). The administrator can override the maximum file size and API toggle under Settings. A maximum of `0` disables both manual and API uploads.

---

## 7. Database Design

### `users` (default Laravel table; exactly one row, created by seeder)

### `files`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | char(36), unique | Public-safe identifier used in routes |
| `original_name` | varchar(255) | Name as uploaded (display only, never used as a path) |
| `stored_path` | varchar(500) | Relative path on private disk |
| `extension` | varchar(20), nullable | Lowercased |
| `mime_type` | varchar(150) | Server-detected, not client-claimed |
| `category` | varchar(30) | `image/video/audio/pdf/text/code/archive/other` |
| `size` | bigint unsigned | Bytes |
| `checksum` | char(64) | SHA-256, updated on every edit |
| `is_editable` | boolean | Derived at upload from the editable map |
| `version` | unsigned int default 1 | Incremented on edit |
| `source_ip` | varchar(45), nullable | Uploader IP for audit |
| `edited_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | `created_at` drives month/year grouping |

Indexes: `uuid` (unique), `created_at`, `category`, fulltext or index on `original_name`.

### `settings`

| Column | Type | Notes |
|--------|------|-------|
| `key` | varchar(100) PK | e.g. `upload_key` |
| `value` | text | Encrypted via `Crypt::encryptString` |
| `updated_at` | timestamp | |

> The upload key is stored **encrypted** (not hashed) so the admin can view and copy it on the dashboard. Comparison on the endpoint uses `hash_equals` against the decrypted value.

### Investigation records

The investigation module uses `people`, `person_images`, `case_files`,
`case_categories`, `case_images`, and `case_person`. Person and case records
receive unique `USR-` and `CSE-` public identifiers, respectively. IDs use a
seven-character uppercase alphanumeric suffix and grow to eight or nine
characters when collisions prevent allocation.

Person data fields are optional; blank values are stored as `Unknown`. A
person's main image is stored separately from additional gallery media.
Case files have a classification (`CLASSIFIED`, `HIGHLY SENSITIVE`, or
`ACCESSIBLE`), workflow status, narrative, comments, and any number of named
evidence categories. Existing media tables retain image behavior and can also
reference uploaded video files. People and cases are linked through the
`case_person` pivot.

All profile and case images are limited to raster formats and 10 MB per image.
Their bytes remain on the private `vault` disk and are served only through
authenticated routes. Person images additionally require a four-digit
administrator-configured PIN; the PIN is stored as an encrypted password hash,
and unlocking applies only to the current person profile visit. Main images and
galleries are not requested by the browser before PIN verification. Male,
Female, and Group GLB avatars are stored on the same private disk; uploads are
checked for a GLB 2.0 header and external asset references are rejected. The
model-viewer JavaScript is loaded only on pages that display a 3D model.
Person and case image viewers fit the complete image by default, with zoom and
drag-to-pan controls. Grayscale can be toggled for inspection and is applied to
the full-resolution PNG generated when downloading while grayscale is enabled.

### Example migration (files)

```php
Schema::create('files', function (Blueprint $t) {
    $t->id();
    $t->uuid('uuid')->unique();
    $t->string('original_name');
    $t->string('stored_path', 500);
    $t->string('extension', 20)->nullable();
    $t->string('mime_type', 150);
    $t->string('category', 30)->index();
    $t->unsignedBigInteger('size');
    $t->char('checksum', 64);
    $t->boolean('is_editable')->default(false);
    $t->unsignedInteger('version')->default(1);
    $t->string('source_ip', 45)->nullable();
    $t->timestamp('edited_at')->nullable();
    $t->timestamps();
    $t->index('created_at');
});
```

### Month-year grouping

```php
$files = File::latest()->get();
$groups = $files->groupBy(fn ($f) => $f->created_at->format('F Y')); // "October 2026"
```

For large datasets, paginate by month: query distinct `DATE_FORMAT(created_at,'%Y-%m')` values, then lazy-load each group's files when its section is expanded.

---

## 8. Private Storage Strategy

`config/filesystems.php`:

```php
'disks' => [
    'vault' => [
        'driver'     => 'local',
        'root'       => storage_path('app/private/vault'),
        'visibility' => 'private',
        'throw'      => true,
    ],
],
```

Rules:

- Stored name = `Str::uuid() . '.' . $safeExt` placed in `YYYY/MM/` subfolders. The original name never touches the filesystem.
- No symlink to `public/`. No direct web server access to `storage/`.
- Nginx/Apache must have the document root at `public/` only.
- Reading, editing, downloading, and deletion go through `FileStorageService` only.

---

## 9. Authentication and Departmental Access

- The public landing page at `/` links to department-specific login routes: `/admin/login`, `/cfm/login`, `/pm/login`, and `/fm/login`. Department links use Livewire navigation.
- The central administrator authenticates only against the separate `Admin` model and `admin` guard. An administrator does not need a matching record in `users`.
- The additive identity migration copies the configured existing admin's identity and already-hashed password to `admins`, and adds username, department, account status, and private photo-path fields to `users`. It does not delete the original account or its file history.
- `AdminSeeder` creates the administrator in `admins` and does not create an administrator row in `users`.
- All management routes under `/admin/` require the Admin guard. A narrowly restricted configured legacy `User` session remains accepted temporarily for compatibility with existing user-owned admin operations; it is not used by the Admin login flow and is not recreated by the seeder. `/admin/staff` provides the central account controls.
- Staff sign in by username at their assigned department route. `cfm`, `pm`, and `fm` are stored as department codes. Only active accounts authenticate; suspended and disabled accounts are refused, and department middleware blocks cross-department route access.
- Admins create and edit staff identity/department and private profile pictures, set initial passwords, reset passwords, change status, and delete accounts. Staff can view a read-only profile at `/{department}/profile`; profile photos are served only through authenticated private-storage routes.
- CFM staff can create, view, and edit case files and their evidence, with uploads tracked privately per staff owner. They cannot access person-management routes or delete case files; only Admin can delete cases. PM and FM feature workspaces remain pending.
- Login throttling: 5 attempts/minute per IP and identity; sessions regenerate on login and invalidate on logout.
- Admin password changes through Settings update the Admin identity only.
- Settings are at `/admin/settings`; API upload keys remain encrypted in the `settings` table.
- Admin retains the full existing case, person, archive, shell, upload, and settings feature set. Department restrictions are enforced by explicit route middleware rather than hidden navigation alone.
- The shell remains available only in the Admin workspace until its File Management integration is delivered.

---

## 10. Upload API Endpoint

### Contract

```
POST /api/upload?auth=<UPLOAD_KEY>
Content-Type: multipart/form-data
Field: file        (single)   OR   files[]  (up to 20 files)
```

### Behavior

1. The API-upload setting and non-zero size policy are checked.
2. `VerifyUploadKey` middleware compares `auth` to the encrypted key using `hash_equals`; missing or wrong key returns **401**.
3. Validates the file(s) and enforces the configured per-file size. A request can contain at most 20 files.
4. Detects the MIME type server-side, generates a private UUID-based storage name, computes SHA-256, and writes file metadata.
5. Returns a JSON response with the stored file metadata.

The upload key is managed at `/admin/settings`. The API enable/disable switch only affects API uploads; manual uploads remain available unless the maximum size is set to `0`.

### Responses

```json
// 201 Created
{
  "status": "success",
  "data": [
    {
      "uuid": "b7d1c1f2-...",
      "name": "report.pdf",
      "size": 482113,
      "mime": "application/pdf",
      "uploaded_at": "2026-10-23T14:11:02+01:00"
    }
  ]
}

// 401 Unauthorized
{ "status": "error", "message": "Unauthorized." }

// 403 API access disabled or all uploads blocked by a zero-MB limit
{ "status": "error", "message": "API uploads are disabled." }

// 422 Validation error
{ "message": "The given data was invalid.", "errors": { "file": ["..."] } }
```

### Example

```bash
curl -X POST "https://your-domain.com/api/upload?auth=YOUR_KEY" \
     -F "file=@/path/to/document.pdf"
```

### Hardening

- Throttle: `throttle:60,1` on the route, plus a stricter limiter for failed key attempts per IP.
- Disable CSRF for this route only (it lives in `routes/api.php`, which is stateless).
- Advise clients to use HTTPS: a key in a query string appears in server logs, so **mask the `auth` parameter in access logs** and avoid logging full URLs in the app.
- Reset of the key instantly invalidates the old key (the cached copy is flushed on reset).

---

## 11. Routes Map

```
GUEST
GET   /                               home / department selector
GET   /admin/login                   login
POST  /admin/login                   auth.attempt     (throttled)
GET   /cfm/login                     cfm.login
GET   /pm/login                      pm.login
GET   /fm/login                      fm.login

API (stateless, key-gated)
POST  /api/upload                    api.upload       (middleware: VerifyUploadKey, throttle)

ADMIN (middleware: auth)
POST  /admin/logout                  auth.logout
GET   /admin/                        dashboard.index
GET   /admin/archive/files           archive.files
GET   /admin/calculator              calculator.index
GET   /admin/uploads                 uploads.create
GET   /admin/settings                settings.index
GET   /admin/staff                   admin.staff.index
GET   /admin/people                  people.index
GET   /admin/people/{person}         people.show
GET   /admin/cases                   cases.index
GET   /admin/cases/{caseFile}        cases.show
DELETE /admin/cases/{caseFile}       cases.destroy (Admin only)
GET   /admin/avatar-models           avatar-models.index
GET   /admin/shell/directory         shell.directory

DEPARTMENT (middleware: auth:web + assigned department)
GET   /cfm/                          cfm.dashboard
GET   /cfm/cases                     cfm.cases.index
GET   /cfm/cases/create              cfm.cases.create
POST  /cfm/cases                     cfm.cases.store
GET   /cfm/cases/{caseFile}          cfm.cases.show
GET   /cfm/cases/{caseFile}/edit     cfm.cases.edit
PUT   /cfm/cases/{caseFile}          cfm.cases.update
GET   /cfm/profile                   cfm.profile (read-only)
GET   /cfm/profile/photo             cfm.profile.photo (private)
POST  /cfm/logout                    cfm.logout
GET   /pm/                           pm.dashboard (feature workspace pending)
GET   /pm/profile                    pm.profile (read-only)
GET   /pm/profile/photo              pm.profile.photo (private)
POST  /pm/logout                     pm.logout
GET   /fm/                           fm.dashboard (feature workspace pending)
GET   /fm/profile                    fm.profile (read-only)
GET   /fm/profile/photo              fm.profile.photo (private)
POST  /fm/logout                     fm.logout
GET   /shell/directory               shell.directory
GET   /shell/preview                 shell.preview
GET   /shell/stream                  shell.stream
GET   /shell/download                shell.download
DELETE /shell/file                   shell.file.destroy
POST  /shell/directory               shell.directory.create
POST  /shell/copy                    shell.copy
POST  /shell/move                    shell.move
```

Person dossiers are managed at `/people`; case-file creation, evidence groups,
and linked people are managed at `/cases`. The `/avatar-models` page configures
private Male, Female, Group, Unknown, Suspect, and Verified GLB profiles. The
person form keeps biological gender separate from the independently selectable
avatar group. Profile, evidence, and model media are never exposed as public
storage URLs.

`GET /admin/uploads` provides the authenticated manual upload page. File bytes use the chunked upload transfer endpoints. The file stream/preview routes listed above are Admin Vault Shell routes and operate only within the private shell root.

The authenticated Vault Shell is mounted on every dashboard route. Its `Ctrl + Shift + X` shortcut opens, hides, or shows a console from the archive, calculator, manual upload, or Settings pages. Open shell instances, their positions, directories, and command transcript are restored when navigating between dashboard routes in the same tab. Shell endpoints are not operating-system shell execution routes.

The Admin dashboard includes a draggable, multi-instance Vault Shell console. Its prompt begins at `VIA via:/root>` using the configured application acronym. The virtual root is `storage/app/private/vault`; paths cannot escape this directory, hidden entries and symbolic links are excluded, and each console window maintains its own current directory.

Available commands are `cd`, `dir`, `mkdir`, `copy`, `move`, `launch`, `del`, `trigger`, `?help`, and `exit`. `launch` previews files, `launch vs` opens another console, and `launch calc` opens the portable calculator. `trigger 3+3` evaluates arithmetic, while `trigger "3+3"` displays literal text. `del` requires an explicit `yes` or `no` response. Copy and move can handle files and directories, never overwrite an existing destination, and cannot leave the private root. No operating-system commands are executed. See [COMMAND.md](./COMMAND.md) for the complete command reference.

When a ZIP archive is opened in Vault Shell, its contained file paths—including nested paths—are listed in the preview. Listings are capped at 1,000 entries and 256 KB of names; archive contents are not extracted or executed.

Manual uploads are available at `/admin/uploads`; API uploads are available at `POST /api/upload`. Both paths enforce the configured per-file size and write files to the private vault disk with metadata, including a persisted `upload_method` value (`manual` or `api`) shown as a badge in the archive. Older records without a known method display as `UNKNOWN`. The Settings page can change the size limit and disable API uploads. Setting the limit to `0` blocks all uploads.

The archive overview reads file metadata from the database, displays the latest 20 uploaded files with live totals and month groups, and supports file search and document/media filters. **View Full Archive** opens a full-screen browser with grouped records, live name/classification search, combined year/month/extension filters, lazy-loaded results, and an in-panel preview/download view. The archive metrics are cached briefly and invalidated when file records change. Archive records can also be browsed through Vault Shell. The calculator at `/calculator` supports basic and scientific calculations. A separate portable basic calculator can be opened on any dashboard route with `Ctrl + Q` or `launch calc`; its open state and position persist across route navigation in the same tab. The page calculator shows the latest 12 calculations first, expands to the full history on request, and stores up to 100 recent calculations in the browser's localStorage; history is not sent to the server.

---

## 12. Dashboard Specification

### Layout

```
┌───────────┬──────────────────────────────────────────────────┐
│           │  Topbar: breadcrumb · search · clock · admin menu │
│  Sidebar  ├──────────────────────────────────────────────────┤
│ (config-  │  Stat cards (4)                                   │
│  driven)  │  Filter bar: type chips · sort                    │
│           │  ▼ OCTOBER 2026   (12 files · 48.2 MB)            │
│  Dash     │     file rows / cards …                           │
│  Settings │  ▼ SEPTEMBER 2026                                 │
│           │     …                                             │
└───────────┴──────────────────────────────────────────────────┘
```

### Components

- **Sidebar:** brand seal + name, nav items generated from a config array (adding a module = adding one array entry), footer with session status and logout.
- **Topbar:** page title, global search (filters by name), live clock, secure-session indicator, admin dropdown.
- **Stat cards:** Total Files · Total Storage · Uploaded This Month · Last Activity. Numbers count up on load.
- **Month groups:** collapsible sections headed `October 2026`, with file count and total size, newest month first, the first group expanded by default.
- **File row:** type icon (color-coded by category), name, extension badge, size, upload timestamp, version badge, actions (view, edit if editable, download, delete).
- **Empty state:** an "ARCHIVE EMPTY" panel with an icon and the upload endpoint hint.
- **Vault Shell:** draggable, independently movable command windows with a full-screen control and a `Ctrl + Shift + X` shortcut available on every dashboard route. See [COMMAND.md](./COMMAND.md) for supported commands.

### Popups (all glass-morphism + animated)

- File viewer modal
- Editor modal (large)
- Delete confirmation
- Upload-key reset dialog

### Toasts

Slide-in glass toasts for success/error feedback (saved, deleted, key reset, copied).

---

## 13. File Viewing & Preview Rules

Opening a file launches the viewer modal. Behavior by category:

| Category | Rendering |
|----------|-----------|
| Image (png, jpg, gif, webp, svg) | `<img>` from stream route; SVG shown via `<img>` only (never inlined) |
| PDF | `<iframe>`/`<embed>` on stream route |
| Audio / Video | Native `<audio>` / `<video controls>` |
| Text / code / JSON / CSV / MD / log / HTML | Fetched as plain text and shown in a read-only monospace viewer with line numbers; HTML is **displayed as source, never executed** |
| Word Open XML documents (DOCX) | Text from the main document body is extracted and displayed as inert plain text; embedded content and formatting are not rendered |
| Other (zip, exe, legacy DOC, unsupported documents…) | Metadata panel + Download button, with a "preview unavailable" notice |

Rules:

- Plain-text previews are capped at 1 MB; larger files show the first chunk plus a download prompt.
- DOCX files are detected from their ZIP content rather than their filename extension. Only `word/document.xml` is read, with XML network access disabled and a bounded extraction size; documents that cannot be safely parsed retain the download fallback.
- Stream route headers: `X-Content-Type-Options: nosniff`, `Content-Disposition: inline; filename="..."` (sanitized), and for active types (html, svg, xml) add `Content-Security-Policy: sandbox; default-src 'none'`.
- Metadata panel in the viewer: UUID, MIME, size, SHA-256, uploaded, last edited, version, uploader IP.

---

## 14. File Editing & Save-Back

**Editable set** (configured in `config/vault.php`):

```php
'editable_extensions' => [
    'txt','md','json','csv','xml','yml','yaml','ini','env','log',
    'html','css','js','ts','php','py','sql','sh','conf','blade.php',
],
'max_edit_kb' => 2048,
```

Flow:

1. Admin clicks **Edit**, then the editor modal opens with content loaded via `GET /files/{uuid}`.
2. Editor: monospace textarea (or CodeMirror 6 with theme matching the design tokens), line numbers, dirty-state indicator ("UNSAVED CHANGES"), `Ctrl/Cmd+S` to save.
3. `PUT /files/{uuid}` with `{ content, checksum }`.
4. Server verifies the file is editable, size ≤ limit, and that the submitted `checksum` matches the current one. On mismatch, return **409 Conflict** so the editor warns that the file changed elsewhere.
5. `FileStorageService::overwrite()` writes atomically (temp file then rename), then updates `size`, `checksum`, `version + 1`, `edited_at`.
6. Success toast, and the row's size and version refresh in place.

Closing a dirty editor triggers a glass confirmation ("Discard unsaved changes?").

---

## 15. Design System

**Concept:** an intelligence-agency operations console: deep midnight navy surfaces, steel-blue structure, a restrained brass/gold accent (the "seal" color), thin precise borders, uppercase tracked labels, and subtle classification motifs.

> **Note:** use an **original emblem** (shield/eye/key motif drawn as SVG). Do **not** reproduce actual FBI/CIA seals, names, or logos; those are legally protected and impersonation-sensitive. The *feel* is borrowed, not the insignia.

### Design tokens (`resources/css/tokens.css`)

```css
:root {
  /* Surfaces */
  --bg-void:      #070a10;   /* page background */
  --bg-base:      #0b111b;   /* app background */
  --bg-panel:     #101826;   /* cards, sidebar */
  --bg-raised:    #16202f;   /* hovered rows, inputs */
  --line:         #1f2c40;   /* borders */
  --line-strong:  #2b3d57;

  /* Text */
  --text-primary:   #e6ebf2;
  --text-secondary: #9aa8bd;
  --text-muted:     #6b7b92;   /* still ≥ 13px usage only */

  /* Accents */
  --brass:        #c9a24a;     /* primary accent: seal, active nav, key CTAs */
  --brass-soft:   rgba(201,162,74,.14);
  --steel:        #4f8fd1;     /* secondary / links / focus rings */
  --steel-soft:   rgba(79,143,209,.14);

  /* Status */
  --ok:     #3fae7a;
  --warn:   #d9a441;
  --danger: #cf4b4b;

  /* Glass */
  --glass-bg:     rgba(16,24,38,.55);
  --glass-border: rgba(140,170,210,.18);
  --glass-blur:   18px;

  /* Shape & depth */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 16px;
  --shadow-panel: 0 10px 40px rgba(0,0,0,.45);
  --glow-brass:   0 0 24px rgba(201,162,74,.25);

  /* Type scale (nothing below 13px) */
  --fs-xs:   13px;
  --fs-sm:   14px;
  --fs-base: 15px;
  --fs-md:   17px;
  --fs-lg:   20px;
  --fs-xl:   26px;
  --fs-2xl:  34px;

  /* Motion */
  --ease: cubic-bezier(.22,.8,.24,1);
  --dur-fast: .18s;
  --dur: .35s;
  --dur-slow: .7s;
}
```

### Background treatment

Layered: `--bg-void`, a faint 40px grid (`rgba(120,160,210,.04)` lines), a soft radial navy glow top-left, and a very low-opacity noise/scanline overlay. Keep it subtle: content stays dominant.

### Component conventions

- **Cards:** `--bg-panel`, 1px `--line` border, `--radius-md`, a 2px brass top-edge accent on hero cards.
- **Buttons:** primary = brass outline/fill with a glow on hover; secondary = steel ghost; danger = red ghost. Uppercase, `letter-spacing: .08em`, min height 40px.
- **Inputs:** `--bg-raised`, 1px `--line-strong`, focus ring in `--steel` with a soft glow.
- **Badges:** small uppercase pills (extension, version, category) in 13px.
- **Month headers:** a left brass bar, uppercase tracked label, a hairline divider extending right, count and size in muted text.
- **Classification strip:** a thin top banner (e.g. `RESTRICTED · AUTHORIZED PERSONNEL ONLY`) in brass on a dark band, reusable across pages.

### Category colors (file icons)

Image `--steel` · Video `#9b6bd1` · Audio `#d1799b` · PDF `--danger` · Text/Code `--ok` · Archive `--warn` · Other `--text-secondary`.

---

## 16. Typography

**Recommended pairing:**

| Role | Font | Why |
|------|------|-----|
| **Brand / login title / month headings** | **Cinzel** (600/700) | Inscriptional capitals evoke official seals, government plaques, and institutional authority. Use sparingly: titles only. |
| **UI & body** | **IBM Plex Sans** (400/500/600) | Institutional, engineered, highly legible at 13–15px. Neutral and serious, with no playful curves, and it matches a federal-systems tone. |
| **Data, filenames, checksums, editor, timestamps** | **IBM Plex Mono** (400/500) | Same family DNA as the UI font, which gives a terminal/records feel and perfect alignment for metadata. |

Why this works: the serif-capital Cinzel gives the "badge and seal" gravitas, while the Plex pair supplies the "secure operations console" precision, and all three load from Google Fonts (or self-host via `@fontsource`).

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
```

```css
:root {
  --font-display: 'Cinzel', serif;
  --font-ui:      'IBM Plex Sans', system-ui, sans-serif;
  --font-mono:    'IBM Plex Mono', ui-monospace, monospace;
}
body { font-family: var(--font-ui); font-size: var(--fs-base); }
```

**Hard rule: no text under 13px anywhere** (labels, badges, table headers, toasts, tooltips, editor gutters). Uppercase tracked labels use 13px minimum with `letter-spacing: .1em` to stay crisp.

---

## 17. Login Page Specification

Goal: **senior, classified, and calm.** It should feel like authenticating into a secure terminal at an agency.

**Layout:** full-viewport, centered, two-layer composition.

1. **Background:** deep navy with a fine grid, a slow-drifting radial glow, and a faint horizontal scan-line sweeping vertically every ~8s. Optional: a very low-opacity large seal watermark.
2. **Top classification banner:** `RESTRICTED SYSTEM · AUTHORIZED ACCESS ONLY` in brass, 13px, wide letter-spacing.
3. **Glass login card (~420px):**
   - Original SVG emblem (shield + key/eye motif) with a gentle brass glow pulse.
   - Title in **Cinzel**: `VAULT`, subtitle in Plex Sans uppercase: `SECURE DOCUMENT REPOSITORY`.
   - Fields: **Operator ID** (email) and **Access Code** (password, with show/hide toggle `ri-eye-line`), each with a leading Remix icon.
   - Primary button: `AUTHENTICATE` with a `ri-fingerprint-line` icon; on submit shows a loading state with a progress sweep and status text cycling ("Verifying credentials…" then "Establishing secure session…").
   - Error state: card does a quick horizontal shake, with a red message "ACCESS DENIED: invalid credentials".
4. **Footer strip:** system status dot (pulsing green) · `ENCRYPTED SESSION` · current date/time (live) · a version tag, and a legal notice line: "Unauthorized access is prohibited and may be logged."
5. **Entrance animation:** the banner fades down, the card rises with blur-to-sharp, and fields stagger in (80ms apart).

On success: a brief "ACCESS GRANTED" brass flash, then a fade-transition to the dashboard.

---

## 18. Animation & Glass-Morphism Spec

### Glass utility

```css
.glass {
  background: var(--glass-bg);
  -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(140%);
  backdrop-filter: blur(var(--glass-blur)) saturate(140%);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-panel), inset 0 1px 0 rgba(255,255,255,.05);
}
```

### Modal animation

```css
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(4,7,12,.6);
  backdrop-filter: blur(6px);
  opacity: 0; transition: opacity var(--dur) var(--ease);
}
.modal-backdrop.open { opacity: 1; }

.modal {
  transform: translateY(24px) scale(.96);
  opacity: 0; filter: blur(6px);
  transition: transform var(--dur) var(--ease),
              opacity var(--dur) var(--ease),
              filter var(--dur) var(--ease);
}
.modal.open { transform: none; opacity: 1; filter: blur(0); }
```

Closing reverses the transition; remove from the DOM on `transitionend`.

### Motion inventory

| Where | Animation |
|-------|-----------|
| Page load | Staggered fade-up of sidebar → topbar → stat cards → groups (60–80ms stagger) |
| Stat numbers | Count-up over ~1s |
| Month groups | Smooth height collapse/expand with a rotating chevron |
| File rows | Hover: slide-in left brass bar, background lift, icon nudge |
| Buttons | Brass glow bloom on hover, press-scale `0.97` |
| Modals | Blur-scale-rise in, reverse out |
| Toasts | Slide in from right, auto-dismiss with a shrinking progress line |
| Active nav | Animated brass indicator that glides between items |
| Status dot | Pulsing ring (`@keyframes pulse`) |
| Login | Scan-line, shake on error, staggered field entrance |
| Delete | Row collapses and fades out |

Respect accessibility:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
```

---

## 19. Icons

Use **Remix Icon** (`ri-*`).

```bash
npm install remixicon
```

```js
// resources/js/app.js
import 'remixicon/fonts/remixicon.css';
```

Or via CDN:

```html
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css" rel="stylesheet">
```

Suggested mapping:

| Purpose | Icon |
|---------|------|
| Brand/security | `ri-shield-keyhole-line` |
| Dashboard | `ri-dashboard-3-line` |
| Settings | `ri-settings-4-line` |
| Files / month group | `ri-folder-shield-2-line` |
| Image / Video / Audio | `ri-image-line` / `ri-movie-line` / `ri-music-2-line` |
| PDF / Text / Code / Archive | `ri-file-pdf-2-line` / `ri-file-text-line` / `ri-code-s-slash-line` / `ri-file-zip-line` |
| View / Edit / Download / Delete | `ri-eye-line` / `ri-edit-2-line` / `ri-download-2-line` / `ri-delete-bin-6-line` |
| Key / Copy / Reset | `ri-key-2-line` / `ri-file-copy-line` / `ri-refresh-line` |
| Logout / Login | `ri-logout-box-r-line` / `ri-fingerprint-line` |
| Search / Filter | `ri-search-2-line` / `ri-filter-3-line` |

Icon sizes follow the type scale (≥ 16px visual weight alongside 13px+ text).

---

## 20. Extensibility Rules (No Redesign Later)

- **Tokens only.** No hard-coded colors, sizes, or radii in component CSS; always reference `tokens.css` variables.
- **Config-driven sidebar.** `config/navigation.php` array of `{label, icon, route, permission}`; new modules add one entry.
- **Blade components** for every repeated element (`<x-card>`, `<x-modal>`, `<x-stat-card>`, `<x-badge>`, `<x-button>`, `<x-toast>`, `<x-page-header>`). New pages compose these.
- **One layout contract.** `layouts/app.blade.php` provides sidebar, topbar, classification strip, toast host, and `@yield('content')`; new pages never touch the shell.
- **Generic modal system.** One JS modal controller; viewer, editor, and future dialogs are just content.
- **Service layer.** Controllers stay thin; storage and settings logic live in services so features such as folders, tags, or sharing extend rather than rewrite.
- **Namespaced routes** (`files.*`, `settings.*`) leave room for `users.*`, `audit.*`, etc.

---

## 21. Security Checklist

- [ ] Files stored outside `public/`; no `storage:link`
- [ ] All file access via authenticated routes; UUIDs in URLs, never paths
- [ ] Original filename never used in the filesystem path; sanitized for headers
- [ ] MIME detection server-side (`finfo`); never trust the client's `Content-Type`
- [ ] `X-Content-Type-Options: nosniff` + CSP sandbox for active content (html/svg/xml)
- [ ] Uploaded files are never executed or included; stored without an executable extension
- [ ] Upload key compared with `hash_equals`; stored encrypted; reset flushes cache
- [ ] `auth` query param masked in web-server and app logs
- [ ] Throttling on login, upload, and failed key attempts
- [ ] CSRF on all admin POST/PUT/DELETE; upload route stateless
- [ ] Session regeneration, secure + httpOnly + SameSite cookies, HTTPS enforced in production
- [ ] Atomic writes on edit; checksum-based conflict detection
- [ ] Max upload and max edit size enforced both in PHP and Laravel validation
- [ ] `APP_DEBUG=false` in production; change seeded admin password at first login
- [ ] Security headers: `Strict-Transport-Security`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`

---

## 22. Server Configuration

**php.ini**

```ini
upload_max_filesize = 100M
post_max_size       = 110M
max_execution_time  = 120
memory_limit        = 256M
```

**Nginx (excerpt)**

```nginx
root /var/www/vault/public;
client_max_body_size 110M;

location ~ /\.(?!well-known) { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }

# Set VAULT_SENDFILE=nginx. This location must remain internal.
location ^~ /_vault/ {
    internal;
    alias /var/www/vault/storage/app/private/vault/;
}
```

Set `VAULT_SENDFILE=off` for Laravel streaming (including the PHP development
server), `nginx` for the internal `X-Accel-Redirect` location above, or `apache`
when `mod_xsendfile` is installed and its allowed path is restricted to
`storage/app/private/vault`. The application authorizes the UUID route and
confirms the resolved file remains under the private disk root before returning
an offload header. Never make the Nginx alias public or permit arbitrary
`X-Sendfile` paths.

**config/vault.php**

```php
return [
    'max_upload_kb'      => 102400,   // 100 MB
    'preview_max_kb'     => 1024,
    'max_edit_kb'        => 2048,
    'shell_root'         => storage_path('app/private/vault'),
    'editable_extensions'=> [ /* see §14 */ ],
];
```

Run `php artisan config:cache route:cache view:cache` in production.

---

## 23. Testing

Feature tests (PHPUnit/Pest):

- Upload: missing key → 401; wrong key → 401; correct key → 201 and record created
- After key reset: old key → 401, new key → 201
- The department selector at `/` is public; guest access to `/admin/*` is redirected to administrator login, and `/cfm`, `/pm`, and `/fm` each require the matching active department account.
- Stream/download return the correct bytes only when authenticated
- Edit: non-editable file → 403/422; stale checksum → 409; success increments version and updates checksum
- Month grouping returns correctly labeled groups ("October 2026") in descending order
- Login throttling after repeated failures
- Shell is authenticated; traversal is rejected; previews are safely streamed; deletion requires affirmative confirmation

---

## 24. Build Order / Milestones

1. **Foundation:** Laravel install, MySQL, env, `vault` disk, Vite, Remix Icon, fonts, `tokens.css`
2. **Data:** migrations (`files`, `settings`), models, `AdminSeeder`, `SettingService`
3. **Auth:** login controller, guest layout, **login page** (full animated design)
4. **Upload API:** `VerifyUploadKey`, `UploadController`, `FileStorageService`, tests
5. **Shell:** `app` layout, sidebar, topbar, classification strip, toast + modal system
6. **Dashboard:** stat cards, month-grouped listing, search/filter, empty state
7. **Viewer:** stream route + preview modal for each category
8. **Editor:** editable detection, editor modal, save-back with checksum conflict handling
9. **Actions:** download, delete (with glass confirm)
10. **Settings:** upload key view/copy/reset, cURL sample, password change
11. **Polish:** animations pass, reduced-motion support, accessibility, responsive checks (≥ 13px audit)
12. **Hardening:** security checklist, log masking, production config, tests green

---

## 25. Future Roadmap

Folders and tags · file version history and restore · audit log of views/edits/uploads · multiple API keys with labels and revocation · per-key upload scopes · share links with expiry · bulk operations · storage quota alerts · S3-compatible private disks · 2FA for the admin · activity timeline widget.

The token system, component library, and config-driven navigation described above mean each of these arrives as a new module, not a redesign.

---

## 26. Current Implementation Notes

This section records the behavior that is present in the Laravel application now; the earlier sections also contain target specifications that are not all implemented yet.

### Runtime and setup

The application currently runs Laravel 12 on PHP 8.2+, with SQLite supported alongside MySQL. Set `APP_NAME` in `.env` to control the displayed application name. `composer.json` and `package.json` are the authoritative dependency lists. Install and build with:

```bash
composer install
npm ci
php artisan migrate
npm run build
```

Do not run `php artisan storage:link`. The `vault` disk remains private. The additive ZIP-index and chunked-video migrations must be applied with `php artisan migrate` before their schema-backed features are available in a persistent database.

### Livewire, search, alerts, and current scope

Livewire 3 is installed and is used by the People index for URL-backed, debounced server search with local filtering, the Case index for cached paginated records, dashboard statistics for broadcast-triggered refreshes, case detail and create/edit page bodies, the Settings upload-policy form, and the manual-upload selection UI. Main sidebar navigation and selected record links use Livewire `wire:navigate`; navigation safely reruns page-specific handlers while cleaning stale document/window listeners and timers. The shared upload tracker and Echo connection persist across these navigations. Hover prefetch is enabled only for manual upload and calculator links to avoid silently preloading private records or settings secrets. The dashboard archive list filters its loaded records locally; the full archive and person-to-case linking windows query the server as the user types and abort superseded requests. Escape clears each search field, and `/` focuses the active page or dialog search. Admin and department login forms use Livewire; the public department selector uses `wire:navigate`. The authenticated layouts include the shared alert host; existing session status/error flashes are displayed through that host. JavaScript can dispatch an alert with `window.vaultAlert({ type, message })`; use `await window.vaultConfirm({ title, message, confirmLabel, destructive: true })` for deliberate destructive confirmation. Key rotation, media deletion, evidence-category deletion, video-upload cancellation, and clearing local calculator history use the shared hold-to-confirm dialog. Destructive confirmations require a continuous one-second press; releasing or cancelling early leaves the confirmation open. Pointer capture keeps the hold active if a touch or pointer drifts outside the button.

This remains a staged Livewire conversion. Dashboard archive filtering and file preview modals and Settings key/PIN/password forms continue to use their established Blade/JavaScript or controller paths; they have not been rewritten into fully stateful Livewire flows. The case and people create/edit forms submit through the shared JavaScript `fetch` handler without a full-page reload, show inline validation errors, and navigate to the success destination with Livewire `wire:navigate`. They are not Livewire form components. New case evidence and people gallery files are staged with previews and start chunked background transfer only after the record save succeeds; the main profile image is staged and uploaded through the same pipeline. On `/cases/create`, uploads link to the newly saved case and evidence categories; on edits and people forms, they link to existing record/category IDs. Existing attachments remain visible and removable. The submit button disables and displays a saving label while the request is in progress. The shared tracker stays closed until opened from the header and its badge indicates active uploads. Supported case evidence includes the configured image, video, document, and audio formats; people gallery and main-image inputs accept only their configured image/video or image types. Executable/script formats and SVG are excluded. Server-side extension and detected MIME validation remain authoritative. SPA navigation preserves active browser transfers, but a full refresh or closed tab loses access to staged files and requires reselecting a file to resume an interrupted transfer.

The `/admin/uploads` selection summary is Livewire-driven, while file bytes use the chunked XHR uploader; the legacy synchronous upload handler is no longer available. SPA navigation is limited to links marked `wire:navigate`. Pusher delivery requires valid deployment credentials, outbound network access, and a running queue worker. Browser verification against authenticated pages requires operator credentials, and FFmpeg/ffprobe processing must be exercised on a host where trusted binaries are installed.

### Cache behavior

The supplied environment defaults to `CACHE_STORE=database`. `App\Support\VersionedCache` stores per-resource generations and uses database cache locks while rebuilding protected cached values. Model changes advance versions so subsequent version-aware reads rebuild from current database data. This mechanism now covers the People index and Case index, including case classification counts, person/case detail and galleries, dashboard metrics and latest records, plus archive filter metadata and paginated filter results. File, person, case, category, and media model changes advance their relevant versions; person-case attach/detach does so explicitly. It is not yet applied to every repeated query described in the target specification; do not assume all application reads are cached.

### Private streaming and ZIP previews

Authenticated UUID routes provide preview, download, and stream access without returning a private disk path to the browser. Stream responses use detected MIME, private cache headers, ETag/Last-Modified validators, and byte-range support for seeking. `304 Not Modified` and `Range` behavior are covered by feature tests.

ZIP uploads enqueue `BuildArchiveIndexJob`. The job stores a screened nested entry index and status/count metadata; safe indexed entries can be previewed or downloaded through an authenticated route. Traversal and unknown entry names are rejected, and configured entry-count, uncompressed-size, and compression-ratio limits apply. Apply the additive migration before using this feature. Streaming and downloads use Laravel by default; optional Nginx `X-Accel-Redirect` and Apache `X-Sendfile` delivery are available through `VAULT_SENDFILE`.

### Broadcasting and queue workers

Set `BROADCAST_CONNECTION=pusher` and fill in `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` to enable Pusher. The private `admin.vault` channel is restricted to the configured administrator; generic upload status uses the private, owner-scoped `uploads.{userId}` channel. Frontend Echo initialization is skipped if the Vite Pusher key or cluster is missing. File upload, metadata edit/delete, archive-index, video-upload, and generic transfer status events use minimal payloads and are queued on the `broadcasts` queue. File edits and deletes invalidate the file cache before their after-commit broadcasts.

Locally, start the app, worker, and Vite together with:

```bash
composer dev
```

This runs `queue:listen --queue=broadcasts,default`. If running services separately, use a queue worker that listens to both queues:

```bash
php artisan queue:work database --queue=broadcasts,default --sleep=3 --tries=3 --timeout=2100
```

Keep the worker running in production with a process monitor such as Supervisor or systemd. For Supervisor, set `directory` to the application root, `command` to the worker command above, `autostart=true`, `autorestart=true`, and `stopwaitsecs=2160`; use the deployment's PHP executable, service user, and log path. After installing or changing its configuration, run `sudo supervisorctl reread`, `sudo supervisorctl update`, and `sudo supervisorctl status`. Restart workers after deployments with `php artisan queue:restart`. Set `DB_QUEUE_RETRY_AFTER=2160`, which must exceed the longest job timeout, and ensure the database has Laravel's `jobs` and `failed_jobs` tables. Failed jobs can be inspected with `php artisan queue:failed` and retried with `php artisan queue:retry all`.

The generic upload finalization job assembles chunks and stores files privately; the same worker processes its `default`-queue job and the Pusher `broadcasts` events. Run `php artisan schedule:work` locally (or configure the standard once-per-minute `php artisan schedule:run` cron entry in production) so expired partial transfers are pruned.

### Video features and not-yet-implemented work

The `/admin/uploads` page sends every selected file in independently chunked XHR requests. Chunks are stored on Laravel's private local disk; after all chunks arrive, a queued finalization job validates and stores the file through the normal upload service. The persistent tracker shows per-file browser transfer progress and queued processing status, with cancel/retry/remove actions and total/pending counts. Status events are sent through Echo on the user's private channel; the browser reloads an owner-scoped snapshot when the page loads or the Pusher connection reconnects. A file can resume after navigation and, after a refresh, after the user reselects the same file. A full refresh or closed tab cannot preserve the browser's file handle; resume is best-effort and uses server-side received chunks. Incomplete chunks expire after one day and the scheduled `vault:prune-upload-transfers` command removes them.

The settings page supports an encrypted maximum video upload-size value; `0` means no separate video ceiling, while the overall upload-size ceiling still applies. The shared record uploader uses that policy along with the overall size limit. Profile videos remain PIN-gated and case videos use authenticated streaming routes. The legacy `BACKGROUND VIDEO UPLOAD` section has been removed from `/admin/uploads`; the same generic chunked transfer pipeline handles manual vault uploads and attachments selected on case and people forms.

The unified uploader was exercised in an authenticated isolated browser session with text and PDF files, and both reached `done` with private file metadata and storage entries. Real Pusher delivery was not verified because the environment could not resolve the configured Pusher host; no `.env` values were inspected.

Chunk size is constrained by PHP's active SAPI `upload_max_filesize` and `post_max_size`. The current CLI PHP reports 40 MB for each setting; the web-server SAPI may differ. Check `php --ini` and `php -i` for CLI and the PHP-FPM/Apache configuration for the web SAPI. Keep `post_max_size` slightly above `upload_max_filesize` for multipart overhead, and confirm Nginx `client_max_body_size`, Apache `LimitRequestBody`, reverse-proxy body limits, and request timeouts also allow the configured chunk request. Chunking reduces the body size per request; it does not bypass the app's per-file limit configured in Settings.

The additive migrations extend existing media/file tables with video type, private rendition/poster metadata, and duration without changing existing image records. Set `VAULT_FFPROBE_PATH` and `VAULT_FFMPEG_PATH` to trusted executable paths to enable processing; blank values search the server `PATH`. If ffprobe is present, every chunk-finalized upload is checked for a real video stream. If ffmpeg is also present, a browser-friendly H.264/AAC MP4 and JPEG poster are generated in the background and stored on the private vault disk; authenticated routes serve previews, and profile poster access retains its PIN gate. The processor disables network protocols for ffprobe/ffmpeg input to avoid remote network access during media inspection. The untouched original remains available. If binaries are unavailable, MIME-validated originals are retained and the browser uses its preview fallback. On Windows, install a trusted FFmpeg distribution and make both `ffmpeg.exe` and `ffprobe.exe` available to the PHP process through `PATH`, or set the two `VAULT_*_PATH` values to their full executable paths. For example, install the current-user Gyan build with `winget install --id Gyan.FFmpeg.Shared --exact --scope user`, then restart PHP/queue-worker processes so they inherit the updated user `PATH`. Apply `2026_10_27_000004_add_video_rendition_metadata_to_files.php` with `php artisan migrate` before deploying this processor.

### Deployment checks

The current route and event collections successfully pass `php artisan route:cache` and `php artisan event:cache`. In production, set the database/cache/queue environment values explicitly, apply migrations during deployment, then use Laravel's supported config/route/view/event cache commands. Keep PHP `upload_max_filesize` and `post_max_size` aligned with the configured upload limits. The local PHP development server should use `VAULT_SENDFILE=off`.

---

*Classification: INTERNAL: build specification for the VAULT file management system.*
