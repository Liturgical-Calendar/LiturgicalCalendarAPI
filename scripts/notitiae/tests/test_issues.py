import unittest

from notitiae.issues import render
from notitiae.tests.test_merge import entry


def _row_cells(line: str) -> list[str]:
    """Split a rendered `| a | b | c |` table row into its stripped cell values."""
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


class RenderTest(unittest.TestCase):
    def test_split_by_flag_and_checkbox_state(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01")
        a["api"].update(calendar_implemented=True, status="applied", applied_in="#999")
        b = entry("N1976-2-76", "CD 2/76", "1976-02-01", nation="FR")
        out = render([a, b])
        self.assertIn("- [x] `N1976-1-76`", out.impl_checklist)
        self.assertIn("#999", out.impl_checklist)
        self.assertIn("- [ ] `N1976-2-76`", out.notimpl_checklist)
        self.assertNotIn("N1976-2-76", out.impl_checklist)

    def test_section_order_and_deep_link(self):
        g = entry("N2016-257-16", "N. 257/16", "2016-06-03", level="general", nation=None)
        g["api"]["calendar_implemented"] = True
        d = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="diocesan", nation="US", diocese_id="boston_us")
        d["api"]["calendar_implemented"] = True
        out = render([d, g], diocese_names={"boston_us": "Archdiocese of Boston"})
        self.assertLess(
            out.impl_checklist.index("## General Roman Calendar"),
            out.impl_checklist.index("## Archdiocese of Boston"),
        )
        self.assertIn("https://x/y.pdf#page=5", out.impl_checklist)

    def test_diocesan_heading_uses_diocese_name_not_printed_latin(self):
        d = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="diocesan", nation="NL", diocese_id="rotter_nl")
        d["target"]["diocese"] = "Roterodamensis"
        d["api"]["calendar_implemented"] = True
        out = render([d], diocese_names={"rotter_nl": "Rotterdam"})
        self.assertIn("## Rotterdam", out.impl_checklist)
        self.assertNotIn("Roterodamensis", out.impl_checklist)

    def test_diocesan_without_id_groups_under_nation_other_dioceses(self):
        d = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="diocesan", nation="IT", diocese_id=None)
        out = render([d])
        self.assertIn("## IT — other dioceses", out.notimpl_checklist)

    def test_checkbox_true_iff_applied(self):
        applied = entry("N1976-1-76", "CD 1/76", "1976-01-01")
        applied["api"]["status"] = "applied"
        rejected = entry("N1976-2-76", "CD 2/76", "1976-02-01")
        rejected["api"]["status"] = "rejected"
        out = render([applied, rejected])
        self.assertIn("- [x] `N1976-1-76`", out.notimpl_checklist)
        self.assertIn("- [ ] `N1976-2-76`", out.notimpl_checklist)

    def test_implemented_table_counts(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="general", nation=None)
        a["api"].update(calendar_implemented=True, status="applied")
        b = entry("N1976-2-76", "CD 2/76", "1976-02-01", level="general", nation=None)
        b["api"]["calendar_implemented"] = True
        b["needs_review"] = True
        out = render([a, b])
        row = next(line for line in out.impl_body.splitlines() if line.startswith("| General Roman Calendar"))
        self.assertEqual(["General Roman Calendar", "2", "1", "1"], _row_cells(row))

    def test_not_implemented_table_rolls_up_diocesan_into_nation_row(self):
        n = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="national", nation="IT")
        d = entry("N1976-2-76", "CD 2/76", "1976-02-01", level="diocesan", nation="IT", diocese_id=None)
        out = render([n, d])
        row = next(line for line in out.notimpl_body.splitlines() if line.startswith("|") and _row_cells(line)[:1] == ["IT"])
        self.assertEqual(["IT", "2", "0", "0"], _row_cells(row))

    def test_checklist_link_present_in_body(self):
        out = render([entry("N1976-1-76", "CD 1/76", "1976-01-01")])
        self.assertIn("docs/decrees/epics/implemented.md", out.impl_body)
        self.assertIn("docs/decrees/epics/not-implemented.md", out.notimpl_body)

    def test_checklist_link_is_markdown_link_not_bare_url(self):
        out = render([entry("N1976-1-76", "CD 1/76", "1976-01-01")])
        impl_url = "https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/blob/development/docs/decrees/epics/implemented.md"
        notimpl_url = "https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/blob/development/docs/decrees/epics/not-implemented.md"
        self.assertIn(f"[Full checklist]({impl_url})", out.impl_body)
        self.assertIn(f"[Full checklist]({notimpl_url})", out.notimpl_body)
        self.assertNotIn(f"Full checklist: {impl_url}", out.impl_body)
        self.assertNotIn(f"Full checklist: {notimpl_url}", out.notimpl_body)

    def test_table_columns_are_pipe_aligned(self):
        g = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="general", nation=None)
        g["api"]["calendar_implemented"] = True
        d = entry("N1976-2-76", "CD 2/76", "1976-02-01", level="diocesan", nation="US", diocese_id="boston_us")
        d["api"]["calendar_implemented"] = True
        out = render([g, d], diocese_names={"boston_us": "Archdiocese of Boston (Massachusetts)"})
        table_lines = [line for line in out.impl_body.splitlines() if line.startswith("|")]
        self.assertGreaterEqual(len(table_lines), 3)
        pipe_positions = [[i for i, c in enumerate(line) if c == "|"] for line in table_lines]
        self.assertTrue(all(positions == pipe_positions[0] for positions in pipe_positions))

    def test_compact_checklist_included_when_under_threshold(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="general", nation=None)
        a["api"]["calendar_implemented"] = True
        out = render([a])
        self.assertIn("N1976-1-76", out.impl_body)

    def test_compact_checklist_omitted_when_over_threshold(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="general", nation=None)
        a["api"]["calendar_implemented"] = True
        out = render([a], checklist_threshold=10)
        self.assertNotIn("N1976-1-76", out.impl_body)
        self.assertIn("omitted", out.impl_body.lower())

    def test_not_implemented_body_never_includes_per_entry_checklist(self):
        b = entry("N1976-2-76", "CD 2/76", "1976-02-01")
        out = render([b])
        self.assertNotIn("N1976-2-76", out.notimpl_body)

    def test_compact_line_truncates_long_summary(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="general", nation=None)
        a["api"]["calendar_implemented"] = True
        a["summary_en"] = "x" * 150
        out = render([a])
        self.assertIn("x" * 100 + "…", out.impl_body)
        self.assertNotIn("x" * 101, out.impl_body)


if __name__ == "__main__":
    unittest.main()
