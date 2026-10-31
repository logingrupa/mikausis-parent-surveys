---
status: validated
---

# Survey list actions and search

Survey card actions align right on desktop. The existing two-column mobile layout remains. Filament global search is disabled; the table search filters the list.

Validation passed: 12 AdminSurveyTest tests with 94 assertions using forced SQLite :memory:, scoped Pint, whitespace checks and one scoped Codex review. Browser checks verified nonmatching, matching and cleared search queries, no header search on the list or dashboard, right alignment, 44px buttons, dropdown keyboard access and no overflow. Screenshots were captured and inspected at 1440x1000 and 390x844 with light and dark preferences, then delivered in chat.

Evidence uses the ignored artifacts/list-search-qa-20261031-a1 prefix. Owned private QA state was removed. Real local environment, database and survey source hashes and metadata stayed unchanged. Deployment uses the existing Forge skola site and script, with production data fingerprints and live revision/configuration checks.
