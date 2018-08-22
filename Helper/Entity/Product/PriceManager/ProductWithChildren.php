<?php

namespace Algolia\AlgoliaSearch\Helper\Entity\Product;

abstract class ProductWithChildren
{
    private function getMinMaxPrices(
        Product $product,
        $withTax,
        $subProducts,
        $currencyCode,
        $baseCurrencyCode,
        Store $store
    ) {
        $type = $product->getTypeId();

        $min = PHP_INT_MAX;
        $max = 0;

        if ($type === 'bundle') {
            /** @var \Magento\Bundle\Model\Product\Price $priceModel */
            $priceModel = $product->getPriceModel();
            list($min, $max) = $priceModel->getTotalPrices($product, null, $withTax, true);
        }

        if ($type === 'grouped' || $type === 'configurable') {
            if (count($subProducts) > 0) {
                /** @var Product $subProduct */
                foreach ($subProducts as $subProduct) {
                    $price = (double) $this->catalogHelper->getTaxPrice(
                        $product,
                        $subProduct->getFinalPrice(),
                        $withTax,
                        null,
                        null,
                        null,
                        $product->getStore(),
                        null
                    );

                    $min = min($min, $price);
                    $max = max($max, $price);
                }
            } else {
                $min = $max;
            }
        }

        if ($currencyCode !== $baseCurrencyCode) {
            $min = $this->priceCurrency->convert($min, $store, $currencyCode);

            if ($min !== $max) {
                $max = $this->priceCurrency->convert($max, $store, $currencyCode);
            }
        }

        return [$min, $max];
    }

    private function getDashedPriceFormat($min, $max, Store $store, $currencyCode)
    {
        if ($min === $max) {
            return '';
        }

        $minFormatted = $this->priceCurrency->format(
            $min,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currencyCode
        );

        $maxFormatted = $this->priceCurrency->format(
            $max,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currencyCode
        );

        return $minFormatted.' - '.$maxFormatted;
    }

    private function handleNonEqualMinMaxPrices(
        $customData,
        $field,
        $currencyCode,
        $min,
        $max,
        $dashedFormat,
        $areCustomersGroupsEnabled,
        $groups
    ) {
        if (isset($customData[$field][$currencyCode]['default_original_formated']) === false
            || $min <= $customData[$field][$currencyCode]['default']) {
            $customData[$field][$currencyCode]['default_formated'] = $dashedFormat;

            //// Do not keep special price that is already taken into account in min max
            unset($customData['price']['special_from_date']);
            unset($customData['price']['special_to_date']);
            unset($customData['price']['default_original_formated']);

            $customData[$field][$currencyCode]['default'] = 0; // will be reset just after
        }

        if ($areCustomersGroupsEnabled) {
            /** @var Group $group */
            foreach ($groups as $group) {
                $groupId = (int) $group->getData('customer_group_id');

                if ($min !== $max && $min <= $customData[$field][$currencyCode]['group_' . $groupId]) {
                    $customData[$field][$currencyCode]['group_'.$groupId] = 0;
                    $customData[$field][$currencyCode]['group_'.$groupId.'_formated'] = $dashedFormat;
                }
            }
        }

        return $customData;
    }

    private function handleZeroDefaultPrice(
        $customData,
        $field,
        $currencyCode,
        $baseCurrencyCode,
        $min,
        $max,
        Store $store
    ) {
        $customData[$field][$currencyCode]['default'] = $min;

        if ($min !== $max) {
            return $customData;
        }

        if ($currencyCode !== $baseCurrencyCode) {
            $min = $this->priceCurrency->convert($min, $store, $currencyCode);
        }

        $minFormatted = $this->priceCurrency->format(
            $min,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currencyCode
        );

        $customData[$field][$currencyCode]['default'] = $min;
        $customData[$field][$currencyCode]['default_formated'] = $minFormatted;

        return $customData;
    }

    private function setFinalGroupPrices($customData, $groups, $field, $currencyCode, $min, $max, $dashedFormat)
    {
        /** @var Group $group */
        foreach ($groups as $group) {
            $groupId = (int) $group->getData('customer_group_id');

            if ($customData[$field][$currencyCode]['group_' . $groupId] === 0) {
                $customData[$field][$currencyCode]['group_' . $groupId] = $min;

                if ($min === $max) {
                    $default = $customData[$field][$currencyCode]['default_formated'];
                    $customData[$field][$currencyCode]['group_'.$groupId.'_formated'] = $default;
                } else {
                    $customData[$field][$currencyCode]['group_'.$groupId.'_formated'] = $dashedFormat;
                }
            }
        }

        return $customData;
    }
}
