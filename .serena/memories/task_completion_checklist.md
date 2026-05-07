# When a Coding Task Is Complete — LiturgicalCalendarAPI

Run before declaring done / committing:

1. **Syntax check (parallel)**

   ```bash
   composer parallel-lint
   ```

2. **Code style (PSR-12)**

   ```bash
   composer lint            # phpcs
   composer lint:fix        # phpcbf for auto-fixable issues
   ```

3. **Static analysis (PHPStan level 10)**

   ```bash
   composer analyse
   ```

4. **Tests**

   ```bash
   composer test            # full suite
   composer test:quick      # excludes @group slow (faster local iteration)
   ```

5. **Markdown (if any .md changed)** — pre-commit will auto-run, but you can:

   ```bash
   composer lint:md
   composer lint:md:fix
   ```

6. **OpenAPI (if `jsondata/schemas/openapi.json` changed)**

   ```bash
   composer lint:openapi
   ```

7. **Manual smoke test** — start the API and exercise affected route:

   ```bash
   composer start
   curl 'http://localhost:8000/calendar?year=2025'
   composer stop
   ```

8. **For changes touching auth flow** — verify both cookie and `Authorization: Bearer` paths still work; double-check `APP_ENV` fail-closed behavior is intact.

## Pre-commit (CaptainHook)

- Installed via `vendor/bin/captainhook install --force`
- Auto-runs `composer lint:md` on staged `.md` files
- Don't bypass with `--no-verify` unless explicitly authorized

## Branch / PR rules

- Branch off `development`, never `stable`
- Target `development` in PR; release flow is `development` → `stable` after community testing
