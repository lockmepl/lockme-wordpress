<?php /** @noinspection SqlDialectInspection */

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use wp_booking_calendar_lists;
use wp_booking_calendar_public_reservation;
use wp_booking_calendar_reservation;
use wp_booking_calendar_slot;

$wpb_path = dirname(__FILE__).'/../../../wp-booking-calendar/';
/** @noinspection PhpIncludeInspection */
@include_once $wpb_path.'/admin/class/list.class.php';

class WPBooking implements PluginInterface
{
    private $options;
    private $delId;
    private $delData;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        global $wpb_path;

        $this->plugin = $plugin;
        $this->options = get_option("lockme_wpb");

        if ($this->options['use'] && $this->CheckDependencies()) {
            register_shutdown_function([$this, 'ShutDown']);

            $script = preg_replace("/^.*wp-booking-calendar\//", "", $_SERVER['SCRIPT_FILENAME']);
            if ($script == "admin/ajax/delReservationItem.php") {
                /** @noinspection PhpIncludeInspection */
                include_once $wpb_path."public/class/reservation.class.php";
                $this->delId = $_REQUEST["item_id"];
                $bookingReservationObj = new wp_booking_calendar_public_reservation;
                $reses = $bookingReservationObj->getReservationsDetails(md5($this->delId));
                $this->delData = $this->AppData($this->delId, $reses[$this->delId]);
            }

            add_action('init', function () {
                if (is_admin()) {
                    if ($_POST['operation'] == 'delReservations') {
                        foreach ($_POST["reservations"] as $id) {
                            if ($id) {
                                $this->Delete($id);
                            }
                        }
                    }
                }

                if ($_GET['wpb_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['wpb_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=wp_booking_plugin");
                    exit;
                }
            });
        }
    }


    private function AppData($id, $res)
    {
        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['calendar_id']],
                    'date' => date("Y-m-d", strtotime($res['reservation_date'])),
                    'hour' => date("H:i:s", strtotime($res['reservation_time_from'])),
                    'people' => $res['reservation_seats'],
                    'pricer' => "API",
                    'price' => $res['reservation_price'],
                    'name' => $res['reservation_name'],
                    'surname' => $res['reservation_surname'],
                    'email' => $res['reservation_email'],
                    'phone' => $res['reservation_phone'],
                    'comment' => $res['reservation_message'],
                    'status' => 1,
                    'extid' => $id
                ]
            );
    }

    public function Delete($id, $appdata = null)
    {
        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return;
        }

        try {
            $api->DeleteReservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }
    }

    public function ExportToLockMe()
    {
        global $wpb_path;
        set_time_limit(0);

        /** @noinspection PhpIncludeInspection */
        include_once $wpb_path."public/class/reservation.class.php";

        $bookingListObj = new wp_booking_calendar_lists();
        $calendars = $bookingListObj->getCalendarsList("");

        $ids = [];
        foreach ($calendars as $cid => $calendar) {
            $rows = $bookingListObj->getReservationsList("and s.`slot_date` >= curdate()", "", $cid);
            foreach ($rows as $id => $res) {
                $ids[] = md5($id);
            }
        }

        if (count($ids)) {
            $bookingReservationObj = new wp_booking_calendar_public_reservation;
            $reses = $bookingReservationObj->getReservationsDetails(join(",", $ids));

            foreach ($reses as $id => $data) {
                $this->Update($id, $data);
            }
        }
    }

    public function CheckDependencies()
    {
        return is_plugin_active("wp-booking-calendar/wp-booking-calendar.php");
    }

    public function RegisterSettings()
    {
        if (!$this->CheckDependencies()) {
            return false;
        }

        register_setting('lockme-wpb', 'lockme_wpb');

        add_settings_section(
            'lockme_wpb_section',
            "Ustawienia wtyczki WP Booking Calendar",
            function () {
                echo '<p>Ustawienia integracji z wtyczką WP Booking Calendar</p>';
            },
            'lockme-wpb'
        );

        add_settings_field(
            "wpb_use",
            "Włącz integrację",
            function () {
                echo '<input name="lockme_wpb[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-wpb',
            'lockme_wpb_section',
            []
        );

        if ($this->options['use'] && $this->plugin->tab == 'wp_booking_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }
            $bookingListObj = new wp_booking_calendar_lists();
            $calendars = $bookingListObj->getCalendarsList("");
            foreach ($calendars as $cid => $calendar) {
                add_settings_field(
                    "calendar_".$cid,
                    "Pokój dla ".$calendar['calendar_title'],
                    function () use ($rooms, $calendar, $cid) {
                        echo '<select name="lockme_wpb[calendar_'.$cid.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$cid],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-wpb',
                    'lockme_wpb_section',
                    []
                );
            }
            add_settings_field(
                "export_wpb",
                "Wyślij dane do LockMe",
                function () {
                    echo '<a href="?page=lockme_integration&tab=wpb_plugin&wpb_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-wpb',
                'lockme_wpb_section',
                []
            );
        }
        return true;
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
//     $data = $wpdb->get_row("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `id` = 60", ARRAY_A);
//     var_dump($data);
//     var_dump($this->Add($data));

        if ($_SESSION['wpb_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['wpb_export']);
        }
        settings_fields('lockme-wpb');
        do_settings_sections('lockme-wpb');
        return;
    }

    public function ShutDown()
    {
        global $bookingReservationObj, $listReservations, $bookingSlotsObj;

        $script = preg_replace("/^.*wp-booking-calendar\//", "", $_SERVER['SCRIPT_FILENAME']);
        switch ($script) {
            //Public
            case "public/ajax/doReservation.php":
                $reses = $bookingReservationObj->getReservationsDetails($listReservations);
                foreach ($reses as $id => $data) {
                    $this->Update($id, $data);
                }
                break;
            case "public/confirm.php":
            case "public/cancel.php":
                $reses = $bookingReservationObj->getReservationsDetails($_GET["reservations"]);
                foreach ($reses as $id => $data) {
                    $this->Update($id, $data);
                }
                break;

            //Admin
            case "admin/ajax/confirmReservation.php":
            case "admin/ajax/unconfirmReservation.php":
                $item_id = $_REQUEST["reservation_id"];
                $bookingReservationObj->setReservation($item_id);
                $bookingSlotsObj->setSlot($bookingReservationObj->getReservationSlotId());
                $this->Update($_REQUEST["reservation_id"], $this->Obj2Data($bookingReservationObj, $bookingSlotsObj));
                break;
            case "admin/ajax/cancelUserReservationItem.php":
                $item_id = $_REQUEST["item_id"];
                $bookingReservationObj->setReservation($item_id);
                $bookingSlotsObj->setSlot($bookingReservationObj->getReservationSlotId());
                $this->Update($_REQUEST["reservation_id"], $this->Obj2Data($bookingReservationObj, $bookingSlotsObj));
                break;
            case "admin/ajax/delReservationItem.php":
                $this->Delete($this->delId, $this->delData);
                break;
        }
    }

    public function GetMessage(array $message)
    {
        global $wpdb, $blog_id;
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $blog_prefix = $blog_id."_";
        if ($blog_id == 1) {
            $blog_prefix = "";
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];

        $calendar_id = $this->GetCalendar($roomid);

        switch ($message["action"]) {
            case "add":
                $slot = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM ".$wpdb->base_prefix.$blog_prefix."booking_slots WHERE slot_date = %s AND slot_active = 1 AND calendar_id=%d AND slot_time_from=%s",
                        $data['date'],
                        $calendar_id,
                        $data['hour']
                    ),
                    ARRAY_A
                );
                $wpdb->insert(
                    $wpdb->base_prefix.$blog_prefix."booking_reservation",
                    [
                        "slot_id" => $slot['slot_id'],
                        "reservation_name" => $data['name'],
                        "reservation_surname" => $data['surname'],
                        "reservation_email" => $data['email'],
                        "reservation_phone" => $data['phone'],
                        "reservation_message" => "LOCKME! {$data['comment']}",
                        "reservation_seats" => $data['people'],
                        "reservation_field1" => "LOCKME! {$data['comment']}",
                        "calendar_id" => $calendar_id,
                        "post_id" => 0,
                        "wordpress_user_id" => 0
                    ],
                    [
                        "%d",
                        "%s",
                        "%s",
                        "%s",
                        "%s",
                        "%s",
                        "%d",
                        "%s",
                        "%d",
                        "%d",
                        "%d"
                    ]
                );
                $id = $wpdb->insert_id;
                if (!$id) {
                    throw new Exception($wpdb->last_error);
                }
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ["extid" => $id]);
                    return true;
                } catch (Exception $e) {
                }
                break;
            case "edit":
                if ($data["extid"]) {
                    $slot = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM ".$wpdb->base_prefix.$blog_prefix."booking_slots WHERE slot_date = %s AND slot_active = 1 AND calendar_id=%d AND slot_time_from=%s",
                            $data['date'],
                            $calendar_id,
                            $data['hour']
                        ),
                        ARRAY_A
                    );
                    $wpdb->update(
                        $wpdb->base_prefix.$blog_prefix."booking_reservation",
                        [
                            "slot_id" => $slot['slot_id'],
                            "reservation_name" => $data['name'],
                            "reservation_surname" => $data['surname'],
                            "reservation_email" => $data['email'],
                            "reservation_phone" => $data['phone'],
                            "reservation_message" => "LOCKME! {$data['comment']}",
                            "reservation_seats" => $data['people'],
                            "reservation_field1" => "LOCKME! {$data['comment']}",
                            "calendar_id" => $calendar_id,
                            "post_id" => null,
                            "wordpress_user_id" => null
                        ],
                        ["reservation_id" => $data["extid"]],
                        [
                            "%d",
                            "%s",
                            "%s",
                            "%s",
                            "%s",
                            "%s",
                            "%d",
                            "%s",
                            "%d",
                            "%d",
                            "%d"
                        ]
                    );
                    if ($wpdb->last_error) {
                        throw new Exception($wpdb->last_error);
                    }
                    return true;
                }
                break;
            case 'delete':
                if ($data["extid"]) {
                    $wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix.$blog_prefix."booking_reservation WHERE reservation_id = %d",
                        $data["extid"]));
                    return true;
                }
                break;
        }
        return false;
    }

    /**
     * @param wp_booking_calendar_reservation $res
     * @param wp_booking_calendar_slot $slot
     * @return array
     */
    private function Obj2Data($res, $slot)
    {
        return [
            "calendar_id" => $res->getReservationCalendarId(),
            "reservation_date" => $slot->getSlotDate(),
            "reservation_time_from" => $slot->getSlotTimeFrom(),
            "reservation_seats" => $res->getReservationSeats(),
            "reservation_price" => $slot->getSlotPrice(),
            "reservation_name" => $res->getReservationName(),
            "reservation_surname" => $res->getReservationSurname(),
            "reservation_email" => $res->getReservationEmail(),
            "reservation_phone" => $res->getReservationPhone(),
            "reservation_message" => $res->getReservationMessage(),
            "reservation_cancelled" => $res->getReservationCancelled()
        ];
    }

    private function Add($id, $res)
    {
        $appdata = $this->AppData($id, $res);

        if ($res['reservation_cancelled']) {
            return $this->Delete($id, $appdata);
        }

        $api = $this->plugin->GetApi();

        try {
            $api->AddReservation($appdata);
        } catch (Exception $e) {
        }
        return null;
    }

    private function Update($id, $res)
    {
        $appdata = $this->AppData($id, $res);

        if ($res['reservation_cancelled']) {
            return $this->Delete($id, $appdata);
        }

        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return $this->Add($id, $res);
        }

        try {
            $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
        return null;
    }

    /**
     * @param $roomid
     * @return int|string
     * @throws Exception
     */
    private function GetCalendar($roomid)
    {
        $bookingListObj = new wp_booking_calendar_lists();
        $calendars = $bookingListObj->getCalendarsList("");
        foreach ($calendars as $cid => $calendar) {
            if ($this->options["calendar_".$cid] == $roomid) {
                return $cid;
            }
        }
        throw new Exception("No calendar");
    }

    public function getPluginName()
    {
        return "WP Booking Calendar";
    }
}
