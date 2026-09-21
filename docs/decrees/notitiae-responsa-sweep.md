# Notitiae responsa sweep

The sweep asked for in [#986](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/986): the *Dubia* /
*Responsa* / *Responsum* sections of the 1965–2022 corpus, read against the rule now written into
`notitiae-transcription-guide.md` § *Responsa ad dubia*. This file is the pass's account of itself — every page it
opened, and why each one was or was not recorded, so that a later pass can tell a considered rejection from a page
nobody read.

## How the pages were selected

`scripts/notitiae/locate.py` gained a `heading:responsa` pattern, which selects **192** pages across 87 volumes, 79 of
them not selected by any earlier reason. Three further sets were added by hand:

- **4 pages** named on a volume's contents page as the responsa section but whose own heading the pattern misses.
- **101 pages** continuing a selected responsa section and carrying a calendar-decision marker — a grade word, a
  transfer or occurrence verb, a precedence or patron phrase.
- **37 pages** carrying a `Resp.` marker or a *Documentorum explanatio* heading together with calendar vocabulary.
  That last set is what turned up the 1970s replies below — and it matters that they were *already* selected by the
  pre-existing `keyword:kalendarium`. The gap those pages expose is the missing rule, not a missing locator signal.

That is 334 pages. A further 7 were opened while following a lead or bounding an item, for **341** in all.
**10** of them produced **8** new register entries; **331** were rejected.

106 pages are marked *(classified from the running head)* below: volume contents pages, volume indexes, Libreria
Editrice Vaticana advertisements, *Summarium Decretorum* listings, and further pages of a document whose opening page
was read. They were identified from their running head and section title rather than read in full. Every other page —
235 of them — was rendered from the PDF and read.

## What was added

Eight entries, all `kind: other`, `level: general`, `needs_review: true`:

- `N1965-NR-50-965` — Notitiae 2 (1966) 14: the liturgical texts of an anticipated evening Mass are those of the following Sunday or feast — the universal basis of the vigil
  Mass.
- `N1972-071-p45-1` — Notitiae 8 (1972) 103: the grade and day of the dedication anniversary of a cathedral, and of a church's own.
- `N1973-082-p37-1` — Notitiae 9 (1973) 151–152: which calendar religious with a proper calendar follow.
- `N1973-082-p38-1` — Notitiae 9 (1973) 152: a concathedral's dedication anniversary is not kept throughout the diocese.
- `N1974-093-094-p76-1` — Notitiae 10 (1974) 222–223: the anticipated evening Mass when a Sunday and a solemnity of precept concur, and the proper Vigil Masses that are said
  even on a Sunday.
- `N1975-102-p35-1` — Notitiae 11 (1975) 61: the cathedral's dedication anniversary may not be kept on a Sunday in Ordinary Time.
- `N2000-410-411-p9-1` — Notitiae 36 (2000) 407: which days a church may be dedicated on, and the precedence of the Anniversary of Dedication over the Title.
- `N2017-201-17` — Notitiae 53 (2017) 92–93: Prot. N. 201/17, on patronal celebrations, non-liturgical Marian titles and processions.

The last two are the issue's own "known starting points"; the other six were found by the sweep.

## What was re-read and left as it was

Seven entries the 1965–2022 survey had already made were re-opened under the new rule and confirmed: `N1969-047-p111-1`,
`N1969-048-p78-1`, `N1978-138-p57-1`, `N1978-147-p60-1`, `N1984-219-p25-1`, `N2009-513-514-p54-1` and
`N2020-597-NS-005-p248-1`. Their `level` values were left as the survey set them: the new rule governs what a later
pass records, and re-levelling settled judgements would churn the register for no gain.

The 2021 *Responsa ad dubia* on *Traditionis custodes* (Prot. N. 620/21, Notitiae 598, pdf 249–292, the same text in
five languages) is out of scope by the issue's own terms and stays unrecorded.

## Follow-up leads, out of scope here

- *Epistola de Calendariis particularibus atque Missarum et Officiorum Propriis recognoscendis*, Notitiae 10 (1974) 87.
  A general norm on revising particular calendars, named in the 1965–1975 cumulative index and absent from the
  register. It is a letter, not a reply to a dubium, so it falls outside this sweep.
- `locate.py` harvests page references only from an `INDEX VOLUMINIS` page, never from a volume's contents page. Four
  responsa pages were reachable only that way and had to be added by hand here.

## Every page opened

### Notitiae-005-1965 (1965)

- pdf 44, printed 136 — rejected: Documentorum explanatio DUBIA ad Instructionem: concelebration, aspersion, sung Ordinary parts. No calendar content.
- pdf 53, printed 145 — rejected: Libreria Editrice Vaticana advertisement page. *(classified from the running head)*

### Notitiae-006-1965 (1965)

- pdf 43, printed 185 — rejected: Documentorum explanatio DUBIA ad Instructionem: kisses of the hand, ring, vernacular in Masses cum populo. No calendar content.
- pdf 51, printed 193 — rejected: Libreria Editrice Vaticana advertisement page. *(classified from the running head)*

### Notitiae-007-008-1965 (1965)

- pdf 64, printed 252 — rejected: DUBIA ad Instructionem: prayer of the faithful, Hora Prima. No calendar content.
- pdf 67, printed 255 — rejected: DUBIA continuation: Communion rite, Missal placement, exposition; De ritu concelebrationis. No calendar content.
- pdf 71, printed 259 — rejected: Libreria Editrice Vaticana advertisement page. *(classified from the running head)*

### Notitiae-009-010-1965 (1965)

- pdf 51, printed 305 — rejected: DUBIA ad Instructionem: seminary Sunday Mass, celebration of the word. No calendar content.
- pdf 53, printed 307 — rejected: DUBIA continuation (Varia n. 87): which Mass to say at an anticipated evening Mass -- orientative reply only, superseded by the SCR letter
  N.R. 50/965 printed in Notitiae 13 (1966) 14, which is where it is recorded.

### Notitiae-011-1965 (1965)

- pdf 40, printed 358 — rejected: False positive: 'responsum' in the running prose of a missionary liturgical report.
- pdf 51, printed 369 — rejected: Libreria Editrice Vaticana advertisement page. *(classified from the running head)*

### Notitiae-013-1966 (1966)

- pdf 15, printed 14 — **recorded**: SCR letter N.R. 50/965, 25 Sep 1965, following up dubium 87 of 1965: anticipated Saturday-evening/vigil Masses satisfying the precept must
  use the liturgical texts of the following Sunday or feast.

### Notitiae-015-016-1966 (1966)

- pdf 15, printed 77 — rejected: Acta Consilii: whether religious may adopt the new order of Mass without a decree; then a study on historical lessons. No calendar ruling.

### Notitiae-017-1966 (1966)

- pdf 2, printed 120 — rejected: Volume contents page (Summarium); the Dubia it lists at printed 132 are read separately.
- pdf 14, printed 132 — rejected: SCR DUBIA: prayers at the foot of the altar; Communion under both kinds at religious profession. No calendar content.

### Notitiae-019-020-1966 (1966)

- pdf 2, printed 200 — rejected: Volume contents page.
- pdf 42, printed 240 — rejected: Documentorum explanatio DUBIA n. 98: singing the Mass in the vernacular. No calendar content.
- pdf 47, printed 245 — rejected: Varia article by a Capuchin calendarista proposing provisional harmonisation of two calendars; a study, not an act of the Congregation, and
  expressly provisional.

### Notitiae-021-022-1966 (1966)

- pdf 2, printed 248 — rejected: Volume contents page.
- pdf 43, printed 289 — rejected: Documentorum explanatio DUBIA nn. 99-100: altering approved vernacular texts; vernacular in the Divine Office. No calendar content.

### Notitiae-023-1966 (1966)

- pdf 2, printed 294 — rejected: Volume contents page.
- pdf 49, printed 341 — rejected: Documentorum explanatio DUBIA nn. 103-104: approval of Ordinary melodies; distributing Communion at the altar table. No calendar content.

### Notitiae-024-1966 (1966)

- pdf 38, printed 380 — rejected: Annual index for 1966. Its calendar items (the anticipated-Mass letter at printed 14, Festum S. Ioseph anno 1967 at printed 180) are already
  covered -- the latter is register entry N1966-018-p32-1.

### Notitiae-031-033-1967 (1967)

- pdf 82, printed 300 — rejected: Documentorum explanatio n. 105: whether the formulae dimissionis include the priest's blessing. No calendar content.

### Notitiae-042-1968 (1968)

- pdf 2, printed 264 — rejected: Volume contents page.
- pdf 4, printed 266 — rejected: Spanish-language Resumen of the issue. The calendar item it abstracts (printed 279, Epiphany/Ascension/Corpus Christi where transferred to
  Sunday) is register entry N1968-042-p17-1.
- pdf 7, printed 269 — rejected: False positive inside Paul VI's address on sacred music.
- pdf 9, printed 271 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 18, printed 280 — rejected: Responsa ad proposita dubia of the Pontifical Commission for interpreting Vatican II: status of episcopal conference norms after Christus
  Dominus. No calendar content.

### Notitiae-043-1968 (1968)

- pdf 68, printed 392 — rejected: Annual index for 1968; the responsa it lists are the Conferentiae Episcopales replies at printed 280, read and rejected.

### Notitiae-047-1969 (1969)

- pdf 109, printed 323 — rejected: Documentorum explanatio ad IGMR nn. 1-2: proper prefaces; Masses ad diversa on Christmas and Easter ferias. Restates IGMR 316/332-333 and
  Normae n. 16.
- pdf 110, printed 324 — rejected: Continuation of the same replies, plus Ad Ordinem Missae n. 3 (Mysterium fidei).
- pdf 111, printed 325 — rejected: First page of Documentorum explanatio, Ad Calendarium nn. 5-8 -- covered by register entry N1969-047-p111-1.
- pdf 112, printed 326 — rejected: Documentorum explanatio Ad Calendarium n. 8 (1970 day-by-day calendar where Epiphany/Ascension/Corpus Christi move to Sunday): already
  register entry N1969-047-p111-1, which spans printed 325-327.

### Notitiae-048-1969 (1969)

- pdf 24, printed 350 — rejected: Memoriale Domini: the bishops' replies on Communion in the hand. No calendar content.
- pdf 33, printed 359 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 34, printed 360 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 35, printed 361 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 78, printed 404 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 79, printed 405 — rejected: Second page of Documentorum explanatio nn. 16-21 -- covered by register entry N1969-048-p78-1.

### Notitiae-055-1970 (1970)

- pdf 37, printed 263 — rejected: Documentorum explanatio ad IGMR nn. 33-37: Gloria, altar consecration, sacred vessels, funeral Mass in the Christmas and Easter octaves. The
  last restates IGMR 336 without changing anything.

### Notitiae-059-1970 (1970)

- pdf 2, printed 372 — rejected: Volume contents page.

### Notitiae-062-1971 (1971)

- pdf 39, printed 112 — rejected: DUBIA ad IGMR: orations in Masses of memorials; whether the Creed is said in the Easter octave. Both restate IGMR 323/44 and Normae n. 12
  without changing anything.
- pdf 40, printed 113 — rejected: Libreria Editrice Vaticana book advertisement.

### Notitiae-071-1972 (1972)

- pdf 45, printed 103 — **recorded**: Documentorum explanatio, De celebratione annuali Dedicationis ecclesiae (Notitiae 8 [1972] 103): the cathedral's dedication anniversary is
  a solemnity in the cathedral and a feast in every other church of the diocese, moved to the nearest free day when perpetually impeded; a church's own dedication anniversary
  is a solemnity whose day is chosen once for all.
- pdf 46, printed 104 — rejected: Bibliographica / In nostra familia; read to confirm the dedication item ends on printed 103.

### Notitiae-074-1972 (1972)

- pdf 4, printed 166 — rejected: Spanish Sumario page; the Documentorum explanatio items it abstracts are not calendar matters.

### Notitiae-076-1972 (1972)

- pdf 2, printed 244 — rejected: Volume contents page.
- pdf 39, printed 281 — rejected: Responsum of the Pontifical Commission on the minister's gesture at Confirmation. No calendar content.

### Notitiae-077-1972 (1972)

- pdf 19, printed 317 — rejected: Commentary on the CDF norms for general absolution. No calendar content.

### Notitiae-078-1972 (1972)

- pdf 2, printed 340 — rejected: Volume contents page.
- pdf 32, printed 370 — rejected: Responsum circa homiliam: whether a lay person may preach the homily. No calendar content.
- pdf 38, printed 376 — rejected: Study article on Sunday celebrations in the absence of a priest. No calendar content.
- pdf 46, printed 384 — rejected: Index voluminis VIII (1972), first page.
- pdf 53, printed 391 — rejected: Index voluminis VIII (1972). Its Documentorum explanatio list led to printed 102-103, De celebratione annuali dedicationis ecclesiae, which is
  recorded.

### Notitiae-082-1973 (1973)

- pdf 37, printed 151 — **recorded**: Documentorum explanatio, De calendariis particularibus (Notitiae 9 [1973] 151-152): which calendar religious with a proper calendar follow
  for Mass and the Hours.
- pdf 38, printed 152 — **recorded**: Second half of the same responsum, plus De dedicatione ecclesiae: a concathedral's dedication anniversary is NOT celebrated throughout the
  diocese (Negative), recorded as its own entry.

### Notitiae-088-1973 (1973)

- pdf 37, printed 423 — rejected: Index voluminis IX (1973); confirms the volume's only calendar Documentorum explanatio items are printed 151 and 152, both recorded.

### Notitiae-092-1974 (1974)

- pdf 2, printed 108 — rejected: Volume contents page.

### Notitiae-093-094-1974 (1974)

- pdf 2, printed 148 — rejected: Volume contents page.
- pdf 76, printed 222 — **recorded**: Documentorum explanatio, De Missa diei dominicae et festi de praecepto vespere diei praecedentis anticipata (Notitiae 10 [1974] 222-223):
  how an anticipated evening Mass is settled when two liturgical days concur, including the rule that the proper Vigil Masses (Christmas, John the Baptist, Peter and Paul,
  Assumption) are said even on a Sunday.
- pdf 77, printed 223 — **recorded**: Second page of the same entry.

### Notitiae-102-1975 (1975)

- pdf 2, printed 28 — rejected: Volume contents page.
- pdf 35, printed 61 — **recorded**: Documentorum explanatio, Ad Calendarium (Notitiae 11 [1975] 61): the anniversary of the dedication of the cathedral may NOT be kept on a
  Sunday per annum -- a proper feast of the Lord does not outrank a Sunday, only feasts of the Lord in the general Calendar do.

### Notitiae-106-107-1975 (1975)

- pdf 14, printed 172 — rejected: Responsa ad proposita dubia of the Pontifical Commission: the minister of Confirmation. No calendar content.
- pdf 20, printed 178 — rejected: AELF editorial rules for French liturgical texts. No calendar content.

### Notitiae-110-1975 (1975)

- pdf 38, printed 288 — rejected: Documentorum explanatio, Quae Missa celebranda est vespere diei 7 decembris 1975: the reply simply refers the reader back to Notitiae 10
  (1974) 222-223, changing nothing.

### Notitiae-111-112-1975 (1975)

- pdf 80, printed 362 — rejected: Index voluminis XI (1975); its two Ad Calendarium leads are printed 61 (recorded) and 288 (read and rejected).

### Notitiae-113-1976-indice-1965-1975 (1976)

- pdf 18, printed 24 — rejected: Cumulative index, SS. Congregationes, Calendarium - Festa et Propria particularia. Every general calendar document it lists is already a
  register entry except the Epistola de Calendariis particularibus of Feb 1974 (Notitiae 10 [1974] 87), which is not a reply to a dubium and so falls outside this sweep;
  flagged as a follow-up lead.
- pdf 25, printed 31 — rejected: Cumulative index, S. Congr. pro Cultu Divino (Actuositas).
- pdf 26, printed 32 — rejected: Volume index page. *(classified from the running head)*
- pdf 28, printed 34 — rejected: Volume index page. *(classified from the running head)*
- pdf 29, printed 35 — rejected: Cumulative index, S. C. pro Sacramentis et Cultu Divino, Summarium Decretorum.
- pdf 35, printed 41 — rejected: Cumulative index, Commissiones. Every Pontifical Commission responsum it lists (episcopal conferences, homily, Confirmation, deacons'
  faculties) is non-calendar; each was read separately in this sweep.
- pdf 94, printed 100 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 174, printed 180 — rejected: Cumulative index 1965-1975, Index nominum et rerum (Communio).
- pdf 176, printed 182 — rejected: Cumulative index (Conferentiae Episcopales, Confirmatio).
- pdf 178, printed 184 — rejected: Cumulative index (Cor Iesu, Corporis et Sanguinis Christi festum). Its calendar leads at 5 (1969) 326 and 404 are register entries
  N1969-047-p111-1 and N1969-048-p78-1.
- pdf 179, printed 185 — rejected: Volume index page. *(classified from the running head)*
- pdf 180, printed 186 — rejected: Volume index page. *(classified from the running head)*
- pdf 181, printed 187 — rejected: Cumulative index (Ecclesia). Leads: De celebratione annuali Dedicationis ecclesiae 8 (1972) 103 -- newly recorded; An celebrandum sit in tota
  dioecesi anniversarium Dedicationis ecclesiae concathedralis 9 (1973) 152 -- newly recorded.
- pdf 182, printed 188 — rejected: Cumulative index (Ecclesiae locales, Epiphaniae sollemnitas). Lead: De calendariis particularibus 9 (1973) 151 -- newly recorded.
- pdf 184, printed 190 — rejected: Volume index page. *(classified from the running head)*
- pdf 185, printed 191 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 186, printed 192 — rejected: Volume index page. *(classified from the running head)*

### Notitiae-114-1976 (1976)

- pdf 2, printed -3 — rejected: Volume contents page.
- pdf 51, printed 46 — rejected: Documentorum explanatio: which readings are used in the Office of saints; the deacon's role in communities. Office texts, not the calendar.

### Notitiae-121-122-1976 (1976)

- pdf 70, printed 356 — rejected: Numbered list of documents published in the review; no act.

### Notitiae-137-1977 (1977)

- pdf 2, printed 548 — rejected: Volume contents page.
- pdf 55, printed 601 — rejected: Documentorum explanatio ad Ordinem benedictionis Abbatis: whether a titular abbot may receive the abbatial blessing. No calendar content.

### Notitiae-138-1978 (1978)

- pdf 2, printed -3 — rejected: Volume contents page.
- pdf 11, printed 6 — rejected: CDF responsum on general sacramental absolution (Prot. N. 274/64). No calendar content.
- pdf 16, printed 11 — rejected: Summarium Decretorum: confirmations of vernacular texts. No calendar content.
- pdf 57, printed 52 — rejected: Dubia, Ad diem celebrationis B.M.V. Reginae Apostolorum -- already register entry N1978-138-p57-1; re-read under the new rule and its recording
  confirmed.

### Notitiae-143-144-1978 (1978)

- pdf 2, printed 232 — rejected: Volume contents page.
- pdf 75, printed 305 — rejected: Documentorum explanatio: who concludes the eucharistic prayer's doxology. No calendar content.
- pdf 76, printed 306 — rejected: Same section: the Agnus Dei and the concluding rites. No calendar content.
- pdf 79, printed 309 — rejected: Nuntia article on liturgical piety. False positive.

### Notitiae-147-1978 (1978)

- pdf 60, printed 499 — rejected: Varia, CORRIGENDA, B. Calendarium proprium O.S.B. (Notitiae 14 [1978] 499-500): a dropped line in the Thesaurus reprint omitted the memorial
  of St Paulinus of Nola on 22 June; the calendar confirmed under Prot. CD 1415/76 and printed in the Proprium Missarum pp. 5-11 is the one to follow. Already register entry
  N1978-147-p60-1; re-read under the new rule and its recording confirmed.
- pdf 61, printed 500 — rejected: Second page of the same corrigendum, covered by the same entry.
- pdf 62, printed 501 — rejected: Libreria Editrice Vaticana advertisement; read to bound the corrigendum.

### Notitiae-149-1978 (1978)

- pdf 2, printed 552 — rejected: Volume contents page.
- pdf 44, printed 594 — rejected: Documentorum explanatio: the chalice veil; mentioning the saint of the day in Eucharistic Prayer III. Not a calendar ruling.
- pdf 45, printed 595 — rejected: Second page of the same replies.
- pdf 51, printed 601 — rejected: Index voluminis XIV (1978), first page.
- pdf 52, printed 602 — rejected: Index voluminis XIV (1978), Conferentiae Episcopales.
- pdf 60, printed 610 — rejected: Libreria Editrice Vaticana advertisement page.

### Notitiae-166-1980 (1980)

- pdf 2, printed 200 — rejected: Volume contents page.

### Notitiae-168-169-170-1980 (1980)

- pdf 2, printed 348 — rejected: Volume contents page.
- pdf 127, printed 473 — rejected: Documentorum explanatio, De Officiis votivis: whether religious may keep a votive Office in place of the Office of the day. Restates IGLH nn.
  245 and 235-236; concerns the Office, not the calendar.

### Notitiae-174-1981 (1981)

- pdf 18, printed 13 — rejected: Instruction on infant baptism, Pars secunda 'Responsa ad difficultates'. No calendar content.

### Notitiae-193-194-1982 (1982)

- pdf 26, printed 408 — rejected: Studia article on the concelebrants' gesture at the consecration. No calendar content.
- pdf 32, printed 414 — rejected: Studia article page; no calendar ruling. *(classified from the running head)*

### Notitiae-204-205-1983 (1983)

- pdf 158, printed 518 — rejected: Index voluminis XIX (1983).
- pdf 169, printed 529 — rejected: Libreria Editrice Vaticana advertisement page. *(classified from the running head)*

### Notitiae-207-1983 (1983)

- pdf 60, printed 670 — rejected: Celebrationes particulares: biography of Bl. Ursula Ledochowska. False positive ('responsum' in prose).
- pdf 63, printed 673 — rejected: Continuation of the same biography.

### Notitiae-211-1984 (1984)

- pdf 2, printed 76 — rejected: Volume contents page.

### Notitiae-212-1984 (1984)

- pdf 2, printed 168 — rejected: Volume contents page.

### Notitiae-219-1984 (1984)

- pdf 2, printed 584 — rejected: Volume contents page.
- pdf 20, printed 602 — rejected: Responsa ad proposita dubia of the Pontifical Commission for the Code: receiving Communion twice in one day (can. 917). No calendar content.
- pdf 23, printed 605 — rejected: Summarium Decretorum (Calendaria particularia, Familiae religiosae) -- ordinary decrees already covered by the 1965-2022 survey, not a
  responsum.
- pdf 25, printed 607 — rejected: De Calendario liturgico exarando pro anno 1984-1985 -- already register entry N1984-219-p25-1; re-read under the new rule and its recording
  confirmed.
- pdf 27, printed 609 — rejected: Last page of De Calendario liturgico 1984-1985 -- covered by register entry N1984-219-p25-1 (printed 603-605).

### Notitiae-256-1987 (1987)

- pdf 2, printed 1134 — rejected: Volume contents page.
- pdf 14, printed 1146 — rejected: Responsum ad dubium circa homiliam (can. 767 §1). No calendar content.
- pdf 18, printed 1150 — rejected: Summarium Decretorum: confirmations of vernacular texts.
- pdf 21, printed 1153 — rejected: Summarium Decretorum: ordinary decrees already covered by the 1965-2022 survey. *(classified from the running head)*
- pdf 22, printed 1154 — rejected: Summarium Decretorum (basilica titles, shrine votive Masses, decreta varia) -- ordinary decrees already covered by the 1965-2022 survey.

### Notitiae-257-1987 (1987)

- pdf 88, printed 1292 — rejected: Index voluminis XXIII (1987). Its calendar lead, De Calendario Liturgico exarando pro Anno 1989 at printed 397, is register entries
  N1987-251-p67-1 and -2.
- pdf 89, printed 1293 — rejected: Index voluminis XXIII (1987), Summarium Decretorum section.

### Notitiae-267-1988 (1988)

- pdf 2, printed 664 — rejected: Volume contents page.
- pdf 7, printed 669 — rejected: Responsa ad proposita dubia: the extraordinary minister of Communion (cann. 910 §2, 230 §3). No calendar content.

### Notitiae-297-1991 (1991)

- pdf 47, printed 213 — rejected: Associationes: a French text on presiding at liturgical assemblies. False positive.

### Notitiae-352-1995 (1995)

- pdf 2, printed 588 — rejected: Volume contents page.
- pdf 12, printed 598 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 24, printed 610 — rejected: CDF Responsum ad dubium on Ordinatio sacerdotalis. No calendar content.

### Notitiae-353-1995 (1995)

- pdf 76, printed 742 — rejected: Index voluminis XXXI (1995).

### Notitiae-364-365-1996 (1996)

- pdf 267, printed 1042 — rejected: Index voluminis XXXII (1996).

### Notitiae-366-368-1997 (1997)

- pdf 2, printed 1 — rejected: Volume contents page.
- pdf 32, printed 31 — rejected: Responsa ad dubia proposita: the tabernacle key; where the chalice is purified. No calendar content.

### Notitiae-369-371-1997 (1997)

- pdf 2, printed 112 — rejected: Volume contents page.
- pdf 28, printed 138 — rejected: Responsa ad dubia proposita: whether the priest may omit a presidential prayer. No calendar content.
- pdf 31, printed 141 — rejected: Pontifical Council for Legislative Texts, nota explicativa on general absolution (can. 961). No calendar content.

### Notitiae-372-374-1997 (1997)

- pdf 2, printed 272 — rejected: Volume contents page.
- pdf 6, printed 276 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 52, printed 322 — rejected: Responsa ad dubia proposita: the paten, its blessing and use. No calendar content.

### Notitiae-375-377-1997 (1997)

- pdf 2, printed 465 — rejected: Volume contents page.
- pdf 56, printed 519 — rejected: Questionnaire for admission to Orders; read because the volume index names printed 519 as a responsa page and the section starts on the facing
  page.
- pdf 57, printed 520 — rejected: Responsa ad dubia proposita: concessions to the Neocatechumenal Way. No calendar content.
- pdf 188, printed 651 — rejected: Index voluminis XXXIII (1997).

### Notitiae-378-379-1998 (1998)

- pdf 59, printed 59 — rejected: Responsa ad dubia proposita: the ambo. No calendar content.

### Notitiae-380-381-1998 (1998)

- pdf 2, printed 112 — rejected: Volume contents page.
- pdf 22, printed 132 — rejected: English version of the Neocatechumenate responsum of 1997. No calendar content.

### Notitiae-382-383-1998 (1998)

- pdf 2, printed 240 — rejected: Volume contents page.
- pdf 34, printed 272 — rejected: Responsa ad dubia proposita: self-communication by intinction in religious communities. No calendar content.

### Notitiae-386-387-1998 (1998)

- pdf 2, printed 528 — rejected: Volume contents page.
- pdf 18, printed 544 — rejected: Apostolos suos (Latin); the responsum in the footnote is on auxiliary bishops' votes. No calendar content.
- pdf 39, printed 565 — rejected: Apostolos suos (Italian), same footnote.
- pdf 63, printed 589 — rejected: Responsa ad dubia proposita: decorating vestments with non-liturgical symbols. No calendar content.

### Notitiae-388-389-1998 (1998)

- pdf 61, printed 667 — rejected: Index voluminis XXXIV (1998); confirms the volume's four responsa pages (59, 132, 272, 590) are all in this sweep.

### Notitiae-390-391-1999 (1999)

- pdf 42, printed 41 — rejected: Responsa ad dubia proposita: kneeling at the consecration. No calendar content.

### Notitiae-392-393-1999 (1999)

- pdf 2, printed 96 — rejected: Volume contents page.
- pdf 11, printed 105 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 12, printed 106 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 66, printed 160 — rejected: Responsa ad dubia proposita: the diocesan administrator and Confirmation; Communion in the hand. No calendar content.
- pdf 67, printed 161 — rejected: Second page of the same replies.

### Notitiae-396-397-1999 (1999)

- pdf 2, printed 304 — rejected: Volume contents page.
- pdf 5, printed 307 — rejected: Responsa officialia Prot. n. 1411/99 on the 1962 Missal indult. Mentions calendar differences as a fact; rules nothing about the calendar.
- pdf 6, printed 308 — rejected: Second page of the same responsa.
- pdf 7, printed 309 — rejected: Third page of the same responsa.
- pdf 9, printed 311 — rejected: Italian version of the same responsa.
- pdf 17, printed 319 — rejected: Summarium Decretorum, Concessiones circa Calendaria -- ordinary decrees already covered by the 1965-2022 survey.
- pdf 18, printed 320 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-398-399-1999 (1999)

- pdf 2, printed 400 — rejected: Volume contents page.
- pdf 11, printed 409 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 58, printed 456 — rejected: Responsa ad dubia proposita: altar cloths in the offertory procession. No calendar content.
- pdf 67, printed 465 — rejected: Pontifical Council for Legislative Texts responsum on the confessional grille (can. 964 §2). No calendar content.
- pdf 68, printed 466 — rejected: Same council's responsum on 'abicere' (can. 1367). No calendar content.

### Notitiae-400-401-1999 (1999)

- pdf 60, printed 570 — rejected: Index voluminis XXXV (1999). Its calendar lead, the notification on the occurrence of the Immaculate Heart at printed 157, is register entry
  N1998-2671-98-L.
- pdf 65, printed 575 — rejected: Volume index page. *(classified from the running head)*

### Notitiae-404-405-2000 (2000)

- pdf 12, printed 74 — rejected: Abbreviation table of the Apostolic Penitentiary's Enchiridion indulgentiarum ('Resp. = Responsum'). False positive.
- pdf 18, printed 80 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-408-409-2000 (2000)

- pdf 2, printed 288 — rejected: Volume contents page.
- pdf 34, printed 320 — rejected: Responsa ad dubia proposita: concelebrating in choir dress without an alb. No calendar content.
- pdf 42, printed 328 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 43, printed 329 — rejected: Summarium Decretorum: ordinary decrees already covered by the 1965-2022 survey. *(classified from the running head)*
- pdf 44, printed 330 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 45, printed 331 — rejected: Summarium Decretorum: ordinary decrees already covered by the 1965-2022 survey. *(classified from the running head)*

### Notitiae-410-411-2000 (2000)

- pdf 2, printed 400 — rejected: Volume contents page.
- pdf 9, printed 407 — **recorded**: Responsa ad dubia proposita, Quibus in diebus ecclesiam dedicare convenit? (Notitiae 36 [2000] 407) -- the 2000 dedication responsum the
  issue names. Beyond restating Ordo Dedicationis n. 7 it rules that where the solemnity of the Title and the Anniversary of Dedication would be united, the Table of Liturgical
  Days always gives the Anniversary of Dedication precedence over the Title.
- pdf 10, printed 408 — rejected: CDF Declaration Dominus Iesus; read to bound the dedication responsum to a single page.
- pdf 13, printed 411 — rejected: CDF Declaration Dominus Iesus; no calendar content. *(classified from the running head)*
- pdf 15, printed 413 — rejected: CDF Declaration Dominus Iesus; no calendar content. *(classified from the running head)*

### Notitiae-412-413-2000 (2000)

- pdf 2, printed 512 — rejected: Volume contents page.
- pdf 31, printed 541 — rejected: Responsa ad dubia proposita: what to do with the Precious Blood remaining after Communion. No calendar content.
- pdf 36, printed 546 — rejected: Studia article on J.-P. Migne. False positive.
- pdf 62, printed 572 — rejected: Volume index page. *(classified from the running head)*

### Notitiae-414-415-2001 (2001)

- pdf 2, printed 1 — rejected: Volume contents page.
- pdf 13, printed 12 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 19, printed 18 — rejected: Responsa ad dubia proposita: whether a tabernacle may be made of glass. No calendar content.
- pdf 20, printed 19 — rejected: Second page of the same reply.
- pdf 25, printed 24 — rejected: CDF text on the pastoral care of the sick. False positive.

### Notitiae-418-2001 (2001)

- pdf 2, printed 176 — rejected: Volume contents page.
- pdf 16, printed 190 — rejected: Responsa ad quaestiones circa obligationem persolvendi Liturgiam Horarum (Prot. N. 2330/00/L). Concerns the obligation and hours of the
  Office, not the calendar.
- pdf 17, printed 191 — rejected: Second page of the same replies.
- pdf 19, printed 193 — rejected: Fourth page of the same replies (veritas temporis of the hours).

### Notitiae-419-420-2001 (2001)

- pdf 2, printed 240 — rejected: Volume contents page.
- pdf 21, printed 259 — rejected: Responsa ad dubia proposita: when the sacrament of Penance may be celebrated. No calendar content.
- pdf 28, printed 266 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 29, printed 267 — rejected: Summarium Decretorum: ordinary decrees already covered by the 1965-2022 survey. *(classified from the running head)*
- pdf 30, printed 268 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-421-422-2001 (2001)

- pdf 95, printed 397 — rejected: Litterae Congregationis Prot. N. 2451/00/L on women and girls as altar servers; read because the 2001 index lists printed 397 among the
  responsa. No calendar content.

### Notitiae-424-425-2001 (2001)

- pdf 63, printed 557 — rejected: Index voluminis XXXVII (2001); confirms the volume's four responsa pages (18, 190, 259, 397) are all in this sweep.
- pdf 68, printed 562 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-434-2002 (2002)

- pdf 2, printed 424 — rejected: Volume contents page.
- pdf 11, printed 433 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 68, printed 490 — rejected: Responsa ad dubia proposita: self-intinction by the faithful. No calendar content.
- pdf 69, printed 491 — rejected: Same section: first Communion at the Mass of the Lord's Supper. Pastoral advice on choosing a day, with no ruling on rank, date or transfer.
- pdf 70, printed 492 — rejected: Same section: a table with bread and wine in the body of the church. No calendar content.

### Notitiae-435-2002 (2002)

- pdf 7, printed 509 — rejected: Misericordia Dei (Latin), citing the 2001 responsa in a footnote. No calendar content.
- pdf 29, printed 531 — rejected: Misericordia Dei (English), same footnote.
- pdf 37, printed 539 — rejected: Motu proprio Misericordia Dei; no calendar content. *(classified from the running head)*

### Notitiae-436-2002 (2002)

- pdf 72, printed 637 — rejected: Index voluminis XXXVIII (2002); its single responsa page (490) is in this sweep.
- pdf 76, printed 641 — rejected: Libreria Editrice Vaticana advertisement for the Martyrologium Romanum editio typica.
- pdf 77, printed 642 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-445-446-2003 (2003)

- pdf 119, printed 533 — rejected: Responsa ad dubia proposita: kneeling or sitting after Communion. No calendar content.
- pdf 121, printed 535 — rejected: Studia article page; no calendar ruling. *(classified from the running head)*

### Notitiae-447-448-2003 (2003)

- pdf 81, printed 639 — rejected: Index voluminis XXXIX (2003); its single responsa page (533) is in this sweep.

### Notitiae-451-452-2004 (2004)

- pdf 79, printed 157 — rejected: Redemptionis Sacramentum nn. 76-77, citing the 2001 responsa in a footnote. Not itself a reply.
- pdf 82, printed 160 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-459-460-2004 (2004)

- pdf 97, printed 647 — rejected: Index voluminis XL (2004); its single responsa page (533, carried over from 2003) is in this sweep.
- pdf 99, printed 649 — rejected: Martyrologium Romanum variations / colophon page. *(classified from the running head)*

### Notitiae-461-462-2005 (2005)

- pdf 26, printed 24 — rejected: Variationes in Martyrologium Romanum, editio typica altera -- already register entry N2004-1140-04-L. Matched only on 'dubia' in the preamble.
- pdf 27, printed 25 — rejected: Martyrologium Romanum variations / colophon page. *(classified from the running head)*

### Notitiae-467-468-2005 (2005)

- pdf 94, printed 380 — rejected: Bibliographica: a cumulative list of Notitiae articles, with a Dubia sub-heading listing questions about the Sanctus. Not an act.

### Notitiae-479-480-2006 (2006)

- pdf 122, printed 376 — rejected: Pontifical Council for Legislative Texts, nota on the nature of recognitio. No calendar content.

### Notitiae-481-482-2006 (2006)

- pdf 111, printed 493 — rejected: Actuositas liturgica: USCCB questions on adoration of the Blessed Sacrament. No calendar content.
- pdf 112, printed 494 — rejected: Second page of the same questions.
- pdf 113, printed 495 — rejected: Third page of the same questions.

### Notitiae-487-488-2007 (2007)

- pdf 120, printed 182 — rejected: Responsa ad dubia proposita: exposing the Precious Blood for adoration. No calendar content.
- pdf 121, printed 183 — rejected: Second page of the same reply.
- pdf 132, printed 194 — rejected: Martyrologium Romanum variations / colophon page. *(classified from the running head)*

### Notitiae-491-492-2007 (2007)

- pdf 2, printed 320 — rejected: Volume contents page.
- pdf 67, printed 385 — rejected: CDF Responsa ad quaestiones on the doctrine of the Church. No calendar content.
- pdf 68, printed 386 — rejected: Second page of the same responsa.
- pdf 69, printed 387 — rejected: Third page of the same responsa.
- pdf 71, printed 389 — rejected: Fifth page of the same responsa.

### Notitiae-495-496-2007 (2007)

- pdf 64, printed 638 — rejected: Volume index page. *(classified from the running head)*
- pdf 65, printed 639 — rejected: Volume index page. *(classified from the running head)*
- pdf 67, printed 641 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*

### Notitiae-499-500-2008 (2008)

- pdf 72, printed 134 — rejected: CDF Responsa ad proposita dubia on the validity of baptism with non-Trinitarian formulas. No calendar content.

### Notitiae-507-508-2008 (2008)

- pdf 2, printed 575 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 4, printed 577 — rejected: Biographical note on the new Prefect. False positive.
- pdf 31, printed 604 — rejected: Benedict XVI's Christmas address; read because the volume contents page names printed 604 on the responsa leader line. Not a reply.
- pdf 36, printed 609 — rejected: Responsa ad dubia proposita: when the celebrating priest communicates. No calendar content.
- pdf 64, printed 637 — rejected: Volume index page. *(classified from the running head)*
- pdf 65, printed 638 — rejected: Volume index page. *(classified from the running head)*
- pdf 68, printed 641 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-511-512-2009 (2009)

- pdf 2, printed 64 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 8, printed 70 — rejected: Prefect's address to the 2009 Plenaria. No calendar ruling.
- pdf 108, printed 170 — rejected: Responsa ad dubia proposita: a bishop concelebrating at a priest's jubilee. No calendar content.
- pdf 109, printed 171 — rejected: Responsa ad dubia proposita: concelebrants taking chalices at the doxology. No calendar content.

### Notitiae-513-514-2009 (2009)

- pdf 2, printed 192 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 52, printed 242 — rejected: Responsa ad dubia proposita: when the celebrating priest communicates (reprint of the 2008 reply). No calendar content.
- pdf 53, printed 243 — rejected: Second page of the same reply.
- pdf 54, printed 244 — rejected: Responsa ad dubia proposita on the number and rank of patrons -- already register entry N2009-513-514-p54-1; re-read under the new rule and
  its recording confirmed.

### Notitiae-519-520-2009 (2009)

- pdf 2, printed 576 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 11, printed 585 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 48, printed 622 — rejected: Responsa ad dubia proposita: handing the crosier to a new bishop at his entry into the diocese. No calendar content.
- pdf 58, printed 632 — rejected: Studia article on the kingship of Christ. False positive.
- pdf 65, printed 639 — rejected: Volume index page. *(classified from the running head)*
- pdf 67, printed 641 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-547-548-2012 (2012)

- pdf 2, printed 64 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 8, printed 70 — rejected: Benedict XVI's letter on 'pro multis'; read because the volume contents page names printed 70 on the responsa leader line. Not a reply.
- pdf 108, printed 170 — rejected: Responsa ad dubia proposita: deacons renewing promises at the chrism Mass. No calendar content.
- pdf 109, printed 171 — rejected: Second page of the same reply.

### Notitiae-555-556-2012 (2012)

- pdf 65, printed 639 — rejected: Volume index page. *(classified from the running head)*
- pdf 68, printed 642 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-565-566-2013 (2013)

- pdf 57, printed 503 — rejected: Studia article on the monastic Office. False positive.
- pdf 58, printed 504 — rejected: Studia article page; no calendar ruling. *(classified from the running head)*
- pdf 68, printed 514 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-569-570-2014 (2014)

- pdf 2, printed 0 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 52, printed 50 — rejected: Responsa ad dubia proposita: whether the Mass formulary 'pro pace' may be used on 1 January. Applies IGMR 374/376 and the formularies' own
  rubric to a case; concerns the Mass formulary, not the rank, date or transfer of the solemnity.

### Notitiae-579-580-2014 (2014)

- pdf 130, printed 640 — rejected: Index voluminis L (2014); its single responsa page (50) is in this sweep.
- pdf 131, printed 641 — rejected: Missale Romanum colophon / back-matter page. *(classified from the running head)*

### Notitiae-594-NS-002-2017 (2017)

- pdf 2, printed 0 — rejected: Volume contents page.
- pdf 94, printed 92 — **recorded**: Responsa circa feste e pieta popolare, Prot. N. 201/17 -- the reply the issue names. On when a patronal or titular celebration is observed:
  the liturgical celebration keeps its own date, a non-liturgical Marian title may not be celebrated with a grade or liturgical texts, and Sundays of Easter outrank every
  solemnity; only the devotional procession may be moved.
- pdf 95, printed 93 — **recorded**: Second page of the same reply.

### Notitiae-596-NS-004-2019 (2019)

- pdf 2, printed 0 — rejected: Volume masthead / contents page. *(classified from the running head)*
- pdf 3, printed 1 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 5, printed 3 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 7, printed 5 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 11, printed 9 — rejected: Decree De celebratione sancti Pauli VI (Prot. N. 29/19) -- already register entry N2019-29-19. A decree, not a reply.
- pdf 13, printed 11 — rejected: Closing page of the St Paul VI decree -- covered by register entry N2019-29-19.
- pdf 151, printed 149 — rejected: Responsa, Prot. N. 150/19: whether a cathedral may be given the title of minor basilica. Basilica titles are never recorded (transcription
  guide).

### Notitiae-597-NS-005-2020 (2020)

- pdf 2, printed 0 — rejected: Volume contents page.
- pdf 3, printed 1 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 4, printed 2 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 5, printed 3 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 6, printed 4 — rejected: Multilingual abstract / contents page. *(classified from the running head)*
- pdf 12, printed 10 — rejected: Acta / decree page of the Congregation; no responsum. *(classified from the running head)*
- pdf 13, printed 11 — rejected: Decree Liturgia in tempo di Covid-19 on Holy Week 2020: emergency provisions for how the rites are carried out. Not a reply to a dubium, and it
  changes no rank, date or transfer (the chrism Mass is left to the conferences).
- pdf 248, printed 246 — rejected: Responsum ad dubia de Calendario liturgico exarando pro anno 2022 -- already register entry N2020-597-NS-005-p248-1; re-read under the new
  rule and its recording confirmed.
- pdf 249, printed 247 — rejected: Second page of the same responsum, covered by the same entry.

### Notitiae-598-NS-006-2021 (2021)

- pdf 4, printed -142 — rejected: Volume contents page; it names the Responsa section at printed 249 as the Traditionis custodes replies.
- pdf 249, printed 103 — rejected: Responsa ad dubia on Traditionis custodes, Prot. N. 620/21 -- out of scope by the issue's own terms. Read visually; the block's extent (pdf
  249-292, the same text in five languages) was then confirmed from the running head on every page.
- pdf 250, printed 104 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 252, printed 106 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 254, printed 108 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 256, printed 110 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 258, printed 112 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 260, printed 114 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 262, printed 116 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 264, printed 118 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 266, printed 120 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 267, printed 121 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 268, printed 122 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 270, printed 124 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 272, printed 126 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 274, printed 128 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 276, printed 130 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 278, printed 132 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 280, printed 134 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 282, printed 136 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 284, printed 138 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 285, printed 139 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 286, printed 140 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 288, printed 142 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 290, printed 144 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 292, printed 146 — rejected: Part of the Traditionis custodes Responsa ad dubia block (Prot. N. 620/21, pdf 249-292) -- out of scope by the issue's own terms; block
  identity and extent confirmed from the running head on every page. *(classified from the running head)*
- pdf 301, printed 155 — rejected: Studia article on Communion in the hand; read to bound the Traditionis custodes block.

### Notitiae-599-NS-007-2022 (2022)

- pdf 5, printed 3 — rejected: Summarium Decretorum: ordinary decrees already covered by the 1965-2022 survey. *(classified from the running head)*
- pdf 6, printed 4 — rejected: Papal acts/address page; no calendar ruling. *(classified from the running head)*
- pdf 460, printed 458 — rejected: Responsa ad dubia to the Dutch Bishops' Conference on copyright in vernacular liturgical books (Prot. N. 585/22). No calendar content.
- pdf 461, printed 459 — rejected: Further page of a Responsa ad dubia item already read on its opening page. *(classified from the running head)*
- pdf 462, printed 460 — rejected: French close and Italian opening of the same copyright reply (Prot. N. 585/22).
- pdf 463, printed 461 — rejected: Italian version of the same reply.
- pdf 466, printed 464 — rejected: Studia article on plague-time paraliturgies. False positive.
- pdf 467, printed 465 — rejected: Studia article page; no calendar ruling. *(classified from the running head)*
- pdf 468, printed 466 — rejected: Studia article page; no calendar ruling. *(classified from the running head)*
