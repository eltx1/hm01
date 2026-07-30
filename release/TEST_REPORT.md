# Test Report

## Validated release

The production-ready Horus Media release was validated on July 30, 2026 against pull request #13.

- Main test workflow run: `30568264602`.
- Production release workflow run: `30568264428`.
- PHP 8.2: 82 tests passed, 481 assertions.
- PHP 8.3: 82 tests passed, 481 assertions.
- PHP 8.4: 82 tests passed, 481 assertions.
- MySQL 8 fresh migrations, seeders, authorization, organization isolation, financial, GAM mock, reporting, and production security tests: passed.
- Horus Loader browser suite: 16 of 16 tests passed.
- Frontend dependency audit: passed.
- Vite CSS/JavaScript, Horus Loader 1.3.0, and custom browser-side Prebid build: passed.
- PHP syntax and production static analysis: passed.
- SQLite fresh migrations, route registration, scheduler registration, and complete PHP suite: passed.
- Production Composer install with development dependencies removed: passed.
- Release ZIP integrity, required-file, secret, and development-file validation: passed.

## Clean artifact

- GitHub Actions artifact ID: `8769734260`.
- Artifact digest: `sha256:92e4e1993c2102efa47039a8b123eef6e6930a6d98371ffe96c9218c96dc4873`.
- Production ZIP SHA-256: `e2eb9cab77b9141acbcc7ca1095f7fef510e990e6b1a3e49607a6695fcb4c23e`.
- ZIP entries inspected: 7,460.
- Populated secrets detected: 0.
- Forbidden development files or directories detected: 0.
- Missing required production files: 0.
