# Roman rite lectionary corpus

This folder holds the rite-level Roman lectionary readings, one file per section (`sanctorum`,
`dominicale_et_festivum_A`/`_B`/`_C`, `feriale_tempus_*`, `feriale_per_annum_*`), each keyed by
`event_key` and holding one `{locale}.json` per supported locale (`en`, `hr`, `it`, `fr`, `nl`, `la`).

## Psalm numbering convention (#973)

The Psalter is numbered two incompatible ways — Hebrew/Masoretic and Greek/Vulgate — and each
locale's own liturgical books follow one or the other, sometimes glossing the citation with the
other system's number, sometimes not. **Getting a citation's number wrong does not produce a
differently-formatted citation; it names a different psalm.** `Psalm 71` (Hebrew) and `Psalmo 71`
(Vulgate) are not the same text — the Vulgate equivalent of Hebrew 71 is 70.

Every value below was taken from a bishops' conference or a printed liturgical book, not inferred.
Where a source is a website it was fetched and quoted; where it is a book it was identified by its
psalm incipits.

### Target forms

| locale | form                 | example          | authority                                                                                                                                  |
|--------|----------------------|------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `la`   | bare **Vulgate**     | `Psalmo 88`      | [Lectionarium pro Missis de BMV, 1987](https://archive.org/details/CMBMV1987Lectionary); iBreviary                                         |
| `it`   | **Vulgate (Hebrew)** | `Salmo 88 (89)`  | [CEI](https://www.chiesacattolica.it/liturgia-del-giorno/)                                                                                 |
| `fr`   | **Vulgate (Hebrew)** | `Psaume 88 (89)` | [AELF](https://www.aelf.org/2026-06-24/romain/messe)                                                                                       |
| `nl`   | **Hebrew (Vulgate)** | `Psalm 89 (88)`  | [Nederlands Lectionarium deel IV](https://dionysiusparochie.nl/lectionaria/deel-iv/24-juni-geboorte-van-de-h-johannes-de-doper-hoogfeest/) |
| `en`   | bare **Hebrew**      | `Psalm 89`       | [USCCB](https://bible.usccb.org/bible/readings/090926.cfm)                                                                                 |
| `hr`   | bare **Hebrew**      | `Ps 89`          | corpus-internal consistency only — see below                                                                                               |

The parenthetical is **omitted where the two numberings coincide**: Ps 1–8 and Ps 148–150. CEI
prints a bare `Sal 5` for 7 September 2026, confirming the rule.

The Latin finding is the load-bearing one. The 1987 Latin lectionary prints bare Vulgate numbers,
proven by its incipits:

| printed       | incipit                          | Hebrew |
|---------------|----------------------------------|--------|
| `Ps 112, 1-2` | *Laudate, pueri Domini*          | 113    |
| `Ps 23, 7-10` | *Attollite, portae… rex gloriae* | 24     |
| `Ps 18, 8-9`  | *Lex Domini immaculata*          | 19     |
| `Ps 102, 1-2` | *Benedic, anima mea, Domino*     | 103    |
| `Ps 144, 1-2` | *Exaltabo te, Deus meus rex*     | 145    |

Note that the Vatican's *Nova Vulgata* uses the opposite order from the lectionaries (`PSALMUS 139
(138)` — Hebrew first). The lectionary, not the Bible, is the relevant book here, and the Latin
lectionary is bare Vulgate.

### Hebrew ↔ Vulgate mapping

Deterministic except in two zones, which depend on the verse range:

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

`Ps 116` and `Ps 147` are the two exceptions: whether a citation of either maps to the first or the
second Vulgate value depends on which verses are cited, not on the psalm number alone. A whole-psalm
citation of either (no verse numbers at all) cannot be resolved from the number alone.
`scripts/lint-lectionary-psalms.php` skips such citations from its pair/cross-locale check rather
than guessing, and reports how many it skipped so they stay visible instead of silently vanishing
from the failure count.

### Croatian (`hr`): open question

`hkm.hr` and `hilp.hr` could not be reached, so `hr` is recorded as bare Hebrew on the strength of
corpus-internal consistency only — every one of its rite-level citations already uses the bare form
and none carries a parenthetical, and #958 treated `hr` as an authoritative source for other
conventions in this corpus — **not** on a printed Croatian liturgical book. This is left unresolved
deliberately: nothing in the #973 data fix depends on it, since `hr` is not touched. If a printed
source is ever found and it disagrees with the bare-Hebrew form recorded here, `hr`'s convention (and
`scripts/lint-lectionary-psalms.php`'s `REQUIRED_PREFIX['hr']`) will need to change together.

## The lint

`composer lint:lectionary-psalms` (`scripts/lint-lectionary-psalms.php`) walks every `{locale}.json`
in this folder and checks every recognised psalm citation against the table above. It recognises
**all five** psalm prefixes the corpus uses (`Psalm`, `Salmo`, `Psalmo`, `Psaume`, `Ps`) in **every**
locale's file, not just that locale's own prefix — a locale-only matcher would find nothing in a file
that still holds another locale's numbering (as `nl.json` did for years) and pass having checked
nothing. It prints a per-locale citation count in its summary line for the same reason: a zero for a
locale that should have hundreds of citations is the signal that the matcher — or the file — is wrong.
