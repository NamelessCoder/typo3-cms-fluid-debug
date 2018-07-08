<?php
declare(strict_types=1);
namespace NamelessCoder\CmsFluidDebug\ActiveViewHelpers;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceInterface;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3Fluid\Fluid\Core\Compiler\TemplateCompiler;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

class DebugViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    protected $templateParsingPointers = [];

    protected $escapeOutput = false;

    protected $escapeChildren = false;

    protected static $gettableMethodPrefixes = ['get', 'is', 'has'];

    protected static $blacklistedMethods = [
        File::class => [
            'getContents' => true
        ],
        'getFileContents' => true
    ];

    public function initializeArguments()
    {
        $this->registerArgument('value', 'mixed', 'Value to be dumped');
        $this->registerArgument('title', 'string', 'Optional title for console output line', false);
        $this->registerArgument('level', 'string', 'Level - or method name - to use on "console" object, e.g. "log" to call "console.log()"', false, 'log');
        $this->registerArgument('maxDepth', 'integer', 'Maximum depth for recursion', false, 8);
        $this->registerArgument('silent', 'boolean', 'If true, no output is generated at all. Combines well with "break" to debug in IDE', false, false);
        $this->registerArgument('pass', 'boolean', 'If true, passes through the child content or value of "value" argument. Defaults to "true"', false, true);
        $this->registerArgument('break', 'boolean', 'If true, and if xdebug is installed, creates a dynamic breakpoint in parsing, rendering and compiling stages of this ViewHelper', false, false);
        $this->registerArgument('compile', 'boolean', 'If switched to "false" this the ViewHelper will no longer allow the template to be compiled, thus always showing line number and triggering parse events', false, true);
    }

    protected function registerBreakAliasArguments()
    {
        $this->registerArgument('value', 'mixed', 'Value to be dumped');
        $this->registerArgument('pass', 'boolean', 'If true, passes through the child content or value of "value" argument. Defaults to "true"', false, true);
        $this->registerArgument('silent', 'boolean', 'If true, no output is generated at all. Combines well with "break" to debug in IDE', false, true);
        $this->registerArgument('break', 'boolean', 'If true, and if xdebug is installed, creates a dynamic breakpoint in parsing, rendering and compiling stages of this ViewHelper', false, true);
        $this->registerArgument('compile', 'boolean', 'If switched to "false" this the ViewHelper will no longer allow the template to be compiled, thus always showing line number and triggering parse events', false, true);
    }

    public function setRenderingContext(RenderingContextInterface $renderingContext)
    {
        if (empty($this->templateParsingPointers)) {
            $this->templateParsingPointers = $renderingContext->getTemplateParser()->getCurrentParsingPointers();
        }
        static::breakPoint($this->arguments['break'] ?? $this->prepareArguments()['break']->getDefaultValue(), $renderingContext, 'parse', $this->templateParsingPointers);
        parent::setRenderingContext($renderingContext);
    }

    public function compile(
        $argumentsName,
        $closureName,
        &$initializationPhpCode,
        ViewHelperNode $node,
        TemplateCompiler $compiler
    ) {
        if (!$this->arguments['compile']) {
            $compiler->disable();
        }
        $renderingContext = $compiler->getRenderingContext();
        $arguments = $node->getArguments();
        foreach ($this->prepareArguments() as $name => $argumentDefinition) {
            if (isset($arguments[$name])) {
                $arguments[$name] = $arguments[$name]->evaluate($renderingContext);
            } else {
                $arguments[$name] = $argumentDefinition->getDefaultValue();
            }
        }
        static::breakPoint($arguments['break'], $renderingContext, 'compile', $this->templateParsingPointers);
        return parent::compile($argumentsName, $closureName, $initializationPhpCode, $node, $compiler);
    }

    public function render()
    {
        $this->renderingContext->getViewHelperVariableContainer()->addOrUpdate(static::class, 'pointers', $this->templateParsingPointers);
        return static::renderStatic($this->arguments, $this->buildRenderChildrenClosure(), $this->renderingContext);
    }

    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $pointers = $renderingContext->getViewHelperVariableContainer()->get(static::class, 'pointers');
        $value = $renderChildrenClosure();
        static::breakPoint($arguments['break'], $renderingContext, 'render', $pointers ?? [], $value);
        if (!$arguments['silent']) {
            $converted = [];
            if (!empty($arguments['title'])) {
                $title = $arguments['title'];
            } elseif (!empty($pointers)) {
                $title = sprintf(
                    'Line %d, character %d: %s',
                    $pointers[0],
                    $pointers[1],
                    trim($pointers[2])
                );
            }
            if (TYPO3_MODE === 'FE') {
                $representation = static::convertAnything($value, (int) $arguments['maxDepth'], $converted);
                $json = json_encode($representation, JSON_HEX_QUOT);
                $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
                $pageRenderer->addJsFooterInlineCode(
                    'Dump ' . sha1(microtime()),
                    sprintf(
                        '%sconsole.%s(%s);',
                        !empty($title) ? 'console.info(\'' . addslashes($title) . '\');' . PHP_EOL : '',
                        $arguments['level'],
                        $json
                    )
                );
            } elseif (php_sapi_name() !== "cli") {
                DebugUtility::debug(is_array($value) ? DebugUtility::viewArray($value) : $representation, $title, 'Fluid');
            } else {
                var_dump($value);
            }
        }
        if ($arguments['pass']) {
            return $value;
        }
        return null;
    }

    protected static function convertAnything($anything, int $depth, array &$convertedIds = [])
    {
        if ($depth < 0) {
            return ['MAX DEPTH REACHED' => 'Maximum depth reached'];
        }
        if (is_array($anything)) {
            $representation = static::convertArray($anything, $depth, $convertedIds);
        } elseif ($anything instanceof \Closure) {
            $representation = '(closure)';
        } elseif (!is_object($anything)) {
            $representation = $anything;
        } else {
            $representation = static::convertObject($anything, $depth, $convertedIds);
        }
        return $representation;
    }

    protected static function convertArray(array $array, int $depth, array &$convertedIds = []): array
    {
        if ($depth < 0) {
            return ['MAX DEPTH REACHED' => 'Maximum depth reached'];
        }
        // Arrays are recursively travelled to convert any objects along the way
        $converted = [];
        foreach ($array as $key => $value) {
            $converted[$key] = static::convertAnything($value, $depth - 1, $convertedIds);
        }
        return $converted;
    }

    protected static function convertObject($object, int $depth, array &$convertedIds = []): array
    {
        if ($depth < 0) {
            return ['MAX DEPTH REACHED' => 'Maximum depth reached'];
        }
        if ($object instanceof \ArrayAccess) {
            return static::convertArray((array) $object, $depth - 1, $convertedIds);
        } elseif ($object instanceof \Iterator) {
            return static::convertArray(iterator_to_array($object), $depth - 1, $convertedIds);
        }
        // Objects are dumped as Extbase usually would. This gives us a starting point where child properties
        // are still objects. We recurse later on to solve those.
        if ($object instanceof DomainObjectInterface) {
            $objectId = get_class($object) . ':' . $object->getUid();
        } elseif ($object instanceof ResourceInterface) {
            $objectId = $object->getHashedIdentifier();
        } else {
            $objectId = spl_object_hash($object);
        }
        if (in_array($objectId, $convertedIds)) {
            return ['RECURSION' => 'Recursion to object ' . $objectId . ' which was already dumped above'];
        }
        $convertedIds[] = $objectId;
        $gettables = [];
        $objectReflection = new \ReflectionClass($object);
        foreach ($objectReflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $publicMethod) {
            if ($publicMethod->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $methodName = $publicMethod->getName();
            if (isset(static::$blacklistedMethods[$methodName]) || isset(static::$blacklistedMethods[get_class($object)][$methodName])) {
                $gettables[$methodName] = '(method blacklisted, not called)';
                continue;
            }
            foreach (static::$gettableMethodPrefixes as $prefix) {
                if (strpos($methodName, $prefix) === 0) {
                    $virtualPropertyName = lcfirst(substr($methodName, strlen($prefix)));
                    if (!isset($gettables[$virtualPropertyName])) {
                        $gettables[$virtualPropertyName] = $publicMethod->invoke($object);
                    }
                }
            }
        }

        return static::convertArray($gettables, $depth - 1, $convertedIds);
    }

    protected static function breakPoint(bool $break, RenderingContextInterface $renderingContext, string $event, array $pointers, $value = null)
    {
        // Special break wrapper which extracts some key variables before breaking, allowing direct inspection of those.
        // These variables appear to be unused, but are defined for reading in the debugging IDE.
        if ($break && function_exists('xdebug_break')) {
            $variables = $renderingContext->getVariableProvider()->getAll();
            $compiled = false;
            if (!empty($pointers)) {
                list ($line, $character, $templateCode) = $pointers;
            } else {
                $compiled = true;
            }
            xdebug_break();
        }
    }
}
