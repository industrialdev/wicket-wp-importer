<?php

declare(strict_types=1);

namespace WicketImporter\Mapping;

class MappingEntry
{
    public ?int $id;
    public string $roleSlug;
    public string $mappingType;      // 'late_fee' | 'discount' | 'section'
    public string $applicationType;  // 'product' | 'coupon'
    public ?int $productId;
    public ?string $productSku;
    public ?string $couponCode;
    public string $label;
    public bool $isActive;
    public int $sortOrder;

    public function __construct(
        string $roleSlug,
        string $mappingType,
        string $applicationType = 'product',
        ?int $productId = null,
        ?string $productSku = null,
        ?string $couponCode = null,
        string $label = '',
        bool $isActive = true,
        int $sortOrder = 0,
        ?int $id = null
    ) {
        $this->roleSlug = $roleSlug;
        $this->mappingType = $mappingType;
        $this->applicationType = $applicationType;
        $this->productId = $productId;
        $this->productSku = $productSku;
        $this->couponCode = $couponCode;
        $this->label = $label ?: $roleSlug;
        $this->isActive = $isActive;
        $this->sortOrder = $sortOrder;
        $this->id = $id;
    }

    /**
     * Instantiate from raw array format stored in HyperFields options.
     */
    public static function fromArray(array $data, string $type): self
    {
        return new self(
            (string) ($data['role_slug'] ?? ''),
            $type,
            (string) ($data['application_type'] ?? 'product'),
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            isset($data['product_sku']) ? (string) $data['product_sku'] : null,
            isset($data['coupon_code']) ? (string) $data['coupon_code'] : null,
            (string) ($data['label'] ?? ''),
            // B12: default a mapping with no is_active key to INACTIVE (fail-closed
            // on money). The HyperFields checkbox sets is_active=true on save, so
            // a missing key means a malformed/legacy row that must not bill.
            isset($data['is_active']) && (bool) $data['is_active'],
            (int) ($data['sort_order'] ?? 0),
            isset($data['id']) ? (int) $data['id'] : null
        );
    }

    /**
     * Convert to array format.
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
}
