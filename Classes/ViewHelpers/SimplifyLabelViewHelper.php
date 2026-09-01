<?php

namespace Sup7even\Mailchimp\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class SimplifyLabelViewHelper extends AbstractViewHelper
{
    /**
     * Initialize arguments
     */
    public function initializeArguments(): void
    {
        $this->registerArgument('label', 'string', 'label', false, '');
        $this->registerArgument('toLowerCase', 'bool', 'should it be lowered', false, false);
    }

    public function render(): mixed
    {
        $label = $this->arguments['label'] ?: $this->renderChildren();

        $label = str_replace(['Ö', 'Ü', 'Ä', 'ö', 'ü', 'ä', 'ß'], ['Oe', 'Ue', 'Ae', 'oe', 'ue', 'ae', 'ss'], $label);
        $filter = preg_replace('/[^a-zA-Z0-9]/', '', $label);
        if ($this->arguments['toLowerCase']) {
            $filter = mb_strtolower($filter);
        }
        return $filter;
    }
}
