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
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Spryker\Composer\Merge\ExtraPackage;

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
    protected $logger;

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
        $this->logger = $io;
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
            ScriptEvents::POST_PACKAGE_INSTALL => ['postPackageInstall', static::CALLBACK_PRIORITY],
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
        $this->logger->info(sprintf('Loading <comment>%s</comment>', $path));
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
                $this->logger->info('spryker/composer-merge-plugin installed');
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
            $this->logger->info('<comment>Running additional update to apply autoload and autoload-dev merge</comment>');

            $config = $this->composer->getConfig();

            $preferSource = $config->get('preferred-install') == 'source';
            $preferDist = $config->get('preferred-install') == 'dist';

            $installer = Installer::create(
                $event->getIO(),
                Factory::create($event->getIO(), null, false)
            );

            $installer->setPreferSource($preferSource);
            $installer->setPreferDist($preferDist);
            $installer->setDevMode($event->isDevMode());
            $installer->setDumpAutoloader(true);
            $installer->setOptimizeAutoloader(false);
            $installer->setUpdate(true);
            $installer->run();
        }
    }
}
