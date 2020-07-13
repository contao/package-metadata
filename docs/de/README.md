# Einführung

Dieses zentrale Repository stellt Metainformationen für die Pakete innerhalb des [Contao Managers][3] bereit.

Es erlaubt das Übersetzen und Anreichern von Paketinformationen jedes beliebigen [Composer][1] Pakets. Daher können
auch Pakete, die nicht vom Typ `contao-bundle` sind (wie bspw. eine allgemeine PHP Excel Exportbibliothek), übersetzt
werden.

Die Metadaten werden im [YAML-Format][4] gespeichert und es kann ein Logo im SVG-Format angegeben werden. Es wird 
empfohlen, das Logo beispielsweise mittels [SVGO][6] bzw. dem GUI-Tool [SVGOMG][7] entsprechend zu optimieren.

## Beispiel einer Paketstruktur

```
meta/[vendor]/[paket-name]
    - de.yml
    - en.yml
    - ru.yml
    - ...
    - logo.svg (optional)
    - composer.json (nur für private Pakete und optional. [Details](#öffentliche-vs-privateproprietäre-pakete))
```

Hinweis: Das `logo.svg` kann auch direkt innerhalb von `[vendor]` liegen, es wird dann als Fallback für alle Pakete
dieses `[vendor]` verwendet, sofern kein Logo für das explizite Paket angegeben wurde.

## Beispiel einer YAML-Sprachdatei

```
de:
    title: Titel des Bundles oder des Moduls
    description: >
        Lange Beschreibung des Bundles oder des Moduls mit Zeilenumbrüchen.

        Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua.
        At vero eos et accusam et justo duo dolores et ea rebum.
    keywords:
      - keyword1
      - lorem ipsum
      - keyword2
      ...
    support:
        issues: https://github.com/demo/demo/issues
        docs: https://example.org/demo/de/docs
    suggest:
        vendor/package: Dieses Package ermöglicht XLSX-Export
```

Bitte verwendet 4 Leerzeichen zum Einrücken oder Verschachteln und sprecht den Nutzer mit "du" an.
Für weitere Informationen zum Dateiformat, sieh dir die [Transifex Dokumentation][2] an.

## YAML-Syntax

In der YAML-Datei können die folgenden Keywörter definiert werden: 

| | | 
|-|-| 
| __title__       | Der Titel der Extension| 
| __description__ | Lange Beschreibung der Extension, die in der Detailansicht erscheint | 
| __keywords__    | Liste von Keywörtern für die Suche | 
| __dependency__  | Bei `true` wird die Extension nicht über die Suche gefunden. Dies ist der Fall für Extensions, die nicht für Anwendende gedacht sind (Standard: `false`) | 
| __support__     | Übersetzung bzw. Angabe von sprach-spezifischen Support-Links der `composer.json`. Key-Value-Syntax: [Unterstützte Keys][8] | 
| __suggest__     | Übersetzung der Texte für empfohlene Pakete (`suggest`-Abschnitt) | 

## Öffentliche vs. private/proprietäre Pakete

Das Metadaten-Repository füttert den Suchindex des Contao Managers und erlaubt die Suche sowohl nach öffentlichen als auch
nach privaten bzw. proprietären Paketen. Entsprechend kannst du für beide Pakettypen Beschreibungen einreichen. Die
Definition eines öffentlichen Pakets ist dessen Verfügbarkeit via [packagist.org][5]. Für private Pakete bietet der 
Contao Manager aktuell noch keinen automatisierten Installationsprozess. Deshalb ist eine "homepage" Pflichtangabe
und soll Angaben zur Installation und ggf. zum Erwerb eines Lizenzschlüssels etc. enthalten.

Der Contao Manager zeigt für jedes Paket hilfreiche Informationen an, darunter eine Auflistung von Abhängigkeiten des
Pakets. Diese Informationen werden über die `composer.json` bereitgestellt und sind aufgrund dessen nur für öffentliche
Pakete verfügbar. Um diese Informationen für private Pakete bereitzustellen, kann den Metadaten eine `composer.json` ergänzt
werden.

## Rechtschreibprüfung

Die Metadaten werden automatisch auf korrekte Rechtschreibung überprüft. Wenn die Überprüfung fehlschlägt, du dir aber sicher bist, 
dass das Wort korrekt ist, musst du womöglich die Allow-Lists aktualisieren. Diese werden im Ordner `linter/allowlists` nach Sprache 
gepflegt. Eigennamen und andere Begriffe, die in jeder Sprache identisch sind, werden in `default.txt` gepflegt.

## Unterstützte Sprachen

Siehe https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js

## Wie kannst Du beitragen

1. Klone das Repo
2. Wähle ein vorhandenes Paket oder erstelle einen neuen Ordner für ein nicht existierendes Paket wie "meta/vendor/package"
3. Erstelle einen Pull Request
4. Dein Pull Request wird automatisch überprüft.
5. Sobald alle Checks grün sind, kann ein Mitglied des Review-Teams deinen Pull Request freigeben.


[1]: https://getcomposer.org
[2]: https://docs.transifex.com/formats/yaml
[3]: https://github.com/contao/contao-manager
[4]: http://yaml.org
[5]: https://packagist.org
[6]: https://github.com/svg/svgo
[7]: https://jakearchibald.github.io/svgomg/
[8]: https://getcomposer.org/doc/04-schema.md#support
