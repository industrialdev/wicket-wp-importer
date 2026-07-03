---
title: "Upload a Member CSV"
audience: end-user
wp_admin_path: "Wicket → Import"
---

# Upload a Member CSV

Use this when you have a file of multiple member records to import at once. For a single member, see [Add a single member manually](add-a-single-member.md).

## Before you start

- Get the correct CSV template. On the **Wicket → Import** screen, click **Download CSV template**. This gives you a file with the exact column headers the importer expects. Do not invent your own headers — only the columns in the template are recognized.
- Fill the template. Each row is one member. Required columns must not be empty.
- Save the file as CSV.

## Steps

1. Go to **Wicket → Import**.
2. Make sure **Upload Type** is set to **CSV file**.
3. Drag the CSV onto the drop zone, or click the drop zone and pick the file.
   - The file preview shows the filename, size, and an approximate row count ("first 2 MB scanned"). The count is approximate for large files.
4. Click **Upload**.
5. When upload finishes, you land on the **Validation** screen. See [Review flagged rows](review-flagged-rows.md) to understand what it shows.
6. If there are flagged rows, decide:
   - **Proceed with Valid Rows** — imports only the rows that passed validation. Flagged rows are skipped entirely; they do not create partial records.
   - **Restart Upload** — discards the file and returns you to the Upload screen.
7. After the import runs, you land on the **Confirmation** screen. It lists each row with its result (Imported / Updated / Skipped / Failed / Needs review), the member's Bar ID and tier where the extension provides them, and a **View in MDP** link.

## Limits

- **Maximum file size**: 4 MB.
- **Maximum rows**: 200 per import. Larger files are rejected when you click Proceed.
- **One import at a time**: if a previous import is still in progress, your upload is rejected. Wait for it to finish or clear the session.

## If your upload fails

- **"Invalid file type"** — the file is not a `.csv`. Re-save as CSV.
- **"File too large"** — over 4 MB. Split the file into smaller batches.
- **"Too many rows" (on Proceed)** — over 200 rows. Split the file.
- **"An import is already running"** — a previous session is still active. Wait, or ask an administrator to clear it.
- **Download CSV template returns an error** — no client extension is active. Contact your Wicket implementer.

## Tips

- Always download a fresh template before preparing a new batch — column definitions can change.
- Fix flagged rows in your source file, then re-upload from scratch. The importer does not edit rows in place.
- Large imports can take a minute or more to run. Leave the browser tab open until the Confirmation screen appears.
