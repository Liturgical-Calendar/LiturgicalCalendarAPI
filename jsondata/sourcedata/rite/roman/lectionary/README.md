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

### The three whole-psalm `Ps 147` citations, resolved

Three keys in `feriale_tempus_nativitatis` cite Hebrew 147 with no verses at all, so the mapping
table alone cannot say whether they mean Vulgate 146 or 147. All three carry the same reading pair
(1 John 5:5-13 with Luke 5:12-16 or Mark 1:7-11 / Luke 3:23-38), and all three were resolved from
printed lectionaries to **Vulgate 147** — the *Lauda, Ierusalem* half of the Hebrew psalm:

| `event_key`              | source                                                                      | printed citation                                  |
|--------------------------|-----------------------------------------------------------------------------|---------------------------------------------------|
| `ChristmasWeekdayJan6`   | [AELF, 6 January 2024](https://www.aelf.org/2024-01-06/romain/messe)        | `Ps 147 (147b), 12-13, 14-15, 19-20`              |
| `DayAfterEpiphanyFriday` | [AELF, 10 January 2025](https://www.aelf.org/2025-01-10/romain/messe)       | `147 (147B), 12-13, 14-15, 19-20`                 |
| `DayAfterEpiphanyJan11`  | [CEI, 11 January 2025](https://www.chiesacattolica.it/liturgia-del-giorno/) | `Dal Sal 147` (*Celebra il Signore, Gerusalemme*) |

Because Hebrew 147:12-20 and Vulgate 147 carry the **same** number, `it`/`fr`/`nl` render these as
`Salmo 147 (147)` / `Psaume 147 (147)` / `Psalm 147 (147)`. The gloss is redundant but not wrong,
and it is what the convention rule produces: the aligned range where the gloss is dropped is 1–8 and
148–150, and 147 is not in it. Note that CEI itself prints a bare `Sal 147` here for that reason.
The citations still carry no verses, so the lint continues to count them among its skips.

### Findings this conversion turned up, and what was done with each

- **`en` held Vulgate numbers at three leaves.** `JesusChristEternalHighPriest/responsorial_psalm`
  read `Psalm 39` / `Psalm 109` / `Psalm 22` in years A/B/C while `hr` read `Ps 40` / `Ps 110` /
  `Ps 23`. The [England & Wales Liturgy Office lectionary for the
  feast](https://www.liturgyoffice.org.uk/Calendar/Sanctoral/May/OLJC-Lectionary.pdf) gives the
  **pairs** 40/39, 110/109 and 23/22 (its printed strings, re-fetched and extracted verbatim, are
  `Psalm 40(39):7-8a, 10-11ab, 17`, `Psalm 110(109):1b-e, 2, 3` and `Psalm 23 (22):2-3, 5, 6`).
  **What settles the question is pair membership, not print order**, which is just as well: other
  England & Wales material prints the same pairs the other way round — Universalis prints
  `Psalm 23(24)` for *Attollite portae*, and CJM's setting for this very feast's year C is
  "Psalm 22" with the refrain "The Lord is my shepherd" — so nothing here should be read as
  evidence of a house order. Either way the corpus's `39` / `109` / `22` are the Vulgate members
  of those pairs and `40` / `110` / `23` the Hebrew ones, corroborated by the texts themselves
  (year A cites verse 17, and Hebrew 39 has only 13 verses; year B is *Dixit Dominus Domino meo…
  tu es sacerdos in aeternum*; year C is *Dominus pascit me*). So `hr` was right and `en` was
  naming a different psalm. **Fixed** — `en` now carries 40, 110 and 23. This mattered beyond
  `en`: the lint derives `la`'s expected Vulgate number from `en`, so leaving it would have
  renumbered four locales onto the wrong psalm.
- **Four citations were spelled `Psalmus`** (`la` and `nl`, years B and C of the same feast).
  `Psalm\s+` does not match `Psalmus`, so the lint's regex could not see them at all and its
  per-locale counts read 128 for corpora of 130. They are now spelled per their locale's
  convention, **and `Psalmus` was added to the lint's recognised prefixes** — recognised precisely
  so that it is reported as a prefix no locale allows, rather than passing unseen. It is the Latin
  nominative and the form the *Nova Vulgata* prints, so reintroduction is likely rather than
  exotic. A `runSelfTest()` case pins it.
- **A `;`-joined continuation reference was left unconverted, in nine leaves.** The Easter Vigil's
  seventh psalm is *two* psalms in one citation, and only the first carries a prefix:
  `Psalm 42:3,5;43:3,4`. The first conversion pass read the leading reference and stopped, leaving
  `Psalmo 41:3,5;43:3,4` in `la` — where the bare `43` resolves to Hebrew 44 (*Deus, auribus
  nostris*) instead of Hebrew 43 (*Iudica me, Deus*) — and the equivalent in `it` and `fr`. `nl`'s
  was merely missing its gloss. **Fixed**: `la` `;42:3,4`, `it` `;42 (43), 3.4`, `fr` `;42 (43):3,4`,
  `nl` `;43 (42):3,4`, in all three `dominicale_et_festivum_*` sections. **A continuation reference
  follows exactly the same rule as the leading one** — that is now the corpus convention, and the
  lint enforces it (see below).
- **`fr`'s year C row for that feast is a verbatim duplicate of its own year B row** — first
  reading, psalm, second reading and gospel are all year B's. Pre-existing and a reading-level
  defect, not a numbering one, so it is **left alone**; its psalm was renumbered from year B's
  Hebrew (`Psaume 109 (110)`) rather than from `hr`'s year C value, which would have left the row
  internally contradictory.
- **`en`'s `ChristmasWeekdayJan5` row holds a different day's readings** than every other locale
  (`1 John 3:22—4:6` with `Matthew 4:12-17, 23-25`, against `hr`'s `1 Iv 3,11-21` / `Ps 100` /
  `Iv 1,43-51`). Also a reading-level defect and **left alone**; no other locale carries a citation
  at that leaf, so nothing is derived from it.

### Croatian (`hr`): open question

`hkm.hr` and `hilp.hr` could not be reached, so `hr` is recorded as bare Hebrew on the strength of
corpus-internal consistency only — every one of its rite-level citations already uses the bare form
and none carries a parenthetical, and #958 treated `hr` as an authoritative source for other
conventions in this corpus — **not** on a printed Croatian liturgical book. If a printed source is
ever found and it disagrees with the bare-Hebrew form recorded here, `hr`'s convention (and
`scripts/lint-lectionary-psalms.php`'s `REQUIRED_PREFIX['hr']`) will need to change together.

No `hr` file is modified by the #973 data fix, but the fix does **depend** on `hr` being bare
Hebrew: `hr` carries a citation at every leaf where `it`, `fr`, `nl` or `la` carries one, so it was
used as the Hebrew ground truth the converted numbers were derived from, cross-checked leaf by leaf
against `en`. That cross-check is what surfaced the three `en` leaves listed above. If `hr` ever
turns out not to be bare Hebrew, the converted numbers — not just `hr`'s own — would need revisiting.

## The lint

`composer lint:lectionary-psalms` (`scripts/lint-lectionary-psalms.php`) walks every `{locale}.json`
in this folder and checks every recognised psalm reference against the table above. It recognises
**six** psalm prefixes in **every** locale's file, not just that locale's own — a locale-only matcher
would find nothing in a file that still holds another locale's numbering (as `nl.json` did for years)
and pass having checked nothing. Five of the six (`Psalm`, `Salmo`, `Psalmo`, `Psaume`, `Ps`) are
valid for some locale; the sixth, `Psalmus`, is valid for none and is recognised so that it is
**reported** rather than skipped.

It also splits a citation into its **leading reference plus every `;`-joined continuation**
(`Psalm 42:3,5;43:3,4` is two references, and the second names its psalm without repeating the
prefix) and holds each to the same locale rule — for `la`, against that continuation's own
counterpart in `en`, not against the leading one. Anything after a `;` inside a psalm citation that
cannot be read as a psalm reference is **failed, not skipped**: silently dropping it is what let nine
wrong psalm numbers through. Only values that *begin* with a psalm citation are examined this way, so
a `;`-joined pair of gospel references such as `John 1:7; Luke 1:17` is never touched.

It prints a per-locale citation count and a continuation count in its summary line for the same
reason it recognises foreign prefixes: a zero for a locale that should have hundreds of citations —
or for a corpus that is known to hold two-psalm citations — is the signal that the matcher, or the
file, is wrong. `runSelfTest()` pins each of these rules and fails the whole run, distinctly from a
data violation, if any of them is relaxed.
