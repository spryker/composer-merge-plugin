<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Composer\Merge;

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackageInterface;
use UnexpectedValueException;

class ExtraPackage
{
    /**
     * @var \Composer\Package\CompletePackage $package
     */
    protected $package;

    /**
     * @var string
     */
    protected $path;

    /**
     * @param string $path
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->package = $this->loadPackage($path);
    }

    /**
     * @param string $path
     *
     * @throws \UnexpectedValueException
     *
     * @return \Composer\Package\CompletePackage
     */
    protected function loadPackage(string $path): CompletePackage
    {
        $json = $this->readPackageJson($path);
        $loader = new ArrayLoader();
        $package = $loader->load($json);

        if (!$package instanceof CompletePackage) {
            throw new UnexpectedValueException(sprintf('Expected instance of CompletePackage, got %s', get_class($package)));
        }

        return $package;
    }

    /**
     * @param string $path
     *
     * @return array
     */
    protected function readPackageJson(string $path): array
    {
        $file = new JsonFile($path);
        $json = $file->read();

        if (!isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return $json;
    }

    /**
     * @param \Composer\Package\RootPackageInterface $root
     *
     * @return void
     */
    public function mergeAutoload(RootPackageInterface $root)
    {
        $autoload = $this->package->getAutoload();

        if (!empty($autoload)) {
            $root->setAutoload(array_merge_recursive(
                $root->getAutoload(),
                $this->fixRelativePaths($autoload)
            ));
        }

        $autoloadDev = $this->package->getDevAutoload();

        if (!empty($autoloadDev)) {
            $root->setDevAutoload(array_merge_recursive(
                $root->getDevAutoload(),
                $this->fixRelativePaths($autoloadDev)
            ));
        }
    }

    /**
     * @param array $paths
     *
     * @return array
     */
    protected function fixRelativePaths(array $paths): array
    {
        $base = dirname($this->path);
        $base = ($base === '.') ? '' : "{$base}/";

        array_walk_recursive(
            $paths,
            function (&$path) use ($base) {
                $path = "{$base}{$path}";
            }
        );

        return $paths;
    }
}
