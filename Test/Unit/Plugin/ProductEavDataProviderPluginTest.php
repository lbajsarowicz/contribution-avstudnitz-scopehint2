<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Plugin;

use AvS\ScopeHint\Api\Data\OverrideResultInterface;
use AvS\ScopeHint\Api\Data\ScopeOverrideInterface;
use AvS\ScopeHint\Block\Adminhtml\Eav\Popover;
use AvS\ScopeHint\Plugin\ProductEavDataProviderPlugin;
use AvS\ScopeHint\Service\OverrideSourcePool;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Eav;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ProductEavDataProviderPluginTest extends TestCase
{
    /** @var OverrideSourcePool&(MockObject|Stub) */
    private $pool;

    /** @var LayoutInterface&(MockObject|Stub) */
    private $layout;

    /** @var ScopeConfigInterface&(MockObject|Stub) */
    private $scopeConfig;

    /** @var Registry&(MockObject|Stub) */
    private $registry;

    /** @var Escaper&Stub */
    private $escaper;

    /** @var Eav&Stub */
    private $subject;

    /** @var ProductEavDataProviderPlugin */
    private $plugin;

    protected function setUp(): void
    {
        $this->configureFixture();
}
    private function configureFixture(
        ?OverrideSourcePool $pool = null,
        ?LayoutInterface $layout = null,
        ?ScopeConfigInterface $scopeConfig = null,
        ?Registry $registry = null
    ): void
    {
        $this->pool = $pool ?? $this->createStub(OverrideSourcePool::class);
        $this->layout = $layout ?? $this->createStub(LayoutInterface::class);
        $this->scopeConfig = $scopeConfig ?? $this->createStub(ScopeConfigInterface::class);
        $this->registry = $registry ?? $this->createStub(Registry::class);
        $this->escaper = $this->createStub(Escaper::class);
        $this->escaper->method('escapeHtml')->willReturnCallback(static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        });
        $this->subject = $this->createStub(Eav::class);

        $this->plugin = new ProductEavDataProviderPlugin(
            $this->pool,
            $this->layout,
            $this->scopeConfig,
            $this->registry,
            $this->escaper
        );
    }

    public function testReturnsResultUnchangedWhenConfigIsMissing(): void
    {
        $result = ['arguments' => []];

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertSame($result, $output);
    }

    public function testReturnsResultUnchangedWhenNoCurrentProduct(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $this->registry->expects(self::atLeastOnce())
            ->method('registry')->with('current_product')->willReturn(null);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertSame($result, $output);
    }

    public function testReturnsResultUnchangedWhenProductIdIsNull(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(null);
        $this->registry->expects(self::atLeastOnce())
            ->method('registry')->with('current_product')->willReturn($product);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertSame($result, $output);
    }

    public function testReturnsResultUnchangedWhenPoolReturnsNull(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->pool->method('resolve')->willReturn(null);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertSame($result, $output);
        self::assertArrayNotHasKey('additionalInfo', $output['arguments']['data']['config']);
    }

    public function testReturnsResultUnchangedWhenOverridesAreEmpty(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);

        $empty = $this->createStub(OverrideResultInterface::class);
        $empty->method('isEmpty')->willReturn(true);
        $this->pool->method('resolve')->willReturn($empty);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertSame($result, $output);
    }

    public function testSetsAdditionalInfoWhenOverridesExist(): void
    {
        $this->configureFixture(
            layout: $this->createMock(LayoutInterface::class),
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $overrides = $this->createStub(OverrideResultInterface::class);
        $overrides->method('isEmpty')->willReturn(false);
        $this->pool->method('resolve')->willReturn($overrides);

        $popoverBlock = $this->createStub(Popover::class);
        $popoverBlock->method('toHtml')->willReturn('<div data-scopehint-popover="1">POP</div>');
        $popoverBlock->method('setOverrides')->willReturnSelf();
        $this->layout->expects(self::atLeastOnce())
            ->method('createBlock')->with(Popover::class)->willReturn($popoverBlock);

        $result = $this->buildResult(['code' => 'meta_description']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertArrayHasKey('additionalInfo', $output['arguments']['data']['config']);
        self::assertStringContainsString('data-scopehint-popover', $output['arguments']['data']['config']['additionalInfo']);
    }

    public function testSetsAdditionalInfoForPageBuilderAttributeMetadata(): void
    {
        $this->configureFixture(
            pool: $this->createMock(OverrideSourcePool::class),
            layout: $this->createMock(LayoutInterface::class),
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $overrides = $this->createStub(OverrideResultInterface::class);
        $overrides->method('isEmpty')->willReturn(false);

        $fieldMeta = [
            'code' => 'description',
            'formElement' => 'wysiwyg',
            'globalScope' => false,
            'wysiwygConfigData' => ['is_pagebuilder_enabled' => true],
        ];
        $this->pool->expects(self::once())->method('resolve')->with('product', 5, $fieldMeta)
            ->willReturn($overrides);

        $popoverBlock = $this->createStub(Popover::class);
        $popoverBlock->method('toHtml')->willReturn('<div data-scopehint-popover="1">POP</div>');
        $popoverBlock->method('setOverrides')->willReturnSelf();
        $this->layout->expects(self::atLeastOnce())
            ->method('createBlock')->with(Popover::class)->willReturn($popoverBlock);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $this->buildResult($fieldMeta));

        self::assertStringContainsString(
            'data-scopehint-popover',
            $output['arguments']['data']['config']['additionalInfo']
        );
    }

    public function testDoesNotSetTooltipDescriptionInDefaultMode(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $overrides = $this->createStub(OverrideResultInterface::class);
        $overrides->method('isEmpty')->willReturn(false);
        $this->pool->method('resolve')->willReturn($overrides);

        $popoverBlock = $this->createStub(Popover::class);
        $popoverBlock->method('toHtml')->willReturn('<div data-scopehint-popover="1">POP</div>');
        $popoverBlock->method('setOverrides')->willReturnSelf();
        $this->layout->method('createBlock')->willReturn($popoverBlock);

        $result = $this->buildResult(['code' => 'meta_description']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertArrayNotHasKey('tooltip', $output['arguments']['data']['config']);
    }

    public function testSetsTooltipDescriptionInLegacyMode(): void
    {
        $this->configureFixture(
            scopeConfig: $this->createMock(ScopeConfigInterface::class),
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->scopeConfig->expects(self::atLeastOnce())
            ->method('isSetFlag')->with('dev/debug/scopehint_legacy_tooltip')->willReturn(true);

        $scopeOverride = $this->createStub(ScopeOverrideInterface::class);
        $scopeOverride->method('getScopeName')->willReturn('German Store');
        $scopeOverride->method('getDisplayValue')->willReturn('Überschriebener Wert');

        $overrides = $this->createStub(OverrideResultInterface::class);
        $overrides->method('isEmpty')->willReturn(false);
        $overrides->method('getOverrides')->willReturn([$scopeOverride]);
        $this->pool->method('resolve')->willReturn($overrides);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertArrayHasKey('tooltip', $output['arguments']['data']['config']);
        self::assertStringContainsString(
            'German Store',
            $output['arguments']['data']['config']['tooltip']['description']
        );
        self::assertArrayNotHasKey('additionalInfo', $output['arguments']['data']['config']);
    }

    public function testDoesNotSetAdditionalInfoWhenPopoverHtmlIsEmpty(): void
    {
        $this->configureFixture(
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(5);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $overrides = $this->createStub(OverrideResultInterface::class);
        $overrides->method('isEmpty')->willReturn(false);
        $this->pool->method('resolve')->willReturn($overrides);

        $popoverBlock = $this->createStub(Popover::class);
        $popoverBlock->method('toHtml')->willReturn('');
        $popoverBlock->method('setOverrides')->willReturnSelf();
        $this->layout->method('createBlock')->willReturn($popoverBlock);

        $result = $this->buildResult(['code' => 'name']);

        $output = $this->plugin->afterSetupAttributeMeta($this->subject, $result);

        self::assertArrayNotHasKey('additionalInfo', $output['arguments']['data']['config']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function testPoolReceivesEditedStoreId(): void
    {
        $this->configureFixture(
            pool: $this->createMock(OverrideSourcePool::class),
            registry: $this->createMock(Registry::class)
        );
        $this->stubProduct(7, 2);
        $fieldMeta = ['code' => 'name'];

        $this->pool->expects(self::once())
            ->method('resolve')
            ->with('product', 7, $fieldMeta, 2)
            ->willReturn(null);

        $this->plugin->afterSetupAttributeMeta($this->subject, $this->buildResult($fieldMeta));
    }

    private function stubProduct(int $id, int $storeId = 0): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getStoreId')->willReturn($storeId);
        $this->registry->expects(self::atLeastOnce())
            ->method('registry')->with('current_product')->willReturn($product);
    }

    private function buildResult(array $fieldMeta): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => $fieldMeta,
                ],
            ],
        ];
    }
}
