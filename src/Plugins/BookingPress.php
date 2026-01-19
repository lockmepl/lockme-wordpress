<?php

declare(strict_types=1);

namespace LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;

class BookingPress implements PluginInterface
{
    private array $options;
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_bookingpress') ?: [];

        if (($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('bookingpress_after_book_appointment', [$this, 'AddEditReservation'], 10, 1);
            add_action('bookingpress_after_update_appointment', [$this, 'AddEditReservation'], 10, 1);
            add_action('bookingpress_before_delete_appointment', [$this, 'DeleteReservation'], 10, 1);

            add_action('init', function () {
                if ($_GET['bookingpress_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=booking_press_plugin&bookingpress_exported=1');
                    exit;
                }
            }, PHP_INT_MAX);
        }
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
        global $wpdb;

        if (!$this->CheckDependencies()) {
            return;
        }

        register_setting('lockme-bookingpress', 'lockme_bookingpress');

        add_settings_section(
            'lockme_bookingpress_section',
            'BookingPress plugin settings',
            static function () {
                echo '<p>Integration settings with the BookingPress plugin</p>';
            },
            'lockme-bookingpress'
        );

        add_settings_field(
            'bookingpress_use',
            'Enable integration',
            function () {
                echo '<input name="lockme_bookingpress[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'] ?? null,
                        false).' />';
            },
            'lockme-bookingpress',
            'lockme_bookingpress_section',
            []
        );

        if (($this->options['use'] ?? null) && $this->plugin->tab === 'booking_press_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }

            $services = $wpdb->get_results("SELECT bookingpress_service_id, bookingpress_service_name FROM {$wpdb->prefix}bookingpress_services");

            foreach ($services as $service) {
                add_settings_field(
                    'service_'.$service->bookingpress_service_id,
                    'Room for '.$service->bookingpress_service_name,
                    function () use ($rooms, $service) {
                        echo '<select name="lockme_bookingpress[service_'.$service->bookingpress_service_id.']">';
                        echo '<option value="">--select--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['service_'.$service->bookingpress_service_id],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-bookingpress',
                    'lockme_bookingpress_section',
                    []
                );
            }

            add_settings_field(
                'export_bookingpress',
                'Send data to LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=booking_press&bookingpress_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
                },
                'lockme-bookingpress',
                'lockme_bookingpress_section',
                []
            );
        }
    }

    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }

        if ($_GET['bookingpress_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-bookingpress');
        do_settings_sections('lockme-bookingpress');
    }

    public function GetMessage(array $message): bool
    {
        global $wpdb;

        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $timestamp = strtotime($data['date'].' '.$data['hour']);
        $surname = $data['surname'];
        if ($data['source'] === 'web' || $data['source'] === 'widget') {
            $surname .= ' (LockMe)';
        }

        $email = !empty($data['email']) ? $data['email'] : 'lockme@example.com';

        $service_id = null;
        foreach ($this->options as $key => $val) {
            if (str_starts_with($key, 'service_') && (int) $val === (int) $roomid) {
                $service_id = (int)str_replace('service_', '', $key);
                break;
            }
        }

        if (!$service_id) {
            return false;
        }

        switch ($message['action']) {
            case 'add':
                // Handle customer
                $customer_id = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT bookingpress_customer_id FROM {$wpdb->prefix}bookingpress_customers WHERE bookingpress_customer_email = %s",
                    $email
                ));

                if (!$customer_id) {
                    $wpdb->insert("{$wpdb->prefix}bookingpress_customers", [
                        'bookingpress_customer_firstname' => $data['name'],
                        'bookingpress_customer_lastname' => $surname,
                        'bookingpress_customer_email' => $email,
                        'bookingpress_customer_phone' => $data['phone'] ?? '',
                        'bookingpress_customer_created_at' => current_time('mysql'),
                    ]);
                    $customer_id = $wpdb->insert_id;
                }

                // Handle appointment
                $wpdb->insert("{$wpdb->prefix}bookingpress_appointment_bookings", [
                    'bookingpress_customer_id' => $customer_id,
                    'bookingpress_service_id' => $service_id,
                    'bookingpress_appointment_date' => $data['date'],
                    'bookingpress_appointment_time' => $data['hour'],
                    'bookingpress_appointment_end_time' => date('H:i:s', $timestamp + 3600), // Default 1h
                    'bookingpress_appointment_status' => '1', // 1 usually means approved/confirmed
                    'bookingpress_customer_name' => $data['name'] . ' ' . $surname,
                    'bookingpress_customer_email' => $email,
                    'bookingpress_customer_phone' => $data['phone'] ?? '',
                    'bookingpress_appointment_timezone' => 'Europe/Warsaw',
                    'bookingpress_created_at' => current_time('mysql'),
                ]);

                $id = $wpdb->insert_id;
                if (!$id) {
                    return false;
                }

                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id,
                        $this->plugin->AnonymizeData(['extid' => $id])
                    );
                    return true;
                } catch (Exception $e) {
                }
                break;

            case 'edit':
                if ($data['extid']) {
                    // Find or create customer for current email
                    $customer_id = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT bookingpress_customer_id FROM {$wpdb->prefix}bookingpress_customers WHERE bookingpress_customer_email = %s",
                        $email
                    ));

                    if (!$customer_id) {
                        $wpdb->insert("{$wpdb->prefix}bookingpress_customers", [
                            'bookingpress_customer_firstname' => $data['name'],
                            'bookingpress_customer_lastname' => $surname,
                            'bookingpress_customer_email' => $email,
                            'bookingpress_customer_phone' => $data['phone'] ?? '',
                            'bookingpress_customer_created_at' => current_time('mysql'),
                        ]);
                        $customer_id = $wpdb->insert_id;
                    }

                    $wpdb->update("{$wpdb->prefix}bookingpress_appointment_bookings", [
                        'bookingpress_customer_id' => $customer_id,
                        'bookingpress_appointment_date' => $data['date'],
                        'bookingpress_appointment_time' => $data['hour'],
                        'bookingpress_appointment_end_time' => date('H:i:s', $timestamp + 3600),
                        'bookingpress_customer_name' => $data['name'] . ' ' . $surname,
                        'bookingpress_customer_email' => $email,
                        'bookingpress_customer_phone' => $data['phone'] ?? '',
                    ], ['bookingpress_appointment_booking_id' => $data['extid']]);
                    return true;
                }
                break;

            case 'delete':
                if ($data['extid']) {
                    $wpdb->delete("{$wpdb->prefix}bookingpress_appointment_bookings",
                        ['bookingpress_appointment_booking_id' => $data['extid']]
                    );
                    return true;
                }
                break;
        }

        return false;
    }

    public function AddEditReservation($appointment_id): void
    {
        if (defined('LOCKME_MESSAGING')) {
            return;
        }

        $appdata = $this->AppData($appointment_id);
        if (!$appdata['roomid']) {
            return;
        }

        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        } catch (Exception $e) {
        }

        try {
            if (!$lockme_data) {
                $api->AddReservation($appdata);
            } else {
                $api->EditReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}", $appdata);
            }
        } catch (Exception $e) {
        }
    }

    public function DeleteReservation($appointment_id): void
    {
        if (defined('LOCKME_MESSAGING')) {
            return;
        }

        $appdata = $this->AppData($appointment_id);
        if (!$appdata['roomid']) {
            return;
        }

        try {
            $api = $this->plugin->GetApi();
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        } catch (Exception $e) {
        }
    }

    private function AppData($id, $info = null): array
    {
        global $wpdb;

        if ($info === null) {
            $info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookingpress_appointment_bookings WHERE bookingpress_appointment_booking_id = %d", $id), ARRAY_A);
        }

        return $this->plugin->AnonymizeData([
            'roomid' => $this->options['service_'.$info['bookingpress_service_id']] ?? null,
            'date' => $info['bookingpress_appointment_date'],
            'hour' => $info['bookingpress_appointment_time'],
            'pricer' => 'API',
            'status' => 1,
            'extid' => $id,
            'email' => $info['bookingpress_customer_email'] ?? '',
            'name' => $info['bookingpress_customer_name'] ?? '',
            'phone' => $info['bookingpress_customer_phone'] ?? '',
        ]);
    }

    public function ExportToLockMe(): void
    {
        global $wpdb;
        $bookings = $wpdb->get_results("SELECT bookingpress_appointment_booking_id FROM {$wpdb->prefix}bookingpress_appointment_bookings WHERE bookingpress_appointment_date >= CURDATE()");

        foreach ($bookings as $b) {
            $this->AddEditReservation($b->bookingpress_appointment_booking_id, null);
        }
    }
}