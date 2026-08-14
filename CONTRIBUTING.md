# Contributing to Biglins

Thanks for taking the time to contribute!

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
composer run dev
```

See the [README](README.md) for more details on local setup and Docker.

## Development workflow

1. Fork the repository and create a branch from `main` for your change.
2. Make your change, following the existing code conventions (check sibling files for structure and naming before adding something new).
3. Add or update tests for any behavior change.
4. Run the checks locally before opening a pull request:

   ```bash
   composer ci:check   # PHP lint, static analysis, tests
   npm run lint:check  # ESLint
   npm run format:check
   npm run types:check # TypeScript
   ```

5. Open a pull request describing what changed and why.

## Reporting bugs

Open an issue with steps to reproduce, expected vs. actual behavior, and relevant environment details (PHP/Node version, browser, Docker vs. local).

## Reporting security issues

Please do not open a public issue for security vulnerabilities — see [SECURITY.md](SECURITY.md) instead.

## Code of Conduct

This project follows a [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold it.
