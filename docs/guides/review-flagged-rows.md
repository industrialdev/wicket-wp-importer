---
title: "Review Flagged Rows"
audience: end-user
wp_admin_path: "Wicket → Import → Validation"
---

# Review Flagged Rows

After you upload a CSV, the importer checks every row and shows the **Validation** screen. This is where you see which rows passed and which need attention before you commit the import.

## The summary bar

At the top:

> **N valid · M flagged · K duplicates**

- **Valid** — ready to import.
- **Flagged** — failed one or more checks. They will not be imported unless you fix the source file and re-upload.
- **Duplicates** — identical to another row inside the same file.

## The flagged rows table

Each flagged row shows:

- **Row number** — its position in the CSV.
- **The values in that row**, with the problem cells highlighted in amber.
- **Status badge** — the type of problem (Invalid, Duplicate, Warning, Email conflict).
- **The reason** — e.g. "Invalid – Phone Number, ZIP Code".

If a row has more than one problem, all problems are listed on the same line.

## Your two choices

### Proceed with Valid Rows (primary button)

Imports only the rows that passed validation. **Flagged rows are skipped entirely** — they do not create partial records, and they do not appear on the Confirmation screen. This is safe.

Use this when the valid rows are what you care about and the flagged ones can be fixed and imported in a separate batch later.

### Restart Upload (secondary button)

Discards the current file and all validation results. You return to the Upload screen with nothing imported.

Use this when you want to fix the flagged rows and try again from a clean file.

> While one action is in flight, the other is disabled — you cannot click both at once.

## Download flagged rows

The **Download flagged rows** button saves the flagged subset as a CSV (with the reasons). Useful for handing to whoever prepared the source file so they can fix the rows offline.

## Common reasons and what to do

| Reason | Likely cause | Fix |
|---|---|---|
| Missing required data | A required column is empty | Fill the value in the source file |
| Email Format | Email is malformed | Correct the email address |
| Phone or Fax Format | Wrong digit count after stripping non-digits | Use a valid phone number |
| ZIP Code Format | Not 5 digits or 5+4 | Use `12345` or `12345-6789` |
| State Format | State abbreviation wrong length or case | Use a 2-letter uppercase US state |
| Gender Format | Not M or F | Use a single letter, M or F |
| Type Format | Not one of the allowed letters | Use the exact allowed letter(s) |
| Date Format (Birth / Admit / Law School) | Not YYYY-MM-DD | Use ISO format, e.g. `1985-03-14` |
| Duplicate in import file | Identical name + email appears more than once in the same file | Remove the duplicate row(s) |

## After you Proceed

You land on the **Confirmation** screen, which lists every row that was actually imported with its result. See [Upload a Member CSV](upload-a-csv.md) for the full flow.
