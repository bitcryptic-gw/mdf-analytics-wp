<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown\Converter;

use MdfAnalytics\Vendor\League\HTMLToMarkdown\ElementInterface;
/** @internal */
class ListBlockConverter implements ConverterInterface
{
    public function convert(ElementInterface $element) : string
    {
        return $element->getValue() . "\n";
    }
    /**
     * @return string[]
     */
    public function getSupportedTags() : array
    {
        return ['ol', 'ul'];
    }
}
