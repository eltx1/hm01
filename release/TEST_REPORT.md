# Test Report

The production workflow validates:

- PHP syntax for application, configuration, migrations, routes, scripts, and tests.
- Custom production static analysis and secret/file-layout checks.
- PHP unit and feature suites on PHP 8.2, 8.3, and 8.4.
- MySQL 8 fresh migrations, seeders, authorization, organization isolation, finance, GAM mocks, and reporting tests.
- Browser loader tests including paused sites, unauthorized hosts, disabled placements, Prebid timeout/failure fallback, native fallback, and global kill switch.
- Frontend dependency audit.
- Compiled Vite CSS/JavaScript, Horus Loader, custom Prebid build, and SHA-256 files.
- Release ZIP contents, absence of development secrets/files, production vendor, migration files, and compiled browser assets.

Final run IDs and exact counts are recorded in the pull request and GitHub Actions logs for the release commit.
