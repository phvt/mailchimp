<?php

namespace Sup7even\Mailchimp\ViewHelpers;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class FooterDataViewHelper
 */
class FooterDataViewHelper extends AbstractViewHelper
{
    public function render(): void
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addFooterData($this->renderChildren());
    }
}
