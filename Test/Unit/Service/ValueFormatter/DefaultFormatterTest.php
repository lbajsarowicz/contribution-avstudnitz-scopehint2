<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Service\ValueFormatter;

use AvS\ScopeHint\Service\OptionLabelResolver;
use AvS\ScopeHint\Service\ValueFormatter\DefaultFormatter;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\Escaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DefaultFormatterTest extends TestCase
{
    /** @var OptionLabelResolver&(MockObject|Stub) */
    private $labelResolver;

    /** @var Escaper&Stub */
    private $escaper;

    /** @var DefaultFormatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->configureFixture();
}
    private function configureFixture(
        ?OptionLabelResolver $labelResolver = null
    ): void
    {
        $this->labelResolver = $labelResolver ?? $this->createStub(OptionLabelResolver::class);
        $this->escaper = $this->createStub(Escaper::class);
        $this->escaper->method('escapeHtml')->willReturnCallback(static function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES);
        });

        $this->formatter = new DefaultFormatter($this->labelResolver, $this->escaper);
    }

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $field = $this->createStub(Field::class);

        self::assertTrue($this->formatter->supports($field));
    }

    public function testFormatScalarValueDelegatesToLabelResolver(): void
    {
        $this->configureFixture(
            labelResolver: $this->createMock(OptionLabelResolver::class)
        );
        $field = $this->createStub(Field::class);
        $this->labelResolver->expects(self::atLeastOnce())
            ->method('resolve')->with($field, '1')->willReturn('Enabled');

        $result = $this->formatter->format('1', $field);

        self::assertSame('Enabled', $result);
    }

    public function testFormatArrayValueReturnsComplexValue(): void
    {
        $field = $this->createStub(Field::class);

        $result = $this->formatter->format(['a' => 1], $field);

        self::assertStringContainsString('complex value', $result);
    }

    public function testFormatEscapesHtml(): void
    {
        $field = $this->createStub(Field::class);
        $this->labelResolver->method('resolve')->willReturn('<b>bold</b>');

        $result = $this->formatter->format('<b>bold</b>', $field);

        self::assertStringContainsString('&lt;b&gt;', $result);
    }
}
