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
4. Your pull request is checked automatically.
5. As soon as all checks are green, either a Code Owner or a member of the review team can approve and merge your
   Pull Request.
   
## Becoming a Code Owner

Code Owners can approve Pull Requests through regular GitHub Reviews and trigger automated merging without actually
having push access to the repository. To become a Code Owner of your vendor you have to create a Pull Request adjusting
the `CODEOWNERS` file. As soon as a member of the review team approves the changes and checks if you are really allowed
to make changes for the given vendor, the Pull Request is merged and from now on you can approve Pull Requests that
affect your own paths yourself.

[1]: https://getcomposer.org
[2]: https://docs.transifex.com/formats/yaml
[3]: https://github.com/contao/contao-manager
[4]: http://yaml.org/
