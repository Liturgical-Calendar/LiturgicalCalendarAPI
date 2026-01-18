## CodeRabbit CLI Workflow

1. Run `coderabbit --plain` and allow it to complete its analysis (can run in background).

2. Evaluate the reported issues:
   - Fix all major and critical issues
   - Fix minor nits only if they are clearly beneficial

3. After implementing changes, re-run CodeRabbit CLI to verify:
   - All critical issues are resolved
   - No new bugs were introduced

4. Iteration limit:
   - Maximum of four iterations
   - If the fourth run shows no critical issues, skip remaining nits
   - Provide a summary of completed changes and rationale
