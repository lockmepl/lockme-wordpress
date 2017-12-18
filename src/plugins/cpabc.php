<?php

class LockMe_cpabc{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_cpabc");

    if(self::$options['use']){
      register_shutdown_function(array('LockMe_cpabc', 'ShutDown'));

      add_action('init', function(){
        if($_GET['cpabc_export']){
          LockMe_cpabc::ExportToLockMe();
          $_SESSION['cpabc_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=cpabc_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("appointment-booking-calendar/cpabc_appointments.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-cpabc', 'lockme_cpabc' );

    add_settings_section(
          'lockme_cpabc_section',
          "Ustawienia wtyczki Appointment Booking Calendar",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Appointment Booking Calendar</p>';
          },
          'lockme-cpabc');

    $options = self::$options;

    add_settings_field(
      "cpabc_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_cpabc[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-cpabc',
      'lockme_cpabc_section',
      array());

    if($options['use'] && $lockme->tab == 'cpabc_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $calendars = $wpdb->get_results( "SELECT * FROM ".CPABC_APPOINTMENTS_CONFIG_TABLE_NAME);
      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->id,
          "Pokój dla ".$calendar->uname,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_cpabc[calendar_'.$calendar->id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-cpabc',
          'lockme_cpabc_section',
          array()
        );
      }
      add_settings_field(
        "export_cpabc",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=cpabc_plugin&cpabc_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-cpabc',
        'lockme_cpabc_section',
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
    //Format: m/d/Y H:i
//     $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where  `calendar` = %d and `booked_time_unformatted` = %s", 14, '2017-02-21 19:00:00'), ARRAY_A);
//     var_dump($res);
//     var_dump(self::AddEditReservation($row['id']));
//     self::Delete(3010);

    if($_SESSION['cpabc_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['cpabc_export']);
    }
    settings_fields( 'lockme-cpabc' );
    do_settings_sections( 'lockme-cpabc' );
  }

  static private function AppData($resid){
    global $wpdb;
    $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where b.id = %d", $resid), ARRAY_A);
    if(!$res) return;

    return array(
      'roomid'=>self::$options['calendar_'.$res['calendar']],
      'date'=>date('Y-m-d', strtotime($res['booked_time_unformatted'])),
      'hour'=>date("H:i:s", strtotime($res['booked_time_unformatted'])),
      'name'=>$res['name'],
      'pricer'=>"API",
      'email'=>$res['email'],
      'phone'=>$res['phone'],
      'status'=>1,
      'extid'=>$resid
    );
  }

  static public function AddEditReservation($id){
    global $lockme, $wpdb;
    if(!is_numeric($id)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where b.id = %d", $id), ARRAY_A);
    if(!$res) return;

    if($res['is_cancelled']){
      return self::Delete($id);
    }

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/{$id}");
    }catch(Exception $e){
    }

    try{
      if(!$lockme_data){ //Add new
        $id = $api->AddReservation(self::AppData($id));
      }else{ //Update
        $api->EditReservation("ext/{$id}", self::AppData($id));
      }
    }catch(Exception $e){
      var_dump($e->getMessage());
    }
  }

  static public function Delete($id){
    global $lockme, $wpdb;

    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where b.id = %d", $id), ARRAY_A);

    if($res && !$res['is_cancelled']){
      return self::AddEditReservation($id);
    }

    $api = $lockme->GetApi();

    try{
      $api->DeleteReservation("ext/{$id}");
    }catch(Exception $e){
    }
  }

  static public function ShutDown(){
  global $wpdb;
    //Add from website
    if($_POST['cpabc_item'] && $_POST['dateAndTime']){
      foreach($_POST['dateAndTime'] as $dat){
        $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where  `calendar` = %d and `booked_time_unformatted` = %s", $_POST['cpabc_item'], $dat), ARRAY_A);
        if($res){
          self::AddEditReservation($res["id"]);
        }
      }
    }

    //Admin
    if(is_admin()){
      if($_GET['page'] == 'cpabc_appointments'){
        if (isset($_GET['delmark']) && $_GET['delmark'] != ''){
          for ($i=0; $i<=50; $i++){
            if (isset($_GET['c'.$i]) && $_GET['c'.$i] != ''){
              self::Delete($_GET['c'.$i]);
            }
          }
        }else if (isset($_GET['ld']) && $_GET['ld'] != ''){
          self::Delete($_GET['ld']);
        }else if (isset($_GET['cancel']) && $_GET['cancel'] != ''){
          self::Delete($_GET['cancel']);
        }else if (isset($_GET['nocancel']) && $_GET['nocancel'] != ''){
          self::AddEditReservation($_GET['nocancel']);
        }else if($_GET['edit'] && $_POST){
          self::AddEditReservation($_GET['edit']);
        }
      }
    }
  }

  static private function GetCalendar($roomid){
    global $wpdb;
    $calendars = $wpdb->get_results( "SELECT * FROM ".CPABC_APPOINTMENTS_CONFIG_TABLE_NAME);
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->id] == $roomid){
        return $calendar->id;
      }
    }
    return null;
  }

  static public function GetMessage($message){
    global $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $date = strtotime($data['date']);
    $hour = $data['hour'];
    $datetime = strtotime($data["date"]." ".$hour);

    $calendar_id = self::GetCalendar($roomid);

    switch($message["action"]){
      case "add":
				$rows_affected = $wpdb->insert( CPABC_APPOINTMENTS_TABLE_NAME,array(
          'calendar' => $calendar_id,
          'time' => current_time('mysql'),
          'booked_time' => date("Y-m-d H:i:s", $datetime),
          'booked_time_customer' => date("Y-m-d H:i:s", $datetime),
          'booked_time_unformatted' => date("Y-m-d H:i:s", $datetime),
          'name' => $data["name"]." ".$data["surname"],
          'email' => $data["email"],
          'phone' => $data["phone"],
          'question' => "Źródło: LockMe\nIlość osób: {$data['people']}\nCena: {$data['price']}",
          'quantity' => 1,
          'quantity_a' => $data['people'],
          'quantity_s' => 0,
          'buffered_date' => serialize($data),
          'who_added' => 0
        ) );
        if(!$rows_affected){
          throw new Exception("Błąd zapisu: ".$wpdb->last_error);
        }
        $tid = $wpdb->insert_id;

        $rows_affected = $wpdb->insert( CPABC_TDEAPP_CALENDAR_DATA_TABLE, array(
          'appointment_calendar_id' => $calendar_id,
          'datatime' => date("Y-m-d H:i:s", $datetime),
          'title' => $data["email"],
          'reminder' => 0,
          'quantity' => 1,
          'quantity_a' => $data['people'],
          'quantity_s' => 0,
          'description' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
          'description_customer' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
          'reference' => $tid,
          'who_added' => 0
        ) );

        if(!$rows_affected){
          throw new Exception("Błąd zapisu 2: ".$wpdb->last_error);
        }
        $id = $wpdb->insert_id;

        try{
          $api = $lockme->GetApi();
          $api->EditReservation($lockme_id, array("extid"=>$id));
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['extid']){
          $event = $wpdb->get_results( "SELECT * FROM ".CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME." WHERE id=".esc_sql($data['extid']));
          $event = $event[0];

          $data1 = array(
            'datatime' => date("Y-m-d H:i:s", $datetime),
            'quantity' => $data['people'],
            'title' => $data['email'],
            'description' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
            'who_edited' => 0
          );

          $data2 = array(
            'booked_time_unformatted' => date("Y-m-d H:i:s", $datetime),
            'booked_time' => date("Y-m-d H:i:s", $datetime),
            'quantity' => $data['people'],
            'buffered_date' => serialize($data)
          );

          $wpdb->update ( CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME, $data1, array( 'id' => $data['extid'] ));
          if ($event->reference != '') $wpdb->update ( CPABC_APPOINTMENTS_TABLE_NAME, $data2, array( 'id' => $event->reference ));
        }
        break;
      case 'delete':
        if($data["extid"]){
          $wpdb->query('DELETE FROM `'.CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME.'` WHERE id='.$data["extid"]);
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    global $wpdb;
    $rows = $wpdb->get_results("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where date(`datatime`) >= curdate()", ARRAY_A);
    foreach($rows as $row){
      self::AddEditReservation($row['id']);
    }
  }
}
