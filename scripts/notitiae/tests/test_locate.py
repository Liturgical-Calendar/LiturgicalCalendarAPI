import unittest

from notitiae.locate import harvest_index_refs, index_page_refs, printed_offset, select_pages


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
        # A bare year line that increments with the page index votes for a CONSTANT decoy
        # diff (1970+i minus i == 1970 on every page), so it accumulates one vote per page
        # -- unlike an unchanging year, whose votes land on distinct diffs and can never
        # outweigh a repeated genuine peak. This is the discriminating case: 5 decoy votes
        # for diff 1970 vs a single genuine vote (90 on pdf page 2) for diff 88. Under a
        # loose guard the decoy wins (1970); the guard must exclude these bare years
        # (they are all >= 1500) so the lone genuine head wins (88).
        pages = [
            page("1971"),
            page("1972", "90"),
            page("1973"),
            page("1974"),
            page("1975"),
        ]
        self.assertEqual(88, printed_offset(pages))

    def test_running_head_near_the_new_ceiling_is_counted(self):
        # A genuine running head must still be counted right up under the 1500 ceiling --
        # 1203 on pdf page 3 and 1204 on pdf page 4 both vote for offset 1200.
        pages = [page("cover"), page("text"), page("1203", "NOTITIAE"), page("text", "1204")]
        self.assertEqual(1200, printed_offset(pages))

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

    def test_bound_matches_printed_offset_ceiling(self):
        # printed_offset() admits printed page numbers up to 1500 (see its comment); the
        # index-ref bound must match, or a cumulative-index reference to a late-year page
        # (e.g. 1203 in a volume that paginates past 1000) is silently dropped.
        text = page("INDEX VOLUMINIS XII (1976)", "IV. Nationes", "Belgium 1203.", "V. Dioeceses",
                    "Abellinensis 1201-1203.")
        self.assertEqual({1203, 1201, 1202}, index_page_refs(text))


class HarvestIndexRefsTest(unittest.TestCase):
    def test_index_of_records_are_excluded_from_harvesting(self):
        recs = [
            {"file": "cumulative-index.pdf", "index_of": [1965, 1975]},
            {"file": "issue-116.pdf", "index_of": None},
        ]
        texts_by_file = {
            "cumulative-index.pdf": [page("INDEX VOLUMINIS XII (1965-1975)", "Belgium 999.")],
            "issue-116.pdf": [page("INDEX VOLUMINIS XII (1976)", "Belgium 134, 178.")],
        }
        self.assertEqual({134, 178}, harvest_index_refs(recs, texts_by_file))


if __name__ == "__main__":
    unittest.main()
