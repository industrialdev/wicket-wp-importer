---
title: "Add a Single Member Manually"
audience: end-user
wp_admin_path: "Wicket → Import → Individual"
---

# Add a Single Member Manually

Use this when you only need to create one member record. It runs the same validation and creation steps as a CSV upload, just for one person.

## Steps

1. Go to **Wicket → Import**.
2. Under **Upload Type**, select **Manual entry**.
3. Fill the form. Required fields are marked; the **Upload Member** button stays disabled until all required fields have valid input.
4. If your form has an **Add Additional State** button (shown for OBA), click it to add extra state rows. Each click adds a State abbreviation + admit date pair. There is no fixed limit.
5. Click **Upload Member**.
6. You land on the **Confirmation** screen with the single row's result (Imported / Updated / Skipped / Failed / Needs review) and its **View in MDP** link.

## Field notes

- Fields with formatting rules (ZIP, email, phone, dates) are checked as you type. If a value is invalid, an inline message points at the specific field and the button stays disabled until you fix it.
- **Dates** must be `YYYY-MM-DD` (e.g. `1985-03-14`).
- **Membership Tier** is a dropdown populated from your site's configured tiers. If the dropdown is empty, no tiers are configured — contact your Wicket implementer.
- Some fields shown (Middle Name, Suffix, Birthdate, Gender, Fax, Law School Code, Law School Grad Date, Type, Admit Date, state rows) are added by the client extension and may vary per installation.

## If submission fails

- **A field is highlighted red** — the value did not pass validation. Read the inline message, correct the value, and try again. The error pins to the exact field.
- **"Email already assigned..."** — a member with that email already exists in the system. The message tells you the specific reason (active membership, Bar ID already assigned, no name match). Review the existing record in MDP before proceeding.
- **"Needs review"** — the member's profile was created but the membership could not be added. An administrator must finish it manually in MDP.

## When to use this instead of a CSV

- Adding one or two members.
- Testing the import flow with a known-good record before running a large CSV.
- Creating a member whose data you have in a non-CSV source (e.g. a phone call).
