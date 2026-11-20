<p align="center">
  <img src="website/assets/icons/favicon.svg" width="112" alt="Glimpse logo">
</p>

<h1 align="center">Glimpse</h1>

<p align="center">
  <b>Share a private GitHub or GitLab repository with a single read-only link.</b><br>
  Visitors browse the code in their browser, with no account and no access to anything you did not choose to show.
</p>

<p align="center">
  <a href="https://github.com/finitysoftware/glimpse/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/finitysoftware/glimpse/ci.yml?branch=master&label=CI" alt="CI status"></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white" alt="PHP 8.5">
  <img src="https://img.shields.io/badge/MariaDB%20%7C%20MySQL-supported-003545?logo=mariadb&logoColor=white" alt="MariaDB or MySQL">
  <img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black" alt="React 19">
  <img src="https://img.shields.io/badge/TypeScript-6-3178C6?logo=typescript&logoColor=white" alt="TypeScript 6">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-2EA44F" alt="MIT license"></a>
</p>

<p align="center">
  <a href="https://glimpse.finitysoftware.io">Website</a> |
  <a href="https://github.com/finitysoftware/glimpse/releases">Releases</a> |
  <a href="docs/manual/README.md">User manual</a> |
  <a href="#installation">Installation</a> |
  <a href="#development">Development</a>
</p>

---

Glimpse lets you show code from a private repository without adding anyone as a collaborator. Connect your GitHub or
GitLab token, choose what a link may show and send it. Nothing is cloned: Glimpse reads the content through the
GitHub and GitLab APIs and caches it briefly in its database. It is developed by Finity Software.

## Features

| Area | What you get |
|---|---|
| **Connections** | A GitHub token, a GitLab.com or self-managed GitLab connection, or both, per user |
| **Repository browser** | Folder tree, file search, syntax highlighting and commit history with diffs |
| **File visibility** | Per branch, share the whole branch or only the files you select |
| **Access links** | Branches, files, history and expiry per link, with instant revocation |
| **Visitor view** | Read-only code and history in the browser, without signing in |
| **Accounts** | Username and password sign-in, with accounts created in the admin panel or from the CLI |
| **Admin panel** | Platform overview, users, their connections and links |
| **Landing page** | A static, SEO-ready page in English and Serbo-Croatian |

## User manual

The [user manual](docs/manual/README.md) walks through every part of Glimpse with screenshots: signing in, connecting
GitHub and GitLab, browsing repositories and history, choosing file visibility, creating access links, what visitors
see, and the admin panel.

## Requirements

- PHP 8.5 with `curl`, `mbstring`, `openssl` and `pdo_mysql` (`php backend/bin/preflight.php` checks them)
- MariaDB or MySQL
- Apache with `mod_rewrite` and HTTPS
- For development: Node.js 24, pnpm 11 and Composer 2

## Installation

Glimpse runs on a regular PHP web host. One domain serves the landing page, the workspace, the admin panel, visitor
links and the API.

1. **Get a package.** Download `glimpse-<version>.zip` from the
   [Releases](https://github.com/finitysoftware/glimpse/releases) page, or build one yourself (see
   [Deployment](#deployment)). `glimpse-<version>.zip.sha256` next to it lets you check the download.
2. **Upload it.** Extract the zip into the document root of your domain, so that `.htaccess`, `public/` and
   `private/` sit directly inside it. Check that `https://<your-domain>/private/` is denied.
3. **Configure it.** Copy `private/backend/config/settings.example.php` to `settings.local.php` in the same folder,
   then set at least `origin` (for example `https://glimpse.example.com`) and the database credentials.
4. **Create the keys, the database and the first admin** over SSH, in `private/backend`:

   ```bash
   php bin/generate-share-key.php
   php bin/generate-github-token-key.php
   php bin/migrate.php
   read -rs PASSWORD
   printf '%s' "$PASSWORD" | php bin/users.php create admin --platform-admin
   unset PASSWORD
   ```

   The password is read from standard input, never from an argument. Keep the generated `*.local.php` keys: without
   them, existing links and saved tokens stop working. On some hosts `php` in SSH is an older version; use the
   host's PHP 8.5 binary instead.
5. **Sign in** at `https://<your-domain>/login` and add a token under **Connections**:
   - **GitHub:** a fine-grained token for the repositories you want to share, with read-only *Metadata* and
     *Contents* permissions.
   - **GitLab:** GitLab.com or a self-managed instance, with a token that has the `read_api` scope.

Run `php bin/maintenance.php` from cron, for example hourly. It removes expired cache entries, sessions and rate
limits.

**Upgrading.** Extract the new package over the existing files, then run `php bin/migrate.php` if the release notes
list new migrations (`php bin/migrate.php --status` shows the state). Never delete `private/backend/config`: the
settings and keys there are not part of the package.

## Configuration

All settings live in `private/backend/config/settings.local.php`. Each one can also be set with a `GLIMPSE_…`
environment variable.

| Key | Meaning |
|---|---|
| `environment` | `production` or `development` |
| `origin` | Public address of the application |
| `workspace_origins` | Other addresses of the same installation; defaults to `origin` only |
| `share_public_origin` | Address used in new access links; defaults to `origin` |
| `share_alias_origins` | Old hosts that keep opening existing links but never serve the workspace |
| `database` | `dsn`, `username` and `password` |
| `cache_global_max_bytes` | Total preview cache for all users; each user is also limited to 32 MiB |
| `gitlab_default_url`, `gitlab_allowed_hosts` | Suggested GitLab address and the GitLab hosts users may connect to |

The CLI tools in `backend/bin`:

| Command | Purpose |
|---|---|
| `users.php create\|reset-password\|disable\|enable\|grant-admin\|revoke-admin\|list` | Manage accounts; passwords always come from standard input |
| `migrate.php [--status]` | Run or inspect database migrations |
| `maintenance.php` | Remove expired cache entries, sessions and rate limits (for cron) |
| `maintenance-mode.php on\|off\|status` | Take the API offline during an upgrade |
| `preflight.php` | Check the PHP version and extensions |

## Development

Install the dependencies:

```bash
pnpm install --frozen-lockfile
composer install --working-dir=backend
```

Copy `backend/config/settings.example.php` to `backend/config/settings.local.php` (git ignores it) and set:

```php
'environment' => 'development',
'origin' => 'http://127.0.0.1:5173',
'database' => ['dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=glimpse;charset=utf8mb4', 'username' => '...', 'password' => '...'],
```

Create the keys, the database and the first admin as in installation step 4, using `backend/bin/...` from the
repository root. Then start the API and the frontend in two terminals:

```bash
php -S 127.0.0.1:8080 -t backend/public backend/bin/serve.php
```

```bash
pnpm --dir frontend run dev
```

Open `http://127.0.0.1:5173/login`. Vite forwards `/api` to the PHP server.

## Running the tests

| What | Command |
|---|---|
| Frontend: format, lint, types and unit tests | `pnpm --dir frontend run format:check`, `run lint`, `run typecheck`, `test` |
| Backend: syntax, format, PHPStan, layers and unit tests | In `backend/`: `composer lint`, `composer format:check`, `composer analyse`, `composer deptrac`, `composer test` |
| Backend with a database | In `backend/`: `composer test:integration` |
| Browser (Playwright) | `pnpm --dir tests/e2e exec playwright install chromium`, then `pnpm --dir tests/e2e test` |

`pnpm --dir frontend run format` and `composer format` (in `backend/`) fix the formatting.

The integration and browser tests need a separate `glimpse_test` database, which they drop and fill again:

```bash
export GLIMPSE_TEST_DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=glimpse_test;charset=utf8mb4"
export GLIMPSE_TEST_DB_USER=root GLIMPSE_TEST_DB_PASSWORD=... GLIMPSE_TEST_DB_RESET=1
```

If PHP is not on `PATH`, set `PHP_BIN` for Playwright. GitHub Actions runs the same checks on every push
([`ci.yml`](.github/workflows/ci.yml)).

## Deployment

**Building a package locally** (Windows PowerShell). The script builds the frontend, assembles and checks the
package, and writes `artifacts/glimpse-package.tar.gz`. It does not upload anything.

```powershell
.\scripts\build-package.ps1 -Domain 'glimpse.example.com'
```

`-Domain` must match the canonical URL of the landing page in `website/index.html`, `robots.txt` and `sitemap.xml`.

**Releases.** Every push to `master` builds and tests a production package in **Verify Glimpse**. Once it passes,
push a version tag made of three single digits to publish that package as a GitHub Release:

```bash
git tag v1.0.0
```

```bash
git push origin v1.0.0
```

The release holds `glimpse-<version>.zip` (the contents of the document root), its SHA-256 and notes that list the
changes and any new migrations. Create releases by pushing a tag, not through the GitHub release form.

**Deploying over FTPS** (optional, started by hand). **Actions → Deploy over FTPS → Run workflow** publishes the
package that Verify Glimpse tested for the latest push to `master`, or for a commit you enter within the 7 days
GitHub keeps the package. Set it up once:

1. Create an FTP account limited to the Glimpse folder on your host.
2. In **Settings → Environments → `production`**, add the secrets `FTP_HOST`, `FTP_USERNAME` and `FTP_PASSWORD`.
   Optionally add required reviewers, so that every deployment waits for approval.
3. Optional variables: `FTP_REMOTE_DIR` if the Glimpse folder is not the root of the FTP account, and
   `FTP_TLS_VERIFY=no` if the host's certificate does not match its address.

The workflow turns maintenance mode on, uploads the files (the pages last) and turns it off again. It never uploads
or deletes `private/backend/config/*.local.php` or `private/logs/`. If the release adds migrations, maintenance
stays on: run `php bin/migrate.php`, then `php bin/maintenance-mode.php off` over SSH.

## Project structure

| Folder | What it holds |
|---|---|
| `backend` | The PHP API (Slim 4) under `/api`, database migrations and CLI tools |
| `frontend` | The React app: sign-in, workspace, admin panel and visitor view |
| `website` | The static landing page on `/`, with no build step |
| `deploy` | `.htaccess` rules for routing, protecting `private/` and security headers |
| `scripts` | Building, checking and uploading the package |
| `tests/e2e` | Playwright browser tests |
| `docs/manual` | The user manual and its screenshots |

| Path | What is there |
|---|---|
| `/` | Landing page |
| `/login`, `/app/*` | User workspace |
| `/admin/*` | Admin panel |
| `/s/<id>#<secret>` | Visitor link; the secret stays in the `#` part and is never sent to the server |
| `/api/*` | Backend |

The backend follows Action-Domain-Responder: one action class per route in `Http/Actions/`, services that return
small read-only results, and responders that turn them into JSON. Layers depend only on the layers below them;
`backend/deptrac.yaml` defines them and CI enforces it. The frontend is split into features (`auth`, `workspace`,
`repositories`, `sharing` and `admin`), and ESLint enforces the import rules between them.

## Contributing

Bug reports and pull requests are welcome. For larger changes, please open an issue first so the approach can be agreed
on before any work starts.

To contribute, fork this repository on GitHub, make your changes in the fork and open a pull request. Please make sure
the checks in [Running the tests](#running-the-tests) pass.

## License

Glimpse is released under the [MIT License](LICENSE).

<p align="center">
  Made by <a href="https://finitysoftware.io">Finity Software</a>
</p>
