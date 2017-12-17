<?php

class LockMe_woo{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_woo");

    if(self::$options['use']){
      add_action('woocommerce_new_booking', array('LockMe_woo', 'AddEditReservation'), 5, 1);
      foreach(array('unpaid', 'pending-confirmation', 'confirmed', 'paid', 'complete', 'in-cart') as $action) {
        add_action('woocommerce_booking_'.$action, array('LockMe_woo', 'AddEditReservation'), 5, 1);
      }
      add_action('woocommerce_booking_cancelled', array('LockMe_woo', 'Delete'), 5, 1);
      add_action('woocommerce_booking_trash', array('LockMe_woo', 'Delete'), 5, 1);
      add_action('woocommerce_booking_was-in-cart', array('LockMe_woo', 'Delete'), 5, 1);
      add_action('before_delete_post', array('LockMe_woo', 'Delete'), 5, 1);

      add_action('init', function(){
        if($_GET['woo_export']){
          LockMe_woo::ExportToLockMe();
          $_SESSION['woo_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=woo_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("woocommerce-bookings/woocommmerce-bookings.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-woo', 'lockme_woo' );

    add_settings_section(
          'lockme_woo_section',
          "Ustawienia wtyczki Woocommerce Bookings",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Woocommerce Bookings</p>';
          },
          'lockme-woo');

    $options = self::$options;

    add_settings_field(
      "woo_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_woo[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-woo',
      'lockme_woo_section',
      array());

    if($options['use'] && $lockme->tab == 'woo_plugin'){
      add_settings_field(
        "slot_length",
        "Dłogośc slota (w min)",
        function() use($options){
          echo '<input name="lockme_woo[slot_length]" type="text" value="'.$options["slot_length"].'" />';
        },
        'lockme-woo',
        'lockme_woo_section',
        array());

      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $args = array(
        'post_type' => 'product'
      );
      $calendars = get_posts($args);

      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->ID,
          "Pokój dla ".$calendar->post_title,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_woo[calendar_'.$calendar->ID.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->ID], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-woo',
          'lockme_woo_section',
          array()
        );
      }
      add_settings_field(
        "export_woo",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=woo_plugin&woo_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-woo',
        'lockme_woo_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
//     $booking = new WC_Booking(2918);
//     var_dump(get_post_meta(2918));

    if($_SESSION['woo_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['woo_export']);
    }
    settings_fields( 'lockme-woo' );
    do_settings_sections( 'lockme-woo' );
  }

  static private function AppData($booking){

    return array(
      'roomid'=>self::$options['calendar_'.$booking->product_id],
      'date'=> date('Y-m-d', $booking->start),
      'hour'=>date("H:i:s", $booking->start),
      'pricer'=>"API",
      'price'=>$booking->cost,
      'status'=>$booking->status == 'in-cart' ? 0 : 1,
      'people'=>is_array($booking->persons) ? count($booking->persons) : $booking->persons,
      'extid'=>$booking->id
    );
  }

  static public function AddEditReservation($postid){
    global $lockme;
    if(!is_numeric($postid)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
    }
    clean_post_cache($postid);
    $booking = new WC_Booking($postid);
    if(!$booking->populated || $booking->post->post_type != 'wc_booking'){
      return;
    }

    if(in_array($booking->status, array('cancelled', 'trash', 'was-in-cart'))){
      return self::Delete($postid);
    }

    $api = $lockme->GetApi();
    $lockme_data = null;
    try{
      $lockme_data = $api->Reservation("ext/{$postid}");
    }catch(Exception $e){};

    try{
      if(!$lockme_data){ //Add new
        $api->AddReservation(self::AppData($booking));
      }else{ //Update
        $api->EditReservation("ext/{$postid}", self::AppData($booking));
      }
    }catch(Exception $e){
    }
  }

  static public function Delete($postid){
    global $lockme;

    if(defined("LOCKME_MESSAGING")){
      return;
    }
    clean_post_cache($postid);
    $booking = new WC_Booking($postid);
    if(!$booking->populated || $booking->post->post_type != 'wc_booking'){
      return;
    }

    if(!in_array($booking->status, array('cancelled', 'trash', 'was-in-cart'))){
      return self::AddEditReservation($postid);
    }

    $api = $lockme->GetApi();

    try{
      $api->DeleteReservation("ext/{$booking->id}");
    }catch(Exception $e){
    }
  }

  static private function GetCalendar($roomid){
    $args = array(
      'post_type' => 'product'
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
    global $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $date = $data['date'];
    $hour = date("H:i:s", strtotime($data['hour']));
    $start = strtotime($date.' '.$hour);

    $calendar_id = self::GetCalendar($roomid);

    switch($message["action"]){
      case "add":
        $booking = create_wc_booking(
          $calendar_id,
          array(
            'product_id' => $calendar_id,
            'start_date' => $start,
            'end_date' => $start + self::$options['slot_length']*60,
            'persons' => $data['people'],
            'cost' => $data['price']
          ),
          'pending-confirmation',
          true
        );

        if($booking){
          try{
            $api = $lockme->GetApi();
            $api->EditReservation($lockme_id, array("extid"=>$booking->id));
          }catch(Exception $e){
          }
        }else{
          throw new Exception("Saving error");
        }
        break;
      case "edit":
        if($data['extid']){
          $booking = new WC_Booking($data['extid']);
          if(!$booking->populated || $booking->post->post_type != 'wc_booking'){
            return;
          }

          if($booking->status != 'confirmed' && $data['status']){
            $booking->update_status('confirmed');
          }

          $meta_args = array(
            '_booking_persons'       => $data['people'],
            '_booking_cost'          => $data['price'],
            '_booking_start'         => date('YmdHis', $start),
            '_booking_end'           => date('YmdHis', $start + self::$options['slot_length']*60)
          );
          foreach($meta_args as $key=>$value) {
            update_post_meta($booking->id, $key, $value );
          }
        }
        break;
      case 'delete':
        if($data["extid"]){
          wp_delete_post($data["extid"]);
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    $args = array(
      'post_type' => 'wc_booking',
      'meta_key' => '_booking_start',
      'meta_value' => date('YmdHis'),
      'meta_compare' => '>=',
      'posts_per_page' => -1,
      'post_status' => 'any'
    );
    $loop = new WP_Query( $args );
    while ( $loop->have_posts() ){
      $loop->the_post();
      $post = $loop->post;
      self::AddEditReservation($post->ID);
    }
  }
}
