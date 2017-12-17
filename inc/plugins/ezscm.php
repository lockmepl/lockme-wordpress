<?php

class LockMe_ezscm{
  static private $options;
  static private $tables;

  static public function Init(){
    global $wpdb;

    self::$options = get_option("lockme_ezscm");

    self::$tables = array(
			"entries"			=> "{$wpdb->prefix}ezscm_entries",
			"schedules"			=> "{$wpdb->prefix}ezscm_schedules",
			"settings"			=> "{$wpdb->prefix}ezscm_settings",
			"settings_schedule"	=> "{$wpdb->prefix}ezscm_settings_schedule"
		);

    if(self::$options['use']){
      register_shutdown_function(array('LockMe_ezscm', 'ShutDown'));

      add_action('init', function(){
        if($_GET['ezscm_export']){
          LockMe_ezscm::ExportToLockMe();
          $_SESSION['ezscm_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=ezscm_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("ez-schedule-manager/ezscm.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-ezscm', 'lockme_ezscm' );

    add_settings_section(
          'lockme_ezscm_section',
          "Ustawienia wtyczki ez Schedule Manager",
          function(){
            echo '<p>Ustawienia integracji z wtyczką ez Schedule Manager</p>';
          },
          'lockme-ezscm');

    $options = self::$options;

    add_settings_field(
      "ezscm_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_ezscm[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-ezscm',
      'lockme_ezscm_section',
      array());

    if($options['use'] && $lockme->tab == 'ezscm_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }
      $calendars = $wpdb->get_results("
        SELECT sc.s_id, sc.name
        FROM ".self::$tables["schedules"]." AS sc"
      );

      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->s_id,
          "Pokój dla ".$calendar->name,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_ezscm[calendar_'.$calendar->s_id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->s_id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-ezscm',
          'lockme_ezscm_section',
          array()
        );
      }
      add_settings_field(
        "export_ezscm",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=ezscm_plugin&ezscm_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-ezscm',
        'lockme_ezscm_section',
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

    if($_SESSION['ezscm_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['ezscm_export']);
    }
    settings_fields( 'lockme-ezscm' );
    do_settings_sections( 'lockme-ezscm' );
  }

  static private function AppData($res){
    $details = json_decode($res['data'], true);

    return array(
      'roomid'=>self::$options['calendar_'.$res['s_id']] ?: self::$options['calendar_'.$res['details-s_id']],
      'date'=>date("Y-m-d", strtotime($res['date'])),
      'hour'=>date("H:i:s", strtotime($res['time_begin'])),
      'people'=>0,
      'pricer'=>"API",
      'price'=>0,
      'email'=>$details['Email'],
      'status'=>1,
      'extid'=>$res['e_id']
    );
  }

  static private function Add($res){
    global $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $id = $api->AddReservation(self::AppData($res));
    }catch(Exception $e){
    }
  }

  static private function Update($id, $res){
    global $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/{$id}");
    }catch(Exception $e){
    }

    if(!$lockme_data){
      return self::Add($res);
    }

    try{
      $api->EditReservation("ext/{$id}", self::AppData($res));
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

  static public function AddReservation($save_data){
    global $wpdb, $lockme;

    $existing = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM ".self::$tables["entries"]." WHERE time_begin='%s' AND date='%s' AND s_id=%d",
			$save_data["time_internal"], $save_data["date_internal"], $save_data["s_id"]
		), ARRAY_A);
		if(!$existing) return;

		self::Add($existing);
  }

  static public function AddEditReservation($save_data){
    global $wpdb, $lockme;

    $existing = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM ".self::$tables["entries"]." WHERE time_begin='%s' AND date='%s' AND s_id=%d",
			$save_data["details-time_internal"], $save_data["details-date_internal"], $save_data["details-s_id"]
		), ARRAY_A);
		if(!$existing) return;

    self::Update($existing['e_id'], $existing);
  }

  static public function ShutDown(){
    if ($_POST['action'] == 'ezscm_frontend' || $_POST['action'] == 'ezscm_backend') {
      parse_str($_REQUEST["data"], $data);
      $action = $data["action"];
      $id = $data["id"];

      switch($action){
        case "submit":
          self::AddReservation($data["data"]);
          break;

        case "entry_delete":
          self::Delete($id);
          break;

        case "save_entry":
          self::AddEditReservation($data);
          break;
      }
    }
  }

  static private function GetCalendar($roomid){
    global $wpdb;

    $calendars = $wpdb->get_results("
        SELECT sc.s_id, sc.name
        FROM ".self::$tables["schedules"]." AS sc"
      );
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->s_id] == $roomid){
        return $calendar->s_id;
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

    $form = array(
      "Nazwa Pokoju" => "LockMe",
      "Ilość graczy" => $data['people'],
      "Imię" => $data['name'].' '.$data['surname'],
      "Email" => $data['email'],
      "Telefon" => $data['phone'],
      "Wiadomość" => $data['comment']
    );
    $sql_data = json_encode($form);

    switch($message["action"]){
      case "add":
        $res = $wpdb->insert(
          self::$tables["entries"],
          array(
            "s_id"       => $calendar_id,
            "date"       => $data['date'],
            "private"    => 0,
            "time_begin" => $hour,
            "data"		 => $sql_data,
            "ip"         => $_SERVER["REMOTE_ADDR"]
          ),
          array(
            "%d",
            "%s",
            "%d",
            "%s",
            "%s",
            "%s"
          )
        );
        $id = $wpdb->insert_id;
        try{
          $api = $lockme->GetApi();
          $api->EditReservation($lockme_id, array("extid"=>$id));
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data["extid"]){
          $res = $wpdb->update(
            self::$tables["entries"],
            array(
              "s_id"       => $calendar_id,
              "date"       => $data['date'],
              "private"    => 0,
              "time_begin" => $hour,
              "data"		 => $sql_data,
              "ip"         => $_SERVER["REMOTE_ADDR"]
            ),
            array("e_id" => $data["extid"]),
            array(
              "%d",
              "%s",
              "%d",
              "%s",
              "%s",
              "%s"
            )
          );
        }
        break;
      case 'delete':
        if($data["extid"]){
          $res = $wpdb->delete(
            self::$tables["entries"],
            array(
              "e_id" => $data["extid"],
            ),
            array(
              "%d",
            )
          );
        }
        break;
    }
  }

  static function ExportToLockMe(){
    global $wpdb, $lockme;
    set_time_limit(0);
    $api = $lockme->getApi();

    $sql = "SELECT * FROM ".self::$tables["entries"]." WHERE date>=curdate() ORDER BY e_id";
    $rows = $wpdb->get_results($sql, ARRAY_A);

    foreach($rows as $row){
      self::Update($row['e_id'], $row);
    }
  }
}
