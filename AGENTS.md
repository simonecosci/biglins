# Agent instructions

Guidance for any coding agent (human or AI) working in this repository. If you are Claude Code, also read [CLAUDE.md](CLAUDE.md) for Laravel Boost-specific tooling.

## Stack

PHP 8.5, Laravel 13, Inertia.js v3 + Vue 3 (TypeScript), Tailwind CSS v4, Laravel Fortify, Laravel Wayfinder, Pest 5. See [README.md](README.md) for the full list and local/Docker setup.

## Conventions

- Follow existing code conventions — check sibling files for structure, naming, and approach before writing new code.
- Use descriptive names (`isRegisteredForDiscounts`, not `discount()`).
- Reuse existing components/classes before creating new ones.
- Don't change dependencies (`composer.json`, `package.json`) or create new top-level directories without approval.
- Only create documentation files when explicitly requested.
- Model primary keys are UUIDs (`HasUuids`) except `User`, which uses an auto-increment id.

### PHP

- Curly braces for all control structures, even one-liners.
- Constructor property promotion; no empty `__construct()`.
- Explicit return types and parameter type hints everywhere.
- TitleCase enum keys (`FavoritePerson`, not `favorite_person`).
- PHPDoc over inline comments; array shapes documented in PHPDoc.

### Frontend

- Vue components have a single root element.
- Import backend routes/controllers from `@/routes` and `@/actions` (Wayfinder-generated — don't hardcode URLs).
- Tailwind v4 utility classes; no ad-hoc CSS unless necessary.

## Testing

Every change must be covered by a test (new or updated) — run it before considering the change done.

```bash
php artisan test --compact --filter=SomeTest   # run only what's relevant
composer ci:check                              # full local CI: lint, format, types, tests
```

Use Pest syntax (`test()`/`it()`/`expect()`) and model factories, not manual model instantiation. Don't delete tests without approval.

## Before finishing a PHP change

```bash
vendor/bin/pint --dirty --format agent
```

## Don't

- Don't add speculative abstractions, error handling for scenarios that can't occur, or feature flags for things that could just be changed directly.
- Don't run tinker or write throwaway verification scripts when a test already proves the behavior.
- Don't touch `config/company.php` casually — it holds the invoicing entity's real data and is intentionally gitignored.
