<?php

namespace LockmeDep\LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
use RuntimeException;
defined('ABSPATH') or die('No script kiddies please!');
class Ezscm implements PluginInterface
{
    private array $options;
    private array $tables;
    private Plugin $plugin;
    private array $resdata = [];
    public function __construct(Plugin $plugin)
    {
        global $wpdb;
        $this->plugin = $plugin;
        $this->options = get_option('lockme_ezscm') ?: [];
        $this->tables = array('entries' => "{$wpdb->prefix}ezscm_entries", 'schedules' => "{$wpdb->prefix}ezscm_schedules", 'settings' => "{$wpdb->prefix}ezscm_settings", 'settings_schedule' => "{$wpdb->prefix}ezscm_settings_schedule");
        if (($this->options['use'] ?? null) && $this->CheckDependencies()) {
            if ($_POST['action'] === 'ezscm_frontend' || $_POST['action'] === 'ezscm_backend') {
                parse_str($_REQUEST['data'], $data);
                $action = $data['action'];
                $id = $data['id'];
                if ($action === 'entry_delete') {
                    $this->resdata = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['entries']} WHERE e_id = %d", $id), ARRAY_A);
                }
            }
            register_shutdown_function([$this, 'ShutDown']);
            add_action('init', function () {
                if ($_GET['ezscm_export'] ?? null) {
                    $this->ExportToLockMe();
                    wp_redirect('?page=lockme_integration&tab=ezscm_plugin&ezscm_exported=1');
                    exit;
                }
            }, \PHP_INT_MAX);
        }
    }
    public function CheckDependencies(): bool
    {
        return is_plugin_active('ez-schedule-manager/ezscm.php');
    }
    public function RegisterSettings(): void
    {
        global $wpdb;
        if (!$this->CheckDependencies()) {
            return;
        }
        register_setting('lockme-ezscm', 'lockme_ezscm', ['sanitize_callback' => [$this->plugin, 'sanitizePluginOptions']]);
        add_settings_section('lockme_ezscm_section', 'ez Schedule Manager plugin settings', static function () {
            echo '<p>Integration settings with the ez Schedule Manager plugin</p>';
        }, 'lockme-ezscm');
        $options = $this->options;
        add_settings_field('ezscm_use', 'Enable integration', static function () use ($options) {
            echo '<input name="lockme_ezscm[use]" type="checkbox" value="1"  ' . checked(1, $options['use'] ?? null, \false) . ' />';
        }, 'lockme-ezscm', 'lockme_ezscm_section', array());
        if (($this->options['use'] ?? null) && $this->plugin->tab === 'ezscm_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }
            $calendars = $wpdb->get_results($wpdb->prepare('SELECT sc.s_id, sc.name FROM %i AS sc', $this->tables['schedules']));
            foreach ($calendars as $calendar) {
                $optionKey = 'calendar_' . (int) $calendar->s_id;
                add_settings_field($optionKey, 'Room for ' . esc_html((string) $calendar->name), static function () use ($options, $rooms, $optionKey) {
                    echo '<select name="lockme_ezscm[' . esc_attr($optionKey) . ']">';
                    echo '<option value="">--select--</option>';
                    foreach ($rooms as $room) {
                        /** @noinspection TypeUnsafeComparisonInspection */
                        echo '<option value="' . esc_attr((string) $room['roomid']) . '" ' . selected(1, ($room['roomid'] ?? null) == ($options[$optionKey] ?? null), \false) . '>' . esc_html((string) ($room['room'] ?? '')) . ' (' . esc_html((string) ($room['department'] ?? '')) . ')</option>';
                    }
                    echo '</select>';
                }, 'lockme-ezscm', 'lockme_ezscm_section', array());
            }
            add_settings_field('export_ezscm', 'Send data to LockMe', static function () {
                echo '<a href="?page=lockme_integration&tab=ezscm_plugin&ezscm_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
            }, 'lockme-ezscm', 'lockme_ezscm_section', array());
        }
    }
    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }
        if ($_GET['ezscm_exported'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields('lockme-ezscm');
        do_settings_sections('lockme-ezscm');
    }
    private function AppData($res): array
    {
        $details = json_decode($res['data'], \true);
        return $this->plugin->AnonymizeData(['roomid' => $this->options['calendar_' . $res['s_id']] ?: $this->options['calendar_' . $res['details-s_id']], 'date' => gmdate('Y-m-d', strtotime($res['date'])), 'hour' => gmdate('H:i:s', strtotime($res['time_begin'])), 'people' => 0, 'pricer' => 'API', 'price' => 0, 'email' => $details['Adres e-mail:'], 'status' => 1, 'extid' => $res['e_id']]);
    }
    private function Add($res): void
    {
        $api = $this->plugin->GetApi();
        try {
            $api->AddReservation($this->AppData($res));
        } catch (Exception $e) {
        }
    }
    private function Update($id, $res): void
    {
        $data = $this->AppData($res);
        if (!$data['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = null;
        try {
            $lockme_data = $api->Reservation((int) $data['roomid'], "ext/{$data['extid']}");
        } catch (Exception $e) {
        }
        if (!$lockme_data) {
            $this->Add($res);
            return;
        }
        try {
            $api->EditReservation((int) $data['roomid'], "ext/{$data['extid']}", $data);
        } catch (Exception $e) {
        }
    }
    private function Delete($id, $res): void
    {
        $data = $this->AppData($res);
        if (!$data['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = null;
        try {
            $lockme_data = $api->Reservation((int) $data['roomid'], "ext/{$data['extid']}");
        } catch (Exception $e) {
        }
        if (!$lockme_data) {
            return;
        }
        try {
            $api->DeleteReservation((int) $data['roomid'], "ext/{$data['extid']}");
        } catch (Exception $e) {
        }
    }
    public function AddReservation($save_data): void
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['entries']} WHERE time_begin=%s AND date=%s AND s_id=%d", $save_data['time_internal'], $save_data['date_internal'], $save_data['s_id']), ARRAY_A);
        if (!$existing) {
            return;
        }
        $this->Add($existing);
    }
    public function AddEditReservation($save_data): void
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['entries']} WHERE time_begin=%s AND date=%s AND s_id=%d", $save_data['details-time_internal'], $save_data['details-date_internal'], $save_data['details-s_id']), ARRAY_A);
        if (!$existing) {
            return;
        }
        $this->Update($existing['e_id'], $existing);
    }
    public function ShutDown(): void
    {
        if ($_POST['action'] === 'ezscm_frontend' || $_POST['action'] === 'ezscm_backend') {
            parse_str($_REQUEST['data'], $data);
            $action = $data['action'];
            $id = $data['id'];
            switch ($action) {
                case 'submit':
                    $this->AddReservation($data['data']);
                    break;
                case 'entry_delete':
                    $this->Delete($id, $this->resdata);
                    break;
                case 'save_entry':
                    $this->AddEditReservation($data);
                    break;
            }
        }
    }
    private function GetCalendar($roomid)
    {
        global $wpdb;
        $calendars = $wpdb->get_results($wpdb->prepare('SELECT sc.s_id, sc.name FROM %i AS sc', $this->tables['schedules']));
        foreach ($calendars as $calendar) {
            /** @noinspection TypeUnsafeComparisonInspection */
            if ($this->options['calendar_' . $calendar->s_id] == $roomid) {
                return $calendar->s_id;
            }
        }
        throw new RuntimeException('No calendar');
    }
    public function GetMessage(array $message): bool
    {
        global $wpdb;
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return \false;
        }
        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $hour = gmdate('H:i', strtotime($data['hour']));
        $calendar_id = $this->GetCalendar($roomid);
        $form = array('Imię:' => $data['name'], 'Nazwisko:' => $data['surname'], 'Adres e-mail:' => $data['email'], 'Telefon:' => $data['phone'], 'Voucher:' => '', 'Dodatkowe uwagi:' => 'Lockme! ' . $data['comment']);
        $sql_data = json_encode($form);
        switch ($message['action']) {
            case 'add':
                $wpdb->insert($this->tables['entries'], array('s_id' => $calendar_id, 'date' => $data['date'], 'private' => 0, 'time_begin' => $hour, 'data' => $sql_data, 'ip' => $_SERVER['REMOTE_ADDR']), array('%d', '%s', '%d', '%s', '%s', '%s'));
                $id = $wpdb->insert_id;
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, (string) $lockme_id, array('extid' => $id));
                    return \true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $wpdb->update($this->tables['entries'], array('s_id' => $calendar_id, 'date' => $data['date'], 'private' => 0, 'time_begin' => $hour, 'data' => $sql_data, 'ip' => $_SERVER['REMOTE_ADDR']), array('e_id' => $data['extid']), array('%d', '%s', '%d', '%s', '%s', '%s'));
                    return \true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    $wpdb->delete($this->tables['entries'], array('e_id' => $data['extid']), array('%d'));
                    return \true;
                }
                break;
        }
        return \false;
    }
    public function ExportToLockMe(): void
    {
        global $wpdb;
        set_time_limit(0);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE date>=curdate() ORDER BY e_id', $this->tables['entries']), ARRAY_A);
        foreach ($rows as $row) {
            $this->Update($row['e_id'], $row);
        }
    }
    public function getPluginName(): string
    {
        return 'ez Schedule Manager';
    }
}
