---
status: validated
---

# A4 report export

The main export action is now "Drukāt / saglabāt PDF". It opens the standalone HTML report in the current tab with a full page navigation. CSV and offline HTML downloads remain available. The existing report uses A4 paper, print margins and embedded fonts and logo.

Automatic printing waits for the loaded document, fonts and decoded images. It no longer depends on animation frames, which browsers can suspend in background tabs. A manual print while assets load cancels the later automatic dialog.

Validation: 79 PHP tests with 1,358 assertions using SQLite :memory:, three Node print tests, scoped Pint, syntax checks and git diff checks. One read-only reviewer found no P1/P2 issues.

The isolated browser verifier clicked the actual export link and confirmed same-tab navigation and one print invocation after content loaded. Manual print remained available. The generated PDF has five populated A4 pages and all five synthetic typed answers. Independent contact details are absent. CSV and HTML downloads, authentication and the four-response restriction passed. Screenshots were inspected at 390x844 light/dark and 1440x1000. The native operating-system print dialog was not inspected; the call was intercepted and Chromium PDF output was checked.

Evidence: artifacts/a4-export-qa-20261103-evidence.json and artifacts/a4-export-qa-20261103-pdf-evidence.json. No production deployment in this task.
