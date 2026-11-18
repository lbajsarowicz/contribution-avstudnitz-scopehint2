<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Service\OverrideSource;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

interface ProductWithDataInterface extends ProductInterface
{
    /**
     * Get an entity data value.
     *
     * @param string $key
     * @param int|string|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null);

    /**
     * Get the product resource.
     *
     * @return ProductResource|null
     */
    public function getResource();
}
