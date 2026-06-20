<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown;

/** @internal */
interface ConfigurationAwareInterface
{
    public function setConfig(Configuration $config) : void;
}
