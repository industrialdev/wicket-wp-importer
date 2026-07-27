<?php

declare(strict_types=1);

namespace WicketImporter\Mapping;

/**
 * Immutable mapping entry (a late-fee / discount / section rule).
 *
 * Readonly:HyperFields writes the option directly and MappingRepository is
 * read-only, so nothing mutates an entry after construction. The mutable
 * version existed only to support a since-removed toggleActive().
 */
final readonly class MappingEntry
{
    public function __construct(
        public string $roleSlug,
        public string $mappingType,
        public string $applicationType = 'product',
        public ?int $productId = null,
        public ?string $productSku = null,
        public ?string $couponCode = null,
        public string $label = '',
        public bool $isActive = true,
        public int $sortOrder = 0,
        public ?int $id = null,
    ) {}

    /**
     * Instantiate from raw array format stored in HyperFields options.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $type): self
    {
        return new self(
            roleSlug: (string) ($data['role_slug'] ?? ''),
            mappingType: $type,
            applicationType: (string) ($data['application_type'] ?? 'product'),
            productId: isset($data['product_id']) ? (int) $data['product_id'] : null,
            productSku: isset($data['product_sku']) ? (string) $data['product_sku'] : null,
            couponCode: isset($data['coupon_code']) ? (string) $data['coupon_code'] : null,
            label: (string) ($data['label'] ?? ''),
            // B12: a missing is_active key defaults to INACTIVE (fail-closed).
            isActive: isset($data['is_active']) && (bool) $data['is_active'],
            sortOrder: (int) ($data['sort_order'] ?? 0),
            id: isset($data['id']) ? (int) $data['id'] : null,
        );
    }

    /**
     * Convert to array format (for the HyperFields option shape).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'role_slug'        => $this->roleSlug,
            'mapping_type'     => $this->mappingType,
            'application_type' => $this->applicationType,
            'product_id'       => $this->productId,
            'product_sku'      => $this->productSku,
            'coupon_code'      => $this->couponCode,
            'label'            => $this->label,
            'is_active'        => $this->isActive ? 1 : 0,
            'sort_order'       => $this->sortOrder,
        ];
    }

    /**
     * G4: resolve the concrete WC product id, preferring SKU (env-portable
     * per D-LOCKBOX-1) and falling back to the stored product_id. Returns null
     * when neither resolves to a real product.
     */
    public function resolveProductId(): ?int
    {
        if (!empty($this->productSku) && function_exists('wc_get_product_id_by_sku')) {
            $by_sku = (int) \wc_get_product_id_by_sku($this->productSku);
            if ($by_sku > 0) {
                return $by_sku;
            }
        }

        return ($this->productId !== null && $this->productId > 0) ? $this->productId : null;
    }
}
