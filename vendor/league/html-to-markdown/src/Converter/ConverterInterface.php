<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown\Converter;

use MdfAnalytics\Vendor\League\HTMLToMarkdown\ElementInterface;
/** @internal */
interface ConverterInterface
{
    public function convert(ElementInterface $element) : string;
    /**
     * @return string[]
     */
    public function getSupportedTags() : array;
}
