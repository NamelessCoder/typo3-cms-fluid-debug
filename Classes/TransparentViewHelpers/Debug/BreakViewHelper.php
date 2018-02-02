<?php
declare(strict_types=1);
namespace NamelessCoder\CmsFluidDebug\TransparentViewHelpers\Debug;

class BreakViewHelper extends \NamelessCoder\CmsFluidDebug\TransparentViewHelpers\DebugViewHelper
{
    public function initializeArguments()
    {
        $this->registerBreakAliasArguments();
    }
}
