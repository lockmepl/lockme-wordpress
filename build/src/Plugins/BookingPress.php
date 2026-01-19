<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Plugins;

use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
class BookingPress implements PluginInterface
{
    public function __construct(Plugin $plugin)
    {
    }
    public function getPluginName(): string
    {
        return 'BookingPress';
    }
    public function CheckDependencies(): bool
    {
        return is_plugin_active('bookingpress-appointment-booking/bookingpress-appointment-booking.php');
    }
    public function RegisterSettings(): void
    {
        // TODO: Implement RegisterSettings() method.
    }
    public function DrawForm(): void
    {
        // TODO: Implement DrawForm() method.
    }
    public function GetMessage(array $message): bool
    {
        // TODO: Implement GetMessage() method.
    }
}
