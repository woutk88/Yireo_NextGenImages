<?php declare(strict_types=1);

namespace Yireo\NextGenImages\Image;

use Magento\Framework\View\LayoutInterface;
use Yireo\NextGenImages\Block\Picture;
use Yireo\NextGenImages\Config\Config;
use Yireo\NextGenImages\Convertor\ConvertorListing;
use Yireo\NextGenImages\Exception\ConvertorException;
use Yireo\NextGenImages\Logger\Debugger;

class HtmlReplacer
{
    /**
     * @var ConvertorListing
     */
    private $convertorListing;

    /**
     * @var Debugger
     */
    private $debugger;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var UrlConvertor
     */
    private $urlConvertor;

    /**
     * ReplaceTags constructor.
     *
     * @param ConvertorListing $convertorListing
     * @param Debugger $debugger
     * @param Config $config
     * @param UrlConvertor $urlConvertor
     */
    public function __construct(
        ConvertorListing $convertorListing,
        Debugger $debugger,
        Config $config,
        UrlConvertor $urlConvertor
    ) {
        $this->convertorListing = $convertorListing;
        $this->debugger = $debugger;
        $this->config = $config;
        $this->urlConvertor = $urlConvertor;
    }

    /**
     * @param LayoutInterface $layout
     * @param string $html
     * @return string
     */
    public function replaceImagesInHtml(LayoutInterface $layout, string $html): string
    {
        $groupRegex = '/(?=(<(?:[^<]+)\ (?:data\-src|src)=\"(?:[^\"]+)\.(?:png|jpg|jpeg)(?:[^>]+)>(?:\s*)<(?:\/?)(?:[a-z]+)))/msi';
        if (preg_match_all($groupRegex, $html, $groups) === false) {
            return $html;
        }

        $regex = '/<([^<]+)\ (data\-src|src)=\"([^\"]+)\.(png|jpg|jpeg)([^>]+)>(\s*)<(\/?)([a-z]+)/msi';
        foreach ($groups[1] as $index => $match) {
            if (preg_match($regex, $match, $matches) === false) {
                continue;
            }

            $nextTag = $matches[7] . $matches[8];
            $fullSearchMatch = $matches[0];
            $imageUrl = $matches[3] . '.' . $matches[4];

            if (!$this->isAllowedByNextTag($nextTag)) {
                continue;
            }

            if (!$this->isAllowedByImageUrl($imageUrl)) {
                continue;
            }

            $sourceImages = $this->getAlternativeImagesByImageUrl($imageUrl);
            if (!$sourceImages) {
                continue;
            }

            $isDataSrc = $matches[2] === 'data-src';
            $htmlTag = preg_replace('/>(.*)/msi', '>', $fullSearchMatch);
            $newHtmlTag = $this->getNewHtmlTag($layout, $imageUrl, $sourceImages, $htmlTag, $isDataSrc);
            $replacement = $newHtmlTag . '<' . $nextTag;
            $html = str_replace($fullSearchMatch, $replacement, $html);
        }

        return $html;
    }

    /**
     * @param string $nextTag
     * @return bool
     */
    private function isAllowedByNextTag(string $nextTag): bool
    {
        if ($nextTag === '/picture' || $nextTag === '/source') {
            return false;
        }

        return true;
    }

    /**
     * @param string $imageUrl
     * @return bool
     */
    private function isAllowedByImageUrl(string $imageUrl): bool
    {
        if (!$this->urlConvertor->isLocal($imageUrl)) {
            return false;
        }

        if (strpos($imageUrl, '/media/captcha/') !== false) {
            return false;
        }

        return true;
    }

    /**
     * @param LayoutInterface $layout
     * @param string $imageUrl
     * @param array $sourceImages
     * @param string $htmlTag
     * @param bool $isDataSrc
     * @return string
     */
    private function getNewHtmlTag(
        LayoutInterface $layout,
        string $imageUrl,
        array $sourceImages,
        string $htmlTag,
        bool $isDataSrc = false
    ): string {
        return $this->getPictureBlock($layout)
            ->setOriginalImage($imageUrl)
            ->setSourceImages($sourceImages)
            ->setAltText($this->getAttributeText($htmlTag, 'alt'))
            ->setOriginalTag($htmlTag)
            ->setClass($this->getAttributeText($htmlTag, 'class'))
            ->setWidth($this->getAttributeText($htmlTag, 'width'))
            ->setHeight($this->getAttributeText($htmlTag, 'height'))
            ->setLazyLoading($this->config->addLazyLoading())
            ->setIsDataSrc($isDataSrc)
            ->toHtml();
    }

    /**
     * @param string $htmlTag
     * @param string $attribute
     * @return string
     */
    private function getAttributeText(string $htmlTag, string $attribute): string
    {
        if (preg_match('/\ ' . $attribute . '=\"([^\"]+)/', $htmlTag, $match)) {
            $altText = $match[1];
            return strtr($altText, ['"' => '', "'" => '']);
        }

        return '';
    }

    /**
     * Get Picture Block-class from the layout
     *
     * @param LayoutInterface $layout
     * @return Picture
     */
    private function getPictureBlock(LayoutInterface $layout): Picture
    {
        /** @var Picture $block */
        $block = $layout->createBlock(Picture::class);
        $block->setDebug($this->config->isDebugging());
        return $block;
    }

    /**
     * @param string $imageUrl
     * @return string[]
     */
    private function getAlternativeImagesByImageUrl(string $imageUrl): array
    {
        $images = [];
        foreach ($this->convertorListing->getConvertors() as $convertor) {
            try {
                $images[] = $convertor->getSourceImage($imageUrl);
            } catch (ConvertorException $convertorException) {
                $this->debugger->debug($convertorException->getMessage(), ['imageUrl' => $imageUrl]);
                continue;
            }
        }

        return $images;
    }
}
