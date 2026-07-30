# Production Test Report

Release date: `__RELEASE_DATE__`  
Source commit: `__GITHUB_SHA__`  
GitHub Actions run: `__GITHUB_RUN_ID__`

## Required release gates

- PHP syntax validation across application, configuration, migrations, routes, scripts, and tests.
- Custom production static analysis and fixed-architecture assertions.
- PHPUnit unit tests.
- Feature, authentication, authorization, organization-isolation, GAM, demand, direct-campaign, financial and production-security tests.
- PHP 8.2, 8.3 and 8.4 test matrix.
- SQLite fresh migration/seed and full suite.
- MySQL 8 fresh migration/seed and full suite.
- Browser Horus Loader tests.
- Prebid browser mock/fallback/timeout tests.
- Frontend dependency audit.
- Vite production build, Loader minification and pinned Prebid build validation.
- Optimized `composer install --no-dev` production dependency build.
- Exact ZIP content validation, secret/path scan, production autoloader test and checksum verification.

## Local pre-commit evidence

- PHP lint: passed.
- Horus Loader and browser-side Prebid/native suite: 16 tests passed, 0 failed.
- Static architecture/security checks: passed before upload.

## CI evidence

The GitHub Actions run above is the source of truth for complete PHP/MySQL/build/release validation. The release must not be merged when any required job is failed, cancelled, or skipped unexpectedly.
