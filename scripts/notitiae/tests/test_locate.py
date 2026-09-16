import unittest

from notitiae.locate import index_page_refs, printed_offset, select_pages

FF = "\f"


def page(*lines):
    return "\n".join(lines) + "\n"


class PrintedOffsetTest(unittest.TestCase):
    def test_offset_is_mode_of_running_head_minus_pdf_page(self):
        pages = [page("cover"), page("90", "NOTITIAE", "text"), page("text", "91"), page("92 NOTITIAE")]
        # pdf page 2 → 90, 3 → 91, 4 → 92: offset 88
        self.assertEqual(88, printed_offset(pages))

    def test_no_running_heads(self):
        self.assertIsNone(printed_offset([page("just text"), page("more")]))

    def test_bare_year_lines_are_not_running_heads(self):
        # "1976" alone on a cover or a broken dateline must not vote for an offset; genuine
        # running heads 90, 91, 92 on pdf pages 2-4 (offset 88) must still win.
        pages = [page("1976", "cover")]
        pages += [page("1976", "90", "NOTITIAE", "text")]
        pages += [page("1976", "text", "91")]
        pages += [page("1976", "92 NOTITIAE")]
        self.assertEqual(88, printed_offset(pages))

    def test_short_page_head_tail_overlap_does_not_double_count(self):
        # A 2-line page's single "90" must cast exactly one vote, not two (splitlines()[:3] and
        # [-3:] overlap on short pages). Page 1 (5 lines, single genuine "50" -> diff 49) is the
        # only vote if page 2 ("90", "x") is correctly counted once (tied at 1 vote, page 1 wins
        # by insertion order); a double count would wrongly give page 2's diff (88) the win.
        pages = [
            page("intro1", "intro2", "intro3", "50", "tail1"),
            page("90", "x"),
        ]
        self.assertEqual(49, printed_offset(pages))


class SelectPagesTest(unittest.TestCase):
    def test_summarium_heading_and_following_prot_pages(self):
        pages = [
            page("Allocutio", "nothing here"),
            page("SUMMARIUM DECRETORUM", "I. Calendaria particularia"),
            page("Bostoniensis, 3 martii 1976 (Prot. CD 123/76): approbatur calendarium"),
            page("Prot. CD 124/76 confirmatur electio Patroni"),
            page("Bibliographia", "books"),
            page("more books"),
        ]
        sel = select_pages(pages)
        self.assertIn("heading:summarium", sel[2])
        self.assertIn("prot", sel[3])
        self.assertIn("prot", sel[4])
        self.assertNotIn(5, sel)
        self.assertNotIn(1, sel)

    def test_keyword_sweep_outside_summarium(self):
        pages = [page("DECRETUM", "Calendarium Romanum Generale ita immutatur: memoria S. N. inscribitur")]
        self.assertIn("keyword:calendarium-romanum", select_pages(pages)[1])

    def test_grade_words_near_action_verbs(self):
        pages = [page("celebratio", "ad gradum festi elevatur", "in calendario proprio")]
        self.assertIn("keyword:grade-action", select_pages(pages)[1])


class IndexPageRefsTest(unittest.TestCase):
    def test_collects_numbers_after_section_heading(self):
        text = page("INDEX VOLUMINIS XII (1976)", "IV. Nationes", "Belgium 134, 178; Dania 11.", "V. Dioeceses",
                    "Abellinensis 310; Alba 366, 368.")
        self.assertEqual({134, 178, 11, 310, 366, 368}, index_page_refs(text))


if __name__ == "__main__":
    unittest.main()
