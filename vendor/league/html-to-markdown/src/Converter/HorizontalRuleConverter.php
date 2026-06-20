<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown\Converter;

use MdfAnalytics\Vendor\League\HTMLToMarkdown\ElementInterface;
/** @internal */
class HorizontalRuleConverter implements ConverterInterface
{
    public function convert(ElementInterface $element) : string
    {
        return "---\n\n";
    }
    /**
     * @return string[]
     */
    public function getSupportedTags() : array
    {
        return ['hr'];
    }
}
