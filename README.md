TYPO3 CMS Fluid Debugging Assistant
===================================

This package contains overrides and additions to the standard Fluid debugging utility in TYPO3 CMS. It replaces the
normal debugging ViewHelper with an improved version that solves several known problems. In addition to dumping
variables it also allows you to insert xdebug break points in Fluid templates via a ViewHelper.


The problems it solves
----------------------

1. The normal Fluid ViewHelper generates output either where it is used, or at the very top of the document before the
   doctype declaration. Either method has tremendous risk to destroy CSS rendering or be unreadable if used in a clipped
   or very tiny component.
2. When you debug properties of an object in Fluid, the Extbase property accessor is used. This means two things: first,
   the data you look at is *not* the actual data, it is the public gettable data which has properties associated with
   it. And two, it will only show you data that has a property, i.e. no virtual properties which only have getters.
3. Debugging a Fluid template means accessing a variable or ViewHelper expression twice, because the built-in debug
   ViewHelper _will not return the variable it debugs_.
4. You do not want debug statements in your production code, but sometimes you would like to leave them in templates and
   make assertions on the output they debug - but you can't, because the debug ViewHelper produces output.

These problems are well known when debugging in Fluid. And this package solves all of them.


Installation
------------

Install with Composer:

```sh
composer require namelesscoder/typo3-cms-fluid-debug
```

And if necessary, activate the `fluid_debug` extension in Extension Manager.

No configuration is needed, but the package only has functionality when the site is not in Production context. Note that
it is not enough to switch the context in the TYPO3 backend if you also defined `TYPO3_APPLICATION_CONTEXT` in your
virtual host - the one defined in your virtual host takes priority, so make sure it is set to Development or Testing.


The strategy
------------

Instead of outputting content, the overridden debug ViewHelper instead delegates the output of variables to the JS
console in your browser, by using PageRenderer to insert a call to a `console.log()` or other method, which receives a
JSON object representation of the data you dump. In TYPO3 backend the ViewHelper dumps to the debugging console (which
you can disable) and in CLI mode a regular `var_dump` is triggered (and xdebug break point if so configured in use).

This means the ViewHelper is safe to use anywhere as it will never produce debug output inside the DOM body.

An additional strategy is to allow the debugging ViewHelper to return the raw value it was asked to debug. This means
you can use it as part of any inline expression to debug the value at that exact point. Consider the following example:

```xml
Long expression with debug in specific point:
{myVariable -> f:debug() -> f:format.striptags() -> f:debug() -> f:format.nl2br()}

Usage in argument value and arrays:
<f:render partial="Render{myDynamicType -> f:debug()}" arguments="{foo: '{my:vh(bar: 1) -> f:debug()}'}" />

Debugging an array costructed in Fluid while also passing that array:
<f:render partial="Foo" arguments="{f:debug(value: {foo: 'bar', baz: 1})}" />
```

Because the ViewHelper simply passes the value through, it can be left in place without causing rendering problems. This
is a major difference compared to the native debugging ViewHelper which cannot be used this way without causing errors.

The FE/BE/CLI sensitivity also means you get the least intrusive output possible; except on CLI where the standard
var_dump is used to produce markup-free dumps.


Dumping strategy
----------------

Contrary to the native TYPO3 CMS debug ViewHelper, the override dumps objects based primarily on the presence of getter
methods which require no arguments - as opposed to basing it on the properties of a reflected object.

Why this?

The answer is that while Extbase dumping (which is what the native debug ViewHelper uses) is exceptionally good at
dumping domain objects, it has shortcomings when the getter method you want to access doesn't have an associated
property. Most prevalent example would be dumping a `File` resource which does not reveal all methods, including some of
the most important API methods that are very useful in Fluid (example: metadata properties).

So by dumping based not just on properties but by the presence of getter methods, with either the `get` or `has` or `is`
prefixes, this dump reveals every property *that you can use in Fluid*, rather than just those that makes sense in an
Extbase persistence context.


Auto-suppressed in Production
-----------------------------

The package silences itself when the TYPO3 application context is set to Production.

There are two main reasons for this:

1. By auto-suppressing, it means it is safe to deploy templates which contain debug statements.
2. Because debug statements output to `console.log()` or other, if you use acceptance testing with browser integration
   you can make assertions on variables passing through Fluid as part of your acceptance testing; variables which you
   don't see in the template output but are used to render it.

So rather than as you normally would, remove debug statements or suppress them with `f:comment`, you can simply leave
them in there - they cause no output in DOM body, they pass the debugged value through, and in Production content they
are replaced with completely transparent versions of themselves.


Usage details
-------------

The package currently contains two ViewHelpers:

* An override for `f:debug` which is semi-compatible (also uses tag content to read dump value)
* A specialised alias with reduced arguments, `f:debug.break`, which instead of outputting to console will create a
  dynamic breakpoint for xdebug so you can inspect the state in your IDE.

The `f:debug` override has the following arguments:

* `value` which can be specified as argument value or is otherwise taken from tag content / child node
* `title` which is a string you can use to identify the debug output - if not specified, the current template source
  code chunk and line/character number is shown if the template is not compiled (flush system cache to cause compiling).
* `level` which is a string containing `log`, `warn` etc. - method name on the `console` object to be called.
* `maxDepth` which is in integer, maximum number of levels to allow when traversing arrays/objects (note that infinite
  recursion is automatically prevented).
* `silent` which is a boolean you can set to `1` if you want to suppress the output in console altogether.
* `pass` which is a boolean you can set to `0` to not pass the dumped variable, useful if you for example have a
  separate `<f:debug pass="0">{object}</f:debug>` that would otherwise cause string conversion problems.
* `break` which is a boolean you can set to `1` to cause an xdebug break point. Only happens if xdebug is installed.
* `compile` which is a boolean you can set to `0` to disable compiling, letting you debug and break on the behavior
  the template has during parsing and compiling without having to flush caches repeatedly.

And the reduced alias `f:debug.break` has the following arguments:

* `value` exactly like above
* `pass` exactly like above
* `silent` like above, but with default set to `1` to suppress output
* `break` like above, but with default set to `1` to always break
* `compile` exactly like above

In other words, `f:debug` is the main utility and `f:debug.break` is a customised alias which uses different default
argument values, making it an ideal "insert breakpoint here" ViewHelper.


Note about using breakpoints
----------------------------

When you use break points with `f:debug.break` you don't just get the option of inspecting the variable you dump when
the ViewHelper gets rendered - when break points are enabled, they trigger on the following events:

* When the ViewHelper is initialized (when template is parsed, when ViewHelperNode is built in syntax tree)
* When the ViewHelper is compiled to a PHP class (when you can dump for example the compiler's state)
* When the ViewHelper is rendered (when you can inspect the actual value you want to dump, as well as other variables)

A handful of key variables are extracted for easier reading in your IDE. These include the template source chunk, the
line/character number, all current template variables, whether template is compiled, and so on.

Note that you can also set `break="1"` on `f:debug` to cause an xdebug break point from that ViewHelper as well.

**Important! Not all objects are possible to debug - when `f:debug` fails, `f:debug.break` and xdebug always works!**
