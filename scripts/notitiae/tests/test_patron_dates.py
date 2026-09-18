import unittest

from notitiae.patron_dates import excerpt_prints_month, strip_unprinted_patron_dates
from notitiae.tests.test_merge import entry


def patron(id_, excerpt, month=5, day=4, kind="patron_confirmation"):
    e = entry(id_, "x", "1971-04-21")
    e["kind"] = kind
    e["excerpt"] = excerpt
    e["celebration"] = {"event_key": None, "name_latin": "S. Florianus", "month": month, "day": day, "grade": None}
    return e


class ExcerptPrintsMonthTest(unittest.TestCase):
    def test_decree_date_alone_is_not_a_printed_celebration_date(self):
        # The parenthesised decree date is on every Summarium line; it says nothing about the celebration's day.
        for excerpt in (
            "Linciensis in Austria, 21 apr. 1971 (Prot. n. 926/71): confirmatur electio S. Floriani martyris.",
            "Ordo Fratrum, 6 iunii 1974 (Prot. n. 1539/74): confirmatur electio S. Caroli.",
            "S. Nicolaus, episcopus: Patronus civitatis (12 mar. 2004, Prot. 426/04/L).",
            "Sanctus Martinus: Patronus apud Deum urbis (9 aug. 2017; Prot. 485/16).",
            "Lusitania, 23 apr. 1076 (Prot. CD 410/76): confirmatur electio Sancti Michaëlis.",  # misprinted year
        ):
            self.assertFalse(excerpt_prints_month(excerpt), excerpt)

    def test_celebration_date_in_latin_or_italian_is_printed(self):
        for excerpt in (
            "cuius festum die 2 februarii celebratur, Patrona confirmatur (19 febr. 1971, Prot. 3880/70).",
            "festo gradu III classis die 21 mensis martii recolendo",
            "quotannis die 1 februarii gradu memoriae obligatoriae peragenda",
            "S. Rocco: Patrono, la cui festa cade il 16 agosto (12 mar. 2004, Prot. 1/04/L).",
            "Patronus, cuius memoria 4 dec. celebratur (20 mart. 1992, Prot. CD 567/92).",
        ):
            self.assertTrue(excerpt_prints_month(excerpt), excerpt)

    def test_month_stems_inside_other_words_do_not_count(self):
        # "mart" in martyr, "dec" in decretum, "nov" in novae, "mai" in Maiella, "ian" in Ianuarius the saint's name.
        self.assertFalse(excerpt_prints_month("S. Floriani martyris, novae dioecesis, decretum, S. Gerardus Maiella, S. Ianuarius"))


class StripUnprintedPatronDatesTest(unittest.TestCase):
    def test_inferred_month_and_day_are_nulled_and_counted(self):
        entries = [patron("N1971-926-71", "Linciensis, 21 apr. 1971 (Prot. n. 926/71): confirmatur electio S. Floriani martyris.")]
        self.assertEqual(1, strip_unprinted_patron_dates(entries))
        self.assertIsNone(entries[0]["celebration"]["month"])
        self.assertIsNone(entries[0]["celebration"]["day"])

    def test_printed_celebration_date_is_kept(self):
        entries = [patron("N1971-3880-70", "cuius festum die 2 februarii celebratur (19 febr. 1971, Prot. n. 3880/70).", month=2, day=2)]
        self.assertEqual(0, strip_unprinted_patron_dates(entries))
        self.assertEqual((2, 2), (entries[0]["celebration"]["month"], entries[0]["celebration"]["day"]))

    def test_other_kinds_null_celebrations_and_already_null_dates_are_untouched(self):
        a = patron("N1971-1-71", "no date here", kind="new_celebration")
        b = patron("N1971-2-71", "no date here")
        b["celebration"] = None
        c = patron("N1971-3-71", "no date here", month=None, day=None)
        self.assertEqual(0, strip_unprinted_patron_dates([a, b, c]))
        self.assertEqual((5, 4), (a["celebration"]["month"], a["celebration"]["day"]))

    def test_a_lone_day_or_month_is_nulled_too(self):
        entries = [patron("N1971-1-71", "confirmatur electio (21 apr. 1971).", month=None, day=4)]
        self.assertEqual(1, strip_unprinted_patron_dates(entries))
        self.assertIsNone(entries[0]["celebration"]["day"])

    def test_idempotent(self):
        entries = [patron("N1971-926-71", "Linciensis, 21 apr. 1971 (Prot. n. 926/71): confirmatur electio S. Floriani.")]
        strip_unprinted_patron_dates(entries)
        self.assertEqual(0, strip_unprinted_patron_dates(entries))


if __name__ == "__main__":
    unittest.main()
