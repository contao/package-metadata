# Introduction

This central repository provides additional meta information for the [Contao Manager][3].

It allows to translate and enhance package information of any [Composer][1] package, thus you can also
provide meta information for e.g. packages that are not of type `contao-bundle` but e.g. a suggestion of
your very own bundle such as a general PHP excel library.

The metadata is stored in [YAML format][4] and a logo can be specified in SVG format.

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

## Supported languages

See https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js

## How to contribute

1. Clone the repo
2. Select an existing package or create new folders for a none existing package like "meta/vendor/package"
3. Make pull request
4. The review team will check your contribution within approx. 5 days

## Roadmap

* **Automated re-indexing**

    Right now, the index is not updated automatically when this repository is changed. We plan to
introduce something like using a GitHub webhook to trigger the package re-indexing for the affected packages.

* **Merge access for the responsible developers**

    We understand it is sort of a problem that developers of packages are not allowed to update their package information
on their own but instead need to wait for somebody that has merge rights to get a pull request merged. We plan to
introduce a bot that is able to determine which GitHub users are allowed to review pull requests for certain paths in the
meta data directory. This could be done based on the GitHub `OWNERS` file and review feature.


[1]: https://getcomposer.org
[2]: https://docs.transifex.com/formats/yaml
[3]: https://github.com/contao/contao-manager
[4]: http://yaml.org/
