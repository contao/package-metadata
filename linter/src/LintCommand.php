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
use GuzzleHttp\Exception\RequestException;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Exception\ValidationException;
use JsonSchema\Validator;
use Symfony\Component\Console\Command\Command;
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

    protected function configure()
    {
        $this
            ->setName('app:lint')
            ->setDescription('Lint all the metadata.')
            ->addOption('skip-private-check', null, InputOption::VALUE_NONE, 'Do not check packagist if a package is private.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contao Package metadata linter');

        $this->validateMetadata($input->getOption('skip-private-check'));
        $this->validateComposerJson();

        if (!$this->error) {
            $this->io->success('All checks successful!');
        }

        return $this->error ? 1 : 0;
    }

    private function validateMetadata(bool $skipPrivate)
    {
        $this->spellChecker = new SpellChecker(__DIR__.'/../whitelists');

        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('*.{yaml,yml}');

        $this->io->writeln('Validating metadata files');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());
            $language = str_replace(['.yaml', '.yml'], '', $file->getBasename());

            $content = file_get_contents($file->getPath().'/'.$file->getFilename());

            $this->io->progressAdvance();

            // Line ending
            if (!("\n" === substr($content, -1) && "\n" !== substr($content, -2))) {
                $this->error($package, $language, 'File must end by a singe new line.');
                continue;
            }

            try {
                $content = Yaml::parse($content);
            } catch (ParseException $e) {
                $this->error($package, $language, 'The YAML file is invalid');
                continue;
            }

            // Language
            if (!isset($content[$language])) {
                $this->error($package, $language, 'The language key in the YAML file does not match the specified language file name.');
                continue;
            }

            // Validate for private package
            if ($skipPrivate) {
                $requiresHomepage = false;
            } else {
                $requiresHomepage = $this->isPrivatePackage($package);
            }

            // Content
            if (!$this->validateContent($package, $language, $content[$language], $requiresHomepage)) {
                $this->error($package, $language, 'The YAML file contains invalid data.');
                continue;
            }
        }

        $this->io->progressFinish();
    }

    private function validateComposerJson()
    {
        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('composer.json');

        $this->io->writeln('Validating composer.json files');
        $this->io->progressStart($finder->count());

        foreach ($finder as $file) {
            try {
                $schemaFile = 'file://'.__DIR__.'/../vendor/composer/composer/res/composer-schema.json';
                $schema = (object) ['$ref' => $schemaFile];
                $schema->required = [];

                $value = json_decode(file_get_contents($file->getPathname()), false);
                $validator = new Validator();
                $validator->validate($value, $schema, Constraint::CHECK_MODE_EXCEPTIONS);
            } catch (ValidationException $e) {
                $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());

                $this->error = true;
                $this->io->error(sprintf('Error in composer.json for %s: %s', $package, $e->getMessage()));
            }

            $this->io->progressAdvance();
        }

        $this->io->progressFinish();
    }

    private function isPrivatePackage(string $package): bool
    {
        static $packageCache = [];

        if (isset($packageCache[$package])) {
            return $packageCache[$package];
        }

        try {
            $this->io->writeln('Checking if package exists on packagist.org: '.$package, OutputInterface::VERBOSITY_DEBUG);
            $this->getJson('https://repo.packagist.org/p/'.$package.'.json');
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
            $message = $error['message'].(('' !== $error['property']) ? (' ['.$error['property'].']') : '');
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
                        'Property "%s" does not pass the spell checker. Either update the whitelist or fix the spelling :) Errors: %s',
                        $key,
                        implode(', ', $errors)
                    )
                );

                return false;
            }
        }

        return $validator->isValid();
    }

    private function error(string $package, string $language, string $message)
    {
        $this->error = true;
        $this->io->error(sprintf('[Package: %s; Language: %s]: %s',
            $package,
            $language,
            $message
        ));
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
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
