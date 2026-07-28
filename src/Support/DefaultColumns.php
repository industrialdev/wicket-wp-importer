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
     * Global gender enum values. X = don't want to specify. A client with a
     * narrower set (e.g. OBA accepts M/F only) overrides the gender column by
     * canonical key via {@see mergeWith()}.
     */
    public const GENDER_VALUES = ['M', 'F', 'X'];

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
                validators: [['type' => 'required'], ['type' => 'email']],
                dedup: true,
                aliases: ['email address', 'email_address', 'e-mail', 'e mail', 'mail'],
            ),
        ];
    }

    /**
     * Reusable person-profile column bundle with pre-wired format validators.
     *
     * Generic contact + demographic fields any client might collect: address
     * (2 lines), city, US state, ZIP, phone, fax, birthdate, and gender. These
     * live in core (not a client extension) because they are reusable plumbing,
     * not domain knowledge: a future client collecting the same fields should
     * not have to re-declare them. AD1 governs client-specific KNOWLEDGE; a
     * postal address is universal.
     *
     * Columns carry FORMAT validators only (us_state / zip / phone / date /
     * enum). Required-ness is opt-in: pass the canonical keys of the fields a
     * client mandates and they get required=true (header mandatory) plus a
     * RequiredValidator spec (cell non-empty). A client that collects a field
     * optionally still gets format validation on non-empty values.
     *
     * Gender defaults to the {@see GENDER_VALUES} enum (M/F/X). A client with a
     * narrower gender set overrides the gender column by key.
     *
     * @param list<string> $required Canonical keys to mark mandatory (header + value).
     *
     * @return list<ColumnDefinition>
     */
    public static function profile(array $required = []): array
    {
        $required = array_values(array_filter($required, 'is_string'));
        $isRequired = static fn (string $key): bool => in_array($key, $required, true);

        $make = static function (string $key, string $label, array $aliases, array $validators) use ($isRequired): ColumnDefinition {
            return new ColumnDefinition(
                key: $key,
                label: $label,
                required: $isRequired($key),
                validators: $isRequired($key) ? array_merge([['type' => 'required']], $validators) : $validators,
                aliases: $aliases,
            );
        };

        return [
            $make(
                'address_1',
                __('Address Line 1', 'wicket-wp-importer'),
                ['address1', 'address line one', 'address 1', 'street', 'street address'],
                []
            ),
            $make(
                'address_2',
                __('Address Line 2', 'wicket-wp-importer'),
                ['address2', 'address line two', 'address 2', 'unit', 'apt'],
                []
            ),
            $make(
                'city',
                __('City', 'wicket-wp-importer'),
                ['town', 'locality'],
                []
            ),
            $make(
                'state',
                __('State', 'wicket-wp-importer'),
                ['st', 'state province', 'province', 'region'],
                [['type' => 'us_state']]
            ),
            $make(
                'zip',
                __('ZIP', 'wicket-wp-importer'),
                ['zip code', 'zip_code', 'postal code', 'postal_code', 'postcode', 'postal'],
                [['type' => 'zip']]
            ),
            $make(
                'phone',
                __('Phone', 'wicket-wp-importer'),
                ['phone number', 'phone_number', 'telephone', 'tel', 'mobile', 'cell'],
                [['type' => 'phone']]
            ),
            $make(
                'fax',
                __('Fax', 'wicket-wp-importer'),
                ['fax number', 'fax_number', 'facsimile'],
                [['type' => 'phone']]
            ),
            $make(
                'birthdate',
                __('Birthdate', 'wicket-wp-importer'),
                ['birth date', 'birth_date', 'dob', 'date of birth', 'birthday', 'birthdate (ymd)'],
                [['type' => 'date']]
            ),
            $make(
                'gender',
                __('Gender', 'wicket-wp-importer'),
                ['sex'],
                [['type' => 'enum', 'values' => self::GENDER_VALUES]]
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
