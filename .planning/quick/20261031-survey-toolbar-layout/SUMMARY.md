---
status: validated
---

# Survey list header and toolbar layout

On phones the "Izveidot aptauju" button now shares the row with the "Aptaujas" heading. The table search has its own row and starts at the left. The filter button sits at the right end of the "Kārtot pēc" row, and it stays there when active filter chips appear above that row. A list with no records has no sorting row, so the search and the filter share one toolbar row.

The duplicate search was already fixed in 3d6cc3d. The marked-up image was the 2026-10-30 screenshot, from before that deploy.

The Codex review found one problem. Picking a sort column shows a direction select, and at 390 px both selects shrank and cut off "Atbildes" and "Dilstošs". The sort controls now wrap at their natural width, so the direction select moves to a second line.

Checked in an isolated SQLite sandbox at 390x844 light and dark, 768x1024 and 1440x1000. The checks covered an active filter, the empty state, an open filter dropdown, all three sort columns, and typing in the search, including a query with no match. Focus stayed in the search field and nothing overflowed horizontally. AdminSurveyTest passed with 12 tests and 94 assertions. Evidence uses the ignored artifacts/toolbar-qa-20261031-b1 prefix.
