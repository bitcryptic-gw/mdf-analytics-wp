<?php

declare (strict_types=1);
namespace MdfAnalytics\Vendor\League\HTMLToMarkdown\Converter;

use MdfAnalytics\Vendor\League\HTMLToMarkdown\Configuration;
use MdfAnalytics\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use MdfAnalytics\Vendor\League\HTMLToMarkdown\ElementInterface;
/** @internal */
class CommentConverter implements ConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;
    public function setConfig(Configuration $config) : void
    {
        $this->config = $config;
    }
    public function convert(ElementInterface $element) : string
    {
        if ($this->shouldPreserve($element)) {
            return '<!--' . $element->getValue() . '-->';
        }
        return '';
    }
    /**
     * @return string[]
     */
    public function getSupportedTags() : array
    {
        return ['#comment'];
    }
    private function shouldPreserve(ElementInterface $element) : bool
    {
        $preserve = $this->config->getOption('preserve_comments');
        if ($preserve === \true) {
            return \true;
        }
        if (\is_array($preserve)) {
            $value = \trim($element->getValue());
            return \in_array($value, $preserve, \true);
        }
        return \false;
    }
}
