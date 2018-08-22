<?php

namespace Algolia\AlgoliaSearch\Helper\Entity\Product;

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
    private $configHelper;
    private $customerGroupCollectionFactory;
    private $priceCurrency;
    private $catalogHelper;
    private $taxHelper;
    private $rule;

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
        $store = $product->getStore();
        $type = $product->getTypeId();

        $fields = $this->getFields($store);

        $areCustomersGroupsEnabled = $this->configHelper->isCustomerGroupsEnabled($product->getStoreId());

        $currencies = $store->getAvailableCurrencyCodes();
        $baseCurrencyCode = $store->getBaseCurrencyCode();

        $groups = $this->customerGroupCollectionFactory->create();

        if (!$areCustomersGroupsEnabled) {
            $groups->addFieldToFilter('main_table.customer_group_id', 0);
        }

        foreach ($fields as $field => $withTax) {
            $customData[$field] = [];

            foreach ($currencies as $currencyCode) {
                $customData[$field][$currencyCode] = [];

                $price = $product->getPrice();
                if ($currencyCode !== $baseCurrencyCode) {
                    $price = $this->priceCurrency->convert($price, $store, $currencyCode);
                }

                $price = (double) $this->catalogHelper
                    ->getTaxPrice($product, $price, $withTax, null, null, null, $product->getStore(), null);

                $customData[$field][$currencyCode]['default'] = $this->priceCurrency->round($price);
                $customData[$field][$currencyCode]['default_formated'] = $this->priceCurrency->format(
                    $price,
                    false,
                    PriceCurrencyInterface::DEFAULT_PRECISION,
                    $store,
                    $currencyCode
                );

                $specialPrice = $this->getSpecialPrice(
                    $groups,
                    $store,
                    $product,
                    $currencyCode,
                    $baseCurrencyCode,
                    $withTax
                );

                if ($areCustomersGroupsEnabled) {
                    $customData = $this->addCustomerGroupsPrices(
                        $customData,
                        $groups,
                        $product,
                        $store,
                        $currencyCode,
                        $baseCurrencyCode,
                        $withTax,
                        $field
                    );
                }

                $customData[$field][$currencyCode]['special_from_date'] = strtotime($product->getSpecialFromDate());
                $customData[$field][$currencyCode]['special_to_date'] = strtotime($product->getSpecialToDate());

                $customData = $this->addSpecialPrices(
                    $customData,
                    $areCustomersGroupsEnabled,
                    $groups,
                    $specialPrice,
                    $field,
                    $currencyCode,
                    $store
                );

//                if ($type === 'configurable' || $type === 'grouped' || $type === 'bundle') {
//                    list($min, $max) = $this->getMinMaxPrices(
//                        $product,
//                        $withTax,
//                        $subProducts,
//                        $currencyCode,
//                        $baseCurrencyCode,
//                        $store
//                    );
//
//                    $dashedFormat = $this->getDashedPriceFormat($min, $max, $store, $currencyCode);
//
//                    if ($min !== $max) {
//                        $customData = $this->handleNonEqualMinMaxPrices(
//                            $customData,
//                            $field,
//                            $currencyCode,
//                            $min,
//                            $max,
//                            $dashedFormat,
//                            $areCustomersGroupsEnabled,
//                            $groups
//                        );
//                    }
//
//                    if (!$customData[$field][$currencyCode]['default']) {
//                        $customData = $this->handleZeroDefaultPrice(
//                            $customData,
//                            $field,
//                            $currencyCode,
//                            $baseCurrencyCode,
//                            $min,
//                            $max,
//                            $store
//                        );
//                    }
//
//                    if ($areCustomersGroupsEnabled) {
//                        $customData = $this->setFinalGroupPrices(
//                            $customData,
//                            $groups,
//                            $field,
//                            $currencyCode,
//                            $min,
//                            $max,
//                            $dashedFormat
//                        );
//                    }
//                }
            }
        }

        return $customData;
    }

    private function getFields($store)
    {
        $priceDisplayType = $this->taxHelper->getPriceDisplayType($store);

        if ($priceDisplayType === TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX) {
            return ['price' => false];
        }

        if ($priceDisplayType === TaxConfig::DISPLAY_TYPE_INCLUDING_TAX) {
            return ['price' => true];
        }

        return ['price' => false, 'price_with_tax' => true];
    }

    private function getSpecialPrice(
        $groups,
        Store $store,
        Product $product,
        $currencyCode,
        $baseCurrencyCode,
        $withTax
    ) {
        $specialPrice = [];

        /** @var Group $group */
        foreach ($groups as $group) {
            $groupId = (int) $group->getData('customer_group_id');
            $specialPrices[$groupId] = [];

            if ($product->getTypeId() == 'configurable')  {
                $childrenPrices = [];
                /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable $typeInstance */
                $typeInstance = $product->getTypeInstance();
                $children = $typeInstance->getUsedProducts($product);
                foreach ($children as $child){
                    $childPrice = (double) $this->rule->getRulePrice(
                        new \DateTime(),
                        $store->getWebsiteId(),
                        $groupId,
                        $child->getId()
                    );
                    $childrenPrices[] = $childPrice;
                }
                $specialPrices[$groupId][] = min($childrenPrices);
            } else {
                $specialPrices[$groupId][] = (double) $this->rule->getRulePrice(
                    new \DateTime(),
                    $store->getWebsiteId(),
                    $groupId,
                    $product->getId()
                );
            }

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
                if ($currencyCode !== $baseCurrencyCode) {
                    $specialPrice[$groupId] = $this->priceCurrency->convert(
                        $specialPrice[$groupId],
                        $store,
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

    private function addCustomerGroupsPrices(
        $customData,
        $groups,
        Product $product,
        Store $store,
        $currencyCode,
        $baseCurrencyCode,
        $withTax,
        $field
    ) {
        /** @var \Magento\Customer\Model\Group $group */
        foreach ($groups as $group) {
            $groupId = (int) $group->getData('customer_group_id');

            $product->setData('customer_group_id', $groupId);

            $discountedPrice = $product->getPriceModel()->getFinalPrice(1, $product);
            if ($currencyCode !== $baseCurrencyCode) {
                $discountedPrice = $this->priceCurrency->convert($discountedPrice, $store, $currencyCode);
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

                $customData[$field][$currencyCode]['group_' . $groupId] = $taxPrice;

                $formated = $this->priceCurrency->format(
                    $customData[$field][$currencyCode]['group_' . $groupId],
                    false,
                    PriceCurrencyInterface::DEFAULT_PRECISION,
                    $store,
                    $currencyCode
                );
                $customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $formated;

                if ($customData[$field][$currencyCode]['default'] >
                    $customData[$field][$currencyCode]['group_' . $groupId]) {
                    $original = $customData[$field][$currencyCode]['default_formated'];
                    $customData[$field][$currencyCode]['group_'.$groupId.'_original_formated'] = $original;
                }
            } else {
                $default = $customData[$field][$currencyCode]['default'];
                $customData[$field][$currencyCode]['group_' . $groupId] = $default;

                $defaultFormated = $customData[$field][$currencyCode]['default_formated'];
                $customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $defaultFormated;
            }
        }

        $product->setData('customer_group_id', null);

        return $customData;
    }

    private function addSpecialPrices(
        $customData,
        $areCustomersGroupsEnabled,
        $groups,
        $specialPrice,
        $field,
        $currencyCode,
        Store $store
    ) {
        if ($areCustomersGroupsEnabled) {
            /** @var \Magento\Customer\Model\Group $group */
            foreach ($groups as $group) {
                $groupId = (int) $group->getData('customer_group_id');

                if ($specialPrice[$groupId]
                    && $specialPrice[$groupId] < $customData[$field][$currencyCode]['group_' . $groupId]) {
                    $customData[$field][$currencyCode]['group_' . $groupId] = $specialPrice[$groupId];

                    $formated = $this->priceCurrency->format(
                        $specialPrice[$groupId],
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $store,
                        $currencyCode
                    );
                    $customData[$field][$currencyCode]['group_' . $groupId . '_formated'] = $formated;

                    if ($customData[$field][$currencyCode]['default'] >
                        $customData[$field][$currencyCode]['group_' . $groupId]) {
                        $original = $customData[$field][$currencyCode]['default_formated'];
                        $customData[$field][$currencyCode]['group_'.$groupId.'_original_formated'] = $original;
                    }
                }
            }

            return $customData;
        }

        if ($specialPrice[0] && $specialPrice[0] < $customData[$field][$currencyCode]['default']) {
            $defaultOriginalFormated = $customData[$field][$currencyCode]['default_formated'];
            $customData[$field][$currencyCode]['default_original_formated'] = $defaultOriginalFormated;

            $defaultFormated = $this->priceCurrency->format(
                $specialPrice[0],
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $store,
                $currencyCode
            );

            $customData[$field][$currencyCode]['default'] = $this->priceCurrency->round($specialPrice[0]);
            $customData[$field][$currencyCode]['default_formated'] = $defaultFormated;
        }

        return $customData;
    }
}
