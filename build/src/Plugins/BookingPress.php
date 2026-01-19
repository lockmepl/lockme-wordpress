<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
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
            add_action('bookingpress_after_change_appointment_status', [$this, 'AddEditReservation'], 10, 1);
            add_action('bookingpress_before_delete_appointment', [$this, 'DeleteReservation'], 10, 1);
            add_action('init', function () {
                if ($_GET['bookingpress_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=booking_press_plugin&bookingpress_exported=1');
                    exit;
                }
            }, \PHP_INT_MAX);
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
        add_settings_section('lockme_bookingpress_section', 'BookingPress plugin settings', static function () {
            echo '<p>Integration settings with the BookingPress plugin</p>';
        }, 'lockme-bookingpress');
        add_settings_field('bookingpress_use', 'Enable integration', function () {
            echo '<input name="lockme_bookingpress[use]" type="checkbox" value="1"  ' . checked(1, $this->options['use'] ?? null, \false) . ' />';
        }, 'lockme-bookingpress', 'lockme_bookingpress_section', []);
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
                add_settings_field('service_' . $service->bookingpress_service_id, 'Room for ' . $service->bookingpress_service_name, function () use ($rooms, $service) {
                    $options = $this->options;
                    echo '<select name="lockme_bookingpress[service_' . $service->bookingpress_service_id . ']">';
                    echo '<option value="">--select--</option>';
                    foreach ($rooms as $room) {
                        echo '<option value="' . $room['roomid'] . '" ' . selected(1, $room['roomid'] == ($options['service_' . $service->bookingpress_service_id] ?? null), \false) . '>' . $room['room'] . ' (' . $room['department'] . ')</option>';
                    }
                    echo '</select>';
                }, 'lockme-bookingpress', 'lockme_bookingpress_section', []);
            }
            add_settings_field('export_bookingpress', 'Send data to LockMe', static function () {
                echo '<a href="?page=lockme_integration&tab=booking_press&bookingpress_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
            }, 'lockme-bookingpress', 'lockme_bookingpress_section', []);
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
        global $wpdb, $BookingPress;
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return \false;
        }
        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $timestamp = strtotime($data['date'] . ' ' . $data['hour']);
        $surname = $data['surname'];
        if ($data['source'] === 'web' || $data['source'] === 'widget') {
            $surname .= ' (LockMe)';
        }
        $email = !empty($data['email']) ? $data['email'] : 'lockme@example.com';
        $service_data = $this->GetService($roomid);
        if (!$service_data) {
            return \false;
        }
        $service_id = (int) $service_data['bookingpress_service_id'];
        $service_duration_val = $service_data['bookingpress_service_duration_val'];
        $service_duration_unit = $service_data['bookingpress_service_duration_unit'];
        $duration_seconds = (int) $service_duration_val * 60;
        if ($service_duration_unit === 'h') {
            $duration_seconds *= 60;
        } elseif ($service_duration_unit === 'd') {
            $duration_seconds *= 60 * 24;
        }
        $currency = $BookingPress->bookingpress_get_settings('payment_default_currency', 'payment_setting');
        switch ($message['action']) {
            case 'add':
                $invoice_id = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM {$wpdb->prefix}bookingpress_settings WHERE setting_name = %s AND setting_type = %s", 'bookingpress_last_invoice_id', 'invoice_setting'));
                $invoice_id++;
                $BookingPress->bookingpress_update_settings('bookingpress_last_invoice_id', 'invoice_setting', $invoice_id);
                // Handle customer
                $customer_id = $this->GetOrCreateCustomer($email, $data, $surname);
                // Handle appointment
                $wpdb->insert("{$wpdb->prefix}bookingpress_appointment_bookings", [
                    'bookingpress_customer_id' => $customer_id,
                    'bookingpress_service_id' => $service_id,
                    'bookingpress_appointment_date' => $data['date'],
                    'bookingpress_appointment_time' => $data['hour'],
                    'bookingpress_appointment_end_time' => date('H:i:s', $timestamp + $duration_seconds),
                    'bookingpress_appointment_status' => '1',
                    // 1 usually means approved/confirmed
                    'bookingpress_customer_name' => $data['name'] . ' ' . $surname,
                    'bookingpress_customer_email' => $email,
                    'bookingpress_customer_phone' => $data['phone'] ?? '',
                    'bookingpress_appointment_timezone' => 'Europe/Warsaw',
                    'bookingpress_created_at' => current_time('mysql'),
                    'bookingpress_service_name' => $service_data['bookingpress_service_name'],
                    'bookingpress_service_price' => $data['price'],
                    'bookingpress_service_currency' => $currency,
                    'bookingpress_service_duration_val' => $service_duration_val,
                    'bookingpress_service_duration_unit' => $service_duration_unit,
                    'bookingpress_booking_id' => $invoice_id,
                ]);
                $id = $wpdb->insert_id;
                if (!$id) {
                    return \false;
                }
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, (string) $lockme_id, ['extid' => $id]);
                    return \true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    // Find or create customer for current email
                    $customer_id = $this->GetOrCreateCustomer($email, $data, $surname);
                    $wpdb->update("{$wpdb->prefix}bookingpress_appointment_bookings", ['bookingpress_customer_id' => $customer_id, 'bookingpress_appointment_date' => $data['date'], 'bookingpress_appointment_time' => $data['hour'], 'bookingpress_appointment_end_time' => date('H:i:s', $timestamp + $duration_seconds), 'bookingpress_customer_name' => $data['name'] . ' ' . $surname, 'bookingpress_customer_email' => $email, 'bookingpress_customer_phone' => $data['phone'] ?? '', 'bookingpress_service_name' => $service_data['bookingpress_service_name'], 'bookingpress_service_price' => $data['price'], 'bookingpress_service_currency' => $currency, 'bookingpress_service_duration_val' => $service_duration_val, 'bookingpress_service_duration_unit' => $service_duration_unit], ['bookingpress_appointment_booking_id' => $data['extid']]);
                    return \true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    $wpdb->delete("{$wpdb->prefix}bookingpress_appointment_bookings", ['bookingpress_appointment_booking_id' => $data['extid']]);
                    return \true;
                }
                break;
        }
        return \false;
    }
    private function GetOrCreateCustomer(string $email, array $data, string $surname): int
    {
        global $wpdb;
        $customer_id = (int) $wpdb->get_var($wpdb->prepare("SELECT bookingpress_customer_id FROM {$wpdb->prefix}bookingpress_customers WHERE bookingpress_user_email = %s", $email));
        if (!$customer_id) {
            $wpdb->insert("{$wpdb->prefix}bookingpress_customers", ['bookingpress_user_firstname' => $data['name'], 'bookingpress_user_lastname' => $surname, 'bookingpress_customer_full_name' => $data['name'] . ' ' . $surname, 'bookingpress_user_name' => $data['name'] . ' ' . $surname, 'bookingpress_user_email' => $email, 'bookingpress_user_login' => $email, 'bookingpress_user_phone' => $data['phone'] ?? '', 'bookingpress_created_at' => current_time('mysql')]);
            $customer_id = $wpdb->insert_id;
        }
        return $customer_id;
    }
    private function GetService($roomid): ?array
    {
        global $wpdb;
        $services = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bookingpress_services", ARRAY_A);
        foreach ($services as $service) {
            if (($this->options['service_' . $service['bookingpress_service_id']] ?? null) == $roomid) {
                return $service;
            }
        }
        return null;
    }
    public function AddEditReservation($appointment_id, $status = null): void
    {
        if (defined('LOCKME_MESSAGING')) {
            return;
        }
        global $wpdb;
        $info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bookingpress_appointment_bookings WHERE bookingpress_appointment_booking_id = %d", $appointment_id), ARRAY_A);
        if (!$info) {
            return;
        }
        $appdata = $this->AppData($appointment_id, $info);
        if (!$appdata['roomid']) {
            return;
        }
        // Handle status-based synchronization
        // 1: Approved, 2: Pending, 3: Cancelled, 4: Rejected
        $current_status = $info['bookingpress_appointment_status'] ?? '1';
        if (in_array($current_status, ['3', '4'])) {
            $this->DeleteReservation($appointment_id);
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
        $service_id = $info['bookingpress_service_id'];
        return $this->plugin->AnonymizeData(['roomid' => $this->options['service_' . $service_id] ?? null, 'date' => $info['bookingpress_appointment_date'], 'hour' => $info['bookingpress_appointment_time'], 'duration' => $this->CalculateDuration($info), 'pricer' => 'API', 'status' => 1, 'extid' => $id, 'email' => $info['bookingpress_customer_email'] ?? '', 'name' => $info['bookingpress_customer_name'] ?? '', 'phone' => $info['bookingpress_customer_phone'] ?? '']);
    }
    private function CalculateDuration(array $info): int
    {
        if (empty($info['bookingpress_appointment_time']) || empty($info['bookingpress_appointment_end_time'])) {
            return 60;
        }
        $start = strtotime($info['bookingpress_appointment_time']);
        $end = strtotime($info['bookingpress_appointment_end_time']);
        if ($end < $start) {
            // Handle overnight if any
            $end += 86400;
        }
        return (int) ceil(($end - $start) / 60);
    }
    public function ExportToLockMe(): void
    {
        global $wpdb;
        $bookings = $wpdb->get_results("SELECT bookingpress_appointment_booking_id FROM {$wpdb->prefix}bookingpress_appointment_bookings WHERE bookingpress_appointment_date >= CURDATE()");
        foreach ($bookings as $b) {
            $this->AddEditReservation($b->bookingpress_appointment_booking_id);
        }
    }
}
