<?php

declare(strict_types=1);

namespace WicketImporter\Mapping;

use HyperFields\HyperFields;
use WicketImporter\BulkImport\Database\DbInstaller;

class MappingSettings
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'register']);
    }

    /**
     * Register the options settings page with HyperFields.
     */
    public function register(): void
    {
        if (!class_exists('\HyperFields\HyperFields')) {
            return;
        }

        $options_page = HyperFields::makeOptionPage('Import Mappings', 'wicket-wp-importer-mappings');
        $options_page->setMenuTitle('Mappings');
        $options_page->setParentSlug('wicket-settings');
        $options_page->setOptionName(DbInstaller::MAPPINGS_OPTION);

        // Section 1: Late Fees Repeater
        $late_fees_repeater = HyperFields::makeRepeater('late_fees', 'Late Fee Mappings');
        $late_fees_repeater->addSubFields([
            HyperFields::makeField('text', 'role_slug', 'Role Slug'),
            HyperFields::makeField('text', 'product_id', 'WC Product ID'),
            HyperFields::makeField('text', 'product_sku', 'Product SKU'),
            HyperFields::makeField('text', 'label', 'Label'),
            HyperFields::makeField('checkbox', 'is_active', 'Active')->setDefault(true),
        ]);

        $late_fees_section = $options_page->addSection('late_fees_sec', 'Late Fees');
        $late_fees_section->addField($late_fees_repeater);

        // Section 2: Discounts Repeater
        $discounts_repeater = HyperFields::makeRepeater('discounts', 'Discount Mappings');
        $discounts_repeater->addSubFields([
            HyperFields::makeField('text', 'role_slug', 'Role Slug'),
            HyperFields::makeField('select', 'application_type', 'Application Type')->setOptions([
                'product' => 'Negative Product Line Item',
                'coupon'  => 'WooCommerce Coupon',
            ])->setDefault('product'),
            HyperFields::makeField('text', 'product_id', 'WC Product ID'),
            HyperFields::makeField('text', 'product_sku', 'Product SKU'),
            HyperFields::makeField('text', 'coupon_code', 'Coupon Code'),
            HyperFields::makeField('text', 'label', 'Label'),
            HyperFields::makeField('checkbox', 'is_active', 'Active')->setDefault(true),
        ]);

        $discounts_section = $options_page->addSection('discounts_sec', 'Discounts');
        $discounts_section->addField($discounts_repeater);

        // Section 3: Sections Repeater
        $sections_repeater = HyperFields::makeRepeater('sections', 'Section Mappings');
        $sections_repeater->addSubFields([
            HyperFields::makeField('text', 'role_slug', 'MDP Group/Section Slug'),
            HyperFields::makeField('text', 'product_id', 'WC Product ID'),
            HyperFields::makeField('text', 'product_sku', 'Product SKU'),
            HyperFields::makeField('text', 'label', 'Label'),
            HyperFields::makeField('checkbox', 'is_active', 'Active')->setDefault(true),
        ]);

        $sections_section = $options_page->addSection('sections_sec', 'Sections');
        $sections_section->addField($sections_repeater);

        $options_page->register();
    }
}
