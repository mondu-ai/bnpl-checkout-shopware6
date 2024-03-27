<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Monolog\Logger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

abstract class AbstractBootstrap implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var Context
     */
    protected Context $context;

    /**
     * @var InstallContext
     */
    protected InstallContext $installContext;

    /**
     * @var UpdateContext
     */
    protected UpdateContext $updateContext;

    /**
     * @var Logger
     */
    protected Logger $logger;

    /**
     * @var PluginEntity
     */
    protected PluginEntity $plugin;

    final public function __construct()
    {
    }

    abstract public function install(): void;

    abstract public function update(): void;

    abstract public function uninstall(bool $keepUserData = false): void;

    abstract public function activate(): void;

    abstract public function deactivate(): void;

    public function injectServices(): void
    {
    }

    final public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    final public function setInstallContext(InstallContext $installContext): void
    {
        $this->installContext = $installContext;
    }

    final public function setUpdateContext(UpdateContext $updateContext): void
    {
        $this->updateContext = $updateContext;
    }

    final public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    final public function setPlugin(PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
    }

    public function preInstall(): void
    {
    }

    public function preUpdate(): void
    {
    }

    public function preUninstall(bool $keepUserData = false): void
    {
    }

    public function preActivate(): void
    {
    }

    public function preDeactivate(): void
    {
    }

    public function postActivate(): void
    {
    }

    public function postDeactivate(): void
    {
    }

    public function postUninstall(): void
    {
    }

    public function postUpdate(): void
    {
    }

    public function postInstall(): void
    {
    }

    final protected function getPluginPath(): string
    {
        return $this->container->getParameter('kernel.root_dir') . DIRECTORY_SEPARATOR . $this->plugin->getPath();
    }
}
