# Introduction

This central repository provides additional meta information for the [Contao Manager][3].

It allows to translate and enhance package information of any [Composer][1] package, thus you can also
provide meta information for e.g. packages that are not of type `contao-bundle` but e.g. a suggestion of
your very own bundle such as a general PHP excel library.

The metadata is stored in [YAML format][4] and a logo can be specified in SVG format. It is recommended to 
optimize the logo using [SVGO][6] or the GUI tool [SVGOMG][7], for example.

## Package structure example

```
meta/[vendor]/[package]
    - de.yml
    - en.yml
    - ru.yml
    - ...
    - logo.svg (optional)
```

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
```


Please use 4 spaces for indentation or nesting.
For more information on the file format, see [the Transifex documentation][2]

## Public vs. private/proprietary packages

The metadata repository feeds the Contao Manager search index and as such allows to search for both, publicly available
or private/proprietary packages. You are allowed to submit any package description. The definition of a publicly available
package is the fact that it's registered on [packagist.org][5]. For private packages, the Contao Manager does currently
not provide any automated installation process so providing a homepage link to describe how a user can obtain and install
the package is required.

## Spell checking

Your meta data is automatically checked for spelling issues. You might need to update the `whitelist.txt` in your
pull request in case the spell check fails but you are sure the word you used is correct.

## Supported languages

See https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js

## How to contribute

1. Clone the repo
2. Select an existing package or create new folders for a none existing package like "meta/vendor/package"
3. Make pull request
4. Your pull request is checked automatically.
5. As soon as all checks are green, a member of the review team can approve and merge your Pull Request.


[1]: https://getcomposer.org
[2]: https://docs.transifex.com/formats/yaml
[3]: https://github.com/contao/contao-manager
[4]: http://yaml.org
[5]: https://packagist.org
[6]: https://github.com/svg/svgo
[7]: https://jakearchibald.github.io/svgomg/
