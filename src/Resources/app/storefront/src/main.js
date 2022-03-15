import MonduPlugin from './mondu-checkout.plugin';

const PluginManager = window.PluginManager;

if (PluginManager.getPluginList().MonduPlugin === undefined) {
    PluginManager.register('MonduPlugin', MonduPlugin, '[data-mondu-checkout="true"]');
}
