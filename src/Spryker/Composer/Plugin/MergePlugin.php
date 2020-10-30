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
        'vendor/spryker/spryker/Bundles/*/composer.json',
        'vendor/spryker/spryker-shop/Bundles/*/composer.json',
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
            $installer->setUpdate(true);
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

        return $input->getOption($optionName);
    }
}
