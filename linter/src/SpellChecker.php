<?php

declare(strict_types=1);

namespace Contao\PackageMetaDataLinter;

use Symfony\Component\Process\Process;

class SpellChecker
{
    private array|null $supportedLanguages = null;

    public function __construct(private readonly string $allowListDir)
    {
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

        return $this->filterAllowedWords($language, $errors);
    }

    private function loadSupportedLanguages(): void
    {
        $process = new Process([
            'aspell',
            'dicts',
        ]);

        $process->mustRun();
        $output = $process->getOutput();

        $this->supportedLanguages = explode("\n", trim($output));
    }

    private function filterAllowedWords(string $language, array $errors): array
    {
        $defaultAllowList = $this->loadAllowList('default');
        $localizedAllowList = $this->loadAllowList($language);

        return array_diff($errors, $defaultAllowList, $localizedAllowList);
    }

    private function loadAllowList(string $key): array
    {
        static $allowLists = [];

        if (isset($allowLists[$key])) {
            return $allowLists[$key];
        }

        $allowListFile = $this->allowListDir.'/'.$key.'.txt';
        $allowLists[$key] = file_exists($allowListFile)
            ? array_filter(explode("\n", file_get_contents($allowListFile)))
            : [];

        return $allowLists[$key];
    }
}
