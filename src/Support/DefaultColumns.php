<?php

declare(strict_types=1);

namespace WicketImporter\Support;

use WicketImporter\ValueObjects\ColumnDefinition;

/**
 * Baseline person columns the core itself understands.
 *
 * The importer core is generic (AD1): it registers no client-specific columns.
 * But it does need a universal person identity (email + names) to drive MDP
 * lookup and WP user seeding (see ImportPipeline::extractPerson / guessPerson).
 *
 * These three columns are seeded as a fallback ONLY when no extension
 * registers columns via {@see 'wicket_import_csv_columns'}. An extension that
 * returns its own column set owns the full list; the baseline never merges in
 * on top of it, so there is no duplicate-key risk.
 *
 * The aliases mirror the keys ImportPipeline::guessPerson() already tolerates,
 * so a CSV written from this template round-trips through the parser without an
 * extension needing to map anything.
 */
final class DefaultColumns
{
    /**
     * The baseline bulk-import column set.
     *
     * @return list<ColumnDefinition>
     */
    public static function bulk(): array
    {
        return [
            new ColumnDefinition(
                key: 'first_name',
                label: __('First Name', 'wicket-wp-importer'),
                aliases: ['first', 'firstname', 'given name', 'given_name', 'forename'],
            ),
            new ColumnDefinition(
                key: 'last_name',
                label: __('Last Name', 'wicket-wp-importer'),
                aliases: ['last', 'lastname', 'family name', 'family_name', 'surname'],
            ),
            new ColumnDefinition(
                key: 'email',
                label: __('Email Address', 'wicket-wp-importer'),
                required: true,
                validators: [['type' => 'email']],
                dedup: true,
                aliases: ['email address', 'email_address', 'e-mail', 'e mail', 'mail'],
            ),
        ];
    }
}
