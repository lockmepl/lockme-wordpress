<?php

class LockMe_birchschedule{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_birchschedule");

    if(self::$options['use']){
      add_action('save_post', array('LockMe_birchschedule', 'AddEditReservation'), 5);
      add_action('transition_post_status', array('LockMe_birchschedule', 'AddEditReservation'), 10, 3 );
      add_action('before_delete_post', array('LockMe_birchschedule', 'Delete'));
      add_action('edit_post', array('LockMe_birchschedule', 'AddEditReservation'));

      add_action('init', function(){
        if($_GET['birchschedule_export']){
          LockMe_birchschedule::ExportToLockMe();
          $_SESSION['birchschedule_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=birchschedule_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("birchschedule/birchschedule.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-birchschedule', 'lockme_birchschedule' );

    add_settings_section(
          'lockme_birchschedule_section',
          "Ustawienia wtyczki birchschedule",
          function(){
            echo '<p>Ustawienia integracji z wtyczką birchschedule</p>';
          },
          'lockme-birchschedule');

    $options = self::$options;

    add_settings_field(
      "birchschedule_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_birchschedule[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-birchschedule',
      'lockme_birchschedule_section',
      array());

    if($options['use'] && $lockme->tab == 'birchschedule_plugin'){

      add_settings_field(
        "location",
        "Lokacja",
        function() use($options){
          echo '<select name="lockme_birchschedule[location]">';
          echo '<option value="">--wybierz--</option>';
          $args = array(
            'post_type' => 'birs_location'
          );
          $loop = new WP_Query( $args );
          while ( $loop->have_posts() ){
            $loop->the_post();
            $location = $loop->post;
            echo '<option value="'.$location->ID.'" '.selected(1, $location->ID==$options['location'], false).'>'.$location->post_title.'</options>';
          }
          echo '</select>';
        },
        'lockme-birchschedule',
        'lockme_birchschedule_section',
        array());

      add_settings_field(
        "staff",
        "Provider",
        function() use($options){
          echo '<select name="lockme_birchschedule[staff]">';
          echo '<option value="">--wybierz--</option>';
          $args = array(
            'post_type' => 'birs_staff'
          );
          $loop = new WP_Query( $args );
          while ( $loop->have_posts() ){
            $loop->the_post();
            $location = $loop->post;
            echo '<option value="'.$location->ID.'" '.selected(1, $location->ID==$options['staff'], false).'>'.$location->post_title.'</options>';
          }
          echo '</select>';
        },
        'lockme-birchschedule',
        'lockme_birchschedule_section',
        array());

      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $args = array(
        'post_type' => 'birs_service'
      );
      $loop = new WP_Query( $args );
      while ( $loop->have_posts() ){
        $loop->the_post();
        $calendar = $loop->post;
        add_settings_field(
          "calendar_".$calendar->ID,
          "Pokój dla ".$calendar->post_title,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_birchschedule[calendar_'.$calendar->ID.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->ID], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-birchschedule',
          'lockme_birchschedule_section',
          array()
        );
      }
      add_settings_field(
        "export_birchschedule",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=birchschedule_plugin&birchschedule_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-birchschedule',
        'lockme_birchschedule_section',
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

    if($_SESSION['birchschedule_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['birchschedule_export']);
    }
    settings_fields( 'lockme-birchschedule' );
    do_settings_sections( 'lockme-birchschedule' );
  }

  static private function AppData($res){
    $meta = get_post_meta($res->ID);

    $appid = $meta['_birs_appointment_id'][0];
    $clientid = $meta['_birs_client_id'][0];

    $dtz = new DateTimeZone('Europe/Warsaw');
    $time = new DateTime('now', $dtz);
    $offset = $dtz->getOffset( $time );

    $cal = get_post_meta($appid, '_birs_appointment_service', true);
    $tiemstamp = get_post_meta($appid, '_birs_appointment_timestamp',true)+$offset;

    $name = get_post_meta($clientid, '_birs_client_name_first',true);
    $surname = get_post_meta($clientid, "_birs_client_name_last", true);
    $email = get_post_meta($clientid, "_birs_client_email", true);
    $phone = get_post_meta($clientid, "_birs_client_phone", true);

    return array(
      'roomid'=>self::$options['calendar_'.$cal],
      'date'=>gmdate('Y-m-d', $tiemstamp),
      'hour'=>gmdate("H:i:s", $tiemstamp),
      'name'=>$name,
      'surname'=>$surname,
      'pricer'=>"API",
      'email'=>$email,
      'phone'=>$phone,
      'status'=>in_array($res->post_status, array('publish','future')) ? 1 : 0,
      'extid'=>$res->ID
    );
  }

  static public function AddEditReservation($id){
    global $lockme;
    if(!is_numeric($id)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $type = get_post_type($id);

    if($type && (get_post_type($id) != 'birs_appointment1on1')){
      return;
    }

    $post = get_post($id);

    if(!$post || in_array(get_post_status($id),['trash', 'cancelled'])){
      return self::Delete($id);
    }

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/{$id}");
    }catch(Exception $e){
    }

    try{
      if(!$lockme_data){ //Add new
        $id = $api->AddReservation(self::AppData($post));
      }else{ //Update
        $api->EditReservation("ext/{$id}", self::AppData($post));
      }
    }catch(Exception $e){
    }
  }

  static public function Delete($id){
    global $lockme;

    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $api = $lockme->GetApi();

    try{
      $api->DeleteReservation("ext/{$id}");
    }catch(Exception $e){
    }
  }

  static private function GetCalendar($roomid){
    $args = array(
      'post_type' => 'birs_service'
    );
    $loop = new WP_Query( $args );
    while ( $loop->have_posts() ){
      $loop->the_post();
      $calendar = $loop->post;
      if(self::$options["calendar_".$calendar->ID] == $roomid){
        return $calendar->ID;
      }
    }
    return null;
  }

  static public function GetMessage($message){
    global $wpdb, $lockme, $birchschedule;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $dtz = new DateTimeZone('Europe/Warsaw');
    $time = new DateTime('now', $dtz);
    $offset = $dtz->getOffset( $time );

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $timestamp = strtotime("{$data['date']} {$data['hour']}")-$offset;

    $calendar_id = self::GetCalendar($roomid);

    switch($message["action"]){
      case "add":
				//Client
				$clientid = wp_insert_post([
          'post_title'=>"{$data['name']} {$data['surname']} (LockMe)",
          'post_status'=>'publish',
          'post_type'=>'birs_client'
				]);
				update_post_meta($clientid, '_birs_client_name_first', $data['name']);
				update_post_meta($clientid, '_birs_client_name_last', $data['surname']);
				update_post_meta($clientid, '_birs_client_email', $data['email']);
				update_post_meta($clientid, '_birs_client_phone', $data['phone']);

				//Appointment
				$appid = wp_insert_post([
          'post_title'=>'LockMe',
          'post_status'=>'publish',
          'post_type'=>'birs_appointment'
        ]);
				update_post_meta($appid, '_birs_appointment_service', $calendar_id);
				update_post_meta($appid, '_birs_appointment_staff', self::$options['staff']);
				update_post_meta($appid, '_birs_appointment_location', self::$options['location']);
				update_post_meta($appid, '_birs_appointment_timestamp', $timestamp);
				update_post_meta($appid, '_birs_appointment_uid', uniqid(rand()));
				update_post_meta($appid, '_birs_appointment_duration', $birchschedule->model->get_service_length($calendar_id));
				update_post_meta($appid, '_birs_appointment_padding_before', $birchschedule->model->get_service_padding_before($calendar_id));
				update_post_meta($appid, '_birs_appointment_padding_after', $birchschedule->model->get_service_padding_after($calendar_id));
				update_post_meta($appid, '_birs_appointment_capacity', $birchschedule->model->get_service_capacity($calendar_id));

				//Appointment1on1
				$resid = wp_insert_post([
          'post_title'=>'LockMe',
          'post_status'=>'publish',
          'post_type'=>'birs_appointment1on1'
				]);
				update_post_meta($resid, '_birs_appointment_id', $appid);
				update_post_meta($resid, '_birs_client_id', $clientid);
				update_post_meta($resid, '_birs_appointment1on1_payment_status', $data['status'] ? 'paid' : 'not-paid');
				update_post_meta($resid, '_birs_appointment1on1_price', $data['price']);
				update_post_meta($resid, '_birs_appointment1on1_uid', uniqid(rand()));
				update_post_meta($resid, '_birs_appointment_notes', $data['comment']);

        try{
          $api = $lockme->GetApi();
          $api->EditReservation($lockme_id, array("extid"=>$resid));
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['extid']){
          $resid = $data["extid"];
          $meta = get_post_meta($resid);
          $appid = $meta['_birs_appointment_id'][0];
          $clientid = $meta['_birs_client_id'][0];

          update_post_meta($clientid, '_birs_client_name_first', $data['name']);
          update_post_meta($clientid, '_birs_client_name_last', $data['surname']);
          update_post_meta($clientid, '_birs_client_email', $data['email']);
          update_post_meta($clientid, '_birs_client_phone', $data['phone']);

          update_post_meta($appid, '_birs_appointment_timestamp', $timestamp);

          update_post_meta($resid, '_birs_appointment1on1_payment_status', $data['status'] ? 'paid' : 'not-paid');
          update_post_meta($resid, '_birs_appointment1on1_price', $data['price']);
          update_post_meta($resid, '_birs_appointment_notes', $data['comment']);
        }
        break;
      case 'delete':
        if($data["extid"]){
          wp_update_post([
            'ID'=>$data["extid"],
            'post_status'=>'cancelled'
          ]);
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    $args = array(
      'post_type' => 'birs_appointment1on1',
    );
    $loop = new WP_Query( $args );
    while ( $loop->have_posts() ){
      $loop->the_post();
      $post = $loop->post;
      self::AddEditReservation($post->ID);
    }
  }
}
