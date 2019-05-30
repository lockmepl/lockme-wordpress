<?php
namespace LockmeIntegration;

use Exception;

interface PluginInterface
{

    /**
     * PluginInterface constructor.
     * @param Plugin $plugin
     */
    public function __construct(Plugin $plugin);

    /**
     * @return string
     */
    public function getPluginName();

    /**
     * @return bool
     */
    public function CheckDependencies();

    /**
     * @return void
     */
    public function RegisterSettings();

    /**
     * @return void
     */
    public function DrawForm();

    /**
     * @param array $message
     * @return bool
     * @throws Exception
     */
    public function GetMessage(array $message);
}