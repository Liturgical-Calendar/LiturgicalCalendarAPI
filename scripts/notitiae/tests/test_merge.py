import unittest

from notitiae.merge import is_implemented, merge, normalise_id

IMPL = {"nations": {"IT", "US"}, "dioceses": {"boston_us"}, "wider_regions": {"Europe"}}


def entry(id_, protocol, date, level="national", nation="IT", diocese_id=None, page=5, issue="116", pdf="Notitiae-116-1976.pdf"):
    return {
        "id": id_, "protocol": protocol, "date": date, "kind": "particular_calendar_change",
        "source": {"volume": 12, "year": 1976, "issue": issue, "pdf": pdf, "pdf_pages": [page, page],
                   "printed_pages": [None, None], "url": "https://x/y.pdf"},
        "target": {"level": level, "nation": nation, "diocese": None, "diocese_id": diocese_id, "institute": None},
        "celebration": None, "summary_en": "s", "excerpt": "e",
        "api": {"calendar_implemented": False, "status": "recorded", "applied_in": None}, "needs_review": False,
    }


class IsImplementedTest(unittest.TestCase):
    def test_levels(self):
        self.assertTrue(is_implemented({"level": "general"}, IMPL))
        self.assertTrue(is_implemented({"level": "national", "nation": "IT"}, IMPL))
        self.assertFalse(is_implemented({"level": "national", "nation": "FR"}, IMPL))
        self.assertTrue(is_implemented({"level": "diocesan", "nation": "US", "diocese_id": "boston_us"}, IMPL))
        self.assertFalse(is_implemented({"level": "diocesan", "nation": "US", "diocese_id": None}, IMPL))
        self.assertTrue(is_implemented({"level": "wider_region", "wider_region": "Europe"}, IMPL))
        self.assertFalse(is_implemented({"level": "religious", "institute": "OFM"}, IMPL))


class MergeTest(unittest.TestCase):
    def test_sets_flag_and_sorts(self):
        out = merge([[entry("N1976-2-76", "CD 2/76", "1976-03-01"), entry("N1976-1-76", "CD 1/76", "1976-01-01", nation="FR")]], IMPL)
        self.assertEqual(["N1976-1-76", "N1976-2-76"], [e["id"] for e in out])
        self.assertFalse(out[0]["api"]["calendar_implemented"])
        self.assertTrue(out[1]["api"]["calendar_implemented"])

    def test_same_protocol_across_fragments_is_one_entry_with_also_in(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", page=5)
        b = entry("N1976-1-76", "CD 1/76", "1976-01-01", page=40)
        out = merge([[a], [b]], IMPL)
        self.assertEqual(1, len(out))
        self.assertEqual([40, 40], out[0]["also_in"][0]["pdf_pages"])

    def test_null_dates_sort_last(self):
        out = merge([[entry("N1976-p9-1", None, None), entry("N1976-1-76", "CD 1/76", "1976-01-01")]], IMPL)
        self.assertEqual("N1976-116-p9-1", out[-1]["id"])

    def test_ids_are_normalised_before_dedupe(self):
        # The same protocol-less act, once per fragment, but written under two different spellings of the id.
        a = entry("N1976-p9-1", None, None, page=9)
        b = entry("N1976-116-p9-1", None, None, page=9)
        out = merge([[a], [b]], IMPL)
        self.assertEqual(["N1976-116-p9-1"], [e["id"] for e in out])
        self.assertEqual(1, len(out[0]["also_in"]))

    def test_same_id_different_pdf_and_protocol_is_refused(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", pdf="Notitiae-116-1976.pdf")
        b = entry("N1976-1-76", "CD 1/76 bis", "1976-01-01", pdf="Notitiae-117-1976.pdf")
        with self.assertRaises(ValueError) as cm:
            merge([[a], [b]], IMPL)
        self.assertIn("N1976-1-76", str(cm.exception))
        self.assertIn("Notitiae-116-1976.pdf", str(cm.exception))
        self.assertIn("Notitiae-117-1976.pdf", str(cm.exception))

    def test_same_id_different_pdf_null_protocol_is_refused(self):
        a = entry("N1976-p9-1", None, None, pdf="Notitiae-116-1976.pdf", issue="116")
        b = entry("N1976-p9-1", None, None, pdf="Notitiae-116-1976-bis.pdf", issue="116")
        with self.assertRaises(ValueError):
            merge([[a], [b]], IMPL)

    def test_same_id_different_pdf_same_protocol_folds(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", pdf="Notitiae-116-1976.pdf")
        b = entry("N1976-1-76", "CD 1/76", "1976-01-01", pdf="Notitiae-117-1976.pdf")
        out = merge([[a], [b]], IMPL)
        self.assertEqual(1, len(out))
        self.assertEqual("Notitiae-117-1976.pdf", out[0]["also_in"][0]["pdf"])

    def test_same_id_same_pdf_different_protocol_folds(self):
        # Two printings in one volume (full text + Summarium) may spell the protocol differently; that is not a cross-volume fold.
        a = entry("N1976-1-76", "Prot. CD 1/76", "1976-01-01", page=5)
        b = entry("N1976-1-76", "CD 1/76", "1976-01-01", page=40)
        self.assertEqual(1, len(merge([[a], [b]], IMPL)))


class NormaliseIdTest(unittest.TestCase):
    def test_protocol_less_id_gets_issue_token(self):
        self.assertEqual("N1976-116-p9-1", normalise_id(entry("N1976-p9-1", None, None)))
        self.assertEqual("N1976-116-p9-1-2", normalise_id(entry("N1976-p9-1-2", None, None)))
        self.assertEqual("N2008-521-522-p51-1", normalise_id(entry("N2008-p51-1", None, None, issue="521-522")))
        self.assertEqual("N2016-593-NS-001-p38-1", normalise_id(entry("N2016-p38-1", None, None, issue="593-NS-001")))

    def test_bare_n_token_is_dropped(self):
        self.assertEqual("N1969-833-69", normalise_id(entry("N1969-N-833-69", "Prot. N. 833/69", None)))

    def test_regular_ids_are_unchanged(self):
        for id_ in ("N1976-CD-1131-76", "N2016-257-16", "N2001-551-00-L", "N2002-444-02-L-2", "N1976-116-p9-1"):
            self.assertEqual(id_, normalise_id(entry(id_, "x", None)))

    def test_idempotent(self):
        e = entry("N1976-p9-1", None, None)
        once = normalise_id(e)
        e["id"] = once
        self.assertEqual(once, normalise_id(e))


if __name__ == "__main__":
    unittest.main()
