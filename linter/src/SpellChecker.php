<?php

declare(strict_types=1);

/*
 * Contao Package Metadata Linter
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataLinter;

use Symfony\Component\Process\Process;

class SpellChecker
{
    /**
     * @var array
     */
    private $whitelist;

    /**
     * @var array|null
     */
    private $supportedLanguages;

    public function __construct(string $whitelist)
    {
        $this->whitelist = array_filter(explode("\n", file_get_contents($whitelist)));
    }

    public function spellCheck(string $text, string $language): array
    {
        if (null === $this->supportedLanguages) {
            $this->loadSupportedLanguages();
        }

        if (!\in_array($language, $this->supportedLanguages, true)) {
            throw new \InvalidArgumentException(sprintf('The language "%s" is not supported. Make sure the aspell package is loaded.', $language));
        }

        $process = new Process([
            'aspell',
            '-l',
            $language,
            'list',
        ], null, null, $text);

        $process->mustRun();
        $output = $process->getOutput();
        $errors = explode("\n", trim($output));
        $errors = array_filter($errors);

        return array_diff($errors, $this->whitelist);
    }

    private function loadSupportedLanguages()
    {
        $process = new Process([
            'aspell',
            'dicts',
        ]);

        $process->mustRun();
        $output = $process->getOutput();

        $this->supportedLanguages = explode("\n", trim($output));
    }
}
