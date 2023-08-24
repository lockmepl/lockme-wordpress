<?php

namespace LockmeDep\LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeDep\LockmeIntegration\Plugin;
use LockmeDep\LockmeIntegration\PluginInterface;
use RuntimeException;
use LockmeDep\WP_Query;
class Booked implements PluginInterface
{
    private $options;
    private $plugin;
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_booked');
        if (\is_array($this->options) && ($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('booked_new_appointment_created', [$this, 'AddEditReservation'], 5);
            add_action('transition_post_status', [$this, 'AddEditReservation'], 10, 3);
            add_action('before_delete_post', [$this, 'Delete']);
            add_action('edit_post', [$this, 'AddEditReservation']);
            add_action('updated_post_meta', function ($meta_id, $post_id) {
                $this->AddEditReservation($post_id);
            }, 10, 2);
            add_action('init', function () {
                if ($_GET['booked_export'] ?? null) {
                    $this->ExportToLockMe();
                    $_SESSION['booked_export'] = 1;
                    wp_redirect('?page=lockme_integration&tab=booked_plugin');
                    exit;
                }
            });
        }
    }
    public function AddEditReservation($id)
    {
        if (!\is_numeric($id)) {
            return null;
        }
        if (\defined('LOCKME_MESSAGING')) {
            return null;
        }
        $type = get_post_type($id);
        if ($type && get_post_type($id) !== 'booked_appointments') {
            return null;
        }
        $post = get_post($id);
        $appdata = $this->AppData($post);
        if (!$appdata['roomid']) {
            return;
        }
        if (!$post || get_post_status($id) === 'trash') {
            $this->Delete($id);
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = [];
        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }
        try {
            if (!$lockme_data) {
                //Add new
                $api->AddReservation($appdata);
            } else {
                //Update
                $api->EditReservation((int) $appdata['roomid'], "ext/{$id}", $appdata);
            }
        } catch (Exception $e) {
        }
        return null;
    }
    public function ExportToLockMe() : void
    {
        $args = ['post_type' => 'booked_appointments', 'orderby' => 'meta_value', 'meta_key' => '_appointment_timestamp', 'meta_query' => [['key' => '_appointment_timestamp', 'value' => \strtotime('today'), 'compare' => '>=']], 'posts_per_page' => -1];
        $loop = new WP_Query($args);
        while ($loop->have_posts()) {
            $loop->the_post();
            $post = $loop->post;
            if (get_post_meta($post->ID, '_appointment_timestamp', \true) >= \strtotime('today')) {
                $this->AddEditReservation($post->ID);
            }
        }
    }
    public function DrawForm() : void
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
        // $api = $lockme->GetApi();
        // $lockme_data = $api->Reservation(1365, "ext/10");
        // var_dump($lockme_data);
        //     $appt_id = 1907;
        //     $timeslot = get_post_meta($appt_id);
        //
        //     var_dump($timeslot);
        if ($_SESSION['booked_export'] ?? null) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['booked_export']);
        }
        settings_fields('lockme-booked');
        do_settings_sections('lockme-booked');
    }
    public function Delete($id) : void
    {
        if (\defined('LOCKME_MESSAGING')) {
            return;
        }
        $type = get_post_type($id);
        if ($type && get_post_type($id) !== 'booked_appointments') {
            return;
        }
        $post = get_post($id);
        $appdata = $this->AppData($post);
        if (!$appdata['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        try {
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }
    }
    public function GetMessage(array $message) : bool
    {
        global $wpdb;
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return \false;
        }
        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $date = \strtotime($data['date']);
        $calendar_id = $this->GetCalendar($roomid);
        $hour = $this->GetSlot($calendar_id, $date, $data['hour']);
        if (!$hour) {
            throw new RuntimeException('No time slot');
        }
        $time_format = get_option('time_format');
        $date_format = get_option('date_format');
        $cf_data = ['Żródło' => \sprintf('LockMe (%s)', $data['source']), 'Telefon' => $data['phone'], 'Ilość osób' => $data['people'], 'Cena' => $data['price'], 'Dodatkowe uwagi' => $data['comment']];
        switch ($data['source']) {
            case 'web':
                $cf_data['Status'] = $data['status'] ? 'Opłacone' : 'Rezerwacja (max. 20 minut)';
                break;
            case 'panel':
                $cf_data['Status'] = 'Rezerwacja z panelu Lockme - status sprawdź w panelu Lockme';
                break;
            case 'api':
                $cf_data['Status'] = 'Rezerwacja z API';
                break;
            case 'widget':
                $cf_data['Status'] = 'Rezerwacja z widgeta';
                break;
        }
        if (isset($data['invoice']) && !empty($data['invoice'])) {
            $cf_data['Faktura'] = $data['invoice'];
        }
        $cf_meta_value = '';
        foreach ($cf_data as $label => $value) {
            $cf_meta_value .= '<p class="cf-meta-value"><strong>' . $label . '</strong><br>' . $value . '</p>';
        }
        switch ($message['action']) {
            case 'add':
                $post = apply_filters('booked_new_appointment_args', [
                    'post_title' => date_i18n($date_format, $date) . ' @ ' . date_i18n($time_format, $date) . ' (User: Guest)',
                    'post_content' => '',
                    'post_status' => $data['status'] ? 'publish' : 'draft',
                    // 'post_date' => date('Y', strtotime($date)).'-'.date('m', strtotime($date)).'-01 00:00:00',
                    'post_type' => 'booked_appointments',
                ]);
                $row_id = wp_insert_post($post, \true);
                if (!$row_id) {
                    throw new RuntimeException('Error saving to database: ' . $wpdb->last_error);
                }
                if (is_wp_error($row_id)) {
                    throw new RuntimeException($row_id->get_error_message());
                }
                update_post_meta($row_id, '_appointment_guest_name', $data['name'] . ' ' . $data['surname']);
                update_post_meta($row_id, '_appointment_guest_email', $data['email']);
                update_post_meta($row_id, '_appointment_timestamp', $date);
                update_post_meta($row_id, '_appointment_timeslot', $hour);
                update_post_meta($row_id, '_appointment_source', 'LockMe');
                update_post_meta($row_id, '_cf_meta_value', $cf_meta_value);
                if (isset($calendar_id) && $calendar_id) {
                    wp_set_object_terms($row_id, $calendar_id, 'booked_custom_calendars');
                }
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ['extid' => $row_id]);
                    return \true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $row_id = $data['extid'];
                    $post = apply_filters('booked_new_appointment_args', [
                        'ID' => $row_id,
                        'post_title' => date_i18n($date_format, $date) . ' @ ' . date_i18n($time_format, $date) . ' (User: Guest)',
                        'post_content' => '',
                        'post_status' => $data['status'] ? 'publish' : 'draft',
                        // 'post_date' => date('Y', strtotime($date)).'-'.date('m', strtotime($date)).'-01 00:00:00',
                        'post_type' => 'booked_appointments',
                    ]);
                    wp_update_post($post, \true);
                    update_post_meta($row_id, '_appointment_guest_name', $data['name'] . ' ' . $data['surname']);
                    update_post_meta($row_id, '_appointment_guest_email', $data['email']);
                    update_post_meta($row_id, '_appointment_timestamp', $date);
                    update_post_meta($row_id, '_appointment_timeslot', $hour);
                    if (get_post_meta($row_id, '_appointment_source', \true) === 'LockMe') {
                        update_post_meta($row_id, '_cf_meta_value', $cf_meta_value);
                    }
                    return \true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    wp_delete_post($data['extid']);
                    return \true;
                }
                break;
        }
        return \false;
    }
    private function AppData($res) : array
    {
        $cal = wp_get_object_terms($res->ID, 'booked_custom_calendars');
        $timeslot = \explode('-', get_post_meta($res->ID, '_appointment_timeslot', \true));
        $time = \str_split($timeslot[0], 2);
        $name = '';
        $email = '';
        $phone = '';
        if ($res->post_author) {
            $user_info = get_userdata($res->post_author);
            $name = booked_get_name($res->post_author);
            $email = $user_info->user_email;
            $phone = get_user_meta($res->post_author, 'booked_phone', \true);
        }
        $name = get_post_meta($res->ID, '_appointment_guest_name', \true) ?: $name;
        $email = get_post_meta($res->ID, '_appointment_guest_email', \true) ?: $email;
        return $this->plugin->AnonymizeData(['roomid' => $this->options['calendar_' . ($cal[0]->term_id ?? 'default')], 'date' => \date('Y-m-d', get_post_meta($res->ID, '_appointment_timestamp', \true)), 'hour' => \date('H:i:s', \strtotime("{$time[0]}:{$time[1]}:00")), 'name' => $name, 'pricer' => 'API', 'email' => $email, 'phone' => $phone, 'status' => \in_array($res->post_status, ['publish', 'future']) ? 1 : 0, 'extid' => $res->ID]);
    }
    private function GetCalendar($roomid)
    {
        $calendars = get_terms('booked_custom_calendars', 'orderby=slug&hide_empty=0');
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_' . $calendar->term_id] == $roomid) {
                return $calendar->term_id;
            }
        }
        return null;
    }
    private function GetSlot($calendar_id, $date, $hour)
    {
        $booked_defaults = get_option('booked_defaults_' . $calendar_id);
        if (!$booked_defaults) {
            $booked_defaults = get_option('booked_defaults');
        }
        $day_name = \date('D', $date);
        $formatted_date = date_i18n('Ymd', $date);
        if (\function_exists('LockmeDep\\booked_apply_custom_timeslots_details_filter')) {
            $booked_defaults = booked_apply_custom_timeslots_details_filter($booked_defaults, $calendar_id);
        } elseif (\function_exists('LockmeDep\\booked_apply_custom_timeslots_filter')) {
            $booked_defaults = booked_apply_custom_timeslots_filter($booked_defaults, $calendar_id);
        }
        if (isset($booked_defaults[$formatted_date]) && !empty($booked_defaults[$formatted_date])) {
            $todays_defaults = \is_array($booked_defaults[$formatted_date]) ? $booked_defaults[$formatted_date] : \json_decode($booked_defaults[$formatted_date], \true);
        } elseif (isset($booked_defaults[$formatted_date]) && empty($booked_defaults[$formatted_date])) {
            $todays_defaults = \false;
        } elseif (isset($booked_defaults[$day_name]) && !empty($booked_defaults[$day_name])) {
            $todays_defaults = $booked_defaults[$day_name];
        } else {
            $todays_defaults = \false;
        }
        $hour = \date('Hi', \strtotime($hour));
        foreach ($todays_defaults as $h => $cnt) {
            if (\preg_match("/^{$hour}/", $h)) {
                return $h;
            }
        }
        return null;
    }
    public function getPluginName() : string
    {
        return 'Booked';
    }
    public function RegisterSettings() : void
    {
        if (!$this->CheckDependencies()) {
            return;
        }
        register_setting('lockme-booked', 'lockme_booked');
        add_settings_section('lockme_booked_section', 'Ustawienia wtyczki Booked', static function () {
            echo '<p>Ustawienia integracji z wtyczką Booked</p>';
        }, 'lockme-booked');
        add_settings_field('booked_use', 'Włącz integrację', function () {
            echo '<input name="lockme_booked[use]" type="checkbox" value="1"  ' . checked(1, $this->options['use'] ?? null, \false) . ' />';
        }, 'lockme-booked', 'lockme_booked_section', []);
        if (($this->options['use'] ?? null) && $this->plugin->tab === 'booked_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }
            add_settings_field('calendar_default', 'Pokój dla domyślnego kalendarza', function () use($rooms) {
                echo '<select name="lockme_booked[calendar_default]">';
                echo '<option value="">--wybierz--</option>';
                foreach ($rooms as $room) {
                    echo '<option value="' . $room['roomid'] . '" ' . selected(1, $room['roomid'] == $this->options['calendar_default'], \false) . '>' . $room['room'] . ' (' . $room['department'] . ')</options>';
                }
                echo '</select>';
            }, 'lockme-booked', 'lockme_booked_section', []);
            $calendars = get_terms('booked_custom_calendars', 'orderby=slug&hide_empty=0');
            foreach ($calendars as $calendar) {
                add_settings_field('calendar_' . $calendar->term_id, 'Pokój dla ' . $calendar->name, function () use($rooms, $calendar) {
                    echo '<select name="lockme_booked[calendar_' . $calendar->term_id . ']">';
                    echo '<option value="">--wybierz--</option>';
                    foreach ($rooms as $room) {
                        echo '<option value="' . $room['roomid'] . '" ' . selected(1, $room['roomid'] == $this->options['calendar_' . $calendar->term_id], \false) . '>' . $room['room'] . ' (' . $room['department'] . ')</options>';
                    }
                    echo '</select>';
                }, 'lockme-booked', 'lockme_booked_section', []);
            }
            add_settings_field('export_booked', 'Wyślij dane do LockMe', static function () {
                echo '<a href="?page=lockme_integration&tab=booked_plugin&booked_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
            }, 'lockme-booked', 'lockme_booked_section', []);
        }
    }
    public function CheckDependencies() : bool
    {
        return is_plugin_active('booked/booked.php') || is_plugin_active('bookedall/booked.php');
    }
}
