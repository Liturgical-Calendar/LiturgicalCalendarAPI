# Decrees documentation

- `notitiae-register.json` — every calendar-relevant decree found in *Notitiae* (1965–2022), validated by
  `notitiae-register.schema.json` and `phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`.
- `notitiae-transcription-guide.md` — how entries are transcribed.
- Tools: `scripts/notitiae/`. Design: `docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`.

The register is a survey, not source data: nothing in it is applied to a calendar until a PR lands it in `jsondata/` and sets
`api.status` to `applied`.
