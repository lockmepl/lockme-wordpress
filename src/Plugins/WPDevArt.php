<?php
/** @noinspection SqlDialectInspection */

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use ReflectionObject;
use wpdevart_bc_BookingCalendar;
use wpdevart_bc_ControllerReservations;
use wpdevart_bc_ModelCalendars;
use wpdevart_bc_ModelExtras;
use wpdevart_bc_ModelForms;
use wpdevart_bc_ModelThemes;

class WPDevArt implements PluginInterface
{
    private $options;
    private $resdata;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        global $wpdb;

        $this->plugin = $plugin;
        $this->options = get_option("lockme_wpdevart");

        if ($this->options['use']) {
            if ($_GET['page'] == "wpdevart-reservations" && is_admin() && $_POST['task']) {
                if ($_POST['id']) {
                    $this->resdata = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'wpdevart_reservations WHERE `id`=%d',
                        $_POST['id']), ARRAY_A);
                }
            }
            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function () {
                if ($_GET['wpdevart_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['wpdevart_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=wpdevart_plugin");
                    exit;
                }
            });
        }
    }

    public function ExportToLockMe()
    {
        global $wpdb;
        set_time_limit(0);

        $sql = "SELECT * FROM {$wpdb->prefix}wpdevart_reservations WHERE `single_day` >= curdate() ORDER BY ID";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as $row) {
            $this->AddEditReservation($row);
        }
    }

    public function AddEditReservation($res)
    {
        if (!is_array($res)) {
            return;
        }
        if (defined("LOCKME_MESSAGING")) {
            return;
        }

        $api = $this->plugin->GetApi();
        $id = $res['id'];
        $resdata = $this->AppData($res);
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($resdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        try {
            if (!$lockme_data) { //Add new
                $api->AddReservation($resdata);
            } else { //Update
                $api->EditReservation($resdata["roomid"], "ext/{$id}", $resdata);
            }
        } catch (Exception $e) {
        }
    }

    private function AppData($res)
    {

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['calendar_id']],
                    'date' => date('Y-m-d', strtotime($res['single_day'])),
                    'hour' => date("H:i:s", strtotime($res['start_hour'])),
                    'pricer' => "API",
                    'email' => $res['email'],
                    'status' => $res["status"] == "approved" ? 1 : 0,
                    'extid' => $res['id'],
                    'price' => $res["price"]
                ]
            );
    }

    public function RegisterSettings()
    {
        global $wpdb;
        if (!$this->CheckDependencies()) {
            return false;
        }

        register_setting('lockme-wpdevart', 'lockme_wpdevart');

        add_settings_section(
            'lockme_wpdevart_section',
            "Ustawienia wtyczki Booking Calendar Pro WpDevArt",
            function () {
                echo '<p>Ustawienia integracji z wtyczką Booking Calendar Pro WpDevArt</p>';
            },
            'lockme-wpdevart');

        add_settings_field(
            "wpdevart_use",
            "Włącz integrację",
            function () {
                echo '<input name="lockme_wpdevart[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-wpdevart',
            'lockme_wpdevart_section',
            []);

        if ($this->options['use'] && $this->plugin->tab == 'wpdevart_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }

            $query = "SELECT * FROM ".$wpdb->prefix."wpdevart_calendars ";
            $calendars = $wpdb->get_results($query);
            foreach ($calendars as $calendar) {
                add_settings_field(
                    "calendar_".$calendar->id,
                    "Pokój dla ".$calendar->title,
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_wpdevart[calendar_'.$calendar->id.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar->id],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-wpdevart',
                    'lockme_wpdevart_section',
                    []
                );
            }
            add_settings_field(
                "export_wpdevart",
                "Wyślij dane do LockMe",
                function () {
                    echo '<a href="?page=lockme_integration&tab=wpdevart_plugin&wpdevart_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-wpdevart',
                'lockme_wpdevart_section',
                []
            );
        }
        return true;
    }

    public function CheckDependencies()
    {
        return
            is_plugin_active("booking-calendar-pro/booking_calendar.php") ||
            is_plugin_active("booking-calendar/booking_calendar.php");
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }

        if ($_SESSION['wpdevart_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['wpdevart_export']);
        }
        settings_fields('lockme-wpdevart');
        do_settings_sections('lockme-wpdevart');
    }

    public function ShutDown()
    {
        if ($_GET['page'] == "wpdevart-reservations" && is_admin() && $_POST['task']) {
            switch ($_POST['task']) {
                case "approve":
                    $this->AddEditReservation($this->resdata);
                    break;
                case "canceled":
                case "delete":
                    $this->Delete($this->resdata);
                    break;
            }
        }
//        if (defined('DOING_AJAX') && DOING_AJAX) {
//            switch ($_POST['action']) {
//                 case 'wpdevart_form_ajax':
//                   $data = json_decode(stripcslashes($_POST['wpdevart_data']),true);
//                   $res = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE single_day="%s" and start_hour="%s" and calendar_id=%d', $data['wpdevart_single_day1'], $data['wpdevart_form_hour1'], $_POST['wpdevart_id']),ARRAY_A);
//                   $this->AddEditReservation($res);
//                   break;
//            }
//        }
    }

    public function Delete($res)
    {
        if (defined("LOCKME_MESSAGING")) {
            return;
        }

        $api = $this->plugin->GetApi();
        $id = $res['id'];
        $resdata = $this->AppData($res);

        try {
            $api->DeleteReservation($resdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }
    }

    public function GetMessage(array $message)
    {
        global $wpdb;
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];
        $date = $data['date'];
        $hour = date("H:i", strtotime($data['hour']));

        $calendar_id = $this->GetCalendar($roomid);
        if (!$calendar_id) {
            throw new Exception("No calendar");
        }

        $cf_meta_value = '';
        foreach ([
                     "Żródło" => "LockMe",
                     "Telefon" => $data['phone'],
                     "Ilość osób" => $data['people'],
                     "Cena" => $data['price'],
                     "Status" => $data['status'] ? "Opłacone" : "Rezerwacja (max. 20 minut)"
                 ] as $label => $value) {
            $cf_meta_value .= '<p class="cf-meta-value"><strong>'.$label.'</strong><br>'.$value.'</p>';
        }

        switch ($message["action"]) {
            case "add":
                $result = $wpdb->insert($wpdb->prefix.'wpdevart_reservations', [
                    'calendar_id' => $calendar_id,
                    'single_day' => $date,
                    'start_hour' => $hour,
                    'count_item' => 1,
                    'price' => $data['price'],
                    'total_price' => $data['price'],
                    'form' => [],
                    'email' => $data['email'],
                    'status' => 'approved',
                    'date_created' => date('Y-m-d H:i', time()),
                    'is_new' => 0
                ]);
                if ($result === false) {
                    throw new Exception("Error saving to database - ".$wpdb->last_error);
                }

                $id = $wpdb->insert_id;

                $res = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'wpdevart_reservations WHERE `id`=%d',
                    $id), ARRAY_A);

                $theme_model = new wpdevart_bc_ModelThemes();
                $calendar_model = new wpdevart_bc_ModelCalendars();
                $form_model = new wpdevart_bc_ModelForms();
                $extra_model = new wpdevart_bc_ModelExtras();
                $ids = $calendar_model->get_ids($calendar_id);
                $theme_option = $theme_model->get_setting_rows($ids["theme_id"]);
                $calendar_data = $calendar_model->get_db_days_data($calendar_id);
                $calendar_title = $calendar_model->get_calendar_rows($calendar_id);
                $calendar_title = $calendar_title["title"];
                $extra_field = $extra_model->get_extra_rows($ids["extra_id"]);
                $form_option = $form_model->get_form_rows($ids["form_id"]);
                if (isset($theme_option)) {
                    $theme_option = json_decode($theme_option->value, true);
                } else {
                    $theme_option = [];
                }
                $wpdevart_booking = new wpdevart_bc_BookingCalendar($date, $res, $calendar_id, $theme_option,
                    $calendar_data, $form_option, $extra_field, [], false, [], $calendar_title);

                $reflector = new ReflectionObject($wpdevart_booking);
                $method = $reflector->getMethod('change_date_avail_count');
                $method->setAccessible(true);
                $method->invoke($wpdevart_booking, $id, true, "insert", []);

                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ["extid" => $id]);
                    return true;
                } catch (Exception $e) {
                }
                break;
            case "edit":
                if ($data['extid']) {
                    $row_id = $data["extid"];

                    $old_reserv = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'wpdevart_reservations WHERE `id`=%d',
                        $row_id), ARRAY_A);

                    $wpdb->update($wpdb->prefix.'wpdevart_reservations', [
                        'calendar_id' => $calendar_id,
                        'single_day' => $date,
                        'start_hour' => $hour,
                        'count_item' => 1,
                        'price' => $data['price'],
                        'total_price' => $data['price'],
                        'form' => [],
                        'email' => $data['email'],
                        'status' => 'approved',
                        'date_created' => date('Y-m-d H:i', time()),
                        'is_new' => 0
                    ], ['id' => $row_id]);

                    $theme_model = new wpdevart_bc_ModelThemes();
                    $calendar_model = new wpdevart_bc_ModelCalendars();
                    $form_model = new wpdevart_bc_ModelForms();
                    $extra_model = new wpdevart_bc_ModelExtras();
                    $ids = $calendar_model->get_ids($calendar_id);
                    $theme_option = $theme_model->get_setting_rows($ids["theme_id"]);
                    $calendar_data = $calendar_model->get_db_days_data($calendar_id);
                    $calendar_title = $calendar_model->get_calendar_rows($calendar_id);
                    $calendar_title = $calendar_title["title"];
                    $extra_field = $extra_model->get_extra_rows($ids["extra_id"]);
                    $form_option = $form_model->get_form_rows($ids["form_id"]);
                    if (isset($theme_option)) {
                        $theme_option = json_decode($theme_option->value, true);
                    } else {
                        $theme_option = [];
                    }
                    $wpdevart_booking = new wpdevart_bc_BookingCalendar($date, $old_reserv, $calendar_id, $theme_option,
                        $calendar_data, $form_option, $extra_field, [], false, [], $calendar_title);

                    $reflector = new ReflectionObject($wpdevart_booking);
                    $method = $reflector->getMethod('change_date_avail_count');
                    $method->setAccessible(true);
                    $method->invoke($wpdevart_booking, $row_id, true, "update", $old_reserv);

                    return true;
                }
                break;
            case 'delete':
                if ($data["extid"]) {
                    $row_id = $data["extid"];

                    $old_reserv = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'wpdevart_reservations WHERE `id`=%d',
                        $row_id), ARRAY_A);

                    $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'wpdevart_reservations WHERE id="%d"',
                        $row_id));

                    /** @noinspection PhpIncludeInspection */
                    require_once(WPDEVART_PLUGIN_DIR.'admin/controllers/Reservations.php');

                    $wpdevart_booking = new wpdevart_bc_ControllerReservations();
                    $reflector = new ReflectionObject($wpdevart_booking);
                    $method = $reflector->getMethod('change_date_avail_count');
                    $method->setAccessible(true);
                    $method->invoke($wpdevart_booking, $row_id, false, $old_reserv);

                    return true;
                }
                break;
        }
        return false;
    }

    private function GetCalendar($roomid)
    {
        global $wpdb;
        $query = "SELECT * FROM ".$wpdb->prefix."wpdevart_calendars ";
        $calendars = $wpdb->get_results($query);
        foreach ($calendars as $calendar) {
            if ($this->options["calendar_".$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        return null;
    }

    public function getPluginName()
    {
        return "Booking Calendar Pro WpDevArt";
    }
}
