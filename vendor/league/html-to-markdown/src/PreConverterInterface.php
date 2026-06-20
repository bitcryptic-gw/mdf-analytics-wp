<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown;

/** @internal */
interface PreConverterInterface
{
    public function preConvert(ElementInterface $element) : void;
}
