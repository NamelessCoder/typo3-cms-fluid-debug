<?php
declare(strict_types=1);
namespace NamelessCoder\CmsFluidDebug\ActiveViewHelpers\Debug;

use NamelessCoder\CmsFluidDebug\ActiveViewHelpers\DebugViewHelper;

class BreakViewHelper extends DebugViewHelper
{
    public function initializeArguments()
    {
        $this->registerBreakAliasArguments();
    }
}
