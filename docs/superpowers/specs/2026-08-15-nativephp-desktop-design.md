# NativePHP Desktop Support — Design

GitHub issue: [#12](https://github.com/simonecosci/biglins/issues/12)

## Problem

Biglins currently ships only as a web app (local PHP server or Docker).
Issue #12 asks for a desktop build using NativePHP so a freelancer/small
business can run Biglins as a native Windows/macOS/Linux app without
standing up a server.

## Goal

Add `nativephp/desktop` to the existing codebase so it can be packaged as a
standalone, single-user desktop app: its own local SQLite database per
installation, no server dependency, full Fortify auth kept as-is, running
side by side with the existing Docker/web deployment (neither replaces the
other).

## Non-goals

- Auto-update (NativePHP's updater). Documented as a future enhancement;
  v1 ships plain installers.
- Actually producing signed/notarized macOS and Linux installers from this
  (Windows) environment. macOS notarization requires Apple hardware/tooling
  we don't have here — code and build config are prepared for all three
  platforms, but only the Windows build is verified end-to-end in this
  change. Documented as a follow-up for whoever runs the macOS/Linux builds.
- A "connect to remote server" mode. Standalone-local only, per the
  approved design.
- Changing the Docker/web deployment in any way.

## Design

### Package & install

```bash
composer require nativephp/desktop
php artisan native:install
```

`native:install` publishes `config/nativephp.php`, generates
`App\Providers\NativeAppServiceProvider` (registered in
`bootstrap/providers.php`), and adds a `native:dev` script to
`composer.json`. Added as a regular (non-dev) dependency since
`native:build` packages the app's vendor directory into the distributable.

### Data storage

No manual wiring: NativePHP auto-detects it's running under Electron and
switches the app to SQLite, creating `{appdata}/database/database.sqlite`
in the OS-specific per-user app-data directory. On each version bump it
runs pending migrations against that file automatically. This is exactly
the "standalone local" model approved — existing migrations run unchanged.

File uploads (company logos in `public/images/companies`, quote
attachments) currently assume a web-server-writable `public/` disk. Add a
`nativephp` filesystem disk (or repoint the existing disk when running
under NativePHP) rooted at `Native\Laravel\Facades\Application::storagePath()`
so uploaded files survive app updates/reinstalls instead of living inside
the (replaceable) packaged app bundle. `CompanyController`/wherever logos
are written should resolve the disk via a small helper/config check rather
than hardcoding `public_path()`, so the web deployment's behavior is
unaffected.

### Auth

Unchanged. Fortify (password, 2FA, passkeys) stays exactly as it is today
in both the web and desktop builds — the desktop build just happens to be
single-user in practice, but the app doesn't special-case that.

### Queue & session

Desktop build's `.env` sets `QUEUE_CONNECTION=sync` (no background worker
process to manage inside the Electron shell) and keeps
`SESSION_DRIVER=database` / `CACHE_STORE=database`, which resolve against
the NativePHP-managed local SQLite file like everything else.

### Config

`config/nativephp.php` key values:

- `app_id`: reverse-domain identifier, e.g. `com.simonecosci.biglins`.
- `version`: kept in step with `composer.json`'s `"version"` manually (same
  release checklist already documented in `README.md`), since the desktop
  build's own updater is out of scope for v1 but the field still drives
  the packaged app's displayed version and migration-on-upgrade check.
- `author`/`copyright`/`description`/`website`: sourced from
  `composer.json` where a matching field exists.

### Environment separation

The desktop build needs its own `.env` (e.g. `.env.native`, not committed)
with `QUEUE_CONNECTION=sync` and no Docker-only keys (`SSL_MODE`, `RUN_MIGRATIONS`,
etc. are simply unused/ignored under NativePHP, not removed from
`.env.example`). `composer.json`'s existing Docker/web scripts (`dev`,
`setup`) are untouched; a new `native:dev` script is added by the
installer for local desktop development.

### Build & distribution (v1 scope)

`php artisan native:build {win|mac|linux}` produces the platform
installer. This change:

- Wires up config/service provider for all three platforms.
- Actually runs and verifies the Windows build in this environment.
- Documents the `mac`/`linux` build commands and their extra requirements
  (Apple ID/team ID + notarization for macOS, Wine for cross-building
  Windows from Linux) in `README.md`, without executing them here.

### Documentation

Add a "Desktop app (NativePHP)" section to `README.md`: install/build
commands, the standalone-local data model, and the platform build caveats
above.

## Testing

Existing Pest suite is unaffected — it exercises the Laravel app itself,
independent of NativePHP. Add/adjust coverage where the change touches
app code:

- A test (or adjustment to existing upload tests) confirming the
  file-storage helper picks the right disk (existing `public` disk under
  web, the NativePHP-storage-path disk when the NativePHP context flag is
  set), so the web deployment's upload behavior has an explicit regression
  guard.

Manual verification (desktop-specific, not automatable in Pest):

1. `php artisan native:install` completes cleanly on top of the existing
   app; `composer ci:check` still passes (nothing in the Laravel app
   itself breaks).
2. `php artisan native:run` boots the app in dev mode: login/2FA/passkeys
   flow works, an invoice can be created, a company logo can be uploaded
   and persists, a PDF can be generated.
3. `php artisan native:build win` produces an installer; installing and
   launching it on a clean Windows machine (or a fresh user profile) shows
   Biglins running standalone with its own local SQLite database and no
   server required.
