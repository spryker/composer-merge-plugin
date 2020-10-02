<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\Event as BaseEvent;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Spryker\Composer\Logger\Logger;
use Spryker\Composer\Merge\ExtraPackage;
use Spryker\Composer\Merge\PluginState;

class MergePlugin implements PluginInterface, EventSubscriberInterface
{
    private const CALLBACK_PRIORITY = 50000;

    /**
     * @var \Composer\Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $logger;

    /**
     * @var string[]
     */
    protected $includes = [
        'vendor/spryker/spryker/Bundles/*/',
        'vendor/spryker/spryker-shop/Bundles/*/',
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
     * @return array[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['preAutoloadDump', static::CALLBACK_PRIORITY],
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
        $this->logger->write(sprintf('Loading <comment>%s</comment>...', $path));
        $extraPackage->mergeAutoload($root);
    }
}
