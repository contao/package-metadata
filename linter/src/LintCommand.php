<?php

declare(strict_types=1);

namespace Contao\PackageMetaDataLinter;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Exception\ValidationException;
use JsonSchema\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LintCommand extends Command
{
    protected static $defaultName = 'app:lint';
    protected static $defaultDescription = 'Lint all the metadata.';

    private SymfonyStyle $io;
    private SpellChecker $spellChecker;
    private bool $error = false;
    private array $privatePackages = [];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'To lint specific files')
            ->addOption('skip-private-check', null, InputOption::VALUE_NONE, 'Do not check packagist if a package is private.')
            ->addOption('skip-spell-check', null, InputOption::VALUE_NONE, 'Do not check spelling.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->spellChecker = new SpellChecker(__DIR__.'/../allowlists');
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contao Package metadata linter');

        $this->validatePackageNames();

        if ($files = $input->getArgument('files')) {
            $this->validateFiles($files, $input->getOption('skip-private-check'), $input->getOption('skip-spell-check'));

            return $this->error ? Command::FAILURE : Command::SUCCESS;
        }

        $this->validateMetadata($input->getOption('skip-private-check'), $input->getOption('skip-spell-check'));
        $this->validateComposerJson();

        if (!$this->error) {
            $this->io->success('All checks successful!');
        }

        return $this->error ? Command::FAILURE : Command::SUCCESS;
    }

    private function validateFiles(array $files, bool $skipPrivate, bool $skipSpellCheck): void
    {
        foreach ($files as $path) {
            $file = new \SplFileInfo(realpath($path) ?: $path);

            if (!$file->isFile()) {
                continue;
            }

            if ('composer.json' === $file->getFilename()) {
                $this->validateComposerFile($file);
            } else {
                $this->validateMetadataFile($file, $skipPrivate, $skipSpellCheck);
            }
        }
    }

    private function validateMetadata(bool $skipPrivate, bool $skipSpellCheck): void
    {
        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('*.{yaml,yml}');

        $this->io->writeln('Validating metadata files');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            $this->validateMetadataFile($file, $skipPrivate, $skipSpellCheck);
        }

        $this->io->progressFinish();
    }

    private function validateMetadataFile(\SplFileInfo $file, bool $skipPrivate, bool $skipSpellCheck): void
    {
        $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());
        $language = str_replace(['.yaml', '.yml'], '', $file->getBasename());

        $content = file_get_contents($file->getPath().'/'.$file->getFilename());

        try {
            $this->io->progressAdvance();
        } catch (RuntimeException $e) {
            // ignore no progress bar
        }

        // Line ending
        if (!(str_ends_with($content, "\n") && "\n" !== substr($content, -2))) {
            $this->error($package, $language, 'File must end by a singe new line.');

            return;
        }

        try {
            $content = Yaml::parse($content);
        } catch (ParseException $e) {
            $this->error($package, $language, 'The YAML file is invalid');

            return;
        }

        // Language
        if (!isset($content[$language])) {
            $this->error($package, $language, 'The language key in the YAML file does not match the specified language file name.');

            return;
        }

        // Validate for private package
        if ($skipPrivate) {
            $requiresHomepage = false;
        } else {
            $requiresHomepage = !file_exists($file->getPath().'/composer.json') && $this->isPrivatePackage($package);
        }

        // Content
        if (!$this->validateContent($package, $language, $content[$language], $requiresHomepage, $skipSpellCheck)) {
            $this->error($package, $language, 'The YAML file contains invalid data.');
        }
    }

    private function validateComposerJson(): void
    {
        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('composer.json');

        $this->io->writeln('Validating composer.json files');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            $this->validateComposerFile($file);
        }

        $this->io->progressFinish();
    }

    private function validatePackageNames(): void
    {
        $finder = new Finder();
        $finder->directories()->in(__DIR__.'/../../meta');

        $this->io->writeln('Validating package names');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            $package = $file->getRelativePathname();

            if ($package !== mb_strtolower($package)) {
                $this->error = true;
                $this->io->error(
                    sprintf('[Package: %s]: The package name has to be all lowercase', $package)
                );
            }
        }

        $this->io->progressFinish();
    }

    private function validateComposerFile(\SplFileInfo $file): void
    {
        $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());

        try {
            $schemaFile = 'file://'.__DIR__.'/../vendor/composer/composer/res/composer-schema.json';
            $schema = (object) ['$ref' => $schemaFile];
            $schema->required = ['name', 'homepage'];

            $value = json_decode(file_get_contents($file->getPathname()), false, 512, JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($value, $schema, Constraint::CHECK_MODE_EXCEPTIONS);

            if ($value->name !== $package) {
                $this->error = true;
                $this->io->error(sprintf('[Package: %s] Package name in composer.json does not match', $package));
            }
        } catch (ValidationException $e) {
            $this->error = true;
            $this->io->error(sprintf('[Package: %s] Error in composer.json: %s', $package, $e->getMessage()));
        }

        try {
            $this->io->progressAdvance();
        } catch (RuntimeException $e) {
            // ignore no progress bar
        }
    }

    private function isPrivatePackage(string $package): bool
    {
        if (isset($this->privatePackages[$package])) {
            return $this->privatePackages[$package];
        }

        try {
            $this->io->writeln('Checking if package exists on packagist.org: '.$package, OutputInterface::VERBOSITY_DEBUG);

            // Throws on response status >= 300
            $this->httpClient
                ->request('GET', 'https://repo.packagist.org/p2/'.$package.'.json')
                ->getContent()
            ;

            return $this->privatePackages[$package] = false;
        } catch (HttpExceptionInterface $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return $this->privatePackages[$package] = true;
            }

            throw $exception;
        }
    }

    private function validateContent(string $package, string $language, array $content, bool $requiresHomepage, bool $skipSpellCheck): bool
    {
        $data = json_decode(json_encode($content));

        $schemaData = json_decode(file_get_contents(\dirname(__DIR__).'/schema.json'), true, 512, JSON_THROW_ON_ERROR);

        if ($requiresHomepage) {
            $schemaData['required'] = ['homepage'];
        }

        $validator = new Validator();
        $validator->validate($data, $schemaData);

        foreach ($validator->getErrors() as $error) {
            $message = $error['message'].('' !== $error['property'] ? (' ['.$error['property'].']') : '');
            $this->io->error($message);
        }

        if (!$validator->isValid()) {
            return false;
        }

        if ($skipSpellCheck) {
            return true;
        }

        return $this->spellCheck($package, $language, $content);
    }

    private function spellCheck(string $package, string $language, array $content): bool
    {
        foreach (['title', 'description'] as $key) {
            if (!isset($content[$key])) {
                continue;
            }

            $errors = $this->spellChecker->spellCheck($content[$key], $language);

            if (0 !== \count($errors)) {
                $this->error(
                    $package,
                    $language,
                    sprintf(
                        'Property "%s" does not pass the spell checker. Either update the allow list or fix the spelling :) Errors: %s',
                        $key,
                        implode(', ', $errors)
                    )
                );

                return false;
            }
        }

        return true;
    }

    private function error(string $package, string $language, string $message): void
    {
        $this->error = true;
        $this->io->error(sprintf(
            '[Package: %s; Language: %s]: %s',
            $package,
            $language,
            $message
        ));
    }
}
