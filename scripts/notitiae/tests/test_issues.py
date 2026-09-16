import unittest

from notitiae.issues import render
from notitiae.tests.test_merge import entry


class RenderTest(unittest.TestCase):
    def test_split_by_flag_and_checkbox_state(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01")
        a["api"].update(calendar_implemented=True, status="applied", applied_in="#999")
        b = entry("N1976-2-76", "CD 2/76", "1976-02-01", nation="FR")
        impl, not_impl = render([a, b])
        self.assertIn("- [x] `N1976-1-76`", impl)
        self.assertIn("#999", impl)
        self.assertIn("- [ ] `N1976-2-76`", not_impl)
        self.assertNotIn("N1976-2-76", impl)

    def test_section_order_and_deep_link(self):
        g = entry("N2016-257-16", "N. 257/16", "2016-06-03", level="general", nation=None)
        g["api"]["calendar_implemented"] = True
        d = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="diocesan", nation="US", diocese_id="boston_us")
        d["api"]["calendar_implemented"] = True
        impl, _ = render([d, g])
        self.assertLess(impl.index("## General Roman Calendar"), impl.index("## US"))
        self.assertIn("https://x/y.pdf#page=5", impl)


if __name__ == "__main__":
    unittest.main()
