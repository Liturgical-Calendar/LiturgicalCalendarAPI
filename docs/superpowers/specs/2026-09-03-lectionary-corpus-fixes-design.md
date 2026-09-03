# Lectionary corpus fixes: #971, #973, #972

Design for three interlocking corrections to the rite-level Roman lectionary corpus
(`jsondata/sourcedata/rite/roman/lectionary/`), to be delivered as three sequential
pull requests.

Date: 2026-09-03

## Summary

Investigation changed the shape of two of the three issues:

- **#971** states the `vigil` block duplicates the `day` block. It is the other way round:
  both blocks hold the **Vigil's** readings, so the `day` block is the wrong one.
- **#973** states that `Psalm 45 (46)` means Hebrew 45 with a Vulgate gloss. It is the
  reverse — the dual form is `Vulgate (Hebrew)`. And the corpus is not split between two
  conventions; it stores **one numbering across six files that each need a different
  rendering**, because each locale's own liturgical books number psalms differently.
- **#972** is confirmed and larger than reported: `nl` is a Latin copy in **five** populated
  sections, not just `sanctorum`.

## Evidence

Every value below was taken from a bishops' conference or a printed liturgical book, not
inferred. Where a source is a website it was fetched and quoted; where it is a book it was
identified by its psalm incipits.

### Nativity of St John the Baptist (#971)

|                    | Vigil (23 June) | Day (24 June) |
|--------------------|-----------------|---------------|
| first reading      | Ger 1,4-10      | Is 49,1-6     |
| responsorial psalm | Sal 70 (71)     | Sal 138 (139) |
| second reading     | 1 Pt 1,8-12     | At 13,22-26   |
| gospel acclamation | Gv 1,7; Lc 1,17 | Lc 1,76       |
| gospel             | Lc 1,5-17       | Lc 1,57-66.80 |

Sources: [CEI 23 June 2026](https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia=20260623),
[CEI 24 June 2026](https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia=20260624),
[USCCB Mass during the Day](https://bible.usccb.org/bible/readings/062418-day-mass.cfm),
[Nederlands Lectionarium deel IV](https://dionysiusparochie.nl/lectionaria/deel-iv/24-juni-geboorte-van-de-h-johannes-de-doper-hoogfeest/).

The corpus currently holds the Vigil column in **both** blocks, in all six locales.

### Psalm numbering conventions (#973)

| locale | form                 | example          | authority                                                                                                                                  |
|--------|----------------------|------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `la`   | bare **Vulgate**     | `Psalmo 88`      | [Lectionarium pro Missis de BMV, 1987](https://archive.org/details/CMBMV1987Lectionary); iBreviary                                         |
| `it`   | **Vulgate (Hebrew)** | `Salmo 88 (89)`  | [CEI](https://www.chiesacattolica.it/liturgia-del-giorno/)                                                                                 |
| `fr`   | **Vulgate (Hebrew)** | `Psaume 88 (89)` | [AELF](https://www.aelf.org/2026-06-24/romain/messe)                                                                                       |
| `nl`   | **Hebrew (Vulgate)** | `Psalm 89 (88)`  | [Nederlands Lectionarium deel IV](https://dionysiusparochie.nl/lectionaria/deel-iv/24-juni-geboorte-van-de-h-johannes-de-doper-hoogfeest/) |
| `en`   | bare **Hebrew**      | `Psalm 89`       | [USCCB](https://bible.usccb.org/bible/readings/090926.cfm)                                                                                 |
| `hr`   | bare **Hebrew**      | `Ps 89`          | corpus-internal consistency only — see Open questions                                                                                      |

The parenthetical is **omitted where the two numberings coincide**: Ps 1–8 and Ps 148–150.
CEI prints a bare `Sal 5` for 7 September 2026, confirming the rule.

The Latin finding is the load-bearing one. The 1987 Latin lectionary prints bare Vulgate
numbers, proven by its incipits:

| printed       | incipit                          | Hebrew |
|---------------|----------------------------------|--------|
| `Ps 112, 1-2` | *Laudate, pueri Domini*          | 113    |
| `Ps 23, 7-10` | *Attollite, portae… rex gloriae* | 24     |
| `Ps 18, 8-9`  | *Lex Domini immaculata*          | 19     |
| `Ps 102, 1-2` | *Benedic, anima mea, Domino*     | 103    |
| `Ps 144, 1-2` | *Exaltabo te, Deus meus rex*     | 145    |

`la.json` currently stores **Hebrew** numbers (its `Psalmo 89` for St Joseph is Hebrew 89 /
Vulgate 88), so the Latin file is the furthest of the six from its own book.

Note that the Vatican's *Nova Vulgata* uses the opposite order from the lectionaries
(`PSALMUS 139 (138)` — Hebrew first). The lectionary, not the Bible, is the relevant book
here, and the Latin lectionary is bare Vulgate.

### Hebrew ↔ Vulgate mapping

Deterministic except in two zones, which depend on the verse range and will be hand-checked:

| Hebrew               | Vulgate   |
|----------------------|-----------|
| 1–8                  | same      |
| 9–10                 | 9         |
| 11–113               | −1        |
| 114–115              | 113       |
| 116:1-9 / 116:10-19  | 114 / 115 |
| 117–146              | −1        |
| 147:1-11 / 147:12-20 | 146 / 147 |
| 148–150              | same      |

The corpus's existing numbers were verified to be Hebrew: wherever `hr` holds a value, its
number matches `en`'s versed citation exactly (`Psalm 132:6-7…` ↔ `Ps 132,6-7…`).

### Dutch placeholder scope (#972)

`md5sum` across every multi-locale folder in the repo:

| section                      | `nl` == `la`?            | populated leaves |
|------------------------------|--------------------------|------------------|
| `sanctorum`                  | yes                      | 349              |
| `feriale_tempus_nativitatis` | yes                      | 56               |
| `dominicale_et_festivum_B`   | yes                      | 27               |
| `dominicale_et_festivum_C`   | yes                      | 27               |
| `dominicale_et_festivum_A`   | values yes, bytes differ | 27               |

Roughly 486 Dutch leaves are in fact Latin. Every *other* identical-file pair in the
repository is an all-empty placeholder (issue #712), not a mistranslation — so `nl` is the
only fake translation in the corpus.

## Per-locale rendering conventions

Book names and punctuation were taken from the corpus itself, per the method established
in #958 — match an existing citation of the same book in the same locale file.

| locale | Isaiah   | Acts          | Luke    | separator       |
|--------|----------|---------------|---------|-----------------|
| `en`   | `Isaiah` | `Acts`        | `Luke`  | `Book C:v-v, v` |
| `it`   | `Isaia`  | `Atti`        | `Luca`  | `Book C, v-v.v` |
| `la`   | `Isaiæ`  | `Actus`       | `Lucam` | `Book C, v-v.v` |
| `fr`   | `Isaïe`  | `Actes`       | `Luc`   | `Book C, v-v.v` |
| `nl`   | `Jesaja` | `Handelingen` | `Lucas` | `Book C, v-v.v` |
| `hr`   | `Iz`     | `Dj`          | `Lk`    | `Bk C,v-v.v`    |

Dutch follows `decrees/lectionary/nl.json`, the repository's only existing Dutch lectionary
data (`Jesaja 58, 6-11`, `Mattheüs 11, 25`, `Psalm 33, 2-3. 4-5.`) — full book names rather
than the printed lectionary's abbreviations (`Jes.`, `Hand.`, `Lc.`), because every other
locale file in this corpus spells book names out.

## PR 1 — #971, Nativity of St John the Baptist day readings

Branch: `fix/971-nativity-john-baptist-day-readings`

Replace the `day` block of `NativityJohnBaptist` in all six
`rite/roman/lectionary/sanctorum/{locale}.json`. Leave `vigil` untouched — it is correct.

| locale | first_reading    | responsorial_psalm | second_reading          | gospel_acclamation | gospel              |
|--------|------------------|--------------------|-------------------------|--------------------|---------------------|
| `en`   | `Isaiah 49:1-6`  | `Psalm 139`        | `Acts 13:22-26`         | `Luke 1:76`        | `Luke 1:57-66, 80`  |
| `it`   | `Isaia 49, 1-6`  | `Salmo 139`        | `Atti 13, 22-26`        | `Luca 1, 76`       | `Luca 1, 57-66.80`  |
| `la`   | `Isaiæ 49, 1-6`  | `Psalmo 139`       | `Actus 13, 22-26`       | `Lucam 1, 76`      | `Lucam 1, 57-66.80` |
| `fr`   | `Isaïe 49, 1-6`  | `Psaume 139`       | `Actes 13, 22-26`       | `Luc 1, 76`        | `Luc 1, 57-66.80`   |
| `nl`   | `Jesaja 49, 1-6` | `Psalm 139`        | `Handelingen 13, 22-26` | `Lucas 1, 76`      | `Lucas 1, 57-66.80` |
| `hr`   | `Iz 49,1-6`      | `Ps 139`           | `Dj 13,22-26`           | `Lk 1,76`          | `Lk 1,57-66.80`     |

Psalm numbers are written in each locale's **current** form and are corrected to the
convention table by PR 2. The `nl` row is written in correct Dutch rather than the Latin the
file currently holds: writing known-wrong data to keep a diff tidy is not a trade worth
making, and PR 3 converts the rest of the file.

**Regression guard.** Add to the `LectionaryCorpusTest` family an assertion that, for every
entry carrying both blocks in every locale, `vigil !== day`. This is the check #969 could not
be: #969 compares key *sets* across locales, and a defect that is uniform across all six
files and internal to one entry passes it by construction.

## PR 2 — #973, psalm numbering convention

Branch: `fix/973-psalm-numbering-convention`

1. Apply the convention table to every psalm citation in the rite-level corpus — whole-psalm,
   versed, and gospel-acclamation alike. Net effect: `en` loses its 11 dual citations; `hr` is
   unchanged; `it` and `fr` gain glosses on roughly 105 citations each; `la` is renumbered to
   Vulgate throughout; `nl` is left for PR 3, which writes the file from scratch.
2. Fix the one stray in `sanctorum/en.json`: `Psalm 84,5` uses comma style in an otherwise
   colon-style file.
3. Document the table in `jsondata/sourcedata/rite/roman/lectionary/README.md`, with the
   sources, so a contributor finds it beside the data it governs.
4. Add a lint asserting each locale's form — that `en`/`hr` carry no parenthetical, that
   `it`/`fr`/`la` carry the Vulgate number in the leading position, that `nl` carries the
   Hebrew one, and that no citation glosses an aligned psalm (1–8, 148–150).

The Vulgate number is derived mechanically from the Hebrew via the mapping table. The
114–116 and 147 zones depend on the verse range and will be enumerated and checked by hand
rather than mapped.

## PR 3 — #972, Dutch lectionary

Branch: `fix/972-dutch-lectionary`

Render all ~486 populated leaves across `sanctorum`, `dominicale_et_festivum_A`, `_B`, `_C`,
and `feriale_tempus_nativitatis` into Dutch: book names per the `decrees/lectionary/nl.json`
precedent, chapter/verse punctuation unchanged from the Latin (`Book C, v-v.v`), psalms in
the `Psalm H (V)` form fixed by PR 2.

Chapter and verse numbers are **not** translated — only book names and psalm numbering
change — so each entry is verifiable against its Latin source line by line.

**Guard.** Add an assertion that no locale file in a lectionary folder is byte-identical to
another, excluding all-empty placeholder files. That is the check that would have caught this
issue and that #969 by construction could not.

## Non-goals

- **Filling empty readings.** ~4,000 leaves across the corpus are `""`. That is #712 and is
  data acquisition, out of scope here.
- **Storing one canonical numbering and rendering per-locale at serve time.** This would
  remove six places to drift, but it is an API change, and the corpus already stores
  per-locale book names — so per-locale psalm numbers are consistent with the existing
  design. Worth revisiting only if the lint proves insufficient.
- **National and diocesan lectionary layers.** `en_US`, `it_IT`, `nl_NL` and friends are
  separate files governed by their own conferences' books; they are all empty placeholders
  today and are not touched.

## Open questions

- **Croatian.** `hkm.hr` and `hilp.hr` could not be reached, so `hr` is recorded as bare
  Hebrew on the strength of corpus-internal consistency (all 106 citations agree, and #958
  treated `hr` as the authoritative source) rather than a printed book. It is left unchanged
  by PR 2, so nothing depends on resolving this; the README will say so plainly.
