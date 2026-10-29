# October parents' survey source

`october-survey.json` transcribes the original `Aptauja vecākiem oktobrī.docx` supplied for this project. That Word document is not included in the public repository. Content was extracted directly from `word/document.xml`; Latvian text is UTF-8. Only obvious spelling, spacing and punctuation were corrected. Printed blank lines are represented by text inputs. The JSON contains questionnaire definitions, not submitted answers or contact records.

- The source has 14 main questions numbered 1–10 and 12–15. JSON uses consecutive `q1`–`q14`: source 12 → `q11`, 13 → `q12`, 14 → `q13`, 15 → `q14`.
- `q7_details` preserves the separate, unnumbered optional strengths/ideas prompt. It is always available, as in the source. There are 15 question records and 69 predefined options.
- Every question is optional. `q4` and `q7` permit multiple selections; their `no` value is exclusive. `q5` is single choice. The seminar (`q14`) is single choice because the source requests the most relevant “tēmu” in the singular.
- `q13` appears only when `q12` contains `more` (“Gribētos vairāk”). A condition's `values` are the allowed triggering option values.
- `allow_other: true` means the existing option whose value is `other` accepts free text; it must not create a second Other option. Source underscore lines are omitted from labels. In `q4`, “Jā, citā veidā” and “Cits variants…” are distinct source options and both remain.
- No Other option was added to `q10`, `q11` or `q12`. `q11` retains its “Ja atbalstāt…” wording without inferring an exact display condition.
- Option values are stable ASCII identifiers, unique within each question. Empty descriptions, option arrays, exclusive-value arrays and null conditions are intentional.

The first charity option (`q5`) is ambiguous: “Ogres novada bērni, kuriem nepieciešams atbalsts Bērnu slimnīca” occurs as one Word bullet, with no internal break. It is preserved as one option rather than silently split into two.

The five section headings organize the source questions for presentation; the Word file has no section headings. The survey title follows the source filename. `estimated_minutes: 7` is a presentation estimate, not a duration specified in the document.
