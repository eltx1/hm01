# Test Report

## Final validation

The production-ready Horus Media release and its final consistency follow-up were validated on July 30, 2026 against pull requests #13 and #14.

- Final consistency test workflow run: `30569527073`.
- Final consistency production release workflow run: `30569527084`.
- PHP 8.2: 83 tests passed, 486 assertions.
- PHP 8.3: 83 tests passed, 486 assertions.
- PHP 8.4: 83 tests passed, 486 assertions.
- MySQL 8 fresh migrations, seeders, authorization, organization isolation, financial, GAM mock, reporting, production security, and bootstrap administrator policy tests: passed.
- Initial administrator weak-password rejection regression: passed.
- Horus Loader browser suite: 16 of 16 tests passed.
- Frontend dependency audit: passed.
- Vite CSS/JavaScript, Horus Loader 1.3.0, and custom browser-side Prebid build: passed.
- PHP syntax and production static analysis: passed.
- SQLite fresh migrations, route registration, scheduler registration, and complete PHP suite: passed.
- Production Composer install with development dependencies removed: passed.
- Release ZIP integrity, required-file, secret, and development-file validation: passed.

## Independently inspected validation artifact

- GitHub Actions artifact ID: `8770216506`.
- Artifact digest: `sha256:9ac7ef8f9f5e7c137cc8906d23a2291c712bb2cc5cbff70352afb3783f6c2f35`.
- Validation ZIP SHA-256 before this report refresh: `a74af79bb9eb215e03800903783d7075c81631f48bffb17a8ad6b1f651ba1a17`.
- ZIP entries inspected: 7,460.
- Populated secrets detected: 0.
- Forbidden development files or directories detected: 0.
- Missing required production files: 0.
- The packaged installation guide references `.env.example` and the protected `horus:create-super-admin` command.

The authoritative checksum for the final ZIP that includes this report is generated afterward in the adjacent `release/CHECKSUMS.txt`; embedding that checksum inside the ZIP itself would create a self-referential checksum.
