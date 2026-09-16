import unittest

from notitiae.merge import is_implemented, merge

IMPL = {"nations": {"IT", "US"}, "dioceses": {"boston_us"}, "wider_regions": {"Europe"}}


def entry(id_, protocol, date, level="national", nation="IT", diocese_id=None, page=5):
    return {
        "id": id_, "protocol": protocol, "date": date, "kind": "particular_calendar_change",
        "source": {"volume": 12, "year": 1976, "issue": "116", "pdf": "Notitiae-116-1976.pdf", "pdf_pages": [page, page],
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
        self.assertEqual("N1976-p9-1", out[-1]["id"])


if __name__ == "__main__":
    unittest.main()
