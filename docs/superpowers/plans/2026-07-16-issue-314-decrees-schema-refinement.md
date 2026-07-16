# Issue #314 — Per-Action Decrees Schema Refinement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the lax generic decree shape in `LitCalDecreesSource.json` with five strict per-action definitions
(`createNew` fixed/mobile, `setProperty` grade/name, `makeDoctor`), and propagate the same per-action `oneOf` structure
to `LitCalDecreeWritePayload.json` and `LitCalDecreesPath.json`.

**Architecture:** Draft-07 JSON Schemas validated by `swaggest/json-schema ~0.12` (no `unevaluatedProperties`, so each
variant lists its full property set with `$ref`s to shared definitions — same pattern as `NationalCalendar.json`).
The source schema keeps a `LitCalDecree` definition (now a `oneOf` of the five variants) so the three existing
`openapi.json` `$ref`s to it keep resolving. Approved spec:
`docs/superpowers/specs/2026-07-15-issue-314-decrees-source-schema-design.md`.

**Tech Stack:** JSON Schema draft-07, PHP 8.4, PHPUnit, swaggest/json-schema.

## Global Constraints

- **Working directory:** the git worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-wt-314`
  on branch `feature/314-decrees-schema-refinement`. Every shell command below runs from that directory. Verify with
  `git rev-parse --show-toplevel` before the first command of each task — it MUST print the worktree path, never
  `.../LiturgicalCalendarAPI`.
- **Never bypass git hooks.** No `--no-verify`. If a hook fails, fix the issue. Commits are GPG-signed; if signing
  fails with `gpg: signing failed: Timeout`, STOP and ask the user to unlock the key (do not disable signing).
- **JSON Schema draft-07 only** — the validator is `swaggest/json-schema ~0.12`.
- **4-space indentation in JSON schema files** (matches existing files).
- Commit messages end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- `composer analyse` covers `src/` only (no `src/` changes in this plan); PHPUnit test code must pass
  `composer lint` (PSR-12 / phpcs, which does scan `phpunit_tests/`).
- Route-level tests (`phpunit_tests/Routes/*`) talk to `localhost:8000`, which locally is a stale Docker build —
  they skip themselves (405 → skip, or connection refusal → skip). Do not chase their skips locally.

## Definition-name map (used across tasks)

`LitCalDecreesSource.json` definitions produced in Task 1 and consumed by Tasks 2–3:

| Definition                                                                                                                                                              | Purpose                                                                   |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------|
| `LitCalDecree`                                                                                                                                                          | `oneOf` of the five decree variants (name retained for openapi.json refs) |
| `DecreeCreateNewFixed`                                                                                                                                                  | full decree object, action `createNew`, fixed date                        |
| `DecreeCreateNewMobile`                                                                                                                                                 | full decree object, action `createNew`, mobile                            |
| `DecreeSetPropertyGrade`                                                                                                                                                | full decree object, `setProperty`/`grade`                                 |
| `DecreeSetPropertyName`                                                                                                                                                 | full decree object, `setProperty`/`name`                                  |
| `DecreeMakeDoctor`                                                                                                                                                      | full decree object, action `makeDoctor`                                   |
| `LiturgicalEventCreateNewFixed` / `LiturgicalEventCreateNewMobile` / `LiturgicalEventSetPropertyGrade` / `LiturgicalEventSetPropertyName` / `LiturgicalEventMakeDoctor` | per-action `liturgical_event` shapes                                      |
| `MetadataCreateNew` / `MetadataSetPropertyGrade` / `MetadataSetPropertyName` / `MetadataMakeDoctor`                                                                     | per-action `metadata` shapes                                              |
| `DecreeDate` / `DecreeProtocol` / `DecreeDescription`                                                                                                                   | shared top-level scalars                                                  |
| `DecreeURL` / `DecreeURLS` / `DecreeLangs`                                                                                                                              | kept verbatim from the current schema                                     |

`LitCalDecreeWritePayload.json` definitions produced in Task 2: `PayloadCreateNewFixed`, `PayloadCreateNewMobile`,
`PayloadSetPropertyGrade`, `PayloadSetPropertyName`, `PayloadMakeDoctor`, `I18n`, `Readings`.

---

### Task 1: Source schema — five per-action shapes + StMartha data cleanup

**Files:**

- Create: `phpunit_tests/Schemas/DecreesSourceSchemaTest.php`
- Modify: `jsondata/schemas/LitCalDecreesSource.json` (full rewrite)
- Modify: `jsondata/sourcedata/decrees/decrees.json` (remove one property)

**Interfaces:**

- Consumes: `LitSchema::DECREES_SRC->path()` (existing enum case), `Router::getApiPaths()`.
- Produces: the definition names in the map above — Tasks 2 and 3 `$ref` them cross-file as
  `./LitCalDecreesSource.json#/definitions/<Name>`.

- [ ] **Step 1: Write the schema test file**

Create `phpunit_tests/Schemas/DecreesSourceSchemaTest.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the per-action decree shapes in LitCalDecreesSource.json (issue #314).
 *
 * The source schema discriminates decrees by metadata.action (and metadata.property
 * for setProperty), mirroring the five model classes in src/Models/Decrees/.
 */
final class DecreesSourceSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        return Schema::import(LitSchema::DECREES_SRC->path());
    }

    private function assertValidDecree(\stdClass $decree): void
    {
        self::schema()->in([$decree]);
        $this->addToAssertionCount(1);
    }

    private function assertInvalidDecree(\stdClass $decree): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in([$decree]);
    }

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    private static function createNewFixedDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 1/25",
            "description": "Test decree creating a new fixed-date liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "day": 14,
                "month": 2,
                "color": ["white"],
                "grade": 2,
                "common": ["Pastors"],
                "type": "fixed",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html",
                "url_lang_map": { "en": "en", "pt": "po" },
                "urls_langs": {
                    "en": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test_en.html",
                    "pt": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test_po.html"
                }
            }
        }
        JSON);
    }

    private static function createNewMobileDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTestMobile_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 2/25",
            "description": "Test decree creating a new mobile liturgical event.",
            "liturgical_event": {
                "event_key": "StTestMobile",
                "color": ["white"],
                "grade": 2,
                "common": ["Proper"],
                "type": "mobile",
                "calendar": "GENERAL ROMAN",
                "strtotime": {
                    "day_of_the_week": "Monday",
                    "relative_time": "after",
                    "event_key": "Pentecost"
                }
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function setPropertyGradeDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Upgrade",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 3/25",
            "description": "Test decree upgrading the grade of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "grade": 4,
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "grade",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function setPropertyNameDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_NameChange",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 4/25",
            "description": "Test decree changing the name of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "name",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function makeDoctorDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Doctor",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 5/25",
            "description": "Test decree declaring a saint Doctor of the Church.",
            "liturgical_event": {
                "event_key": "StTest",
                "common": ["Proper"],
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "makeDoctor",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    public function testCreateNewFixedDecreeIsValid(): void
    {
        $this->assertValidDecree(self::createNewFixedDecree());
    }

    public function testCreateNewMobileDecreeIsValid(): void
    {
        $this->assertValidDecree(self::createNewMobileDecree());
    }

    public function testSetPropertyGradeDecreeIsValid(): void
    {
        $this->assertValidDecree(self::setPropertyGradeDecree());
    }

    public function testSetPropertyNameDecreeIsValid(): void
    {
        $this->assertValidDecree(self::setPropertyNameDecree());
    }

    public function testMakeDoctorDecreeIsValid(): void
    {
        $this->assertValidDecree(self::makeDoctorDecree());
    }

    public function testStrayGradeOnSetPropertyNameIsRejected(): void
    {
        $decree                            = self::setPropertyNameDecree();
        $decree->liturgical_event->grade   = 3;
        $this->assertInvalidDecree($decree);
    }

    public function testMismatchedDecreeIdSuffixIsRejected(): void
    {
        // createNew decree with a _Doctor suffix: no oneOf branch matches.
        $decree            = self::createNewFixedDecree();
        $decree->decree_id = 'StTest_Doctor';
        $this->assertInvalidDecree($decree);
    }

    public function testMissingSinceYearIsRejected(): void
    {
        $decree = self::createNewFixedDecree();
        unset($decree->metadata->since_year);
        $this->assertInvalidDecree($decree);
    }

    public function testNameInsideLiturgicalEventIsRejected(): void
    {
        // In source data the event name lives in the i18n sidecar files, never inline.
        $decree                         = self::createNewFixedDecree();
        $decree->liturgical_event->name = 'Saint Test';
        $this->assertInvalidDecree($decree);
    }

    public function testApiPathInSourceIsRejected(): void
    {
        $decree           = self::createNewFixedDecree();
        $decree->api_path = 'https://litcal.johnromanodorazio.com/api/dev/decrees/StTest_Create';
        $this->assertInvalidDecree($decree);
    }

    public function testMakeDoctorWithDayAndMonthIsRejected(): void
    {
        $decree                          = self::makeDoctorDecree();
        $decree->liturgical_event->day   = 14;
        $decree->liturgical_event->month = 2;
        $this->assertInvalidDecree($decree);
    }

    public function testSetPropertyWithUnknownPropertyIsRejected(): void
    {
        $decree                     = self::setPropertyGradeDecree();
        $decree->metadata->property = 'color';
        $this->assertInvalidDecree($decree);
    }

    public function testCreateNewFixedWithoutTypeIsRejected(): void
    {
        $decree = self::createNewFixedDecree();
        unset($decree->liturgical_event->type);
        $this->assertInvalidDecree($decree);
    }
}
```

- [ ] **Step 2: Run the new test file — expect the negative tests to fail against the old lax schema**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DecreesSourceSchemaTest.php`

Expected: the 5 `...IsValid` tests PASS (the old schema is laxer), and the negative tests
(`testStrayGradeOnSetPropertyNameIsRejected`, `testMismatchedDecreeIdSuffixIsRejected`,
`testMissingSinceYearIsRejected`, `testNameInsideLiturgicalEventIsRejected`, `testApiPathInSourceIsRejected`,
`testMakeDoctorWithDayAndMonthIsRejected`, `testCreateNewFixedWithoutTypeIsRejected`) FAIL because the old schema
accepts those payloads so no exception is thrown. (`testSetPropertyWithUnknownPropertyIsRejected` may already pass —
the old schema has an enum on `property`.)

- [ ] **Step 3: Rewrite `jsondata/schemas/LitCalDecreesSource.json`**

Replace the entire file content with:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "LitCalDecreesSource",
    "description": "Decrees issued by the Dicastery for Divine Worship and the Discipline of the Sacraments or Apostolic Letters and similar dispositions from the Supreme Pontiff that determine new data in the calculation of the Liturgical Calendar",
    "type": "array",
    "items": {
        "$ref": "#/definitions/LitCalDecree"
    },
    "definitions": {
        "LitCalDecree": {
            "oneOf": [
                {
                    "$ref": "#/definitions/DecreeCreateNewFixed"
                },
                {
                    "$ref": "#/definitions/DecreeCreateNewMobile"
                },
                {
                    "$ref": "#/definitions/DecreeSetPropertyGrade"
                },
                {
                    "$ref": "#/definitions/DecreeSetPropertyName"
                },
                {
                    "$ref": "#/definitions/DecreeMakeDoctor"
                }
            ],
            "title": "LitCalDecree"
        },
        "DecreeDate": {
            "type": "string",
            "format": "date",
            "title": "DecreeDate"
        },
        "DecreeProtocol": {
            "type": "string",
            "minLength": 1,
            "title": "DecreeProtocol"
        },
        "DecreeDescription": {
            "type": "string",
            "minLength": 1,
            "title": "DecreeDescription"
        },
        "DecreeCreateNewFixed": {
            "type": "object",
            "additionalProperties": false,
            "description": "A decree that creates a new fixed-date liturgical event (metadata.action = createNew, liturgical_event.type = fixed)",
            "properties": {
                "decree_id": {
                    "type": "string",
                    "pattern": "^[A-Z][A-Za-z]+_Create$"
                },
                "decree_date": {
                    "$ref": "#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventCreateNewFixed"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataCreateNew"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeCreateNewFixed"
        },
        "DecreeCreateNewMobile": {
            "type": "object",
            "additionalProperties": false,
            "description": "A decree that creates a new mobile liturgical event (metadata.action = createNew, liturgical_event.type = mobile)",
            "properties": {
                "decree_id": {
                    "type": "string",
                    "pattern": "^[A-Z][A-Za-z]+_Create$"
                },
                "decree_date": {
                    "$ref": "#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventCreateNewMobile"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataCreateNew"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeCreateNewMobile"
        },
        "DecreeSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "description": "A decree that changes the liturgical grade of an existing liturgical event (metadata.action = setProperty, metadata.property = grade)",
            "properties": {
                "decree_id": {
                    "type": "string",
                    "pattern": "^[A-Z][A-Za-z]+_Upgrade$"
                },
                "decree_date": {
                    "$ref": "#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventSetPropertyGrade"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataSetPropertyGrade"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeSetPropertyGrade"
        },
        "DecreeSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "description": "A decree that changes the name of an existing liturgical event (metadata.action = setProperty, metadata.property = name); the new name lives in the i18n sidecar files",
            "properties": {
                "decree_id": {
                    "type": "string",
                    "pattern": "^[A-Z][A-Za-z]+_NameChange$"
                },
                "decree_date": {
                    "$ref": "#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventSetPropertyName"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataSetPropertyName"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeSetPropertyName"
        },
        "DecreeMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "description": "A decree that declares a saint with an existing liturgical event a Doctor of the Church (metadata.action = makeDoctor)",
            "properties": {
                "decree_id": {
                    "type": "string",
                    "pattern": "^[A-Z][A-Za-z]+_Doctor$"
                },
                "decree_date": {
                    "$ref": "#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventMakeDoctor"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataMakeDoctor"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeMakeDoctor"
        },
        "LiturgicalEventCreateNewFixed": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                },
                "day": {
                    "$ref": "./CommonDef.json#/definitions/Day"
                },
                "month": {
                    "$ref": "./CommonDef.json#/definitions/Month"
                },
                "color": {
                    "$ref": "./CommonDef.json#/definitions/LitColor"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                },
                "type": {
                    "type": "string",
                    "const": "fixed"
                }
            },
            "required": [
                "event_key",
                "calendar",
                "grade",
                "day",
                "month",
                "color",
                "common",
                "type"
            ],
            "title": "LiturgicalEventCreateNewFixed"
        },
        "LiturgicalEventCreateNewMobile": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                },
                "color": {
                    "$ref": "./CommonDef.json#/definitions/LitColor"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                },
                "type": {
                    "type": "string",
                    "const": "mobile"
                },
                "strtotime": {
                    "oneOf": [
                        {
                            "type": "string",
                            "minLength": 1,
                            "description": "supports PHP strtotime string format"
                        },
                        {
                            "$ref": "./CommonDef.json#/definitions/RelativeDateObject"
                        }
                    ]
                }
            },
            "required": [
                "event_key",
                "calendar",
                "grade",
                "color",
                "common",
                "type",
                "strtotime"
            ],
            "title": "LiturgicalEventCreateNewMobile"
        },
        "LiturgicalEventSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                }
            },
            "required": [
                "event_key",
                "calendar",
                "grade"
            ],
            "title": "LiturgicalEventSetPropertyGrade"
        },
        "LiturgicalEventSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                }
            },
            "required": [
                "event_key",
                "calendar"
            ],
            "title": "LiturgicalEventSetPropertyName"
        },
        "LiturgicalEventMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                }
            },
            "required": [
                "event_key",
                "calendar",
                "common"
            ],
            "title": "LiturgicalEventMakeDoctor"
        },
        "MetadataCreateNew": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "createNew"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "#/definitions/DecreeLangs"
                },
                "urls_langs": {
                    "$ref": "#/definitions/DecreeURLS"
                }
            },
            "required": [
                "action",
                "since_year",
                "url"
            ],
            "title": "MetadataCreateNew"
        },
        "MetadataSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "setProperty"
                },
                "property": {
                    "type": "string",
                    "const": "grade"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "#/definitions/DecreeLangs"
                },
                "urls_langs": {
                    "$ref": "#/definitions/DecreeURLS"
                }
            },
            "required": [
                "action",
                "property",
                "since_year",
                "url"
            ],
            "title": "MetadataSetPropertyGrade"
        },
        "MetadataSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "setProperty"
                },
                "property": {
                    "type": "string",
                    "const": "name"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "#/definitions/DecreeLangs"
                },
                "urls_langs": {
                    "$ref": "#/definitions/DecreeURLS"
                }
            },
            "required": [
                "action",
                "property",
                "since_year",
                "url"
            ],
            "title": "MetadataSetPropertyName"
        },
        "MetadataMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "makeDoctor"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "#/definitions/DecreeLangs"
                },
                "urls_langs": {
                    "$ref": "#/definitions/DecreeURLS"
                }
            },
            "required": [
                "action",
                "since_year",
                "url"
            ],
            "title": "MetadataMakeDoctor"
        },
        "DecreeURL": {
            "type": "string",
            "pattern": "^https?:\\/\\/(www|press)\\.vatican\\.va\\/((roman_curia\\/congregations\\/ccdds\\/documents\\/[-_%a-z0-9]+)|(content\\/salastampa\\/it\\/bollettino\\/pubblico\\/[0-9\\/]+)|(content\\/[-_%\\/a-z0-9]+))\\.(html|pdf)(#[%a-zD]+)?$",
            "title": "DecreeURL"
        },
        "DecreeURLS": {
            "type": "object",
            "additionalProperties": false,
            "patternProperties": {
                "(de|en|es|fr|it|la|pl|pt)": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(www|press)\\.vatican\\.va\\/((roman_curia\\/congregations\\/ccdds\\/documents\\/[-_%a-z0-9]+)|(content\\/salastampa\\/it\\/bollettino\\/pubblico\\/[0-9\\/]+)|(content\\/[-_%\\/a-z0-9]+))\\.(html|pdf)(#[%a-zD]+)?$"
                }
            },
            "description": "precalculated per-language decree URLs; a commodity for consuming clients, derivable from url + url_lang_map",
            "title": "DecreeURLS"
        },
        "DecreeLangs": {
            "type": "object",
            "minProperties": 1,
            "propertyNames": {
                "pattern": "^[a-z]{2}$"
            },
            "additionalProperties": {
                "type": "string",
                "minLength": 1
            },
            "description": "mapping between a two-letter ISO 639-1 language code and the language token used in that language's Vatican Decree URL (an arbitrary Vatican-specific string, e.g. 'ge' for German or 'po' for Portuguese)",
            "title": "DecreeLangs"
        }
    }
}
```

**IMPORTANT — copy the `DecreeURL` / `DecreeURLS` / `DecreeLangs` definitions verbatim from the current file**
(open the existing `jsondata/schemas/LitCalDecreesSource.json` before rewriting and preserve those three blocks
character-for-character; the regex escaping above may render differently in this plan than in the file).

- [ ] **Step 4: Remove the stray `grade` from StMartha_NameChange in `jsondata/sourcedata/decrees/decrees.json`**

Locate the `StMartha_NameChange` entry and change its `liturgical_event` from:

```json
        "liturgical_event": {
            "event_key": "StMartha",
            "grade": 3,
            "calendar":"GENERAL ROMAN"
        },
```

to:

```json
        "liturgical_event": {
            "event_key": "StMartha",
            "calendar":"GENERAL ROMAN"
        },
```

Note: the worktree copy of the file has `"calendar":"GENERAL ROMAN"` without a space after the colon — match the
actual file content when editing, and change nothing else in the file.

- [ ] **Step 5: Run the new test file — expect all tests to pass**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DecreesSourceSchemaTest.php`
Expected: OK, 13 tests.

- [ ] **Step 6: Run the source-corpus validation test**

Run: `vendor/bin/phpunit --filter testRealDecreesSourceValidation phpunit_tests/Schemas/SchemaValidationTest.php`
Expected: OK (1 test) — the shipped `decrees.json` (with StMartha cleaned) validates against the new schema.
If it fails, read the validator message: it names the first oneOf branch mismatch (usually a property present in
the data but not in the plan's shape — fix the schema, not the data, unless it is the StMartha `grade`).

- [ ] **Step 7: Commit**

```bash
git add jsondata/schemas/LitCalDecreesSource.json jsondata/sourcedata/decrees/decrees.json phpunit_tests/Schemas/DecreesSourceSchemaTest.php
git commit -m "refactor(schemas): per-action decree shapes in LitCalDecreesSource (#314)

Replace the single lax LitCalDecree definition with a oneOf over five
strict per-action shapes mirroring src/Models/Decrees/, enforce the
decree_id suffix per action, require since_year, and drop the stray
grade from StMartha_NameChange source data.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Write payload schema — per-action oneOf + openapi pointer updates

**Files:**

- Modify: `phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php` (add tests; keep all existing tests unchanged)
- Modify: `jsondata/schemas/LitCalDecreeWritePayload.json` (full rewrite)
- Modify: `jsondata/schemas/openapi.json` (two `$ref` pointer updates, lines ~4901 and ~4904)

**Interfaces:**

- Consumes (from Task 1): `./LitCalDecreesSource.json#/definitions/` → `DecreeCreateNewFixed/properties/decree_id`
  (and the other four variants' `decree_id`), `DecreeDate`, `DecreeProtocol`, `DecreeDescription`,
  `LiturgicalEventCreateNewFixed|CreateNewMobile|SetPropertyGrade|SetPropertyName|MakeDoctor`,
  `MetadataCreateNew|SetPropertyGrade|SetPropertyName|MakeDoctor`.
- Produces: `LitCalDecreeWritePayload.json#/definitions/I18n` and `#/definitions/Readings` — referenced by
  `openapi.json` (updated in this task). Root becomes `oneOf` (no root `properties` anymore).

- [ ] **Step 1: Add new test methods to `DecreeWritePayloadSchemaTest.php`**

Append these methods inside the class (after `testUrlLangMapRejectsNonIso6391Key`), and add these private fixture
builders after `validCreateNewPayload()`:

```php
    private static function decodePayload(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    private static function validMakeDoctorPayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_Doctor",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 2/25",
            "description": "Test decree elevating a saint to Doctor of the Church.",
            "liturgical_event": {
                "event_key": "StTest",
                "common": ["Proper"],
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "makeDoctor",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-doctor.html"
            },
            "i18n": { "en": "Saint Test, Doctor of the Church" }
        }
        JSON);
    }

    private static function validSetPropertyGradePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_Upgrade",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 3/25",
            "description": "Test decree upgrading the grade of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "grade": 4,
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "grade",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-grade.html"
            }
        }
        JSON);
    }

    private static function validSetPropertyNamePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_NameChange",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 4/25",
            "description": "Test decree changing the name of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "name",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-name.html"
            },
            "i18n": { "en": "Saint Test, renamed" }
        }
        JSON);
    }

    private static function validCreateNewMobilePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTestMobile_Create",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 5/25",
            "description": "Test decree creating a new mobile liturgical event.",
            "liturgical_event": {
                "event_key": "StTestMobile",
                "color": ["white"],
                "grade": 2,
                "common": ["Proper"],
                "type": "mobile",
                "calendar": "GENERAL ROMAN",
                "strtotime": {
                    "day_of_the_week": "Monday",
                    "relative_time": "after",
                    "event_key": "Pentecost"
                }
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-mobile.html"
            },
            "i18n": { "en": "Saint Test Mobile" },
            "readings": {
                "en": {
                    "first_reading": "Genesis 1:1",
                    "responsorial_psalm": "Psalm 1",
                    "gospel_acclamation": "John 1:1",
                    "gospel": "John 1:1-14"
                }
            }
        }
        JSON);
    }

    public function testValidMakeDoctorPayloadPasses(): void
    {
        self::schema()->in(self::validMakeDoctorPayload());
        $this->addToAssertionCount(1);
    }

    public function testValidSetPropertyGradePayloadPasses(): void
    {
        self::schema()->in(self::validSetPropertyGradePayload());
        $this->addToAssertionCount(1);
    }

    public function testValidSetPropertyNamePayloadPasses(): void
    {
        self::schema()->in(self::validSetPropertyNamePayload());
        $this->addToAssertionCount(1);
    }

    public function testValidCreateNewMobilePayloadPasses(): void
    {
        self::schema()->in(self::validCreateNewMobilePayload());
        $this->addToAssertionCount(1);
    }

    public function testDecreeIdSuffixMismatchedWithActionFails(): void
    {
        // createNew payload with a _Doctor decree_id: no oneOf branch matches.
        $payload            = self::validCreateNewPayload();
        $payload->decree_id = 'StTest_Doctor';
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testStrayGradeOnSetPropertyNamePayloadFails(): void
    {
        $payload                          = self::validSetPropertyNamePayload();
        $payload->liturgical_event->grade = 3;
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testMissingSinceYearFails(): void
    {
        $payload = self::validCreateNewPayload();
        unset($payload->metadata->since_year);
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }
```

- [ ] **Step 2: Run the test file — expect the three new negative tests to fail**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php`
Expected: the four new `Valid...Passes` tests PASS, existing tests PASS, and
`testDecreeIdSuffixMismatchedWithActionFails`, `testStrayGradeOnSetPropertyNamePayloadFails`,
`testMissingSinceYearFails` FAIL (old flat schema accepts these payloads, no exception raised).

- [ ] **Step 3: Rewrite `jsondata/schemas/LitCalDecreeWritePayload.json`**

Replace the entire file content with:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "LitCalDecreeWritePayload",
    "description": "Single-decree write payload for PUT/PATCH /decrees/{decree_id}. The payload shape is discriminated by metadata.action (and metadata.property for setProperty). Method- and action-dependent sidecar requirements (i18n, readings) are enforced by the API handler (DecreeWritePayloadGuard), not by this schema.",
    "oneOf": [
        {
            "$ref": "#/definitions/PayloadCreateNewFixed"
        },
        {
            "$ref": "#/definitions/PayloadCreateNewMobile"
        },
        {
            "$ref": "#/definitions/PayloadSetPropertyGrade"
        },
        {
            "$ref": "#/definitions/PayloadSetPropertyName"
        },
        {
            "$ref": "#/definitions/PayloadMakeDoctor"
        }
    ],
    "definitions": {
        "I18n": {
            "type": "object",
            "minProperties": 1,
            "propertyNames": {
                "pattern": "^[a-z]{2,3}$"
            },
            "additionalProperties": {
                "type": "string",
                "minLength": 1
            },
            "title": "I18n"
        },
        "Readings": {
            "type": "object",
            "minProperties": 1,
            "propertyNames": {
                "pattern": "^[a-z]{2,3}$"
            },
            "additionalProperties": {
                "$ref": "./CommonDef.json#/definitions/Readings"
            },
            "title": "Readings"
        },
        "PayloadCreateNewFixed": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeCreateNewFixed/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEventCreateNewFixed"
                },
                "metadata": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/MetadataCreateNew"
                },
                "i18n": {
                    "$ref": "#/definitions/I18n"
                },
                "readings": {
                    "$ref": "#/definitions/Readings"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "PayloadCreateNewFixed"
        },
        "PayloadCreateNewMobile": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeCreateNewMobile/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEventCreateNewMobile"
                },
                "metadata": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/MetadataCreateNew"
                },
                "i18n": {
                    "$ref": "#/definitions/I18n"
                },
                "readings": {
                    "$ref": "#/definitions/Readings"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "PayloadCreateNewMobile"
        },
        "PayloadSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeSetPropertyGrade/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEventSetPropertyGrade"
                },
                "metadata": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/MetadataSetPropertyGrade"
                },
                "readings": {
                    "$ref": "#/definitions/Readings"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "PayloadSetPropertyGrade"
        },
        "PayloadSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeSetPropertyName/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEventSetPropertyName"
                },
                "metadata": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/MetadataSetPropertyName"
                },
                "i18n": {
                    "$ref": "#/definitions/I18n"
                },
                "readings": {
                    "$ref": "#/definitions/Readings"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "PayloadSetPropertyName"
        },
        "PayloadMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeMakeDoctor/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "liturgical_event": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/LiturgicalEventMakeDoctor"
                },
                "metadata": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/MetadataMakeDoctor"
                },
                "i18n": {
                    "$ref": "#/definitions/I18n"
                },
                "readings": {
                    "$ref": "#/definitions/Readings"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "liturgical_event",
                "metadata"
            ],
            "title": "PayloadMakeDoctor"
        }
    }
}
```

Design notes baked into this file:

- `PayloadSetPropertyGrade` has **no `i18n` property** — the sidecar guard rejects i18n for grade decrees in all
  methods, so the schema can encode it structurally. All other variants allow optional `i18n`.
- Every variant allows optional `readings` — the guard's PUT/PATCH matrix (readings required on PUT createNew,
  rejected on PUT for others, optional on PATCH everywhere) is method-dependent and stays in PHP.

- [ ] **Step 4: Update the two `$ref` pointers in `jsondata/schemas/openapi.json`**

The root of `LitCalDecreeWritePayload.json` no longer has `properties`, so the two JSON pointers into it must move
to `definitions`. Around lines 4901 and 4904, change:

```json
"$ref": "./LitCalDecreeWritePayload.json#/properties/i18n"
```

to:

```json
"$ref": "./LitCalDecreeWritePayload.json#/definitions/I18n"
```

and:

```json
"$ref": "./LitCalDecreeWritePayload.json#/properties/readings"
```

to:

```json
"$ref": "./LitCalDecreeWritePayload.json#/definitions/Readings"
```

(The three `openapi.json` refs to `./LitCalDecreesSource.json#/definitions/LitCalDecree` are untouched — that
definition still exists as the `oneOf` wrapper after Task 1.)

- [ ] **Step 5: Run the write payload schema tests**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php`
Expected: OK, 13 tests (6 pre-existing + 7 new).

- [ ] **Step 6: Lint the OpenAPI schema**

Run: `composer lint:openapi`
Expected: exits 0 (warnings allowed if they exist on the development branch today; no *new* errors).

- [ ] **Step 7: Run the sidecar guard tests (unchanged code, sanity check)**

Run: `vendor/bin/phpunit phpunit_tests/Models/Decrees/DecreeWritePayloadGuardTest.php`
Expected: OK, no failures.

- [ ] **Step 8: Commit**

```bash
git add jsondata/schemas/LitCalDecreeWritePayload.json jsondata/schemas/openapi.json phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php
git commit -m "refactor(schemas): per-action oneOf for decree write payload (#314)

The write payload now mirrors the five per-action source shapes,
rejecting mismatched decree_id suffixes and stray properties at the
schema layer; i18n is structurally disallowed for setProperty/grade.
openapi.json pointers to the payload's i18n/readings sidecars move
from #/properties to #/definitions.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Response path schema — real per-action response shapes

**Files:**

- Create: `phpunit_tests/Handlers/DecreesHandlerResponseSchemaTest.php`
- Modify: `jsondata/schemas/LitCalDecreesPath.json` (full rewrite)

**Interfaces:**

- Consumes (from Task 1): `./LitCalDecreesSource.json#/definitions/DecreeDate`, `DecreeProtocol`,
  `DecreeDescription`, `DecreeURL`, `DecreeLangs`, and each variant's `.../properties/decree_id`.
- Consumes (existing): `AbstractHandlerTestCase::requestFor()`, `DecreesHandler`, `LitSchema::DECREES->path()`.
- Produces: nothing consumed by later tasks.

**Response-shape facts** (verified against the live handler during design):

- Top level adds required `api_path`; the models drop `urls_langs`, so response metadata has only
  `action` (+`property`), `since_year`, `url`, optional `url_lang_map`.
- `name` is injected from i18n into `liturgical_event` for name-bearing shapes (createNew ×2, makeDoctor,
  setProperty/name) — required there; `DecreeItemSetPropertyGrade` has no name — absent there.
- `readings` is merged into `liturgical_event` by event_key from the lectionary sidecar whenever an entry exists;
  since PATCH may add readings for any action, `readings` is **optional on all five** response variants.

- [ ] **Step 1: Write the response schema test**

Create `phpunit_tests/Handlers/DecreesHandlerResponseSchemaTest.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use Swaggest\JsonSchema\Schema;

/**
 * Validates the actual GET /decrees index response against the per-action
 * response shapes in LitCalDecreesPath.json (issue #314).
 */
final class DecreesHandlerResponseSchemaTest extends AbstractHandlerTestCase
{
    private function decreesIndexBody(): \stdClass
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody());
        assert($body instanceof \stdClass);
        return $body;
    }

    public function testDecreesIndexValidatesAgainstPathSchema(): void
    {
        $body = $this->decreesIndexBody();
        Schema::import(LitSchema::DECREES->path())->in($body);
        $this->addToAssertionCount(1);
    }

    public function testUrlsLangsInResponseMetadataIsRejected(): void
    {
        // The models drop urls_langs; a response carrying it must not validate.
        $body = $this->decreesIndexBody();
        $body->litcal_decrees[0]->metadata->urls_langs = (object) [
            'en' => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html',
        ];
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        Schema::import(LitSchema::DECREES->path())->in($body);
    }

    public function testMissingNameOnNameBearingDecreeIsRejected(): void
    {
        $body     = $this->decreesIndexBody();
        $tampered = false;
        foreach ($body->litcal_decrees as $decree) {
            if ($decree->metadata->action === 'makeDoctor') {
                unset($decree->liturgical_event->name);
                $tampered = true;
                break;
            }
        }
        self::assertTrue($tampered, 'Expected at least one makeDoctor decree in the index');
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        Schema::import(LitSchema::DECREES->path())->in($body);
    }
}
```

- [ ] **Step 2: Run it — expect the two tamper tests to fail against the old wrapper schema**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerResponseSchemaTest.php`
Expected: `testDecreesIndexValidatesAgainstPathSchema` PASSES (old schema is laxer);
`testUrlsLangsInResponseMetadataIsRejected` and `testMissingNameOnNameBearingDecreeIsRejected` FAIL
(no exception raised by the old schema).

- [ ] **Step 3: Rewrite `jsondata/schemas/LitCalDecreesPath.json`**

Replace the entire file content with (note: every `ApiPath...` pattern below is the current `api_path` pattern from
the pre-Task-1 `LitCalDecreesSource.json` with the trailing `(Upgrade|Create|NameChange|Doctor)` alternation replaced
by the single per-action suffix — copy the host/base portion verbatim from git history or the pattern below):

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "LitCalDecreesPath",
    "description": "Response of the GET /decrees index: decrees issued by the Dicastery for Divine Worship and the Discipline of the Sacraments or Apostolic Letters and similar dispositions from the Supreme Pontiff that determine new data in the calculation of the Liturgical Calendar. Response items carry the localized event name (for name-bearing decree types), the api_path self-link, and lectionary readings when available; the urls_langs commodity from the source data is not exposed.",
    "type": "object",
    "additionalProperties": false,
    "properties": {
        "litcal_decrees": {
            "type": "array",
            "items": {
                "$ref": "#/definitions/DecreeResponse"
            }
        }
    },
    "required": [
        "litcal_decrees"
    ],
    "definitions": {
        "DecreeResponse": {
            "oneOf": [
                {
                    "$ref": "#/definitions/DecreeResponseCreateNewFixed"
                },
                {
                    "$ref": "#/definitions/DecreeResponseCreateNewMobile"
                },
                {
                    "$ref": "#/definitions/DecreeResponseSetPropertyGrade"
                },
                {
                    "$ref": "#/definitions/DecreeResponseSetPropertyName"
                },
                {
                    "$ref": "#/definitions/DecreeResponseMakeDoctor"
                }
            ],
            "title": "DecreeResponse"
        },
        "DecreeResponseCreateNewFixed": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeCreateNewFixed/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "api_path": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(?:litcal\\.johnromanodorazio\\.com\\/api\\/(?:dev|v[4-9]|v[1-9]\\d+)|(?:localhost|127\\.0\\.0\\.1)(?:\\:\\d{4}))\\/decrees\\/[A-Z][A-Za-z]+_Create$"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventResponseCreateNewFixed"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataResponseCreateNew"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "api_path",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeResponseCreateNewFixed"
        },
        "DecreeResponseCreateNewMobile": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeCreateNewMobile/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "api_path": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(?:litcal\\.johnromanodorazio\\.com\\/api\\/(?:dev|v[4-9]|v[1-9]\\d+)|(?:localhost|127\\.0\\.0\\.1)(?:\\:\\d{4}))\\/decrees\\/[A-Z][A-Za-z]+_Create$"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventResponseCreateNewMobile"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataResponseCreateNew"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "api_path",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeResponseCreateNewMobile"
        },
        "DecreeResponseSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeSetPropertyGrade/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "api_path": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(?:litcal\\.johnromanodorazio\\.com\\/api\\/(?:dev|v[4-9]|v[1-9]\\d+)|(?:localhost|127\\.0\\.0\\.1)(?:\\:\\d{4}))\\/decrees\\/[A-Z][A-Za-z]+_Upgrade$"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventResponseSetPropertyGrade"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataResponseSetPropertyGrade"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "api_path",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeResponseSetPropertyGrade"
        },
        "DecreeResponseSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeSetPropertyName/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "api_path": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(?:litcal\\.johnromanodorazio\\.com\\/api\\/(?:dev|v[4-9]|v[1-9]\\d+)|(?:localhost|127\\.0\\.0\\.1)(?:\\:\\d{4}))\\/decrees\\/[A-Z][A-Za-z]+_NameChange$"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventResponseSetPropertyName"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataResponseSetPropertyName"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "api_path",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeResponseSetPropertyName"
        },
        "DecreeResponseMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "decree_id": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeMakeDoctor/properties/decree_id"
                },
                "decree_date": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDate"
                },
                "decree_protocol": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeProtocol"
                },
                "description": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeDescription"
                },
                "api_path": {
                    "type": "string",
                    "pattern": "^https?:\\/\\/(?:litcal\\.johnromanodorazio\\.com\\/api\\/(?:dev|v[4-9]|v[1-9]\\d+)|(?:localhost|127\\.0\\.0\\.1)(?:\\:\\d{4}))\\/decrees\\/[A-Z][A-Za-z]+_Doctor$"
                },
                "liturgical_event": {
                    "$ref": "#/definitions/LiturgicalEventResponseMakeDoctor"
                },
                "metadata": {
                    "$ref": "#/definitions/MetadataResponseMakeDoctor"
                }
            },
            "required": [
                "decree_id",
                "decree_date",
                "decree_protocol",
                "description",
                "api_path",
                "liturgical_event",
                "metadata"
            ],
            "title": "DecreeResponseMakeDoctor"
        },
        "LiturgicalEventResponseCreateNewFixed": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "name": {
                    "type": "string",
                    "minLength": 1
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                },
                "day": {
                    "$ref": "./CommonDef.json#/definitions/Day"
                },
                "month": {
                    "$ref": "./CommonDef.json#/definitions/Month"
                },
                "color": {
                    "$ref": "./CommonDef.json#/definitions/LitColor"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                },
                "type": {
                    "type": "string",
                    "const": "fixed"
                },
                "readings": {
                    "$ref": "./CommonDef.json#/definitions/Readings"
                }
            },
            "required": [
                "event_key",
                "name",
                "calendar",
                "grade",
                "day",
                "month",
                "color",
                "common",
                "type"
            ],
            "title": "LiturgicalEventResponseCreateNewFixed"
        },
        "LiturgicalEventResponseCreateNewMobile": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "name": {
                    "type": "string",
                    "minLength": 1
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                },
                "color": {
                    "$ref": "./CommonDef.json#/definitions/LitColor"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                },
                "type": {
                    "type": "string",
                    "const": "mobile"
                },
                "strtotime": {
                    "oneOf": [
                        {
                            "type": "string",
                            "minLength": 1,
                            "description": "supports PHP strtotime string format"
                        },
                        {
                            "$ref": "./CommonDef.json#/definitions/RelativeDateObject"
                        }
                    ]
                },
                "readings": {
                    "$ref": "./CommonDef.json#/definitions/Readings"
                }
            },
            "required": [
                "event_key",
                "name",
                "calendar",
                "grade",
                "color",
                "common",
                "type",
                "strtotime"
            ],
            "title": "LiturgicalEventResponseCreateNewMobile"
        },
        "LiturgicalEventResponseSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "grade": {
                    "$ref": "./CommonDef.json#/definitions/LitGrade"
                },
                "readings": {
                    "$ref": "./CommonDef.json#/definitions/Readings"
                }
            },
            "required": [
                "event_key",
                "calendar",
                "grade"
            ],
            "title": "LiturgicalEventResponseSetPropertyGrade"
        },
        "LiturgicalEventResponseSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "name": {
                    "type": "string",
                    "minLength": 1
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "readings": {
                    "$ref": "./CommonDef.json#/definitions/Readings"
                }
            },
            "required": [
                "event_key",
                "name",
                "calendar"
            ],
            "title": "LiturgicalEventResponseSetPropertyName"
        },
        "LiturgicalEventResponseMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": {
                    "$ref": "./CommonDef.json#/definitions/EventKey"
                },
                "name": {
                    "type": "string",
                    "minLength": 1
                },
                "calendar": {
                    "$ref": "./CommonDef.json#/definitions/Calendar"
                },
                "common": {
                    "$ref": "./CommonDef.json#/definitions/LitCommon"
                },
                "readings": {
                    "$ref": "./CommonDef.json#/definitions/Readings"
                }
            },
            "required": [
                "event_key",
                "name",
                "calendar",
                "common"
            ],
            "title": "LiturgicalEventResponseMakeDoctor"
        },
        "MetadataResponseCreateNew": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "createNew"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeLangs"
                }
            },
            "required": [
                "action",
                "since_year",
                "url"
            ],
            "title": "MetadataResponseCreateNew"
        },
        "MetadataResponseSetPropertyGrade": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "setProperty"
                },
                "property": {
                    "type": "string",
                    "const": "grade"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeLangs"
                }
            },
            "required": [
                "action",
                "property",
                "since_year",
                "url"
            ],
            "title": "MetadataResponseSetPropertyGrade"
        },
        "MetadataResponseSetPropertyName": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "setProperty"
                },
                "property": {
                    "type": "string",
                    "const": "name"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeLangs"
                }
            },
            "required": [
                "action",
                "property",
                "since_year",
                "url"
            ],
            "title": "MetadataResponseSetPropertyName"
        },
        "MetadataResponseMakeDoctor": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "action": {
                    "type": "string",
                    "const": "makeDoctor"
                },
                "since_year": {
                    "type": "integer"
                },
                "url": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeURL"
                },
                "url_lang_map": {
                    "$ref": "./LitCalDecreesSource.json#/definitions/DecreeLangs"
                }
            },
            "required": [
                "action",
                "since_year",
                "url"
            ],
            "title": "MetadataResponseMakeDoctor"
        }
    }
}
```

- [ ] **Step 4: Run the response schema tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerResponseSchemaTest.php`
Expected: OK, 3 tests. If `testDecreesIndexValidatesAgainstPathSchema` fails, the validator message names the
offending decree/branch — compare against the live shape facts above (e.g. the response may include a property
this plan missed) and adjust the response schema, NOT the handler.

- [ ] **Step 5: Run the existing decrees handler tests (read paths must be unaffected)**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerTest.php`
Expected: OK, no failures.

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/LitCalDecreesPath.json phpunit_tests/Handlers/DecreesHandlerResponseSchemaTest.php
git commit -m "refactor(schemas): precise per-action response shapes in LitCalDecreesPath (#314)

The /decrees index response schema no longer wraps the source schema:
response items require api_path and the localized name (on name-bearing
decree types), allow lectionary readings on every variant, and drop the
urls_langs commodity which the models never expose.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Fix the 409-conflict test fixtures (decree_id suffix now enforced)

The two 409 tests PUT a `createNew` payload at `StMaryMagdalene_Upgrade`. Schema validation runs BEFORE the
existence check in `DecreesHandler::handlePutRequest()`, so under the new write payload schema that request now
fails validation (`_Upgrade` suffix on a `createNew` payload) instead of reaching the 409. Both tests must target a
shipped `_Create` decree: `MaryMotherChurch_Create`.

**Files:**

- Modify: `phpunit_tests/Handlers/DecreesHandlerWriteTest.php` (~lines 125–135, the test using
  `createNewPayload('StMaryMagdalene_Upgrade')`)
- Modify: `phpunit_tests/Routes/ReadWrite/DecreesTest.php:110-138` (`testAuthenticatedPutOnExistingDecreeReturns409`)

**Interfaces:**

- Consumes: `createNewPayload(string $decreeId)` helpers already present in both files.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Run the handler write tests to see the regression**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php`
Expected: the PUT-on-existing-decree test FAILS (a `ValidationException` about the payload/oneOf is thrown instead
of the expected `ConflictException`). All other tests PASS — every other fixture in the file already uses
suffix-consistent decree ids (`StTest_Create`, `StTest_Doctor` with a makeDoctor payload, `Nonexistent_Create`).

- [ ] **Step 2: Update the handler-level 409 test**

In `phpunit_tests/Handlers/DecreesHandlerWriteTest.php`, replace the method `testPutExistingDecreeIdConflicts()`
(currently at lines 124–131), which reads:

```php
    public function testPutExistingDecreeIdConflicts(): void
    {
        $payload = self::createNewPayload('StMaryMagdalene_Upgrade');
        $this->expectException(ConflictException::class);
        ( new DecreesHandler(['StMaryMagdalene_Upgrade']) )->handle(
            $this->requestFor('PUT', '/decrees/StMaryMagdalene_Upgrade', ['Accept-Language' => 'en'], $payload)
        );
    }
```

with:

```php
    public function testPutExistingDecreeIdConflicts(): void
    {
        // MaryMotherChurch_Create ships with the API, so a PUT on it must conflict.
        // (A _Create decree_id is required: the write payload schema binds the
        // decree_id suffix to metadata.action, and the fixture payload is createNew.)
        $payload = self::createNewPayload('MaryMotherChurch_Create');
        $this->expectException(ConflictException::class);
        ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'en'], $payload)
        );
    }
```

- [ ] **Step 3: Update the route-level 409 test**

In `phpunit_tests/Routes/ReadWrite/DecreesTest.php`, `testAuthenticatedPutOnExistingDecreeReturns409()`:
replace both occurrences of `StMaryMagdalene_Upgrade` (payload argument at line ~121 and request URI at line ~123)
with `MaryMotherChurch_Create`, and change the comment at line ~120 to:

```php
        // MaryMotherChurch_Create ships with the API, so a PUT on it must conflict.
        // (A _Create decree_id is required: the write payload schema binds the
        // decree_id suffix to metadata.action, and the fixture payload is createNew.)
        $payload = self::createNewPayload('MaryMotherChurch_Create');
```

- [ ] **Step 4: Run the handler write tests again**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesHandlerWriteTest.php`
Expected: OK, all tests pass.

(The route-level test runs against `localhost:8000`; locally it either passes or self-skips against the stale
Docker build — both acceptable. It is exercised for real in CI.)

- [ ] **Step 5: Commit**

```bash
git add phpunit_tests/Handlers/DecreesHandlerWriteTest.php phpunit_tests/Routes/ReadWrite/DecreesTest.php
git commit -m "test(decrees): use a _Create decree for the 409 conflict fixtures (#314)

The per-action write payload schema now rejects a createNew payload
addressed to an _Upgrade decree_id before the existence check runs, so
the conflict tests target MaryMotherChurch_Create instead.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Full verification + spec document sync

**Files:**

- Modify: `docs/superpowers/specs/2026-07-15-issue-314-decrees-source-schema-design.md`

**Interfaces:** none — verification and documentation only.

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `composer test`
Expected: no failures and no errors. Skips are acceptable ONLY for: `Routes/*` tests (server on `localhost:8000`
stale or unreachable), DB-gated tests (`isDatabaseConfigured`), and pre-existing environment-gated skips.
Any failure in `Schemas/*`, `Handlers/*`, `Models/*` must be fixed before proceeding.

- [ ] **Step 2: Run static analysis and code style checks**

Run: `composer analyse && composer lint`
Expected: PHPStan clean (no `src/` changes, so any error would be pre-existing — report it, don't fix here);
phpcs clean including the new/modified test files.

- [ ] **Step 3: Run the OpenAPI and markdown linters**

Run: `composer lint:openapi && composer lint:md`
Expected: both exit 0 (same warnings as the development branch at most; no new errors).

- [ ] **Step 4: Sync the spec document with implementation-time decisions**

In `docs/superpowers/specs/2026-07-15-issue-314-decrees-source-schema-design.md` apply these corrections:

1. In the model-class table, fix the decree counts: `DecreeItemCreateNewFixed` → **9** (not 8); the total corpus is
   14 decrees (9 fixed createNew + 1 mobile createNew + 1 setProperty/grade + 1 setProperty/name + 2 makeDoctor).

2. In section "1. `LitCalDecreesSource.json`", add this bullet:

   ```text
   - The schema keeps a definition named `LitCalDecree` (now the `oneOf` of the five variants) so that
     the three existing `$ref`s to it in `openapi.json` keep resolving.
   ```

3. In section "3. `LitCalDecreeWritePayload.json`", add this bullet:

   ```text
   - The `setProperty`/`grade` payload variant omits the `i18n` property entirely (the sidecar guard
     rejects i18n for grade decrees in every method, so the schema encodes it structurally); the two
     `openapi.json` pointers to the payload's sidecars move from `#/properties/*` to
     `#/definitions/I18n` and `#/definitions/Readings`.
   ```

4. In section "4. `LitCalDecreesPath.json`", replace the bullet that says readings are *required* for the two
   `createNew` variants with:

   ```text
   - `readings` optional in `liturgical_event` on **all five** response variants: the handler merges
     readings by `event_key` whenever a lectionary sidecar entry exists, and a PATCH may legitimately
     add readings to any decree type.
   ```

5. In section "5. Validation and test impact", add this bullet:

   ```text
   - The two 409-conflict tests (handler-level and route-level) switch their fixture from
     `StMaryMagdalene_Upgrade` to `MaryMotherChurch_Create`, because the write payload schema now
     rejects a `createNew` payload addressed to an `_Upgrade` decree_id before the existence check runs.
   ```

Then run: `composer lint:md` (or `npx --yes markdownlint-cli docs/superpowers/specs/2026-07-15-issue-314-decrees-source-schema-design.md`)
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-07-15-issue-314-decrees-source-schema-design.md
git commit -m "docs(spec): sync decrees schema spec with implementation decisions (#314)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Report**

Summarize: tests run and their results, any pre-existing failures/skips observed, and that the branch
`feature/314-decrees-schema-refinement` is ready for PR against `development`.

---

## Follow-up (out of scope, note for the PR description)

- The LiturgicalCalendarFrontend decree admin UI may fetch `LitCalDecreeWritePayload.json` (via `/schemas`) for
  client-side validation; if it dereferences `#/properties/*` on the payload schema root, it needs a matching
  update. Flag this in the PR description.
- The single-decree `GET /decrees/{decree_id}` aggregated response (decree + all-locale `i18n`/`readings` maps +
  merged `liturgical_event.readings`) is documented in `openapi.json` via a pre-existing, imperfect `allOf`
  composition; refining that is a separate documentation concern.
