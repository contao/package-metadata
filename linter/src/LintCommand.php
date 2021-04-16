<?php

declare(strict_types=1);

/*
 * Contao Package Metadata Linter
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataLinter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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

class LintCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var SpellChecker
     */
    private $spellChecker;

    /**
     * @var bool
     */
    private $error = false;

    protected function configure(): void
    {
        $this
            ->setName('app:lint')
            ->setDescription('Lint all the metadata.')
            ->addArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'To lint specific files')
            ->addOption('skip-private-check', null, InputOption::VALUE_NONE, 'Do not check packagist if a package is private.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contao Package metadata linter');

        $this->validatePackageNames();

        if ($files = $input->getArgument('files')) {
            $this->validateFiles($files, $input->getOption('skip-private-check'));

            return $this->error ? 1 : 0;
        }

        $this->validateMetadata($input->getOption('skip-private-check'));
        $this->validateComposerJson();

        if (!$this->error) {
            $this->io->success('All checks successful!');
        }

        return $this->error ? 1 : 0;
    }

    private function validateFiles(array $files, $skipPrivate): void
    {
        foreach ($files as $path) {
            $file = new \SplFileInfo(realpath($path));

            if (!$file->isFile()) {
                $this->io->error(sprintf('The file "%s" was not found', $path));
                $this->error = true;
                continue;
            }

            if ('composer.json' === $file->getFilename()) {
                $this->validateComposerFile($file);
            } else {
                $this->validateMetadataFile($file, $skipPrivate);
            }
        }
    }

    private function validateMetadata(bool $skipPrivate): void
    {
        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('*.{yaml,yml}');

        $this->io->writeln('Validating metadata files');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            $this->validateMetadataFile($file, $skipPrivate);
        }

        $this->io->progressFinish();
    }

    private function validateMetadataFile(\SplFileInfo $file, bool $skipPrivate): void
    {
        if (null === $this->spellChecker) {
            $this->spellChecker = new SpellChecker(__DIR__.'/../allowlists');
        }

        $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());
        $language = str_replace(['.yaml', '.yml'], '', $file->getBasename());

        $content = file_get_contents($file->getPath().'/'.$file->getFilename());

        try {
            $this->io->progressAdvance();
        } catch (RuntimeException $e) {
            // ignore no progress bar
        }

        // Line ending
        if (!("\n" === substr($content, -1) && "\n" !== substr($content, -2))) {
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
        if (!$this->validateContent($package, $language, $content[$language], $requiresHomepage)) {
            $this->error($package, $language, 'The YAML file contains invalid data.');

            return;
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

            $value = json_decode(file_get_contents($file->getPathname()), false);
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
        static $packageCache = [];

        if (isset($packageCache[$package])) {
            return $packageCache[$package];
        }

        try {
            $this->io->writeln('Checking if package exists on packagist.org: '.$package, OutputInterface::VERBOSITY_DEBUG);
            $this->getJson('https://repo.packagist.org/p2/'.$package.'.json');
        } catch (RequestException $e) {
            if (404 !== $e->getResponse()->getStatusCode()) {
                // Shouldn't happen, throw
                throw $e;
            }

            return true;
        }

        return $packageCache[$package] = false;
    }

    private function validateContent(string $package, string $language, array $content, bool $requiresHomepage): bool
    {
        $data = json_decode(json_encode($content));

        $schemaData = json_decode(file_get_contents(\dirname(__DIR__).'/schema.json'), true);

        if ($requiresHomepage) {
            $schemaData['required'] = ['homepage'];
        }

        $validator = new Validator();
        $validator->validate($data, $schemaData);

        foreach ($validator->getErrors() as $error) {
            $message = $error['message'].('' !== $error['property'] ? (' ['.$error['property'].']') : '');
            $this->io->error($message);
        }

        // Spellcheck certain properties
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

        return $validator->isValid();
    }

    private function error(string $package, string $language, string $message): void
    {
        $this->error = true;
        $this->io->error(sprintf('[Package: %s; Language: %s]: %s',
            $package,
            $language,
            $message
        ));
    }

    /**
     * @throws GuzzleException
     */
    private function getJson(string $uri): array
    {
        $client = new Client();

        $response = $client->request('GET', $uri);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Response error. Status code %s', $response->getStatusCode()));
        }

        return (array) json_decode($response->getBody()->getContents(), true);
    }
}
