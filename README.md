
# Package metadata for Contao Manager

This repository provides meta information for the search index of the [Contao Manager](https://github.com/contao/contao-manager)

The metadata is stored in yaml files and a logo can be specified in SVG format.

Read this in other languages: [German](docs/de/index.md)

## Package structure example

    meta/[vendor]/[package]
        - de.yml
        - en.yml
        - ru.yml
        - ...
        - logo.svg (optional)

## Language yaml example

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
    category: Tools

## Available categories

- Communication
- Content
- Tools

## Supported languages

See https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js

## How to contribute

1. Clone the repo
2. Select an existing package or create new folders for a none existing package like "meta/vendor/package"
3. Make pull request
4. The review team will check yout contribution within approximately 5 days
