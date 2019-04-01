<?php

namespace Netresearch\Composer\Patches;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Downloader\DownloaderInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * The patchSet integration for Composer, which applies the patches contained
 * with this package onto other packages
 *
 * @author Christian Opitz <christian.opitz at netresearch.de>
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Composer
     */
    protected $composer;

    /**
     *
     * @var DownloaderInterface
     */
    protected $downloader;

    /**
     * Get the events, this {@see \Composer\EventDispatcher\EventSubscriberInterface}
     * subscribes to
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::PRE_PACKAGE_UNINSTALL => ['restore'],
            PackageEvents::PRE_PACKAGE_UPDATE    => ['restore'],
            ScriptEvents::POST_UPDATE_CMD        => ['apply'],
            ScriptEvents::POST_INSTALL_CMD       => ['apply'],
        ];
    }

    /**
     * Activate the plugin (called from {@see \Composer\Plugin\PluginManager})
     *
     * @param \Composer\Composer       $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io         = $io;
        $this->composer   = $composer;
        $this->downloader = new Downloader\Composer($io);

        // Add the installer
        $noopInstaller = new Installer($io);
        $composer->getInstallationManager()->addInstaller($noopInstaller);
    }

    /**
     * Revert patches on/from packages that are going to be removed
     *
     * @param PackageEvent $event
     *
     * @throws Exception
     *
     * @return void
     */
    public function restore(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if ($operation instanceof UpdateOperation) {
            $initialPackage = $operation->getInitialPackage();
        } elseif ($operation instanceof UninstallOperation) {
            $initialPackage = $operation->getPackage();
        } else {
            throw new Exception('Unexpected operation ' . get_class($operation));
        }

        static $history = [];

        foreach ($this->getPatches($initialPackage, $history) as $patchesAndPackage) {
            list($patches, $package) = $patchesAndPackage;
            $packagePath = $this->getPackagePath($package);
            foreach (array_reverse($patches) as $patch) {
                /* @var $patch Patch */
                try {
                    $patch->revert($packagePath, true);
                } catch (PatchCommandException $e) {
                    $this->writePatchNotice('revert', $patch, $package, $e);
                    continue;
                }
                $this->writePatchNotice('revert', $patch, $package);
                $patch->revert($packagePath);
            }
        }
    }

    /**
     * Get the patches and packages that are not already in $history
     *
     * @param \Composer\Package\PackageInterface $initialPackage
     * @param array &                            $history
     *
     * @return array
     * @throws \Netresearch\Composer\Patches\Exception
     */
    protected function getPatches(PackageInterface $initialPackage, array &$history)
    {
        $packages  = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $patchSets = [];
        foreach ($packages as $package) {
            $extra = $package->getExtra();
            if (isset($extra['patches']) && $initialPackage->getName() != $package->getName()) {
                $patchSets[$package->getName()] = [$extra['patches'], [$initialPackage]];
            }
        }

        $extra = $initialPackage->getExtra();
        if (isset($extra['patches'])) {
            $patchSets[$initialPackage->getName()] = [$extra['patches'], $packages];
        }

        $patchesAndPackages = [];
        foreach ($patchSets as $sourceName => $patchConfAndPackages) {
            $patchSet = new PatchSet($patchConfAndPackages[0], $this->downloader);
            foreach ($patchConfAndPackages[1] as $package) {
                $id      = $sourceName . '->' . $package->getName();
                $patches = $patchSet->getPatches($package->getName(), $package->getVersion());
                if (!array_key_exists($id, $history) && count($patches)) {
                    $patchesAndPackages[$id] = [$patches, $package];
                    $history[$id]            = true;
                }
            }
        }

        return $patchesAndPackages;
    }

    /**
     * Get the install path for a package
     *
     * @param \Composer\Package\PackageInterface $package
     *
     * @return string
     */
    protected function getPackagePath(PackageInterface $package)
    {
        return $this->composer->getInstallationManager()->getInstallPath($package);
    }

    /**
     * Write a notice to IO
     *
     * @param string                                  $action
     * @param \Netresearch\Composer\Patches\Patch     $patch
     * @param \Composer\Package\PackageInterface      $package
     * @param \Netresearch\Composer\Patches\Exception $exception
     */
    protected function writePatchNotice($action, Patch $patch, PackageInterface $package, $exception = null)
    {
        $adverbMap = ['test' => 'on', 'apply' => 'to', 'revert' => 'from'];
        if ($action == 'test' && !$this->io->isVeryVerbose()) {
            return;
        }
        $msg = '  ' . ucfirst($action) . 'ing patch';
        if ($this->io->isVerbose() || !isset($patch->title)) {
            $msg .= ' <info>' . $patch->getChecksum() . '</info>';
        }
        $msg .= ' ' . $adverbMap[$action];
        $msg .= ' <info>' . $package->getName() . '</info>';
        if ($this->io->isVerbose()) {
            ' (<comment>' . $package->getPrettyVersion() . '</comment>)';
        }
        if (isset($patch->title)) {
            $msg .= ': <comment>' . $patch->title . '</comment>';
        }
        $this->io->write($msg);
        if ($exception) {
            $this->io->write(
                '  <warning>Could not ' . $action . ' patch</warning>' .
                ($action == 'revert' ? ' (was probably not applied)' : '')
            );
            if ($this->io->isVerbose()) {
                $this->io->write('<warning>' . $exception->getMessage() . '</warning>');
            }
        }
    }

    /**
     * Event handler to the postUpdateCmd/postInstallCmd events: Loop through all
     * installed packages and apply patches for them.
     *
     * @param \Composer\Script\Event $event
     *
     * @throws \Netresearch\Composer\Patches\Exception
     * @throws \Netresearch\Composer\Patches\PatchCommandException
     */
    public function apply(Event $event)
    {
        static $history = [];

        $this->io->write('<info>Maintaining patches</info>');

        foreach ($event->getComposer()->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $initialPackage) {
            foreach ($this->getPatches($initialPackage, $history) as $patchesAndPackage) {
                /* @var $patches Patch[] */
                list($patches, $package) = $patchesAndPackage;
                $packagePath = $this->getPackagePath($package);
                foreach ($patches as $patch) {
                    $this->writePatchNotice('test', $patch, $package);
                    try {
                        $patch->apply($packagePath, true);
                    } catch (PatchCommandException $applyException) {
                        try {
                            // If this won't fail, patch was already applied
                            $patch->revert($packagePath, true);
                        } catch (PatchCommandException $revertException) {
                            // Patch seems not to be applied and fails as well
                            $this->writePatchNotice('apply', $patch, $package, $applyException);
                        }
                        continue;
                    }
                    $this->writePatchNotice('apply', $patch, $package);
                    $patch->apply($packagePath);
                }
            }
        }
    }
}
