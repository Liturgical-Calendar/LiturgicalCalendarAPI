import unittest

from notitiae.patron_grades import strip_unprinted_patron_grades
from notitiae.tests.test_merge import entry


def patron(id_, excerpt, grade=6, kind="patron_confirmation"):
    e = entry(id_, "x", "1971-01-01")
    e["kind"] = kind
    e["excerpt"] = excerpt
    e["celebration"] = {"event_key": None, "name_latin": "S. Florianus", "month": None, "day": None, "grade": grade}
    return e


class StripUnprintedPatronGradesTest(unittest.TestCase):
    def test_grade_without_rank_word_is_nulled_and_counted(self):
        entries = [patron("N1971-1-71", "confirmatur electio S. Floriani martyris, Patroni principalis dioecesis.")]
        self.assertEqual(1, strip_unprinted_patron_grades(entries))
        self.assertIsNone(entries[0]["celebration"]["grade"])

    def test_printed_rank_words_keep_the_grade(self):
        for word in ("gradu", "sollemnitas", "sollemnitatis", "festum", "festi", "memoria", "Memoria", "SOLLEMNITAS"):
            entries = [patron("N1971-1-71", f"Patronus confirmatur, {word} celebrandus.")]
            self.assertEqual(0, strip_unprinted_patron_grades(entries), word)
            self.assertEqual(6, entries[0]["celebration"]["grade"], word)

    def test_other_kinds_and_null_celebrations_are_untouched(self):
        a = patron("N1971-1-71", "no rank word here", kind="new_celebration")
        b = patron("N1971-2-71", "no rank word here")
        b["celebration"] = None
        c = patron("N1971-3-71", "no rank word here", grade=None)
        self.assertEqual(0, strip_unprinted_patron_grades([a, b, c]))
        self.assertEqual(6, a["celebration"]["grade"])

    def test_idempotent(self):
        entries = [patron("N1971-1-71", "confirmatur electio S. Floriani.")]
        strip_unprinted_patron_grades(entries)
        self.assertEqual(0, strip_unprinted_patron_grades(entries))


if __name__ == "__main__":
    unittest.main()
