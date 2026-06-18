# Introduction

This central repository provides additional meta information for the [Contao Manager][3].

It allows to translate and enhance package information of any [Composer][1] package, thus you can also
provide meta information for e.g. packages that are not of type `contao-bundle` but e.g. a suggestion of
your very own bundle such as a general PHP excel library.

The metadata is stored in [YAML format][4] and a logo can be specified in SVG format. It is recommended to
optimize the logo using [SVGO][6] or the GUI tool [SVGOMG][7], for example.

## Package structure example

<pre>
meta/[vendor]/[package]
    - de.yml
    - en.yml
    - ru.yml
    - ...
    - logo.svg (optional)
    - composer.json (only for private packages and optional. <a href="#public-vs-privateproprietary-packages">Details</a>)
</pre>

Hint: The `logo.svg` can also be place directly within `[vendor]`. It is then used as a fallback logo for all packages of
`[vendor]` in case there was no specific `logo.svg` defined for the package.

## Language YAML example

```
en:
    title: Title of bundle or module
    description: >
        Long description of bundle or module with line breaks.

        Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua.
        At vero eos et accusam et justo duo dolores et ea rebum.
    keywords:
        - keyword1
        - lorem ipsum
        - keyword2
        ...
    support:
        issues: https://github.com/demo/demo/issues
        docs: https://example.org/demo/
    suggest:
        vendor/package: This package allows you to export XLSX files
```


Please use 4 spaces for indentation or nesting.
For more information on the file format, see [the Transifex documentation][2]

## YAML syntax

The following keywords can be defined in the meta data yaml file:

| | |
|-|-|
| __title__         | Title of the extension |
| __description__   | Long description of the extension that shows up in the Details view |
| __keywords__      | List of keywords that will enhance search performance  |
| __dependency__    | If true, the extension will not show up in the search. This is useful for extensions not tailored for end-users (default: `false`) |
| __discoverable__  | If false, the extension will not show up on the discovery page (top rated, most downloaded) (default: `true`) |
| __support__       | Allows you to provide differing support links for certain languages that originally are part of the `composer.json`. Key-value syntax: [supported keys][8] |
| __suggest__       | Provide translation of the texts within the `suggest` section |
| __homepage__      | Homepage for private packages, which do not provide this information through their `composer.json`. |

## Public vs. private/proprietary packages

The metadata repository feeds the Contao Manager search index and as such allows to search for both, publicly available
or private/proprietary packages. You are allowed to submit any package description. The definition of a publicly available
package is the fact that it's registered on [packagist.org][5]. For private packages, the Contao Manager does currently
not provide any automated installation process so providing a homepage link to describe how a user can obtain and install
the package is required.

The Contao Manager shows meaningful information for all packages, for example, a list of requirements. This information is
provided by the `composer.json` and thus is only available for public packages. To add this information for private
packages, you can add a `composer.json` to your metadata.

## Spell checking

Your meta data is automatically checked for spelling issues. You might need to update the allow lists in your
pull request in case the spell check fails but you are sure the word you used is correct. The allow lists for each language
are located in the folder `linter/allowlists`. For proper names and other terms that shouldn't change between different
translations, use the general `default.txt` allow list.

## Supported languages

See https://github.com/contao/contao-manager/blob/1.3/src/i18n/locales.js

## How to contribute

1. Clone the repo
2. Select an existing package or create new folders for a none existing package like "meta/vendor/package"
3. Make pull request
4. Your pull request is checked automatically.
5. As soon as all checks are green, a member of the review team can approve and merge your Pull Request.

### Checking (linting) locally

As the checks do not always start automatically and to save time overall, you might want to run the linter
locally. The following should work on a Debian based system, but might need to be adapted to your needs.

```
sudo apt install aspell aspell-cs aspell-de aspell-en aspell-es
composer install --working-dir=linter
php linter/lint meta/yourvendor/yourpackage/*.yml
```

## Code Owners

If you are the owner of a package namespace, make sure to add your GitHub username to the CODEOWNERS file.
This way you will be notified of any changes to that folder, so you can check if someone tries to modify
your namespace.


[1]: https://getcomposer.org
[2]: https://docs.transifex.com/formats/yaml
[3]: https://github.com/contao/contao-manager
[4]: http://yaml.org
[5]: https://packagist.org
[6]: https://github.com/svg/svgo
[7]: https://jakearchibald.github.io/svgomg/
[8]: https://getcomposer.org/doc/04-schema.md#support
