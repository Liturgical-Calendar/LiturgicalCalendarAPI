import unittest

from notitiae.gate import find_protocol, gate_report, protocol_number


class ProtocolNumberTest(unittest.TestCase):
    def test_strips_prefix(self):
        self.assertEqual("257/16", protocol_number("Prot. N. 257/16"))
        self.assertEqual("1131/76", protocol_number("Prot. CD 1131/76"))

    def test_missing(self):
        self.assertIsNone(protocol_number(""))


class FindProtocolTest(unittest.TestCase):
    def test_finds_page_tolerating_ocr_spacing(self):
        corpus = {"Notitiae-593-NS-001-2016.pdf": ["cover", "Decretum (Prot. N. 257 / 16) de S. Maria Magdalena"]}
        self.assertEqual([("Notitiae-593-NS-001-2016.pdf", 2)], find_protocol("257/16", corpus))


class GateReportTest(unittest.TestCase):
    def test_hit_on_unselected_page_is_a_miss(self):
        hits = [("f.pdf", 2)]
        worklist = {"f.pdf": {3}}
        self.assertEqual("MISSED", gate_report(hits, worklist)["status"])

    def test_hit_on_selected_page_passes(self):
        self.assertEqual("OK", gate_report([("f.pdf", 2)], {"f.pdf": {2}})["status"])

    def test_no_hit_is_reported_not_failed(self):
        self.assertEqual("NOT_IN_CORPUS", gate_report([], {})["status"])


if __name__ == "__main__":
    unittest.main()
