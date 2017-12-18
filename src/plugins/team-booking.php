<?php


class TeamBookingAPI {

  public $settings;
  public $reservation_data;
  public $reservation_id;

  public function execute(){
    LockMe_team_booking::AddEditReservation($this->reservation_id, $this->reservation_data);
  }

}

class LockMe_team_booking{
  static private $options;
  static public $tb;

  static public function Init(){
    self::$options = get_option("lockme_teamb");

    if(self::$options['use']){
      register_shutdown_function(array('LockMe_team_booking', 'ShutDown'));

      add_action('init', function(){
        LockMe_team_booking::$tb = getSettingsTB();
        if($_GET['teamb_export']){
          LockMe_team_booking::ExportToLockMe();
          $_SESSION['teamb_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=team_booking_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("team-booking/team-booking.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-teamb', 'lockme_teamb' );

    add_settings_section(
          'lockme_teamb_section',
          "Ustawienia wtyczki Team Booking",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Team Booking</p>';
          },
          'lockme-teamb');

    $options = self::$options;

    add_settings_field(
      "teamb_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_teamb[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-teamb',
      'lockme_teamb_section',
      array());

    if($options['use'] && $lockme->tab == 'team_booking_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      foreach(self::$tb->getBookings() as $service){
        add_settings_field(
          "calendar_".$service->getId(),
          "Pokój dla ".$service->getName(),
          function() use($options, $rooms, $service){
            echo '<select name="lockme_teamb[calendar_'.$service->getId().']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$service->getId()], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-teamb',
          'lockme_teamb_section',
          array()
        );
      }
      add_settings_field(
        "export_teamb",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=team_booking_plugin&teamb_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-teamb',
        'lockme_teamb_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
//     $result = getTeamBookingReservationById(1007);
//     var_dump(self::$tb->getBookings());

    if($_SESSION['teamb_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['teamb_export']);
    }else if($_SESSION['teamb_fix']){
      echo '<div class="updated">';
      echo '  <p>Ustawienia zostały naprawione. <b>Sprawdź działanie kalendarza i migrację ustawień!</b></p>';
      echo '</div>';
      unset($_SESSION['teamb_fix']);
    }
    settings_fields( 'lockme-teamb' );
    do_settings_sections( 'lockme-teamb' );
  }

  static private function AppData($id, $data){
    $timezone = tbGetTimezone();
    $date_time_object = DateTime::createFromFormat('U', $data->getStart());
    $when_value_offset = $timezone->getOffset($date_time_object);
    $when_value = $data->getStart() + $when_value_offset;

    return array(
      'roomid'=>self::$options['calendar_'.$data->getServiceId()],
      'date'=>date("Y-m-d", $when_value),
      'hour'=>date("H:i:s", $when_value),
      'people'=>0,
      'pricer'=>"API",
      'price'=>$data->getPrice(),
      'email'=>$data->getCustomerEmail(),
      'status'=>1,
      'extid'=>$id
    );
  }

  static private function Add($id, $data){
    global $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $id = $api->AddReservation(self::AppData($id, $data));
    }catch(Exception $e){
    }
  }

  static private function Update($id, $data){
    global $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/".$id);
    }catch(Exception $e){
    }

    if(!$lockme_data){
      return self::Add($id, $data);
    }

    try{
      $api->EditReservation("ext/".$id, self::AppData($id, $data));
    }catch(Exception $e){
    }
  }

  static private function Delete($id){
    global $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/{$id}");
    }catch(Exception $e){
    }

    if(!$lockme_data){
      return;
    }

    try{
      $api->DeleteReservation("ext/{$id}");
    }catch(Exception $e){
    }
  }

  static public function AddEditReservation($id, $data){
    global $lockme;

    self::Update($id, $data);
  }

  static public function ShutDown(){
    if(isset($_POST['team_booking_delete_reservation_confirm'])){
      $id = $_POST['reservation_id'];
      self::Delete($id);
    }elseif(isset($_POST['tb_modify_reservation_save'])){
      $reservation_database_id = $_POST['reservation_database_id'];
      $reservation = getTeamBookingReservationById($reservation_database_id);
      self::AddEditReservation($reservation_database_id, $reservation);
    }
  }

  static private function GetCalendar($roomid){
    global $wpdb;

    foreach(self::$tb->getBookings() as $service){
      if(self::$options['calendar_'.$service->getId()] == $roomid){
        return $service->getId();
      }
    }
    throw new Exception("No calendar");
  }

  static public function GetMessage($message){
    global $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $hour = date("H:i", strtotime($data['hour']));

    $calendar_id = self::GetCalendar($roomid);
    $booking = self::$tb->getBooking($calendar_id);

    $timezone = tbGetTimezone();
    $date_time_object = new DateTime;
    $when_value_offset = $timezone->getOffset($date_time_object);

    $cw = array_shift(self::$tb->getCoworkersData());
    $duration = $cw->getCustomEventSettings($calendar_id)->getFixedDuration();

    $tit = $cw->getCustomEventSettings($calendar_id)->getLinkedEventTitle();

    $time = strtotime("{$data['date']} {$data['hour']}")-$when_value_offset;

    $client = new Google_Client();
    $client->addScope('https://www.googleapis.com/auth/calendar');
    $client->setApplicationName(self::$tb->getApplicationProjectName());
    $client->setClientId(self::$tb->getApplicationClientId());
    $client->setClientSecret(self::$tb->getApplicationClientSecret());
    $client->setAccessType('offline');
    $google_service = new Google_Service_Calendar($client);
    $access_token = $cw->getAccessToken();
    if (!empty($access_token)) {
      $client->setAccessToken($cw->getAccessToken());
    }

    foreach ($cw->getCalendarId() as $cal_id) {
      $google_calendar_id = $cal_id;
      break;
    }

    if($data["extid"]){
      $res_data = getTeamBookingReservationById($data["extid"]);
    }

    switch($message["action"]){
      case "add":
        $list = $google_service->events->listEvents($google_calendar_id, ['timeMin'=>date('c', $time-100), 'timeMax'=>date('c', $time+100)]);
        $ok = false;
        foreach($list as $ev){
          if($ev->getSummary() == $tit){
            $ok = true;
            break;
          }
        }
        if(!$ok){
          throw new Exception('No event');
        }

        $attendees = $ev->getAttendees();
        $new_attendee = new Google_Service_Calendar_EventAttendee();
        $new_attendee->setEmail("lockme@findout.pl");
        $new_attendee->setResponseStatus('accepted');
        $attendees[] = $new_attendee;
        $ev->setAttendees($attendees);
        $google_service->events->patch($google_calendar_id, $ev->getId(), $ev);

//         $res = new TeamBooking_Reservation($res_data);
//         $id = $res->doReservation();
/*
        if(!$id){
          throw new Exception("Error saving to database - ".$wpdb->last_error);
        }*/
/*
        try{
          $api = $lockme->GetApi();
          $api->EditReservation($lockme_id, array("extid"=>$id));
        }catch(Exception $e){
        }*/
        break;
      case "edit":
//         updateTeamBookingReservationById($data['extid'], $res_data);

        break;
      case 'delete':
        $list = $google_service->events->listEvents($google_calendar_id, ['timeMin'=>date('c', $time-100), 'timeMax'=>date('c', $time+100)]);
        $ok = false;
        foreach($list as $ev){
          if($ev->getSummary() == $tit){
            $ok = true;
            break;
          }
        }
        if(!$ok){
          throw new Exception('No event');
        }
        $ev->setAttendees([]);
        $google_service->events->patch($google_calendar_id, $ev->getId(), $ev);
        break;
    }
  }

  static function ExportToLockMe(){
    global $wpdb, $lockme;
    set_time_limit(0);
    $api = $lockme->getApi();

    $rows = getTeamBookingReservations();

    foreach($rows as $id=>$row){
      if($row->getStart() >= time()){
        self::AddEditReservation($id, $row);
      }
    }
  }
}
