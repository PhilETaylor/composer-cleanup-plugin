<?php

namespace Barryvdh\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Script\CommandEvent;
use Composer\Util\Filesystem;
use Composer\Package\BasePackage;

class CleanupPlugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer $composer */
    protected $composer;
    /** @var IOInterface $io */
    protected $io;
    /** @var Config $config */
    protected $config;
    /** @var Filesystem $filesystem */
    protected $filesystem;
    /** @var array $rules */
    protected $rules;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer   = $composer;
        $this->io         = $io;
        $this->config     = $composer->getConfig();
        $this->filesystem = new Filesystem();
        $this->rules      = CleanupRules::getRules();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_PACKAGE_INSTALL  => [
                ['onPostPackageInstall', 0],
            ],
            ScriptEvents::POST_PACKAGE_UPDATE  => [
                ['onPostPackageUpdate', 0],
            ],
            ScriptEvents::POST_INSTALL_CMD  => [
                ['onPostInstallUpdateCmd', 0],
            ],
            ScriptEvents::POST_UPDATE_CMD  => [
                ['onPostInstallUpdateCmd', 0],
            ],
        ];
    }

    /**
     * Function to run after a package has been installed.
     * @param PackageEvent $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        /** @var \Composer\Package\CompletePackage $package */
        $package = $event->getOperation()->getPackage();

        $this->cleanPackage($package);
    }

    /**
     * Function to run after a package has been updated.
     * @param PackageEvent $event
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
        /** @var \Composer\Package\CompletePackage $package */
        $package = $event->getOperation()->getTargetPackage();

        $this->cleanPackage($package);
    }

    /**
     * Function to run after a package has been updated.
     *
     * @param CommandEvent $event
     */
    public function onPostInstallUpdateCmd(Event $event)
    {
        /** @var WritableRepositoryInterface $repository */
        $repository = $this->composer->getRepositoryManager()->getLocalRepository();

        /** @var \Composer\Package\CompletePackage $package */
        foreach ($repository->getPackages() as $package) {
            if ($package instanceof BasePackage) {
                $this->cleanPackage($package);
            }
        }
    }

    /**
     * Clean a package, based on its rules.
     *
     * @param BasePackage $package The package to clean
     *
     * @return bool True if cleaned
     */
    protected function cleanPackage(BasePackage $package)
    {
        // Only clean 'dist' packages
        if ('dist' !== $package->getInstallationSource()) {
            return false;
        }

        $vendorDir   = $this->config->get('vendor-dir');
        $targetDir   = $package->getTargetDir();
        $packageName = $package->getPrettyName();
        $packageDir  = $targetDir ? $packageName.'/'.$targetDir : $packageName;

        $rules = isset($this->rules[$packageName]) ? $this->rules[$packageName] : null;
        if (!$rules) {
            return;
        }

        $dir = $this->filesystem->normalizePath(realpath($vendorDir.'/'.$packageDir));
        if (!is_dir($dir)) {
            return false;
        }

        foreach ((array) $rules as $part) {
            // Split patterns for single globs (should be max 260 chars)
            $patterns = explode(' ', trim($part));

            foreach ($patterns as $pattern) {
                try {
                    foreach (glob($dir.'/'.$pattern) as $file) {
                        $this->filesystem->remove($file);
                        $this->io->write('[CleanupPlugin] Removing file: '.$file);
                    }
                } catch (\Exception $e) {
                    $this->io->write("[CleanupPlugin] Could not parse $packageDir ($pattern): ".$e->getMessage());
                }
            }
        }

        return true;
    }
}
