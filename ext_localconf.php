<?php

use TYPO3\CMS\Core\Core\Bootstrap;

defined('TYPO3_MODE') or die();

(function() {
    $context = Bootstrap::getInstance()->getApplicationContext();
    switch (true) {
        case $context->isTesting():
        case $context->isDevelopment():
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['f'][] = 'NamelessCoder\\CmsFluidDebug\\ActiveViewHelpers';
            break;
        default:
        case $context->isProduction():
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['f'][] = 'NamelessCoder\\CmsFluidDebug\\TransparentViewHelpers';
            break;
    }
})();
