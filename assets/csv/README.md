# CSV Templates

Reference CSV files for the Wicket Importer. Browse them to learn the expected
shape, copy one as a starting point, or hand it to a member who is preparing a
file for upload.

## Golden rule: the Download button always wins

The **Download CSV template** button on the import screen generates the file
dynamically from the columns your install actually has registered. That file is
always the source of truth for *your* environment.

These static files are **reference examples**. They show the shape of the data
and the patterns for extending it, but a live install with extensions active
will produce a template with more (or different) columns than the files here.
When in doubt, download from the screen.

## Files

| File | What it demonstrates |
|------|----------------------|
| `default-template.csv` | The minimum the importer accepts: the three baseline identity columns (First Name, Last Name, Email Address). This is what a vanilla core install, with no extensions active, expects. |
| `example-with-profile.csv` | Identity plus the reusable **profile** bundle: address (2 lines), city, US state, ZIP, phone, fax, birthdate, gender. Core ships this bundle so a client does not re-declare standard contact fields. |
| `example-with-domain-columns.csv` | Identity + profile + a generic client's **domain** columns (Membership ID, Tier, Status, dates, practice area, license). These only validate when the matching extension is active. |
| `oba-sample.csv` | A worked example from the OBA client extension (semicolon-delimited). OBA-specific; kept as a real-world reference. |

## The baseline identity columns (always present)

The importer core is generic (AD1). It registers no client-specific columns, but
it always contributes three universal identity columns that drive MDP lookup and
WordPress user seeding. Every CSV must carry them.

| Header | Required | Validator |
|--------|----------|-----------|
| First Name | recommended | — |
| Last Name | recommended | — |
| Email Address | **yes** | email format; in-file duplicate detection |

Header matching is alias-aware and case-insensitive, and ignores
underscores/hyphens/whitespace. `Email Address`, `email_address`, `E-mail`, and
`mail` all map to the same column.

## The reusable profile bundle

Core offers a ready-made set of standard contact + demographic columns so a
client composes plumbing instead of re-declaring it. All carry **format**
validators; none are required by default (a client opts individual fields in).

Address Line 1, Address Line 2, City, State (US state), ZIP, Phone, Fax,
Birthdate (date), Gender (enum: `M`, `F`, `X` where `X` = prefer not to say).

## How a client extends the columns

An extension registers its domain columns (and optionally requires profile
fields) through the `wicket_import_csv_columns` filter. Core always merges the
baseline identity columns on top, so the extension declares only its own
columns:

```php
add_filter('wicket_import_csv_columns', function (array $columns, array $ctx) {
    $columns[] = new ColumnDefinition(
        key: 'membership_id',
        label: __('Membership ID', 'my-client'),
        required: true,
        validators: [['type' => 'required']],
    );
    // ...more domain columns
    return $columns;
}, 10, 2);
```

Once registered, the live **Download CSV template** button emits a header row
that includes those columns, and uploads are validated against them. The domain
columns in `example-with-domain-columns.csv` are illustrative; they only
validate when a matching extension is active.

## Delimiter

The default delimiter is a comma (`,`). An extension can override it via the
`wicket_import_csv_delimiter` filter (the OBA client uses `;`, which is why
`oba-sample.csv` is semicolon-delimited). The files in this folder, other than
`oba-sample.csv`, use the default comma.
