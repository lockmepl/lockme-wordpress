<?php

class LockMe_em{
  static private $options;

  static public function Init(){
    global $wpdb;

    self::$options = get_option("lockme_em");

    if(self::$options['use']){
      add_action('em_booking_add', array('LockMe_em', 'AddEditHook'), 5, 3);
      add_filter('em_booking_delete', array('LockMe_em', 'Delete'), 5, 2);

      add_action('init', function(){
        if($_GET['em_export']){
          LockMe_em::ExportToLockMe();
          $_SESSION['em_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=em_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("events-manager/events-manager.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-em', 'lockme_em' );

    add_settings_section(
          'lockme_em_section',
          "Ustawienia wtyczki Event Manager",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Event Manager</p>';
          },
          'lockme-em');

    $options = self::$options;

    add_settings_field(
      "em_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_em[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-em',
      'lockme_em_section',
      array());

    if($options['use'] && $lockme->tab == 'em_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }
      $calendars = get_terms('event-categories','orderby=slug&hide_empty=0');

      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->term_id,
          "Pokój dla ".$calendar->name,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_em[calendar_'.$calendar->term_id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->term_id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-em',
          'lockme_em_section',
          array()
        );
      }
      add_settings_field(
        "export_em",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=em_plugin&em_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-em',
        'lockme_em_section',
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
//     $appt_id = 5951;
//     $timeslot = get_term_by('name', get_the_title($appt_id), 'event-categories');
//
//     var_dump($timeslot);

    if($_SESSION['em_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['em_export']);
    }
    settings_fields( 'lockme-em' );
    do_settings_sections( 'lockme-em' );
  }

  static public function Delete($result, $obj){
    global $lockme, $wpdb;

    if(!$result) return $result;

    $id = $obj->event_id;

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

    return $result;
  }

  static public function AddEditHook($EM_Event, $EM_Booking, $post_validation){
    global $wpdb, $lockme;
    if(!$post_validation || defined("LOCKME_MESSAGING")) return;

    $post_id = $EM_Event->post_id;
    self::AddEditReservation($post_id, $EM_Event->event_id);
  }

  static public function AddEditReservation($post_id, $event_id){
    global $wpdb, $lockme;

    $cat = get_term_by('name', get_the_title($post_id), 'event-categories');
    $cat_id = $cat->term_id;

    $meta = get_post_meta($post_id);

    $data = array(
      'date'=>date("Y-m-d", strtotime($meta['_event_start_date'][0])),
      'hour'=>date("H:i:s", strtotime($meta['_event_start_time'][0])),
      'people'=>0,
      'pricer'=>"API",
      'price'=>0,
      'email'=>'',
      'status'=>$meta['_event_status'][0],
      'extid'=>$event_id
    );

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation("ext/{$event_id}");
    }catch(Exception $e){
    }

    try{
      if(!$lockme_data){
        //Adding new reservation
        $data['roomid'] = self::$options['calendar_'.$cat_id];

        $api->AddReservation($data);

      }else{
        //Editing reservation

        $api->EditReservation("ext/{$post_id}", $data);
      }
    }catch(Exception $e){
    }
  }

  static private function GetCalendar($roomid){
    global $wpdb;

    $calendars = get_terms('event-categories','orderby=slug&hide_empty=0');
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->term_id] == $roomid){
        return $calendar->term_id;
      }
    }
    return null;
  }

  static public function GetMessage($message){
    global $wpdb, $lockme, $EM_Person;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $hour = date("H:i", strtotime($data['hour']));

    $calendar_id = self::GetCalendar($roomid);
    $calendar = get_term($calendar_id);
    $calname = $calendar->name;

    $form = array(
      "Nazwa Pokoju" => "LockMe",
      "Ilość graczy" => $data['people'],
      "Imię" => $data['name'].' '.$data['surname'],
      "Email" => $data['email'],
      "Telefon" => $data['phone'],
      "Wiadomość" => $data['comment']
    );
    $sql_data = json_encode($form);

    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".EM_EVENTS_TABLE." WHERE `event_start_date` = %s and `event_start_time` = %s and `event_name` = %s", $data['date'], $data['hour'], $calname), ARRAY_A);

    $EM_Person = new EM_Person();
    $EM_Person->user_email = $data['email'];

    switch($message["action"]){
      case "add":
        $EM_Booking = em_get_booking();
        $EM_Event = new EM_Event($event['event_id']);

        $EM_Booking->get_post();
        $EM_Booking->event_id = $EM_Event->event_id;
        $EM_Booking->booking_price = $data['price'];
        $EM_Booking->booking_comment = $data['comment'];
        $EM_Booking->booking_meta = array(
          "registration"=>array(
            "user_email"=>$data['email'],
            "user_name"=>"LockMe",
            "first_name"=>$data['name'],
            "last_name"=>$data['surname'],
            "phone"=>$data['phone']
          )
        );
        $EM_Booking->booking_status = 1;
        $EM_Booking->booking_spaces = 1;

        $post_validation = $EM_Booking->validate();

        ob_start();
        do_action('em_booking_add', $EM_Event, $EM_Booking, $post_validation);
        ob_end_clean();

        if($EM_Booking->save(false)){
          try{
            $api = $lockme->GetApi();
            $api->EditReservation($lockme_id, array("extid"=>$event['event_id']));
          }catch(Exception $e){
          }
        }
        break;
      case "edit":
        break;
      case 'delete':
        if($data["extid"]){
          $EM_Event = new EM_Event($event['event_id']);
          $EM_Bookings = $EM_Event->get_bookings();
          $booking_ids = array();
          foreach( $EM_Bookings->bookings as $EM_Booking ){
            $booking_ids[] = $EM_Booking->booking_id;
          }
          if( count($booking_ids) > 0 ){
            $result_tickets = $wpdb->query("DELETE FROM ". EM_TICKETS_BOOKINGS_TABLE ." WHERE booking_id IN (".implode(',',$booking_ids).");");
            $result = $wpdb->query("DELETE FROM ".EM_BOOKINGS_TABLE." WHERE booking_id IN (".implode(',',$booking_ids).")");
          }
          $post_id = $EM_Event->post_id;
          wp_set_object_terms($post_id, $calendar_id, 'event-categories');
        }
        break;
    }
  }

  static function ExportToLockMe(){
    global $wpdb, $lockme;
    set_time_limit(0);

    $rows = get_posts(array(
      'post_type' => 'event',
      'numberposts' => -1,
      'tax_query' => array(
        array(
          'taxonomy' => 'event-categories',
          'field' => 'id',
          'terms' => 6,
          'include_children' => false
        )
      )
    ));

    foreach($rows as $row){
      $event = new EM_Event($row->ID, "post_id");
      self::AddEditHook($event, null, true);
    }
  }
}
