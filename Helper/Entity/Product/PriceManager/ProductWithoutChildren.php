<?php

namespace Algolia\AlgoliaSearch\Helper\Entity\Product\PriceManager;

use Algolia\AlgoliaSearch\Helper\ConfigHelper;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Store\Model\Store;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Customer\Model\Group;
use Magento\CatalogRule\Model\ResourceModel\Rule;

abstract class ProductWithoutChildren
{
    protected $configHelper;
    protected $customerGroupCollectionFactory;
    protected $priceCurrency;
    protected $catalogHelper;
    protected $taxHelper;
    protected $rule;

    protected $store;
    protected $baseCurrencyCode;
    protected $groups;
    protected $areCustomersGroupsEnabled;
    protected $customData = [];

    public function __construct(
        ConfigHelper $configHelper,
        CollectionFactory $customerGroupCollectionFactory,
        PriceCurrencyInterface $priceCurrency,
        CatalogHelper $catalogHelper,
        TaxHelper $taxHelper,
        Rule $rule
    ) {
        $this->configHelper = $configHelper;
        $this->customerGroupCollectionFactory = $customerGroupCollectionFactory;
        $this->priceCurrency = $priceCurrency;
        $this->catalogHelper = $catalogHelper;
        $this->taxHelper = $taxHelper;
        $this->rule = $rule;
    }

    public function addPriceData($customData, Product $product, $subProducts)
    {
        $this->customData = $customData;
        $this->store = $product->getStore();
        $type = $product->getTypeId();

        $fields = $this->getFields();

        $this->areCustomersGroupsEnabled = $this->configHelper->isCustomerGroupsEnabled($product->getStoreId());

        $currencies = $this->store->getAvailableCurrencyCodes();
        $this->baseCurrencyCode = $this->store->getBaseCurrencyCode();

        $this->groups = $this->customerGroupCollectionFactory->create();

        if (!$this->areCustomersGroupsEnabled) {
            $this->groups->addFieldToFilter('main_table.customer_group_id', 0);
        }

        foreach ($fields as $field => $withTax) {
            $this->customData[$field] = [];

            foreach ($currencies as $currencyCode) {
                $this->customData[$field][$currencyCode] = [];

                $price = $product->getPrice();
                if ($currencyCode !== $this->baseCurrencyCode) {
                    $price = $this->priceCurrency->convert($price, $this->store, $currencyCode);
                }

                $price = (double) $this->catalogHelper
                    ->getTaxPrice($product, $price, $withTax, null, null, null, $product->getStore(), null);

                $this->customData[$field][$currencyCode]['default'] = $this->priceCurrency->round($price);
                $this->customData[$field][$currencyCode]['default_formated'] = $this->priceCurrency->format(
                    $price,
                    false,
                    PriceCurrencyInterface::DEFAULT_PRECISION,
                    $this->store,
                    $currencyCode
                );

                $specialPrice = $this->getSpecialPrice($product, $currencyCode, $withTax);

                if ($this->areCustomersGroupsEnabled) {
                    $this->addCustomerGroupsPrices($product, $currencyCode, $withTax, $field);
                }

                $this->customData[$field][$currencyCode]['special_from_date'] =
                    strtotime($product->getSpecialFromDate());
                $this->customData[$field][$currencyCode]['special_to_date'] =
                    strtotime($product->getSpecialToDate());

                $this->addSpecialPrices($specialPrice, $field, $currencyCode);

                $this->addAdditionalData($product, $withTax, $subProducts, $currencyCode, $field);
            }
        }

        return $this->customData;
    }

    protected function addAdditionalData(
        $product,
        $withTax,
        $subProducts,
        $currencyCode,
        $field
    ) {
        // Empty for products without children
    }

    protected function getFields()
    {
        $priceDisplayType = $this->taxHelper->getPriceDisplayType($this->store);

        if ($priceDisplayType === TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX) {
            return ['price' => false];
        }

        if ($priceDisplayType === TaxConfig::DISPLAY_TYPE_INCLUDING_TAX) {
            return ['price' => true];
        }

        return ['price' => false, 'price_with_tax' => true];
    }

    protected function getSpecialPrice(
        Product $product,
        $currencyCode,
        $withTax
    ) {
        $specialPrice = [];

        /** @var Group $group */
        foreach ($this->groups as $group) {
            $groupId = (int) $group->getData('customer_group_id');
            $specialPrices[$groupId] = [];
            $specialPrices[$groupId][] = $this->getRulePrice($groupId, $product);

            // The price with applied catalog rules
            $specialPrices[$groupId][] = $product->getFinalPrice(); // The product's special price

            $specialPrices[$groupId] = array_filter($specialPrices[$groupId], function ($price) {
                return $price > 0;
            });

            $specialPrice[$groupId] = false;
            if ($specialPrices[$groupId] && $specialPrices[$groupId] !== []) {
                $specialPrice[$groupId] = min($specialPrices[$groupId]);
            }

            if ($specialPrice[$groupId]) {
                if ($currencyCode !== $this->baseCurrencyCode) {
                    $specialPrice[$groupId] = $this->priceCurrency->convert(
                        $specialPrice[$groupId],
                        $this->store,
                        $currencyCode
                    );
                    $specialPrice[$groupId] = $this->priceCurrency->round($specialPrice[$groupId]);
                }

                $specialPrice[$groupId] = (double) $this->catalogHelper->getTaxPrice(
                    $product,
                    $specialPrice[$groupId],
                    $withTax,
                    null,
                    null,
                    null,
                    $product->getStore(),
                    null
                );
            }
        }

        return $specialPrice;
    }

    protected function getRulePrice($groupId, $product)
    {
        return (double) $this->rule->getRulePrice(
            new \DateTime(),
            $this->store->getWebsiteId(),
            $groupId,
            $product->getId()
        );
    }

    protected function addCustomerGroupsPrices(
        Product $product,
        $currencyCode,
        $withTax,
        $field
    ) {
        /** @var \Magento\Customer\Model\Group $group */
        foreach ($this->groups as $group) {
            $groupId = (int) $group->getData('customer_group_id');

            $product->setData('customer_group_id', $groupId);

            $discountedPrice = $product->getPriceModel()->getFinalPrice(1, $product);
            if ($currencyCode !== $this->baseCurrencyCode) {
                $discountedPrice = $this->priceCurrency->convert($discountedPrice, $this->store, $currencyCode);
            }

            if ($discountedPrice !== false) {
                $taxPrice = (double) $this->catalogHelper->getTaxPrice(
                    $product,
                    $discountedPrice,
                    $withTax,
                    null,
                    null,
                    null,
                    $product->getStore(),
                    null
                );

                $this->customData[$field][$currencyCode]['group_' . $groupId] = $taxPrice;

                $formated = $this->priceCurrency->format(
                    $this->customData[$field][$currencyCode]['group_' . $groupId],
                    false,
                    PriceCurrencyInterface::DEFAULT_PRECISION,
                    $this->store,
                    $currencyCode
                );
                $this->customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $formated;

                if ($this->customData[$field][$currencyCode]['default'] >
                    $this->customData[$field][$currencyCode]['group_' . $groupId]) {
                    $original = $this->customData[$field][$currencyCode]['default_formated'];
                    $this->customData[$field][$currencyCode]['group_'.$groupId.'_original_formated'] = $original;
                }
            } else {
                $default = $this->customData[$field][$currencyCode]['default'];
                $this->customData[$field][$currencyCode]['group_' . $groupId] = $default;

                $defaultFormated = $this->customData[$field][$currencyCode]['default_formated'];
                $this->customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $defaultFormated;
            }
        }

        $product->setData('customer_group_id', null);
    }

    protected function addSpecialPrices(
        $specialPrice,
        $field,
        $currencyCode
    ) {
        if ($this->areCustomersGroupsEnabled) {
            /** @var \Magento\Customer\Model\Group $group */
            foreach ($this->groups as $group) {
                $groupId = (int) $group->getData('customer_group_id');

                if ($specialPrice[$groupId]
                    && $specialPrice[$groupId] < $this->customData[$field][$currencyCode]['group_' . $groupId]) {
                    $this->customData[$field][$currencyCode]['group_' . $groupId] = $specialPrice[$groupId];

                    $formated = $this->priceCurrency->format(
                        $specialPrice[$groupId],
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $this->store,
                        $currencyCode
                    );
                    $this->customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $formated;

                    if ($this->customData[$field][$currencyCode]['default'] >
                        $this->customData[$field][$currencyCode]['group_' . $groupId]) {
                        $original = $this->customData[$field][$currencyCode]['default_formated'];
                        $this->customData[$field][$currencyCode]['group_'.$groupId.'_original_formated'] = $original;
                    }
                }
            }

            return;
        }

        if ($specialPrice[0] && $specialPrice[0] < $this->customData[$field][$currencyCode]['default']) {
            $defaultOriginalFormated = $this->customData[$field][$currencyCode]['default_formated'];
            $this->customData[$field][$currencyCode]['default_original_formated'] = $defaultOriginalFormated;

            $defaultFormated = $this->priceCurrency->format(
                $specialPrice[0],
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $this->store,
                $currencyCode
            );

            $this->customData[$field][$currencyCode]['default'] = $this->priceCurrency->round($specialPrice[0]);
            $this->customData[$field][$currencyCode]['default_formated'] = $defaultFormated;
        }
    }
}
