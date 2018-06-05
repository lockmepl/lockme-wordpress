<?php

$wpb_path = dirname(__FILE__).'/../../../wp-booking-calendar/';
@include_once $wpb_path.'/admin/class/list.class.php';

class LockMe_wp_booking
{
    private static $options;
    private static $delId;
    private static $delData;

    public static function Init()
    {
        global $wpdb, $wpb_path;

        self::$options = get_option("lockme_wpb");

        if (self::$options['use']) {
            register_shutdown_function(array('LockMe_wp_booking', 'ShutDown'));

            $script = preg_replace("/^.*wp-booking-calendar\//", "", $_SERVER['SCRIPT_FILENAME']);
            if ($script == "admin/ajax/delReservationItem.php") {
                include_once $wpb_path."public/class/reservation.class.php";
                self::$delId = $_REQUEST["item_id"];
                $bookingReservationObj = new wp_booking_calendar_public_reservation;
                $reses = $bookingReservationObj->getReservationsDetails(md5(self::$delId));
                self::$delData = self::AppData(self::$delId, $reses[self::$delId]);
            }

            add_action('init', function () {
                if (is_admin()) {
                    if ($_POST['operation'] == 'delReservations') {
                        foreach ($_POST["reservations"] as $id) {
                            if ($id) {
                                LockMe_wp_booking::Delete($id);
                            }
                        }
                    }
                }

                if ($_GET['wpb_export']) {
                    LockMe_wp_booking::ExportToLockMe();
                    $_SESSION['wpb_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=wp_booking_plugin");
                    exit;
                }
            });
        }
    }

    public static function CheckDependencies()
    {
        return is_plugin_active("wp-booking-calendar/wp-booking-calendar.php");
    }

    public static function RegisterSettings(LockMe_Plugin $lockme)
    {
        global $wpdb;
        if (!self::CheckDependencies()) {
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

        $options = self::$options;

        add_settings_field(
      "wpb_use",
      "Włącz integrację",
      function () use ($options) {
          echo '<input name="lockme_wpb[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-wpb',
      'lockme_wpb_section',
      array()
    );

        if ($options['use'] && $lockme->tab == 'wp_booking_plugin') {
            $api = $lockme->GetApi();
            if ($api) {
                $rooms = $api->RoomList();
            }
            $bookingListObj = new wp_booking_calendar_lists();
            $calendars = $bookingListObj->getCalendarsList("");
            foreach ($calendars as $cid=>$calendar) {
                add_settings_field(
          "calendar_".$cid,
          "Pokój dla ".$calendar['calendar_title'],
          function () use ($options, $rooms, $calendar, $cid) {
              echo '<select name="lockme_wpb[calendar_'.$cid.']">';
              echo '<option value="">--wybierz--</option>';
              foreach ($rooms as $room) {
                  echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$cid], false).'>'.$room['room'].' ('.$room['department'].')</options>';
              }
              echo '</select>';
          },
          'lockme-wpb',
          'lockme_wpb_section',
          array()
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
        array()
      );
        }
    }

    public static function DrawForm(LockMe_Plugin $lockme)
    {
        global $wpdb;
        if (!self::CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
//     $data = $wpdb->get_row("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `id` = 60", ARRAY_A);
//     var_dump($data);
//     var_dump(self::Add($data));

        if ($_SESSION['wpb_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['wpb_export']);
        }
        settings_fields('lockme-wpb');
        do_settings_sections('lockme-wpb');
    }

    private static function AppData($id, $res)
    {
        $details = json_decode($res['data'], true);

        return array(
      'roomid'=>self::$options['calendar_'.$res['calendar_id']],
      'date'=>date("Y-m-d", strtotime($res['reservation_date'])),
      'hour'=>date("H:i:s", strtotime($res['reservation_time_from'])),
      'people'=>$res['reservation_seats'],
      'pricer'=>"API",
      'price'=>$res['reservation_price'],
      'name'=>$res['reservation_name'],
      'surname'=>$res['reservation_surname'],
      'email'=>$res['reservation_email'],
      'phone'=>$res['reservation_phone'],
      'comment'=>$res['reservation_message'],
      'status'=>1,
      'extid'=>$id
    );
    }

    private static function Obj2Data($res, $slot)
    {
        return array(
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
    );
    }

    private static function Add($id, $res)
    {
        global $lockme;

        $appdata = self::AppData($id, $res);

        if ($res['reservation_cancelled']) {
            return self::Delete($id, $appdata);
        }

        $api = $lockme->GetApi();

        try {
            $id = $api->AddReservation($appdata);
        } catch (Exception $e) {
        }
    }

    private static function Update($id, $res)
    {
        global $lockme;

        $appdata = self::AppData($id, $res);

        if ($res['reservation_cancelled']) {
            return self::Delete($id, $appdata);
        }

        $api = $lockme->GetApi();

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return self::Add($id, $res);
        }

        try {
            $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
    }

    public static function Delete($id, $appdata = null)
    {
        global $lockme, $wpdb;

        $api = $lockme->GetApi();

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

    public static function ShutDown()
    {
        global $bookingReservationObj, $listReservations, $bookingSlotsObj;

        $script = preg_replace("/^.*wp-booking-calendar\//", "", $_SERVER['SCRIPT_FILENAME']);
        switch ($script) {
      //Public
      case "public/ajax/doReservation.php":
        $reses = $bookingReservationObj->getReservationsDetails($listReservations);
        foreach ($reses as $id=>$data) {
            self::Update($id, $data);
        }
        break;
      case "public/confirm.php":
      case "public/cancel.php":
        $reses = $bookingReservationObj->getReservationsDetails($_GET["reservations"]);
        foreach ($reses as $id=>$data) {
            self::Update($id, $data);
        }
        break;

      //Admin
      case "admin/ajax/confirmReservation.php":
      case "admin/ajax/unconfirmReservation.php":
        $item_id = $_REQUEST["reservation_id"];
        $bookingReservationObj->setReservation($item_id);
        $bookingSlotsObj->setSlot($bookingReservationObj->getReservationSlotId());
        self::Update($_REQUEST["reservation_id"], self::Obj2Data($bookingReservationObj, $bookingSlotsObj));
        break;
      case "admin/ajax/cancelUserReservationItem.php":
        $item_id = $_REQUEST["item_id"];
        $bookingReservationObj->setReservation($item_id);
        $bookingSlotsObj->setSlot($bookingReservationObj->getReservationSlotId());
        self::Update($_REQUEST["reservation_id"], self::Obj2Data($bookingReservationObj, $bookingSlotsObj));
        break;
      case "admin/ajax/delReservationItem.php":
        self::Delete(self::$delId, self::$delData);
        break;
    }
    }

    private static function GetCalendar($roomid)
    {
        global $wpdb;

        $bookingListObj = new wp_booking_calendar_lists();
        $calendars = $bookingListObj->getCalendarsList("");
        foreach ($calendars as $cid=>$calendar) {
            if (self::$options["calendar_".$cid] == $roomid) {
                return $cid;
            }
        }
        throw new Exception("No calendar");
    }

    public static function GetMessage($message)
    {
        global $wpdb, $lockme, $blog_id;
        if (!self::$options['use'] || !self::CheckDependencies()) {
            return;
        }

        $blog_prefix=$blog_id."_";
        if ($blog_id==1) {
            $blog_prefix="";
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];

        $calendar_id = self::GetCalendar($roomid);

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
        $res = $wpdb->insert(
          $wpdb->base_prefix.$blog_prefix."booking_reservation",
          array(
            "slot_id"=>$slot['slot_id'],
            "reservation_name"=>$data['name'],
            "reservation_surname"=>$data['surname'],
            "reservation_email"=>$data['email'],
            "reservation_phone"=>$data['phone'],
            "reservation_message"=>"LOCKME! {$data['comment']}",
            "reservation_seats"=>$data['people'],
            "reservation_field1"=>"LOCKME! {$data['comment']}",
            "calendar_id"=>$calendar_id,
            "post_id"=>0,
            "wordpress_user_id"=>0
          ),
          array(
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
          )
        );
        $id = $wpdb->insert_id;
        if (!$id) {
            throw new Exception($wpdb->last_error);
        }
        try {
            $api = $lockme->GetApi();
            $api->EditReservation($roomid, $lockme_id, array("extid"=>$id));
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
            $res = $wpdb->update(
            $wpdb->base_prefix.$blog_prefix."booking_reservation",
            array(
              "slot_id"=>$slot['slot_id'],
              "reservation_name"=>$data['name'],
              "reservation_surname"=>$data['surname'],
              "reservation_email"=>$data['email'],
              "reservation_phone"=>$data['phone'],
              "reservation_message"=>"LOCKME! {$data['comment']}",
              "reservation_seats"=>$data['people'],
              "reservation_field1"=>"LOCKME! {$data['comment']}",
              "calendar_id"=>$calendar_id,
              "post_id"=>null,
              "wordpress_user_id"=>null
            ),
            array("reservation_id" => $data["extid"]),
            array(
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
            )
          );
            if ($wpdb->last_error) {
                throw new Exception($wpdb->last_error);
            }
            return true;
        }
        break;
      case 'delete':
        if ($data["extid"]) {
            $wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix.$blog_prefix."booking_reservation WHERE reservation_id = %d", $data["extid"]));
            return true;
        }
        break;
    }
    }

    public static function ExportToLockMe()
    {
        global $wpdb, $lockme, $wpb_path;
        set_time_limit(0);
        $api = $lockme->getApi();

        include_once $wpb_path."public/class/reservation.class.php";

        $bookingListObj = new wp_booking_calendar_lists();
        $calendars = $bookingListObj->getCalendarsList("");

        $ids = [];
        foreach ($calendars as $cid=>$calendar) {
            $rows = $bookingListObj->getReservationsList("and s.`slot_date` >= curdate()", "", $cid);
            foreach ($rows as $id=>$res) {
                $ids[] = md5($id);
            }
        }

        if (count($ids)) {
            $bookingReservationObj = new wp_booking_calendar_public_reservation;
            $reses = $bookingReservationObj->getReservationsDetails(join(",", $ids));

            foreach ($reses as $id=>$data) {
                self::Update($id, $data);
            }
        }
    }
}
