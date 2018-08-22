<?php

namespace Algolia\AlgoliaSearch\Helper\Entity\Product\PriceManager;

use Magento\Catalog\Model\Product;

class Bundle extends ProductWithChildren
{
    protected function getMinMaxPrices(
        Product $product,
        $withTax,
        $subProducts,
        $currencyCode
    ) {
        $type = $product->getTypeId();

        $min = PHP_INT_MAX;
        $max = 0;

        /** @var \Magento\Bundle\Model\Product\Price $priceModel */
        $priceModel = $product->getPriceModel();
        list($min, $max) = $priceModel->getTotalPrices($product, null, $withTax, true);


        if ($currencyCode !== $this->baseCurrencyCode) {
            $min = $this->priceCurrency->convert($min, $this->store, $currencyCode);

            if ($min !== $max) {
                $max = $this->priceCurrency->convert($max, $this->store, $currencyCode);
            }
        }

        return [$min, $max];
    }
}
