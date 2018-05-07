
# Paketmetadaten für den Contao Manager

Dieses Repository stellt Metainformationen für den Suchindex des [Contao Managers](https://github.com/contao/contao-manager) bereit.

Die Metadaten werden in Yaml-Dateien gespeichert und es kann ein Logo im SVG-Format angegeben werden.

## Beispieldaten für ein Paket

    meta/[vendor]/[package]
        - de.yml
        - en.yml
        - ru.yml
        - ...
        - logo.svg (optional)

## Beispiel für eine Sprachdatei (Yaml)

    title: Titel des Bundles oder des Modules
    description: >
      Lange Beschreibung des Bundles oder des Modules mit Zeilenumbrüchen.

      Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua.
      At vero eos et accusam et justo duo dolores et ea rebum.
    keywords:
      - keyword1
      - lorem ipsum
      - keyword2
      ...
    category: Tools

## Verfügbare Kategorien

- Kommunikation
- Inhalte
- Hilfprogramme

## Unterstützte Sprachen

Siehe https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js

## Wie kannst Du beitragen

1. Klone das Repo
2. Wählen ein vorhandenes Paket oder erstelle neue Ordner für ein nicht existierendes Paket wie "meta/vendor/package"
3. Erstelle einen Pull-Request
4. Das Review-Team überprüft Deinen Pull-Request innerhalb von ca. 5 Tagen