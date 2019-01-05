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
     * @var string
     */
    private $whitelistDir;

    /**
     * @var array[]
     */
    private $whitelists = [];

    /**
     * @var array|null
     */
    private $supportedLanguages;

    public function __construct(string $whitelistDir)
    {
        $this->whitelistDir = $whitelistDir;
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

        return $this->filterWhitelistedWords($language, $errors);
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

    private function filterWhitelistedWords(string $language, array $errors): array
    {
        $defaultWhitelist   = $this->loadWhitelist('default');
        $localizedWhitelist = $this->loadWhitelist($language);

        return array_diff($errors, $defaultWhitelist, $localizedWhitelist);
    }

    private function loadWhitelist(string $key): array
    {
        if (isset($this->whitelists[$key])) {
            return $this->whitelists[$key];
        }

        $whitelistFile = $this->whitelistDir . '/' . $key . '.txt';
        $this->whitelists[$key] = file_exists($whitelistFile)
            ? array_filter(explode("\n", file_get_contents($whitelistFile)))
            : [];

        return $this->whitelists[$key];
    }
}
