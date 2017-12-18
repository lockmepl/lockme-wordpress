<?php

class LockMe_salon{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_salon");

    if(self::$options['use']){
      add_action('save_post', array('LockMe_salon', 'AddEditReservation'), 15);
      add_action('transition_post_status', array('LockMe_salon', 'AddEditReservation'), 15, 3 );
      add_action('before_delete_post', array('LockMe_salon', 'Delete'), 15);
      add_action('edit_post', array('LockMe_salon', 'AddEditReservation'), 15);

      add_action('init', function(){
        if($_GET['salon_export']){
          LockMe_salon::ExportToLockMe();
          $_SESSION['salon_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=salon_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("salon-booking-plugin-3.0.1/salon.php") || is_plugin_active("salon-booking-plugin/salon.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-salon', 'lockme_salon' );

    add_settings_section(
          'lockme_salon_section',
          "Ustawienia wtyczki salon",
          function(){
            echo '<p>Ustawienia integracji z wtyczką salon</p>';
          },
          'lockme-salon');

    $options = self::$options;

    add_settings_field(
      "salon_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_salon[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-salon',
      'lockme_salon_section',
      array());

    if($options['use'] && $lockme->tab == 'salon_plugin'){

      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $args = array(
        'post_type' => SLN_Plugin::POST_TYPE_SERVICE
      );
      $calendars = get_posts($args);

      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->ID,
          "Pokój dla ".$calendar->post_title,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_salon[calendar_'.$calendar->ID.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->ID], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-salon',
          'lockme_salon_section',
          array()
        );
      }
      add_settings_field(
        "export_salon",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=salon_plugin&salon_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-salon',
        'lockme_salon_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    global $sln_plugin;
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
//     $booking = $sln_plugin->createBooking(3658);
//     var_dump($booking->getServicesIds());

    if($_SESSION['salon_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['salon_export']);
    }
    settings_fields( 'lockme-salon' );
    do_settings_sections( 'lockme-salon' );
  }

  static private function AppData($booking, $service){

    return array(
      'roomid'=>self::$options['calendar_'.$service->getId()],
      'date'=> $booking->getDate()->format("Y-m-d"),
      'hour'=>$booking->getTime()->format("H:i:s"),
      'name'=>$booking->getFirstname(),
      'surname'=>$booking->getLastname(),
      'pricer'=>"API",
      'email'=>$booking->getEmail(),
      'phone'=>$booking->getPhone(),
      'status'=>$booking->isNew() ? 1 : 0,
      'people'=>count($booking->getAttendantsIds()),
      'extid'=>$booking->getId()."-".$service->getId()
    );
  }

  static public function AddEditReservation($postid){
    global $lockme, $sln_plugin;
    if(!is_numeric($postid)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $type = get_post_type($postid);

    if($type && (get_post_type($postid) != SLN_Plugin::POST_TYPE_BOOKING)){
      return;
    }

    if(in_array(get_post_status($postid),['trash', 'sln-b-canceled'])){
      return self::Delete($postid);
    }

    $booking = $sln_plugin->createBooking($postid);

    $args = array(
      'post_type' => SLN_Plugin::POST_TYPE_SERVICE
    );
    $calendars = get_posts($args);

    $api = $lockme->GetApi();

    foreach($calendars as $post){
      $service = new SLN_Wrapper_Service($post);
      $id = $booking->getId()."-".$service->getId();
      $lockme_data = null;
      try{
        $lockme_data = $api->Reservation("ext/{$id}");
      }catch(Exception $e){};

      if(!$booking->hasService($service)){
        if($lockme_data){
          try{
            $api->DeleteReservation("ext/{$id}");
          }catch(Exception $e){
          }
        }
      }else{
        try{
          if(!$lockme_data){ //Add new
            $id = $api->AddReservation(self::AppData($booking, $service));
          }else{ //Update
            $api->EditReservation("ext/{$id}", self::AppData($booking, $service));
          }
        }catch(Exception $e){
        }
      }
    }
  }

  static public function Delete($postid){
    global $lockme, $sln_plugin;

    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $type = get_post_type($postid);

    if($type && (get_post_type($postid) != SLN_Plugin::POST_TYPE_BOOKING)){
      return;
    }

    $booking = $sln_plugin->createBooking($postid);
    $api = $lockme->GetApi();

    foreach($booking->getServicesIds() as $serviceid){
      $id = $booking->getId()."-".$serviceid;
      try{
        $api->DeleteReservation("ext/{$id}");
      }catch(Exception $e){
      }
    }
  }

  static private function GetCalendar($roomid){

    $args = array(
      'post_type' => SLN_Plugin::POST_TYPE_SERVICE
    );
    $calendars = get_posts($args);
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->ID] == $roomid){
        return $calendar->ID;
      }
    }
    return null;
  }

  static public function GetMessage($message){
    global $sln_plugin, $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $date = $data['date'];
    $hour = date("H:i:s", strtotime($data['hour']));

    $fields = array(
      'firstname' => $data['name'],
      'lastname'  => $data['surname'].' (LockMe)',
      'email'     => $data['email'],
      'phone'     => $data['phone'],
      'address'     => ''
    );

    $calendar_id = self::GetCalendar($roomid);

    switch($message["action"]){
      case "add":
        $builder = $sln_plugin->getBookingBuilder();

        $builder->setDate($date);
        $builder->setTime($hour);
        $builder->addService(new SLN_Wrapper_Service($calendar_id));

        foreach($fields as $field=>$value){
          $builder->set($field, $value);
        }

        try{
          $builder->create();
        }catch(Exception $e){}
        $booking = $builder->getLastBooking();

        if($booking){
          try{
            $api = $lockme->GetApi();
            $api->EditReservation($lockme_id, array("extid"=>$booking->getId().'-'.$calendar_id));
          }catch(Exception $e){
          }
        }
        break;
      case "edit":
        if($data['extid']){
          $id = explode('-', $data['extid']);
          $postid = $id[0];

          $booking = $sln_plugin->createBooking($postid);
          $booking->setMeta('date', $date);
          $booking->setMeta('time', $hour);
          foreach($fields as $field=>$value){
            $booking->setMeta($field, $value);
          }
        }
        break;
      case 'delete':
        if($data["extid"]){
          $id = explode('-', $data['extid']);
          wp_delete_post($id[0]);
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    $meta = '_'.SLN_Plugin::POST_TYPE_BOOKING.'_date';
    $args = array(
      'post_type' => SLN_Plugin::POST_TYPE_BOOKING,
      'orderby' => 'meta_value',
      'meta_key' => $meta,
      'posts_per_page' => -1
    );
    $loop = new WP_Query( $args );
    while ( $loop->have_posts() ){
      $loop->the_post();
      $post = $loop->post;
      if(strtotime(get_post_meta($post->ID, $meta, true)) >= strtotime("today")){
        self::AddEditReservation($post->ID);
      }
    }
  }
}
