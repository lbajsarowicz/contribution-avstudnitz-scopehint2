<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Plugin;

use Magento\Framework\Data\Form\Element\Text;

class TextElement extends Text
{
    public function __construct(private string $htmlId)
    {
    }

    public function getHtmlId()
    {
        return $this->htmlId;
    }
}
