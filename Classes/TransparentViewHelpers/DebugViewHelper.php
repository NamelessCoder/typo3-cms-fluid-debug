<?php
declare(strict_types=1);
namespace NamelessCoder\CmsFluidDebug\TransparentViewHelpers;

use TYPO3Fluid\Fluid\Core\Compiler\TemplateCompiler;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

class DebugViewHelper extends \NamelessCoder\CmsFluidDebug\ActiveViewHelpers\DebugViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    public function render()
    {
        return static::renderStatic($this->arguments, $this->buildRenderChildrenClosure(), $this->renderingContext);
    }

    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $value = $renderChildrenClosure();
        if ($arguments['pass']) {
            return $value;
        }
        return null;
    }

    public function compile(
        $argumentsName,
        $closureName,
        &$initializationPhpCode,
        ViewHelperNode $node,
        TemplateCompiler $compiler
    ) {
        return sprintf(
            '%s::renderStatic(%s, %s, $renderingContext)',
            get_class($this),
            $argumentsName,
            $closureName
        );
    }

    protected static function breakPoint(bool $break, RenderingContextInterface $renderingContext, string $event, array $pointers, $value = null)
    {
        // void
    }
}
