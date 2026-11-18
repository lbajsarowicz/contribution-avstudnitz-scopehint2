<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Service\OverrideSource;

use Magento\Catalog\Api\Data\CategoryInterface;

interface CategoryWithDataInterface extends CategoryInterface
{
    /**
     * Get an entity data value.
     *
     * @param string $key
     * @param int|string|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null);
}
