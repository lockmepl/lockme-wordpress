<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Plugins;

use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
class Amelia implements PluginInterface
{
    public function __construct(Plugin $plugin)
    {
    }
    public function getPluginName() : string
    {
        return 'Amelia';
    }
    public function CheckDependencies() : bool
    {
        return is_plugin_active('ameliabooking/ameliabooking.php');
    }
    public function RegisterSettings() : void
    {
        if (!$this->CheckDependencies()) {
            return;
        }
        register_setting('lockme-amelia', 'lockme_amelia');
    }
    public function DrawForm() : void
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
    }
    public function GetMessage(array $message) : bool
    {
        // TODO: Implement GetMessage() method.
    }
}
