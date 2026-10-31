# Survey list header and toolbar layout

The owner marked up the 390 px survey list. "Izveidot aptauju" moves up next to the "Aptaujas" heading. The search starts at the left edge on its own row. The filter button moves down into the "Kārtot pēc" row.

The duplicate header search was already removed in 3d6cc3d. The marked-up image was the 2026-10-30 screenshot, taken before that deploy.

Files: public/css/admin.css; this plan; SUMMARY.md in this directory.

The change is CSS only. List page headers stay in one row on phones. On the survey table, the toolbar wrappers use `display: contents`, so the search field and the filter dropdown become grid items of `.fi-ta-main`. The search takes the first row. The filter shares the content area with the record list and sits at the top right, which is the sorting row. When there is no sorting row (no records), the old one-row toolbar returns with the search on the left and the filter on the right.

Check in an isolated SQLite sandbox: 390x844 light and dark, 768, 1440, an active filter, the empty state, an open filter dropdown, and typing in the search, including a query with no match. Run AdminSurveyTest. Get one Codex review. Commit, push, deploy to the Forge skola site, then check the live CSS, HTTP health and the production data fingerprints.
