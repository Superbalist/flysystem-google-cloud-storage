# Contribution Guide

Based on [Laravel's](https://github.com/laravel/docs/blob/master/contributions.md)

- [Bug Reports](#bug-reports)
- [Core Development Discussion](#core-development-discussion)
- [Which Branch?](#which-branch)
- [Coding Style](#coding-style)
    - [Code Style Fixer](#code-style-fixer)

<a name="bug-reports"></a>
## Bug Reports

To encourage active collaboration, we strongly encourage pull requests, not just bug reports. "Bug reports" may also be sent in the form of a pull request containing a failing test.

However, if you file a bug report, your issue should contain a title and a clear description of the issue. You should also include as much relevant information as possible and a code sample that demonstrates the issue. The goal of a bug report is to make it easy for yourself - and others - to replicate the bug and develop a fix.

Remember, bug reports are created in the hope that others with the same problem will be able to collaborate with you on solving it. Do not expect that the bug report will automatically see any activity or that others will jump to fix it. Creating a bug report serves to help yourself and others start on the path of fixing the problem.

<a name="core-development-discussion"></a>
## Core Development Discussion

Discussion regarding bugs, new features, and implementation of existing features takes place in issues on the GitHub repo [here](https://github.com/Superbalist/flysystem-google-storage/issues).

<a name="which-branch"></a>
## Which Branch?

**All** patches should be sent to the `master` branch. Bug fixes should **never** be sent to the `master` branch unless they fix features that exist only in the upcoming release.

<a name="coding-style"></a>
## Coding Style

We follow the [PSR-2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md) coding standard and the [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) autoloading standard.

### DocBlocks

`@param` tags should **not be aligned** and arguments should be separated by **2 spaces**.

Here's an example block:

    /**
     * Register a binding with the container.
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        //
    }

<a name="code-style-fixer"></a>
### Code Style Fixer

You may use the [PHP-CS-Fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer) to fix your code style before committing.

To get started, [install the tool globally](https://github.com/FriendsOfPHP/PHP-CS-Fixer#globally-manual) and check the code style by issuing the following terminal command from your project's root directory:

```sh
php-cs-fixer fix
```
