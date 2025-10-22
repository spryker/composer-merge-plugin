<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Spryker\Composer\Merge\ExtraPackage;
use Symfony\Component\Console\Input\InputInterface;

class MergePlugin implements PluginInterface, EventSubscriberInterface
{
    private const CALLBACK_PRIORITY = 50000;

    /**
     * @var bool
     */
    protected $isFirstInstall = false;

    /**
     * @var \Composer\Composer
     */
    protected $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * @var string[]
     */
    protected $includes = [
        'src/Spryker/*/composer.json',
        'src/SprykerFeature/*/composer.json',
        'src/SprykerShop/*/composer.json',
    ];

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @return array[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['preAutoloadDump', static::CALLBACK_PRIORITY],
            ScriptEvents::POST_INSTALL_CMD => ['postInstallOrUpdate', static::CALLBACK_PRIORITY],
            ScriptEvents::POST_UPDATE_CMD => ['postInstallOrUpdate', static::CALLBACK_PRIORITY],
            PackageEvents::POST_PACKAGE_INSTALL => ['postPackageInstall', static::CALLBACK_PRIORITY],
        ];
    }

    /**
     * @param \Composer\Script\Event $event
     *
     * @return void
     */
    public function preAutoloadDump(ScriptEvent $event): void
    {
        $this->mergeFiles();
        $this->addProjectWildCard();
        $this->addSplitNamespaces();
    }

    /**
     * @return void
     */
    protected function mergeFiles(): void
    {
        $root = $this->composer->getPackage();

        $files = array_map('glob', $this->includes);

        foreach (array_reduce($files, 'array_merge', []) as $path) {
            $this->mergeFile($root, $path);
        }
    }

    /**
     * @param \Composer\Package\RootPackageInterface $root
     * @param string $path
     *
     * @return void
     */
    protected function mergeFile(RootPackageInterface $root, string $path): void
    {
        $extraPackage = new ExtraPackage($path);
        $this->io->info(sprintf('Loading <comment>%s</comment>', $path));
        $extraPackage->mergeAutoload($root);
    }

    /**
     * @param \Composer\Installer\PackageEvent $event
     *
     * @return void
     */
    public function postPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage()->getName();
            if ($package === 'spryker/composer-merge-plugin') {
                $this->io->info('spryker/composer-merge-plugin installed');
                $this->isFirstInstall = true;
            }
        }
    }

    /**
     * @param \Composer\Script\Event $event
     *
     * @return void
     */
    public function postInstallOrUpdate(ScriptEvent $event): void
    {
        if ($this->isFirstInstall) {
            $this->isFirstInstall = false;
            $this->io->info('<comment>Running additional update to apply autoload and autoload-dev merge</comment>');

            $config = $this->composer->getConfig();

            $preferSource = $config->get('preferred-install') === 'source';
            $preferDist = $config->get('preferred-install') === 'dist';

            $installer = Installer::create(
                $event->getIO(),
                Factory::create($event->getIO(), null, false)
            );

            $installer->setPreferSource($preferSource);
            $installer->setPreferDist($preferDist);
            $installer->setDevMode($event->isDevMode());
            $installer->setDumpAutoloader(true);
            $installer->setOptimizeAutoloader($this->getOption($event->getIO(), 'optimize-autoloader'));
            $installer->setPreferLowest($this->getOption($event->getIO(), 'prefer-lowest'));
            $installer->setUpdate(false);
            $installer->run();
        }
    }

    /**
     * @return bool
     */
    protected function getOption(IOInterface $io, string $optionName): bool
    {
        $ioReflection = new \ReflectionClass($io);

        $inputReflection = $ioReflection->getProperty('input');
        $inputReflection->setAccessible(true);

        /** @var InputInterface $input */
        $input = $inputReflection->getValue($io);

        if (!$input->hasOption($optionName)) {
            return false;
        }

        return $input->getOption($optionName);
    }

    protected function addProjectWildCard(): void
    {
        $package  = $this->composer->getPackage();
        $extra    = $package->getExtra();
        $mapping  = $extra['psr4-wildcard'] ?? [];
        if (!$mapping || !is_array($mapping)) {
            return;
        }

        $autoload = $package->getAutoload();
        $psr4     = $autoload['psr-4'] ?? [];
        $root     = getcwd();

        foreach ($mapping as $namespaceNamespace => $pattern) {
            $dirs = glob($pattern . '*' . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
            $modules = [];
            foreach ($dirs as $dir) {
                $pathParts = explode(DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR));
                $modules[] = end($pathParts);
            }
            $modules = array_unique($modules);

            foreach ($modules as $module) {
                $namespace = $namespaceNamespace . $module . '\\';
                $dirs = glob($pattern . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
                $rels = array_map(function ($abs) use ($root) {
                    $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $abs)), '/');
                    return rtrim($rel, '/') . '/';
                }, $dirs);

                $existing = $psr4[$namespace] ?? [];
                $existing = is_array($existing) ? $existing : [$existing];

                $psr4[$namespace] = array_values(array_unique(array_merge($existing, $rels)));
                $this->io->info(
                    sprintf('<info>psr4-wildcard</info>: %s -> %d dirs', $namespace, count($rels))
                );
            }
        }

        $autoload['psr-4'] = $psr4;
        $package->setAutoload($autoload);
    }

    protected function addSplitNamespaces(): void
    {
        $package  = $this->composer->getPackage();
        $namespacesToSplit = $package->getExtra()['splitting']['namespaces'] ?? ['Spryker\\'];

        $autoload = $package->getAutoload();
        $psr4     = $autoload['psr-4'] ?? [];
        $root     = getcwd();

        foreach ($namespacesToSplit as $namespace) {
            if (!isset($psr4[$namespace])) {
                continue;
            }

            $unprocessedFolders = [];
            foreach ($psr4[$namespace] as $folder) {
                $folderProcessed = false;
                $dirs = glob($folder . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
                foreach ($dirs as $dir) {
                    $pathParts = explode(DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR));
                    $module = array_pop($pathParts);
                    $layer = array_pop($pathParts);
                    if (in_array($layer, ['Shared', 'Service', 'Client', 'Yves', 'Glue', 'Zed']) === false) {
                        continue;
                    }
                    $psr4[$namespace . $layer . '\\' . $module . '\\'] = [$dir];
                    $folderProcessed = true;
                }
                if (!$folderProcessed) {
                    $unprocessedFolders[] = $folder;
                }
            }
            unset($psr4[$namespace]);
            if (count($unprocessedFolders) > 1) {
                $psr4[$namespace] = $unprocessedFolders;
            }
        }

        $autoload['psr-4'] = $psr4;
        $package->setAutoload($autoload);
    }
}
