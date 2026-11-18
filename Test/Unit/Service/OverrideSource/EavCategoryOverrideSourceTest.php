<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Test\Unit\Service\OverrideSource;

use AvS\ScopeHint\Api\Data\ScopeOverrideInterface;
use AvS\ScopeHint\Service\EavOptionLabelResolver;
use AvS\ScopeHint\Service\OverrideSource\EavCategoryOverrideSource;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogAttribute;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EavCategoryOverrideSourceTest extends TestCase
{
    /** @var CategoryRepositoryInterface&Stub */
    private $categoryRepository;

    /** @var StoreManagerInterface&(MockObject|Stub) */
    private $storeManager;

    /** @var ScopeConfigInterface&(MockObject|Stub) */
    private $scopeConfig;

    /** @var EavConfig&Stub */
    private $eavConfig;

    /** @var Escaper&Stub */
    private $escaper;

    /** @var LoggerInterface&(MockObject|Stub) */
    private $logger;

    /** @var EavCategoryOverrideSource */
    private $source;

    protected function setUp(): void
    {
        $this->configureFixture();
}
    private function configureFixture(
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null
    ): void
    {
        $this->categoryRepository = $this->createStub(CategoryRepositoryInterface::class);
        $this->storeManager = $storeManager ?? $this->createStub(StoreManagerInterface::class);
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->eavConfig = $this->createStub(EavConfig::class);
        $this->escaper = $this->createStub(Escaper::class);
        $this->logger = $logger ?? $this->createStub(LoggerInterface::class);

        $this->escaper->method('escapeHtml')->willReturnArgument(0);

        // Default: max_stores not exceeded
        $this->scopeConfig->method('getValue')->willReturnCallback(static function ($path) {
            self::assertSame(EavCategoryOverrideSource::XML_PATH_MAX_STORES, $path);
            return 50;
        });

        $this->source = new EavCategoryOverrideSource(
            $this->categoryRepository,
            $this->storeManager,
            $this->scopeConfig,
            $this->eavConfig,
            $this->escaper,
            $this->logger,
            new EavOptionLabelResolver()
        );
    }

    // ------------------------------------------------------------------
    // supports() tests
    // ------------------------------------------------------------------

    public function testSupportsReturnsFalseForProductEntityType(): void
    {
        self::assertFalse($this->source->supports('product', ['code' => 'name']));
    }

    public function testSupportsReturnsFalseWhenCodeIsMissing(): void
    {
        self::assertFalse($this->source->supports('category', []));
    }

    public function testSupportsReturnsFalseWhenCodeIsEmpty(): void
    {
        self::assertFalse($this->source->supports('category', ['code' => '']));
    }

    public function testSupportsReturnsFalseWhenGlobalScopeIsTrue(): void
    {
        $this->stubAttribute(ScopedAttributeInterface::SCOPE_GLOBAL);
        self::assertFalse(
            $this->source->supports('category', ['code' => 'name', 'globalScope' => true])
        );
    }

    public function testSupportsReturnsTrueForValidCategoryField(): void
    {
        $this->stubAttribute(ScopedAttributeInterface::SCOPE_STORE);
        self::assertTrue($this->source->supports('category', ['code' => 'name']));
    }

    public function testSupportsReturnsTrueWhenGlobalScopeIsFalsy(): void
    {
        $this->stubAttribute(ScopedAttributeInterface::SCOPE_STORE);
        self::assertTrue(
            $this->source->supports('category', ['code' => 'description', 'globalScope' => false])
        );
    }

    // ------------------------------------------------------------------
    // getOverrides() tests
    // ------------------------------------------------------------------

    public function testGetOverridesReturnsEmptyResultWhenNoStores(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);

        $defaultCategory = $this->createCategory(10, 'name', 'Root');
        $this->categoryRepository->method('get')->willReturn($defaultCategory);

        $result = $this->source->getOverrides('category', 10, ['code' => 'name']);

        self::assertTrue($result->isEmpty());
    }

    public function testGetOverridesReturnsEmptyResultWhenCategoryNotFound(): void
    {
        $store = $this->createStore(1, 'default', 'Default Store', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $this->categoryRepository
            ->method('get')
            ->willThrowException(new NoSuchEntityException());

        $result = $this->source->getOverrides('category', 999, ['code' => 'name']);

        self::assertTrue($result->isEmpty());
    }

    public function testGetOverridesDetectsStoreOverride(): void
    {
        $this->configureFixture(
            storeManager: $this->createMock(StoreManagerInterface::class)
        );
        $store = $this->createStore(2, 'de', 'German Store', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $website = $this->createWebsite(1, 'base');
        $this->storeManager->expects(self::atLeastOnce())
            ->method('getWebsite')->with(1)->willReturn($website);

        $defaultCategory = $this->createCategory(10, 'name', 'Shoes');
        $storeCategory   = $this->createCategory(10, 'name', 'Schuhe');

        $this->categoryRepository
            ->method('get')
            ->willReturnMap([
                [10, 0, $defaultCategory],
                [10, 2, $storeCategory],
            ]);

        $result = $this->source->getOverrides('category', 10, ['code' => 'name']);

        self::assertFalse($result->isEmpty());
        self::assertSame(1, $result->count());

        $overrides = $result->getOverrides();
        self::assertSame(ScopeOverrideInterface::SCOPE_TYPE_STORES, $overrides[0]->getScopeType());
        self::assertSame('de', $overrides[0]->getScopeCode());
    }

    public function testGetOverridesComparesAgainstEditedStoreAndExcludesIt(): void
    {
        $this->storeManager->method('getStores')->willReturn([
            $this->createStore(1, 'en', 'English Store', 1),
            $this->createStore(2, 'de', 'German Store', 1),
        ]);
        $this->storeManager->method('getWebsite')->willReturn($this->createWebsite(1, 'base'));
        $this->categoryRepository->method('get')->willReturnMap([
            [10, 1, $this->createCategory(10, 'name', 'Shoes')],
            [10, 2, $this->createCategory(10, 'name', 'Schuhe')],
        ]);

        $overrides = $this->source->getOverrides('category', 10, ['code' => 'name'], 2)->getOverrides();

        self::assertCount(1, $overrides);
        self::assertSame('en', $overrides[0]->getScopeCode());
        self::assertSame('Shoes', $overrides[0]->getDisplayValue());
    }

    public function testGetOverridesSkipsUnchangedStoreValue(): void
    {
        $store = $this->createStore(2, 'de', 'German Store', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $category = $this->createCategory(10, 'name', 'Same');

        $this->categoryRepository->method('get')->willReturn($category);

        $result = $this->source->getOverrides('category', 10, ['code' => 'name']);

        self::assertTrue($result->isEmpty());
    }

    public function testGetOverridesLogsWarningAndReturnsEmptyWhenStoreCountExceedsMax(): void
    {
        $this->configureFixture(
            logger: $this->createMock(LoggerInterface::class)
        );
        $stores = [
            $this->createStore(1, 's1', 'Store 1', 1),
            $this->createStore(2, 's2', 'Store 2', 1),
            $this->createStore(3, 's3', 'Store 3', 1),
        ];
        $this->storeManager->method('getStores')->willReturn($stores);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::atLeastOnce())
            ->method('getValue')
            ->with(EavCategoryOverrideSource::XML_PATH_MAX_STORES)
            ->willReturn(2);

        $this->logger->expects(self::once())->method('warning');

        $source = new EavCategoryOverrideSource(
            $this->categoryRepository,
            $this->storeManager,
            $scopeConfig,
            $this->eavConfig,
            $this->escaper,
            $this->logger,
            new EavOptionLabelResolver()
        );

        $result = $source->getOverrides('category', 1, ['code' => 'name']);

        self::assertTrue($result->isEmpty());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param mixed $value
     */
    private function createCategory(int $id, string $attributeCode, $value): CategoryInterface
    {
        $category = $this->createMock(CategoryWithDataInterface::class);
        $category->method('getId')->willReturn($id);
        $category->expects(self::atLeastOnce())
            ->method('getData')
            ->with($attributeCode)
            ->willReturn($value);

        return $category;
    }

    private function createStore(int $id, string $code, string $name, int $websiteId): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getName')->willReturn($name);
        $store->method('getWebsiteId')->willReturn($websiteId);

        return $store;
    }

    private function createWebsite(int $id, string $code): WebsiteInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn($id);
        $website->method('getCode')->willReturn($code);

        return $website;
    }

    private function stubAttribute(int $scope): void
    {
        $attribute = $this->createStub(CatalogAttribute::class);
        $attribute->method('getAttributeId')->willReturn(42);
        $attribute->method('getIsGlobal')->willReturn($scope);
        $this->eavConfig->method('getAttribute')->willReturn($attribute);
    }
}
