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
 * These three columns are ALWAYS contributed to the registered column set via
 * {@see mergeWith()}, on top of which an extension's domain columns are layered.
 * AD1 governs domain KNOWLEDGE (OBA/Bar-ID/cheque), not baseline identity
 * plumbing: an extension declares only its domain columns (e.g. OBA's
 * admit_date/type) and inherits first_name/last_name/email for free, instead
 * of re-registering the whole identity set.
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
        // NOTE: callers should prefer mergeWith() so extension domain columns
        // are layered on top of this baseline. Call bulk() directly only when
        // you explicitly want the identity-only set.

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

    /**
     * Merge the baseline identity columns with an extension's column set.
     *
     * Core always contributes first_name/last_name/email (the universal person
     * identity that drives MDP lookup and WP user seeding). Extension-supplied
     * columns are layered on top, keyed by canonical key: an extension entry
     * whose key matches a baseline column OVERRIDES it (so a client can
     * intentionally redefine email validation/required-ness/header-matching);
     * non-matching keys are additive.
     *
     * This is the single merge all three resolveColumns() call sites converge
     * on, so identity plumbing is declared once and every extension inherits it.
     *
     * @param list<ColumnDefinition> $extensionColumns
     *
     * @return list<ColumnDefinition>
     */
    public static function mergeWith(array $extensionColumns): array
    {
        $merged = [];
        foreach (self::bulk() as $column) {
            $merged[$column->key] = $column;
        }
        foreach ($extensionColumns as $column) {
            $merged[$column->key] = $column;
        }

        return array_values($merged);
    }
}
