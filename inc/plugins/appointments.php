<?php

class LockMe_Appointments{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_app");

    if(self::$options['use']){
      add_action('wpmudev_appointments_insert_appointment', array('LockMe_Appointments', 'AddReservation'), 10, 1);
      add_action('app-appointment-inline_edit-after_save', array('LockMe_Appointments', 'AddEditReservation'), 10, 2);
      add_action('app-appointments-appointment_cancelled', array('LockMe_Appointments', 'RemoveReservation'), 10, 1);

      add_action('init', function(){
        if($_GET['app_export']){
          LockMe_Appointments::ExportToLockMe();
          $_SESSION['app_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=appointments_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("appointments/appointments.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $appointments;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-app', 'lockme_app' );

    add_settings_section(
          'lockme_app_section',
          "Ustawienia wtyczki Appointments",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Appointments</p>';
          },
          'lockme-app');

    $options = self::$options;

    add_settings_field(
      "app_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_app[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-app',
      'lockme_app_section',
      array());

    if($options['use'] && $lockme->tab == 'appointments_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }
      $services = $appointments->get_services();
      foreach($services as $service){
        $workers = $appointments->get_workers_by_service($service->ID);
        foreach($workers as $worker){
          $user = get_userdata($worker->ID);
          add_settings_field(
            "service_".$service->ID."_".$worker->ID,
            "Pokój dla ".$service->name." - ".$user->user_login,
            function() use($options, $rooms, $service, $worker){
              echo '<select name="lockme_app[service_'.$service->ID.'_'.$worker->ID.']">';
              echo '<option value="">--wybierz--</option>';
              foreach($rooms as $room){
                echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['service_'.$service->ID.'_'.$worker->ID], false).'>'.$room['room'].' ('.$room['department'].')</options>';
              }
              echo '</select>';
            },
            'lockme-app',
            'lockme_app_section',
            array()
          );
        }
      }
      add_settings_field(
        "export_apps",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=appointments_plugin&app_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-app',
        'lockme_app_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    global $appointments, $wpdb;
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
//     $sql = "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `start` > now() ORDER BY ID";
//     $rows = $wpdb->get_results($sql);

    if($_SESSION['app_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['app_export']);
    }
    settings_fields( 'lockme-app' );
    do_settings_sections( 'lockme-app' );
  }

  static private function AppData($app){
    global $appointments;
    $date = date('Y-m-d', strtotime($app['start']));
    $hour = date('H:i:s', strtotime($app['start']));

    return array(
      'roomid'=>self::$options['service_'.$app['service'].'_'.$app['worker']],
      'date'=>$date,
      'hour'=>$hour,
      'people'=>0,
      'pricer'=>$appointments->get_service_name($app['service']),
      'price'=>$app['price'],
      'name'=>$app['name'],
      'surname'=>$app['surname'],
      'email'=>$app['email'],
      'phone'=>$app['phone'],
      'comment'=>$app['note'],
      'status'=>in_array($app['status'], array('confirmed', 'paid')),
      'extid'=>$app['ID']
    );
  }

  static private function Add($app_id, $app){
    global $appointments, $lockme, $wpdb;
    if($app['status'] == 'removed'){
      return;
    }

    $api = $lockme->GetApi();

    try{
      $id = $api->AddReservation(self::AppData($app));

      if($id){
        $wpdb->update($wpdb->prefix . 'app_appointments', array('note'=>$app['note']."\n\n#LOCKME:{$id}"), array('ID'=>$app_id));
        $appointments->flush_cache();
      }
    }catch(Exception $e){
      var_dump($e->getMessage());
    }
  }

  static private function Update($app_id, $app, $lockme_id){
    global $appointments, $lockme, $wpdb;

    if(!$lockme_id){
      return self::Add($app_id, $app);
    }
    if($app['status'] == 'removed'){
      return self::Delete($lockme_id, $app);
    }

    $api = $lockme->GetApi();

    try{
      $api->EditReservation($lockme_id, self::AppData($app));
    }catch(Exception $e){
    }
  }

  static private function Delete($lockme_id, $app){
    global $appointments, $lockme, $wpdb;

    $api = $lockme->GetApi();

    try{
      $api->DeleteReservation($lockme_id);
    }catch(Exception $e){
    }
    $wpdb->update($wpdb->prefix . 'app_appointments', array('note'=>trim(strtr($app['note'], array("#LOCKME:{$lockme_id}"=>"")))), array('ID'=>$app['ID']));
    $appointments->flush_cache();
  }

  static public function AddReservation($id){
    global $appointments, $lockme;

    $app = json_decode(json_encode($appointments->get_app($id)), true);

    self::Add($id, $app);
  }

  static public function AddEditReservation($id, $data = array()){
    global $appointments, $lockme;

    $id = $id ?: $data['ID'];
    $app = $data ?: json_decode(json_encode($appointments->get_app($id)), true);
    $api = $lockme->GetApi();

    $m = array();
    preg_match('/^#LOCKME:(\d+)$/m', $app['note'], $m);
    self::Update($id, $app, $m[1]);
  }

  static public function RemoveReservation($id){
    global $appointments, $lockme;

    $app = json_decode(json_encode($appointments->get_app($id)), true);
    $api = $lockme->GetApi();

    $m = array();
    preg_match('/^#LOCKME:(\d+)$/', $app['note'], $m);
    if($m){
      self::Delete($m[1], $app);
    }
  }

  static private function GetService($roomid, $people){
    global $appointments;

    $services = $appointments->get_services();
    foreach($services as $k=>$v){
      $workers = $appointments->get_workers_by_service($v->ID);
      foreach($workers as $worker){
        if(self::$options["service_".$v->ID."_".$worker->ID] == $roomid){
          return array($v, $worker->ID);
        }
      }
    }
    throw new Exception("No service");
  }

  static public function GetMessage($message){
    global $appointments, $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $start = strtotime($data['date'].' '.$data['hour']);

    list($service, $worker) = self::GetService($roomid, $data['people']);

    switch($message["action"]){
      case "add":
        $result = $wpdb->insert( $wpdb->prefix . 'app_appointments',
            array(
              'created'	=>	date ("Y-m-d H:i:s", $appointments->local_time ),
              'user'  =>  0,
              'name'		=>	$data['name'],
              'email'		=>	$data['email'],
              'phone'		=>	$data['phone'],
              'service'	=>	$service->ID,
              'worker'	=> 	$worker,
              'price'		=>	$data['price'],
              'status'	=>	$data['status'] ? 'paid' : 'pending',
              'start'		=>	date ("Y-m-d H:i:s", $start),
              'end'		=>	date ("Y-m-d H:i:s", $start + ($service->duration * 60 ) ),
              'note'		=>	$data['comment']."\n\n#LOCKME:{$lockme_id}"
            )
          );
        if(!$result){
          throw new Exception("Error saving to database");
        }
        $appointments->flush_cache();
        break;
      case "edit":
        $app = $wpdb->get_row(
          "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `note` LIKE '%#LOCKME:{$lockme_id}'"
        );
        if(!$app){
          throw new Exception('No appointment');
        }
        $result = $wpdb->update( $wpdb->prefix . 'app_appointments',
          array(
            'user'  =>  0,
            'name'		=>	$data['name'],
            'email'		=>	$data['email'],
            'phone'		=>	$data['phone'],
            'service'	=>	$service->ID,
            'worker'	=> 	$worker,
            'price'		=>	$data['price'],
            'status'	=>	$data['status'] ? 'paid' : 'pending',
            'start'		=>	date ("Y-m-d H:i:s", $start),
            'end'		=>	date ("Y-m-d H:i:s", $start + ($service->duration * 60 ) ),
            'note'		=>	$data['comment']."\n\n#LOCKME:{$lockme_id}"
          ),
          array(
            'ID'=>$app->ID
          )
        );
        if(!$result){
          throw new Exception("Error saving to database");
        }
        $appointments->flush_cache();
        break;
      case 'delete':
        $app = $wpdb->get_row(
          "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `note` LIKE '%#LOCKME:{$lockme_id}'"
        );
        if(!$app){
          throw new Exception('No appointment');
        }
        $result = $wpdb->update( $wpdb->prefix . 'app_appointments',
          array(
            'status'=>'removed'
          ),
          array(
            'ID'=>$app->ID
          )
        );
        if(!$result){
          throw new Exception("Error saving to database");
        }
        $appointments->flush_cache();
        break;
    }
  }

  static function ExportToLockMe(){
    global $appointments, $wpdb, $lockme;

    $sql = "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `start` > now() ORDER BY ID";
    $rows = $wpdb->get_results($sql);

    foreach($rows as $row){
      $m = array();
      preg_match('/^#LOCKME:(\d+)$/m', $row->note, $m);
      if(!$m[1]){
        self::AddEditReservation($row->ID);
      }
    }
  }
}
