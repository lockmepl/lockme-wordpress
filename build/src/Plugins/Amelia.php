<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Plugins;

use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Infrastructure\Common\Container;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
class Amelia implements PluginInterface
{
    private ?Container $container = null;
    private array $options;
    private Plugin $plugin;
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_amelia') ?: [];
        if (($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('amelia_after_booking_added', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_added', [$this, 'AddEditAppointment']);
            add_action('amelia_after_booking_rescheduled', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_updated', [$this, 'AddEditAppointment']);
            add_action('amelia_after_appointment_status_updated', [$this, 'AddEditAppointment']);
        }
    }
    public function getPluginName() : string
    {
        return 'Amelia';
    }
    public function CheckDependencies() : bool
    {
        return is_plugin_active('ameliabooking/ameliabooking.php');
    }
    public function AddEditAppointment(?array $appointment) : void
    {
        if (!$appointment) {
            return;
        }
        if (($appointment['type'] ?? null) === 'appointment' && isset($appointment['appointment'])) {
            $this->AddEditAppointment($appointment['appointment']);
            return;
        }
        foreach ($appointment['bookings'] as $booking) {
            $this->AddEditBooking($appointment, $booking);
        }
    }
    public function AddEditBooking(array $appointment, array $booking) : void
    {
        if (!$booking) {
            return;
        }
        $appData = $this->AppData($appointment, $booking);
        if (!$appData) {
            return;
        }
        if (!\in_array($booking['status'], ['approved', 'pending'])) {
            $this->Delete($appointment, $booking);
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = [];
        try {
            $lockme_data = $api->Reservation((int) $appData['roomid'], "ext/{$appData['extid']}");
        } catch (Exception) {
        }
        try {
            if (!$lockme_data) {
                //Add new
                $api->AddReservation($appData);
            } else {
                //Update
                $api->EditReservation((int) $appData['roomid'], "ext/{$appData['extid']}", $appData);
            }
        } catch (Exception) {
        }
    }
    public function Delete(array $appointment, array $booking) : void
    {
        if (\defined('LOCKME_MESSAGING')) {
            return;
        }
        $appData = $this->AppData($appointment, $booking);
        if (!$appData['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        try {
            $api->DeleteReservation((int) $appData['roomid'], "ext/{$appData['extid']}");
        } catch (Exception) {
        }
    }
    public function RegisterSettings() : void
    {
        if (!$this->CheckDependencies()) {
            return;
        }
        register_setting('lockme-amelia', 'lockme_amelia');
        add_settings_section('lockme_amelia_section', 'Amelia plugin settings', static function () {
            echo '<p>Integration settings with the Amelia plugin</p>';
        }, 'lockme-amelia');
        add_settings_field('amelia_use', 'Enable integration', function () {
            echo '<input name="lockme_amelia[use]" type="checkbox" value="1"  ' . checked(1, $this->options['use'] ?? null, \false) . ' />';
        }, 'lockme-amelia', 'lockme_amelia_section');
        if (($this->options['use'] ?? null) && $this->plugin->tab === 'amelia_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException) {
                }
            }
            $serviceRepository = $this->container()->get('domain.bookable.service.repository');
            $services = $serviceRepository->getAllArrayIndexedById();
            foreach ($services->getItems() as $service) {
                \assert($service instanceof Service);
                add_settings_field('calendar_' . $service->getId()->getValue(), 'Room for ' . $service->getName()->getValue(), function () use($rooms, $service) {
                    echo '<select name="lockme_amelia[calendar_' . $service->getId()->getValue() . ']">';
                    echo '<option value="">--select--</option>';
                    foreach ($rooms as $room) {
                        echo '<option value="' . $room['roomid'] . '" ' . selected(1, $room['roomid'] == $this->options['calendar_' . $service->getId()->getValue()], \false) . '>' . $room['room'] . ' (' . $room['department'] . ')</options>';
                    }
                    echo '</select>';
                }, 'lockme-amelia', 'lockme_amelia_section');
            }
            add_settings_field('export_amelia', 'Send data to LockMe', static function () {
                echo '<a href="?page=lockme_integration&tab=amelia_plugin&amelia_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
            }, 'lockme-amelia', 'lockme_amelia_section');
        }
    }
    public function DrawForm() : void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }
        if ($_GET['amelia_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-amelia');
        do_settings_sections('lockme-amelia');
    }
    public function GetMessage(array $message) : bool
    {
        // TODO: Implement GetMessage() method.
    }
    private function AppData(array $appointment, array $booking) : array
    {
        $room = $this->options['calendar_' . $appointment['serviceId']] ?? null;
        if (!$room) {
            return [];
        }
        $dateTime = \explode(' ', $appointment['bookingStart']);
        return $this->plugin->AnonymizeData(['roomid' => $room, 'date' => $dateTime[0], 'hour' => $dateTime[1], 'people' => $booking['persons'], 'pricer' => 'API', 'price' => $booking['price'], 'name' => $booking['customer']['firstName'] ?? '', 'surname' => $booking['customer']['lastName'] ?? '', 'email' => $booking['customer']['email'] ?? '', 'phone' => $booking['customer']['phone'] ?? '', 'status' => $booking['status'] === 'approved' ? 1 : 0, 'extid' => $booking['id']]);
    }
    private function container() : Container
    {
        return $this->container ??= (require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php');
    }
}
