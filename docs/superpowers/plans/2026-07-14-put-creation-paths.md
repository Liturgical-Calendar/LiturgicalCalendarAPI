# RFC 9110-Aligned Creation Paths Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans
> to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move resource creation to `PUT /{collection}/{id}` (path id authoritative, body id must match, 409 on conflict) for `/tests` and
`/data`, move the `/missals` route shape, document it in OpenAPI and README, per issue #706.

**Architecture:** Hard cutover modeled on the existing `DecreesHandler` pattern (`PUT /decrees/{decree_id}`). Router arity changes let the
existing path-based FGA middleware cover creates; a new payload-based scope fallback in `TestScopeResolver` handles the tests create flow
where the file does not exist yet.

**Tech Stack:** PHP 8.4, PSR-7/15, PHPUnit, OpenFGA (mocked in unit tests), OpenAPI 3.1 (`jsondata/schemas/openapi.json`).

**Spec:** `docs/superpowers/specs/2026-07-14-put-creation-paths-design.md`

## Global Constraints

- Work in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI/.claude/worktrees/feat-put-creation-paths`,
  branch `feat/put-creation-paths`. Never commit in the main checkout.
- PHPStan level 10 (`composer analyse`), PSR-12 (`composer lint`), markdownlint (`composer lint:md`), OpenAPI lint (`composer lint:openapi`).
- Never use `--no-verify`. CaptainHook pre-commit runs linting.
- All `phpunit` commands run from the worktree root: `vendor/bin/phpunit phpunit_tests/<path> --testdox`.
- `Routes/*` tests hit the server configured in `.env`/`.env.local`; port 8000 runs a STALE docker image. See Task 8 for how to run them
  against worktree code on port 8001. Handler/unit tests need no server.
- Status-code conventions: 201 create, 409 create-conflict (`ConflictException` from `LiturgicalCalendar\Api\Http\Exception`), 422
  path/body id mismatch (`UnprocessableContentException` for tests/data, matching each handler's existing PATCH behavior), 400
  `ValidationException` for path-shape errors.
- Legacy-shape responses after the cutover: `PUT /tests` and `PUT /missals` → 405 (those handlers validate the request method first);
  `PUT /data/{category}` → **400** with the 'Expected two path params' message, because `RegionalDataHandler` validates the path before
  the method — do not reorder that handler's validation to force a 405 (it would change documented GET/POST arity errors).

---

### Task 1: TestScopeResolver — `isSafeName()` + `resolveFromPayload()`

**Files:**

- Modify: `src/Services/TestScopeResolver.php`
- Test: `phpunit_tests/Services/TestScopeResolverTest.php`

**Interfaces:**

- Produces: `TestScopeResolver::isSafeName(string $testName): bool` (public static);
  `TestScopeResolver::resolveFromPayload(mixed $decoded): ?array` returning `array{0: string, 1: string}|null`.
  Task 2's middleware closure and Task 4's handler consume both.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Services/TestScopeResolverTest.php` (inside the class; `$this->fixturesDir` already exists):

```php
    public function testResolveFromPayloadNational(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['national_calendar_test', 'NL'],
            $r->resolveFromPayload(['applies_to' => ['national_calendar' => 'NL']])
        );
    }

    public function testResolveFromPayloadDiocesan(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['diocesan_calendar_test', 'romamo_it'],
            $r->resolveFromPayload(['applies_to' => ['diocesan_calendar' => 'romamo_it']])
        );
    }

    public function testResolveFromPayloadDefaultsToGeneralRoman(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertSame(
            ['general_roman_calendar_test', 'general_roman_calendar'],
            $r->resolveFromPayload(['name' => 'SomeTest'])
        );
    }

    public function testResolveFromPayloadReturnsNullForNonArray(): void
    {
        $r = new TestScopeResolver($this->fixturesDir);
        $this->assertNull($r->resolveFromPayload(null));
        $this->assertNull($r->resolveFromPayload('not-json'));
    }

    public function testIsSafeName(): void
    {
        $this->assertTrue(TestScopeResolver::isSafeName('Foo_Test-1'));
        $this->assertFalse(TestScopeResolver::isSafeName('..'));
        $this->assertFalse(TestScopeResolver::isSafeName('a/b'));
        $this->assertFalse(TestScopeResolver::isSafeName(''));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/TestScopeResolverTest.php --testdox`
Expected: FAIL — `Call to undefined method ... resolveFromPayload()` / `isSafeName()`.

- [ ] **Step 3: Implement**

In `src/Services/TestScopeResolver.php`, add both methods and refactor `resolve()` to use `isSafeName()` in place of its inline
`preg_match` (keep the existing comment about path traversal on `isSafeName()`):

```php
    /**
     * True when the given name is safe to use as a bare file-stem.
     *
     * Only letters, digits, hyphens, and underscores are allowed. This rejects
     * '..', '/', '\', null bytes, spaces, and every other special character.
     */
    public static function isSafeName(string $testName): bool
    {
        return (bool) preg_match('/\A[A-Za-z0-9_-]+\z/', $testName);
    }

    /**
     * Resolve the FGA scope pair for a test that does not yet exist on disk,
     * using the decoded request payload's `applies_to` key (create flow).
     *
     * Mirrors the mapping applied by resolve() to the stored file, so the scope
     * that authorizes the create is the same scope the created file will have.
     *
     * @param mixed $decoded The json_decode'd (assoc mode) request body
     * @return array{0: string, 1: string}|null
     */
    public function resolveFromPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $appliesTo = $decoded['applies_to'] ?? null;

        if (is_array($appliesTo) && isset($appliesTo['diocesan_calendar']) && is_string($appliesTo['diocesan_calendar'])) {
            return ['diocesan_calendar_test', $appliesTo['diocesan_calendar']];
        }

        if (is_array($appliesTo) && isset($appliesTo['national_calendar']) && is_string($appliesTo['national_calendar'])) {
            return ['national_calendar_test', $appliesTo['national_calendar']];
        }

        return ['general_roman_calendar_test', 'general_roman_calendar'];
    }
```

In `resolve()`, replace:

```php
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $testName)) {
            return null;
        }
```

with:

```php
        if (!self::isSafeName($testName)) {
            return null;
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Services/TestScopeResolverTest.php --testdox`
Expected: PASS (all, including the pre-existing resolve() tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/TestScopeResolver.php phpunit_tests/Services/TestScopeResolverTest.php
git commit -m "feat(auth): payload-based test scope resolution for create flows"
```

---

### Task 2: forTestScopes — payload fallback on PUT create

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` (`forTestScopes()`, lines ~230-262)
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`

**Interfaces:**

- Consumes: `TestScopeResolver::isSafeName()`, `TestScopeResolver::resolveFromPayload()` from Task 1.
- Produces: unchanged middleware factory signature `forTestScopes(OpenFgaClient $client, TestScopeResolver $resolver): self`;
  new behavior: on PUT with a missing test file, the FGA object is derived from the request body's `applies_to`.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php` (model: `testForTestScopesFactoryPutMapsToEditor` at line ~434;
`$this->tempPaths`, `$this->nextHandler` and imports already exist in the file):

```php
    public function testForTestScopesPutCreateResolvesScopeFromPayload(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->once())
            ->method('check')
            ->with('user:user-123', 'editor', 'national_calendar_test:NL')
            ->willReturn(true);

        // Empty temp dir: the test file does NOT exist (create flow).
        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $body    = (string) json_encode(['applies_to' => ['national_calendar' => 'NL']]);
        $request = ( new ServerRequest('PUT', '/tests/BrandNewTest', [], $body) )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

        $response = $middleware->process($request, $this->nextHandler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testForTestScopesPutCreateUnparseableBodyIsForbidden(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        $request = ( new ServerRequest('PUT', '/tests/BrandNewTest', [], 'not-json') )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }

    public function testForTestScopesPatchMissingFileStillFailsClosed(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('check');

        $tempDir = sys_get_temp_dir() . '/fga_test_' . uniqid();
        mkdir($tempDir);
        $this->tempPaths[] = $tempDir;
        $scopeResolver     = new TestScopeResolver($tempDir);

        $middleware = OpenFgaAuthorizationMiddleware::forTestScopes($client, $scopeResolver);

        // PATCH must NOT fall back to the payload: the resource must already exist.
        $body    = (string) json_encode(['applies_to' => ['national_calendar' => 'NL']]);
        $request = ( new ServerRequest('PATCH', '/tests/BrandNewTest', [], $body) )
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'roles' => ['test_editor']])
            ->withAttribute('test_id', 'BrandNewTest');

        $this->expectException(ForbiddenException::class);
        $middleware->process($request, $this->nextHandler);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php --testdox`
Expected: the first new test FAILS with `ForbiddenException: Cannot resolve authorization scope for this request` (no fallback yet);
the other two already pass (they pin current fail-closed behavior).

- [ ] **Step 3: Implement**

In `forTestScopes()` replace the `$objectResolver` closure with:

```php
        $objectResolver = static function (ServerRequestInterface $request) use ($resolver): ?array {
            $testId = $request->getAttribute('test_id');
            if (!is_string($testId) || trim($testId) === '') {
                return null;
            }
            $resolved = $resolver->resolve($testId);
            if (
                $resolved === null
                && strtoupper($request->getMethod()) === 'PUT'
                && TestScopeResolver::isSafeName($testId)
            ) {
                // Create flow: the test file does not exist yet, so derive the scope
                // from the payload's `applies_to` — the same value the handler will
                // persist, so the scope that authorizes the create is the scope the
                // created resource will carry.
                $body = (string) $request->getBody();
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
                $resolved = $resolver->resolveFromPayload(json_decode($body, true));
            }
            return $resolved;
        };
```

Update the `forTestScopes()` docblock: the sentence "If the attribute is absent or the resolver returns null the request is denied
(fail-closed)." becomes "If the attribute is absent or the scope cannot be resolved the request is denied (fail-closed). For PUT
(create), when no file exists yet, the scope is resolved from the request payload's `applies_to`."

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php --testdox`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/OpenFgaAuthorizationMiddleware.php phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php
git commit -m "feat(auth): resolve tests create scope from payload applies_to on PUT"
```

---

### Task 3: Router — creation moves to per-item paths

**Files:**

- Modify: `src/Router.php` (route cases: `missals` ~201-234, `data` ~459-495, `tests` ~496-523; `configureAuthorizationPipeline()`
  `missals` branch ~731-744)
- Test: `phpunit_tests/Http/RouterPipelineTest.php` (`testMissalsWithoutIdRequiresAdminAndSkipsFga` ~344-372)

**Interfaces:**

- Consumes: nothing new.
- Produces: route arities — `PUT` accepted at `/tests/{name}`, `/data/{category}/{key}`, `/missals/{missal_id}` (1/2/1 segments) and no
  longer at `/tests`, `/data/{category}`, `/missals`. Tasks 4-5 handlers rely on these arities; the existing count>=1 (tests) and
  count>=2 (data) FGA branches in `configureAuthorizationPipeline()` now cover PUT unchanged.

- [ ] **Step 1: Update the failing pipeline test first**

In `phpunit_tests/Http/RouterPipelineTest.php`, replace the whole method `testMissalsWithoutIdRequiresAdminAndSkipsFga` with:

```php
    public function testMissalsWithoutIdUsesCalendarEditorAndSkipsFga(): void
    {
        $router   = $this->routerWithoutConstructor();
        $pipeline = $this->emptyPipeline();

        // Collection-level writes are no longer routed (PUT moved to /missals/{missal_id});
        // an id-less write is still role-gated but only ever reaches the handler's 405.
        $this->callConfigurePipeline($router, $pipeline, 'missals', []);

        $queue = $this->getQueue($pipeline);

        $authMw = null;
        foreach ($queue as $mw) {
            if ($mw instanceof AuthorizationMiddleware) {
                $authMw = $mw;
                break;
            }
        }
        self::assertNotNull($authMw, 'Expected an AuthorizationMiddleware for an id-less missals write');
        self::assertSame('calendar_editor', $this->getPrivateProp($authMw, 'requiredRole'));

        foreach ($queue as $mw) {
            self::assertNotInstanceOf(
                OpenFgaAuthorizationMiddleware::class,
                $mw,
                'No fine-grained FGA middleware should be added when the missal id is absent'
            );
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Http/RouterPipelineTest.php --testdox`
Expected: FAIL — `requiredRole` is `admin`, not `calendar_editor`.

- [ ] **Step 3: Implement the Router changes**

3a. `tests` case (~line 496): move PUT from the 0-segment list to the 1-segment list:

```php
            case 'tests':
                $testsHandler = new TestsHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $testsHandler->setAllowedRequestMethods([]);
                }
```

3b. `data` case (~line 463): PUT moves from the 1-segment arm to the 2-segment arm; the 1-segment arms collapse into `default`:

```php
                $allowedMethods      = match (true) {
                    $pathCount === 2 && $firstInCategory => [
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ],
                    $pathCount === 3 && $firstInCategory => [
                        RequestMethod::GET,
                        RequestMethod::POST
                    ],
                    default => []
                };
```

3c. `missals` case (~line 203): move PUT from the 0-segment list to the 1-segment list (write paths remain 405 stubs in the handler):

```php
                if (count($requestPathParts) === 0) {
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $missalsHandler->setAllowedRequestMethods([]);
                }
```

3d. `configureAuthorizationPipeline()` `missals` branch: replace the whole `elseif ($route === 'missals') { ... }` block with:

```php
        } elseif ($route === 'missals') {
            // Writes are authorized per-missal: calendar_editor role plus fine-grained FGA
            // (Editio Typica -> general_roman_calendar, national missal -> national_calendar).
            // Collection-level writes are no longer routed (PUT moved to /missals/{missal_id}),
            // so an id-less write only ever reaches the handler's 405 Method Not Allowed.
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 1) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forMissals($fgaClient, $requestPathParts[0]));
            }
        }
```

The `data` and `tests` authorization branches are intentionally untouched: their existing count>=2 / count>=1 conditions now cover PUT
because the id is in the path.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Http/RouterPipelineTest.php --testdox`
Expected: PASS (all, including `testTestsRouteWithoutPathPartSkipsFga`, which pins the unchanged count-0 config).

- [ ] **Step 5: Commit**

```bash
git add src/Router.php phpunit_tests/Http/RouterPipelineTest.php
git commit -m "feat(router): route resource creation at PUT /{collection}/{id} (#706)"
```

---

### Task 4: TestsHandler — path-based create, 409 on conflict

**Files:**

- Modify: `src/Handlers/TestsHandler.php` (`handlePutRequest()` ~243-283; class `use` imports)
- Test: `phpunit_tests/Handlers/TestsHandlerTest.php`

**Interfaces:**

- Consumes: `TestScopeResolver::isSafeName()` (Task 1); `ConflictException` from
  `LiturgicalCalendar\Api\Http\Exception\ConflictException` (same import as `DecreesHandler`).
- Produces: `PUT /tests/{test_name}` — 201 create; 400 `ValidationException` on wrong arity or unsafe name; 422
  `UnprocessableContentException` on body/path name mismatch; 409 `ConflictException` when the test exists.

- [ ] **Step 1: Write the failing tests**

In `phpunit_tests/Handlers/TestsHandlerTest.php` add the import `use LiturgicalCalendar\Api\Http\Exception\ConflictException;`
(alongside the existing exception imports) and `use LiturgicalCalendar\Api\Enum\JsonData;` if not already present. Update the existing
`testPutWithMalformedPayloadIsValidationError` to the new shape (URI and constructor arg):

```php
        $req = $this->requestFor('PUT', '/tests/SomeTest', [], '[1, 2, 3]')
            ->withHeader('Content-Type', 'application/json');
        ( new TestsHandler(['SomeTest']) )->handle($req);
```

Then append the new tests:

```php
    public function testPutCreatesTestAtPathName(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
            true
        );
        $payload['name'] = 'ZzzPutCreatedTest';

        $this->testFixturePath = JsonData::TESTS_FOLDER->path() . '/ZzzPutCreatedTest.json';

        $response = ( new TestsHandler(['ZzzPutCreatedTest']) )->handle(
            $this->requestFor('PUT', '/tests/ZzzPutCreatedTest', [], $payload)
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertFileExists($this->testFixturePath);
    }

    public function testPutWithNoPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler() )->handle(
            $this->requestFor('PUT', '/tests', [], ['name' => 'SomeTest'])
        );
    }

    public function testPutExistingTestConflicts(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
            true
        );

        $this->expectException(ConflictException::class);
        ( new TestsHandler(['MaryMotherChurchTest']) )->handle(
            $this->requestFor('PUT', '/tests/MaryMotherChurchTest', [], $payload)
        );
    }

    public function testPutBodyNameMismatchIsRejected(): void
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode(
            (string) file_get_contents(JsonData::TESTS_FOLDER->path() . '/MaryMotherChurchTest.json'),
            true
        );
        // Body says MaryMotherChurchTest, path says ZzzOtherTest.

        $this->expectException(UnprocessableContentException::class);
        ( new TestsHandler(['ZzzOtherTest']) )->handle(
            $this->requestFor('PUT', '/tests/ZzzOtherTest', [], $payload)
        );
    }

    public function testPutUnsafePathNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['..']) )->handle(
            $this->requestFor('PUT', '/tests/..', [], ['name' => '..'])
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php --testdox`
Expected: the new tests FAIL — current handler throws `ValidationException` ("Expected no path params") for path-based PUT.
`testPutWithNoPathParamsIsValidationError` may pass already only if payload parsing rejects first; expect failures for the others.

- [ ] **Step 3: Implement**

In `src/Handlers/TestsHandler.php` add imports:

```php
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
```

Replace `handlePutRequest()` (including its docblock) with:

```php
    /**
     * Handles PUT requests for creating a specific test at /tests/{test_name}.
     *
     * The test name in the request path is authoritative; the payload's `name`
     * must match it. The payload is validated against the LitCalTest JSON schema
     * (422 on failure). If a test with the same name already exists a 409 Conflict
     * is returned. On success the test is written to disk and a 201 Created
     * response is returned.
     */
    private function handlePutRequest(ResponseInterface $response): ResponseInterface
    {
        if (count($this->requestPathParams) !== 1) {
            $description = 'Expected one and only one path param for PUT requests, received ' . count($this->requestPathParams) . '.';
            throw new ValidationException($description);
        }

        $testName = $this->requestPathParams[0];
        if (false === TestScopeResolver::isSafeName($testName)) {
            $description = 'The Unit Test name in the request path may only contain letters, digits, hyphens and underscores.';
            throw new ValidationException($description);
        }

        $this->validatePayloadAgainstTestSchema('create');
        self::sanitizeObjectValues($this->payload);

        if (false === property_exists($this->payload, 'name') || false === is_string($this->payload->name)) {
            $description = 'The Unit Test you are attempting to create must have a valid name.';
            throw new UnprocessableContentException($description);
        }

        if ($this->payload->name !== $testName) {
            $description = 'You are attempting to create the Unit Test at /tests/' . $testName . ' with a Unit Test that has the name '
                . $this->payload->name . ' in the request body. This is not allowed.';
            throw new UnprocessableContentException($description);
        }

        $testFilePath = JsonData::TESTS_FOLDER->path() . '/' . $testName . '.json';
        if (file_exists($testFilePath)) {
            $description = 'A Unit Test with the name ' . $testName . ' already exists. Did you perhaps mean to use a PATCH request?';
            throw new ConflictException($description);
        }

        $jsonEncodedTest = JsonFormatter::encode($this->payload, false);
        $bytesWritten    = file_put_contents($testFilePath, $jsonEncodedTest);
        if (false === $bytesWritten) {
            $description = 'The server did not succeed in writing to disk the Unit Test. Please try again later or contact the service administrator for support.';
            throw new ServiceUnavailableException($description);
        }

        $responseBody = (object) ['response' => 'Unit Test ' . $testName . ' created successfully.'];
        return $this->encodeResponseBody($response, $responseBody, StatusCode::CREATED);
    }
```

Also fix the copy-pasted docblock on `handlePatchRequest()` (it currently says "Handles PUT requests ... expects no path parameters"):
first line becomes "Handles PATCH requests for updating a specific test at /tests/{test_name}."

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TestsHandlerTest.php --testdox`
Expected: PASS (all).

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/TestsHandler.php phpunit_tests/Handlers/TestsHandlerTest.php
git commit -m "feat(tests): create tests at PUT /tests/{test_name} with 409 on conflict (#706)"
```

---

### Task 5: RegionalDataHandler — PUT key from path

**Files:**

- Modify: `src/Handlers/RegionalDataHandler.php` (`validateRequestPath()` ~1307-1347; `handle()` ~1548-1721)
- Test: `phpunit_tests/Handlers/RegionalDataHandlerTest.php`
- Test: `phpunit_tests/Routes/ReadWrite/RegionalDataTest.php`

**Interfaces:**

- Consumes: Router arity from Task 3 (`PUT /data/{category}/{key}`).
- Produces: PUT requires exactly 2 path params; `$params['key']` comes from `requestPathParams[1]` for ALL of GET/POST/PUT/PATCH/DELETE;
  payload-derived key must equal the path key for both PUT and PATCH (422 otherwise). Conflict/creation status codes unchanged
  (409 `ResourceConflictException` / 201).

- [ ] **Step 1: Write/update the failing handler tests**

In `phpunit_tests/Handlers/RegionalDataHandlerTest.php`:

Update `testPutWithoutPayloadIsValidationError` (~line 174) to the 2-param shape:

```php
    public function testPutWithoutPayloadIsValidationError(): void
    {
        // PUT requires exactly 2 path params (category + key). Passing the
        // request without a body trips the empty-payload check in
        // parseBodyPayload → ValidationException.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation', 'ZZ']) )
            ->handle($this->requestFor('PUT', '/data/nation/ZZ', ['Content-Type' => 'application/json'], ''));
    }
```

Update `testCreateNationalCalendarRejectsNonIsoNationCode` (~line 184): change only the last statement to the 2-param shape:

```php
        ( new RegionalDataHandler(['nation', 'ZZ']) )->handle($this->requestFor('PUT', '/data/nation/ZZ', [], $payload));
```

Append two new tests (reuse the `$payload` array literal from `testCreateNationalCalendarRejectsNonIsoNationCode` verbatim where
indicated):

```php
    public function testPutWithSinglePathParamIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('PUT', '/data/nation', ['Content-Type' => 'application/json'], ''));
    }

    public function testPutPathBodyKeyMismatchIsUnprocessable(): void
    {
        // Same payload shape as testCreateNationalCalendarRejectsNonIsoNationCode
        // (metadata.nation = 'ZZ'), but PUT to /data/nation/XK: path/body key mismatch
        // must be rejected before any create-condition checks (including the ISO gate).
        $payload = [
            'litcal'   => [
                [
                    'liturgical_event' => ['event_key' => 'StGeorgeMartyr', 'grade' => 4],
                    'metadata'         => ['action' => 'makePatron', 'since_year' => 1868, 'url' => 'https://www.vatican.va/'],
                ],
            ],
            'settings' => [
                'epiphany'               => 'JAN6',
                'ascension'              => 'SUNDAY',
                'corpus_christi'         => 'SUNDAY',
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => false,
                    'Ascension'            => false,
                    'CorpusChristi'        => false,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => false,
                    'StsPeterPaulAp'       => false,
                    'AllSaints'            => false,
                ],
            ],
            'metadata' => [
                'nation'  => 'ZZ',
                'missals' => ['IT_1983'],
                'locales' => ['en'],
            ],
            'i18n'     => [
                'en' => ['StGeorgeMartyr' => 'Saint George, Martyr'],
            ],
        ];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('The key in the request path does not match the key in the payload');
        ( new RegionalDataHandler(['nation', 'XK']) )->handle($this->requestFor('PUT', '/data/nation/XK', [], $payload));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php --testdox`
Expected: the updated/new PUT tests FAIL (current code: 'Expected one path param for PUT requests, received 2').

- [ ] **Step 3: Implement**

3a. `validateRequestPath()`: merge PUT into the PATCH/DELETE branch. Replace the `case RequestMethod::PUT: ... break;` and
`case RequestMethod::PATCH: ... case RequestMethod::DELETE: ... break;` blocks with:

```php
            case RequestMethod::PUT:
                // no break (intentional fallthrough)
            case RequestMethod::PATCH:
                // no break (intentional fallthrough)
            case RequestMethod::DELETE:
                if (count($this->requestPathParams) !== 2) {
                    $description = 'Expected two path params for PUT, PATCH and DELETE requests, received ' . count($this->requestPathParams);
                    throw new ValidationException($description);
                }
                break;
```

3b. In `handle()`, the key now always comes from the path. Replace:

```php
        if (in_array($method, [RequestMethod::GET, RequestMethod::POST, RequestMethod::PATCH, RequestMethod::DELETE], true)) {
            $params['key'] = $this->requestPathParams[1];
        }
```

with:

```php
        if (in_array($method, [RequestMethod::GET, RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE], true)) {
            $params['key'] = $this->requestPathParams[1];
        }
```

3c. Still in `handle()`, replace the PUT/PATCH key handling:

```php
            if ($method === RequestMethod::PUT) {
                $params['key'] = $key;
            } else {
                if ($params['key'] !== $key) {
                    throw new UnprocessableContentException('The key in the request path does not match the key in the payload');
                }
            }
```

with:

```php
            if ($params['key'] !== $key) {
                throw new UnprocessableContentException('The key in the request path does not match the key in the payload');
            }
```

3d. Update the comment above `$this->validateRequestPath($request);` that reads "We expect the key to be set in the request path for
GET, POST, PATCH and DELETE requests" to "We expect the key to be set in the request path for all request methods".

- [ ] **Step 4: Run handler tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php --testdox`
Expected: PASS (all).

- [ ] **Step 5: Update the route-level tests (verified in Task 8 / CI)**

In `phpunit_tests/Routes/ReadWrite/RegionalDataTest.php`:

- `testPutOrPatchWithoutContentTypeHeaderReturnsError` (~176): `self::$http->put('/data/nation', [])` → `self::$http->put('/data/nation/IT', [])`.
- `testPutDataExistingCalendarReturnsError` (~212): `'/data/nation'` → `'/data/nation/CA'` (matches `self::$existingBody`, which is
  Canada's calendar — check the `$existingBody` fixture at the top of the file and use its `metadata.nation` value).
- `testAuthenticatedPutDataExistingCalendarReturns409` (~245): `'/data/nation'` → `'/data/nation/CA'` (same fixture-key note).
- `testAuthenticatedPutPatchWithoutContentTypeReturns415` (~294): `'/data/nation'` → `'/data/nation/CA'`.
- `testAuthenticatedWriteOperationsWithoutPathParametersReturnValidationErrors` (~321): comment "(expects one path param)" →
  "(expects two path params)".
- `validatePutNoPathParametersErrorResponse` (~390): assert the new message
  `'Expected two path params for PUT, PATCH and DELETE requests, received 0'`.
- `validatePatchDeleteNoPathParametersErrorResponse`: update its asserted message to the same new merged wording.
- Add to `testGetOrPostOrPatchOrDeleteWithoutKeyParameterInPathReturnsError` (~97) two entries exercising the retired create shape,
  `[ 'uri' => '/data/nation/', 'method' => 'PUT' ]` and `[ 'uri' => '/data/diocese/', 'method' => 'PUT' ]`, and confirm the expected
  status code logic in that test treats PUT like PATCH/DELETE (401 unauthenticated).

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/RegionalDataHandler.php phpunit_tests/Handlers/RegionalDataHandlerTest.php phpunit_tests/Routes/ReadWrite/RegionalDataTest.php
git commit -m "feat(data): create calendars at PUT /data/{category}/{key} (#706)"
```

---

### Task 6: OpenAPI schema

**Files:**

- Modify: `jsondata/schemas/openapi.json`

**Interfaces:**

- Consumes: final route shapes from Tasks 3-5.
- Produces: documented `put` operations under `/data/nation/{key}` (~4019), `/data/diocese/{key}` (~4220), `/data/widerregion/{key}`
  (~4419), `/tests/{test_name}` (~5328); path items `/data/nation` (~3794), `/data/widerregion` (~3869), `/data/diocese` (~3944) removed;
  `put` removed from `/tests` (~5249).

- [ ] **Step 1: Move the three `/data/{category}` put operations**

For each of `/data/nation`, `/data/widerregion`, `/data/diocese`: cut the entire `"put": { ... }` operation object and paste it as a new
`"put"` member of the corresponding `/{...}/{key}` path item (after `"get"`). Then DELETE the now-empty `/data/nation`,
`/data/widerregion`, `/data/diocese` path items entirely. In each moved operation:

- Add a `parameters` array as the first member after `operationId`, copying the `key` parameter object verbatim from the sibling `get`
  operation in the same path item (name/schema/description identical).
- Update the `summary` (e.g. "Create a national calendar data resource" stays) and `description`: append the sentence
  "The `{key}` in the path is authoritative; the corresponding identifier in the request body must match it."
- Keep `operationId` values (`nationalCalendarDataPUT`, `widerregionDataPUT`, `diocesanDataPUT`) and all response entries (409 and 422
  are already documented for the data PUTs; no response changes needed).

- [ ] **Step 2: Move the `/tests` put operation**

Cut the `"put": { ... }` from the `/tests` path item (~5249-5326) and add it to `/tests/{test_name}` (~5328, after `"get"`):

- Add a `parameters` array copying the `test_name` parameter from the sibling `get` (~5338-5347).
- Rename `operationId` from `testsIndexPUT` to `createTestByNamePUT` (consistent with `retrieveTestByNameGET`).
- Change `summary` to "Create a new unit test at its own URI in the API `tests` folder".
- Fix the `201` response description (it currently claims overwrites return 201): "201 Created. The unit test did not previously exist
  and was created at the request URI."
- Add a `409` response: `"409": { "$ref": "#/components/responses/Conflict409" }` (alphabetical position between 403 and 415).

- [ ] **Step 3: Lint and validate**

Run: `composer lint:openapi`
Expected: no errors (warnings pre-existing are acceptable if already present on development).
Also run: `vendor/bin/phpunit phpunit_tests/Schemas/ --testdox` — schema-corpus tests must still pass (use
`vendor/bin/phpunit --group slow phpunit_tests/Schemas/SchemaValidationTest.php` if the default run skips it).

- [ ] **Step 4: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): document creation at PUT /{collection}/{id} (#706)"
```

---

### Task 7: README RFC 9110 note

**Files:**

- Modify: `README.md` (the "Some characteristics of this API" bullet list, ~line 69)

- [ ] **Step 1: Add the bullet**

Append as a third top-level bullet after the "**The data is historically accurate**" bullet:

```markdown
* **HTTP method semantics follow [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110)**:
  `PUT` is create-or-replace at the resource's own URI (idempotent, returning `409 Conflict` when attempting to create a resource that already exists),
  and `PATCH`/`DELETE` likewise address resources by path (e.g. `PUT /data/nation/IT`, `PATCH /tests/MaryMotherChurchTest`).
  One deliberate exception: on read endpoints this API uses `POST` as a body-parameterized synonym of `GET` (not as collection-create),
  pending possible adoption of the [`QUERY` method](https://datatracker.ietf.org/doc/draft-ietf-httpbis-safe-method-w-body/) when it becomes standard.
```

- [ ] **Step 2: Lint**

Run: `composer lint:md`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(readme): note RFC 9110 method semantics (#706)"
```

---

### Task 8: Full verification gate + PR

**Files:** none new (verification + PR only).

- [ ] **Step 1: Static analysis + style**

Run: `composer analyse && composer lint && composer parallel-lint`
Expected: no errors. Fix anything reported before proceeding.

- [ ] **Step 2: Unit + handler suite (no server)**

Run: `composer test:quick`
Expected: `Routes/*` tests hit port 8000 (STALE docker image) — the RegionalData ReadWrite tests updated in Task 5 will FAIL against it.
That is expected; verify them in Step 3 instead. Everything else must pass.

- [ ] **Step 3: Route tests against worktree code on port 8001**

```bash
sed -i 's/^API_PORT=.*/API_PORT=8001/' .env.local
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8001 -t public &> /dev/null &
SERVER_PID=$!
vendor/bin/phpunit phpunit_tests/Routes/ReadWrite/RegionalDataTest.php --testdox
kill $SERVER_PID
git checkout -- .env.local 2>/dev/null || sed -i 's/^API_PORT=.*/API_PORT=8000/' .env.local
```

Expected: PASS (auth-dependent tests skip if the local DB/Zitadel stack is down — that's acceptable; CI covers them).
Note: `.env.local` is gitignored, so `git checkout` won't restore it — use the `sed` fallback to revert the port.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin feat/put-creation-paths
gh pr create --repo Liturgical-Calendar/LiturgicalCalendarAPI --base development \
  --title "feat: align resource creation paths to PUT /{collection}/{id}" \
  --body "$(cat <<'EOF'
## Summary

- `PUT /tests/{test_name}`, `PUT /data/{category}/{key}`, and (route shape only) `PUT /missals/{missal_id}` replace the
  id-in-body collection-level PUT creates, per RFC 9110 create-or-replace semantics.
- Path id is authoritative; body id must match (422). Creating over an existing resource returns 409 Conflict
  (tests endpoint changed from 422 to 409).
- OpenFGA fine-grained authorization now covers creates (the id is in the path); tests creates resolve their FGA scope
  from the payload's `applies_to`, the same value the created file will carry.
- OpenAPI schema updated; README documents the RFC 9110 method semantics.

Design: docs/superpowers/specs/2026-07-14-put-creation-paths-design.md

Coordinated frontend PR (merge after this): Liturgical-Calendar/LiturgicalCalendarFrontend (admin-tests.js, extending.js).

Closes #706

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR opens against `development`.

---

### Task 9: Frontend PR (LiturgicalCalendarFrontend repo)

**Files** (in `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarFrontend`, own branch `feat/put-creation-paths`
off `development` — use a worktree there too):

- Modify: `assets/js/admin-tests.js` (~line 781)
- Modify: `assets/js/extending.js` (`API.path` proxy branches ~504-513, ~526-530, ~569-572, ~587-593)
- Verify only: `assets/js/missals-editor.js` (~730-744)

**Interfaces:**

- Consumes: the new API shapes from Tasks 3-5. Merge this PR only AFTER the API PR is merged (frontend e2e builds `litcal-api`
  from `development`).

- [ ] **Step 1: admin-tests.js**

Line ~781, in the save flow (`state.editing` is null on create; the payload carries the new name):

```javascript
// before
await fetchJson('PUT', '/tests', payload);
// after
await fetchJson('PUT', `/tests/${encodeURIComponent(payload.name)}`, payload);
```

Confirm in place that the create branch's payload variable is named `payload` and its name property is `payload.name`;
the 409 handling branch (`err.status === 409`) already exists at ~787.

- [ ] **Step 2: extending.js**

The `API.path` proxy deliberately omits the key from PUT URLs in four `case` branches. In each, make PUT build the same
`${RegionalDataUrl}/${category}/${key}` URL the other verbs use. Representative shape (apply the same edit to all four branches —
`path`, `category`, `key`, `method`):

```javascript
// before
if (API.method === 'PUT') {
    return `${RegionalDataUrl}/${API.category}`;
}
return `${RegionalDataUrl}/${API.category}/${API.key}`;
// after
return `${RegionalDataUrl}/${API.category}/${API.key}`;
```

Read each branch before editing — the four sites are near lines 504-513, 526-530, 569-572, 587-593 and differ slightly in variable
naming; the invariant is: PUT no longer gets a key-less URL. Verify `API.key` is always populated before a PUT is issued
(it is what the PATCH/DELETE flows already rely on).

- [ ] **Step 3: missals-editor.js — verify only**

Confirm the PUT at ~line 734 already targets a per-file endpoint (`apiUrl = BaseUrl + '/' + encodePathSegments(endpoint)`) and keeps
its 405-stub handling. No change expected.

- [ ] **Step 4: Verify end-to-end**

With the API worktree server running on 8001 (Task 8 Step 3 setup) and the frontend configured against it
(`.env.development`: `API_PORT=8001`), exercise: create a test in admin-tests UI, create/patch a calendar in extending.php.
Then restore `.env.development`.

- [ ] **Step 5: Commit and PR**

```bash
git add assets/js/admin-tests.js assets/js/extending.js
git commit -m "feat: use PUT /{collection}/{id} creation paths (API #706)"
git push -u origin feat/put-creation-paths
gh pr create --repo Liturgical-Calendar/LiturgicalCalendarFrontend --base development \
  --title "feat: use PUT /{collection}/{id} creation paths" \
  --body "Companion to Liturgical-Calendar/LiturgicalCalendarAPI PUT-creation-paths PR (issue API#706). Merge after the API PR.

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Expected: PR opens; its e2e runs against the freshly-merged API `development` code.
