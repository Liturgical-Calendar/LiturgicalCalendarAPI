import unittest

from notitiae.issue_tokens import fix_issue_tokens, issue_token
from notitiae.tests.test_merge import entry


class IssueTokenTest(unittest.TestCase):
    def test_token_is_spelled_exactly_as_the_file_name(self):
        self.assertEqual("085", issue_token("Notitiae-085-1973.pdf"))
        self.assertEqual("116", issue_token("Notitiae-116-1976.pdf"))
        self.assertEqual("306-307", issue_token("Notitiae-306-307-1992.pdf"))
        self.assertEqual("521-522", issue_token("Notitiae-521-522-2008.pdf"))
        self.assertEqual("593-NS-001", issue_token("Notitiae-593-NS-001-2016.pdf"))
        self.assertEqual("113", issue_token("Notitiae-113-1976-indice-1965-1975.pdf"))  # the cumulative index

    def test_unrecognised_file_name_is_refused(self):
        for name in ("Notitiae-1976.pdf", "notitiae-116-1976.pdf", "Notitiae-116-1976.txt", "Notitiae--1976.pdf"):
            with self.assertRaises(ValueError, msg=name):
                issue_token(name)


class FixIssueTokensTest(unittest.TestCase):
    def test_source_and_also_in_are_corrected_and_counted(self):
        a = entry("N1973-645-73", "645/73", "1973-05-01", issue="85", pdf="Notitiae-085-1973.pdf")
        a["also_in"] = [entry("N1973-645-73", "645/73", "1973-05-01", issue="82", pdf="Notitiae-082-1973.pdf", page=40)["source"]]
        b = entry("N1976-1-76", "CD 1/76", "1976-01-01")  # already right
        entries, changed = fix_issue_tokens([a, b])
        self.assertEqual(2, changed)
        self.assertEqual("085", entries[0]["source"]["issue"])
        self.assertEqual("082", entries[0]["also_in"][0]["issue"])
        self.assertEqual("116", entries[1]["source"]["issue"])

    def test_idempotent(self):
        a = entry("N1973-645-73", "645/73", "1973-05-01", issue="85", pdf="Notitiae-085-1973.pdf")
        entries, _ = fix_issue_tokens([a])
        _, changed = fix_issue_tokens(entries)
        self.assertEqual(0, changed)


if __name__ == "__main__":
    unittest.main()
