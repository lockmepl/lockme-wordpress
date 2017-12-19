<?php

class LockMe_wpdevart{
  static private $options;
  static private $resdata;

  static public function Init(){
    global $wpdb;
    self::$options = get_option("lockme_wpdevart");

    if(self::$options['use']){
      if($_GET['page'] == "wpdevart-reservations" && is_admin() && $_POST['task']){
        if($_POST['id']){
          self::$resdata = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE `id`=%d', $_POST['id']), ARRAY_A);
        }
      }
      register_shutdown_function(array('LockMe_wpdevart', 'ShutDown'));

      add_action('init', function(){
        if($_GET['wpdevart_export']){
          LockMe_wpdevart::ExportToLockMe();
          $_SESSION['wpdevart_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=wpdevart_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("booking-calendar-pro/booking_calendar.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-wpdevart', 'lockme_wpdevart' );

    add_settings_section(
          'lockme_wpdevart_section',
          "Ustawienia wtyczki Booking Calendar Pro WpDevArt",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Booking Calendar Pro WpDevArt</p>';
          },
          'lockme-wpdevart');

    $options = self::$options;

    add_settings_field(
      "wpdevart_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_wpdevart[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-wpdevart',
      'lockme_wpdevart_section',
      array());

    if($options['use'] && $lockme->tab == 'wpdevart_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $query = "SELECT * FROM " . $wpdb->prefix . "wpdevart_calendars ";
      $calendars = $wpdb->get_results($query);
      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->id,
          "Pokój dla ".$calendar->title,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_wpdevart[calendar_'.$calendar->id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-wpdevart',
          'lockme_wpdevart_section',
          array()
        );
      }
      add_settings_field(
        "export_wpdevart",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=wpdevart_plugin&wpdevart_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-wpdevart',
        'lockme_wpdevart_section',
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

    if($_SESSION['wpdevart_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['wpdevart_export']);
    }
    settings_fields( 'lockme-wpdevart' );
    do_settings_sections( 'lockme-wpdevart' );
  }

  static private function AppData($res){

    return array(
      'roomid'=>self::$options['calendar_'.$res['calendar_id']],
      'date'=>date('Y-m-d', strtotime($res['single_day'])),
      'hour'=>date("H:i:s", strtotime($res['start_hour'])),
      'pricer'=>"API",
      'email'=>$res['email'],
      'status'=>$res["status"] == "approved" ? 1 : 0,
      'extid'=>$res['id'],
      'price'=>$res["price"]
    );
  }

  static public function AddEditReservation($res){
    global $lockme;
    if(!is_array($res)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $api = $lockme->GetApi();
    $id = $res['id'];
    $resdata = self::AppData($res);

    try{
      $lockme_data = $api->Reservation($resdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }

    try{
      if(!$lockme_data){ //Add new
        $id = $api->AddReservation($resdata);
      }else{ //Update
        $api->EditReservation($resdata["roomid"], "ext/{$id}", $resdata);
      }
    }catch(Exception $e){
    }
  }

  static public function Delete($res){
    global $lockme;

    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $api = $lockme->GetApi();
    $id = $res['id'];
    $resdata = self::AppData($res);

    try{
      $api->DeleteReservation($resdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }
  }

  static public function ShutDown(){
    global $wpdb;
    if($_GET['page'] == "wpdevart-reservations" && is_admin() && $_POST['task']){
      switch($_POST['task']){
        case "approve":
          self::AddEditReservation(self::$resdata);
          break;
        case "canceled":
        case "delete":
          self::Delete(self::$resdata);
          break;
      }
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
      switch($_POST['action']){
        // case 'wpdevart_form_ajax':
        //   $data = json_decode(stripcslashes($_POST['wpdevart_data']),true);
        //   $res = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE single_day="%s" and start_hour="%s" and calendar_id=%d', $data['wpdevart_single_day1'], $data['wpdevart_form_hour1'], $_POST['wpdevart_id']),ARRAY_A);
        //   self::AddEditReservation($res);
        //   break;
      }
    }
  }

  static private function GetCalendar($roomid){
    global $wpdb;
    $query = "SELECT * FROM " . $wpdb->prefix . "wpdevart_calendars ";
    $calendars = $wpdb->get_results($query);
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
    $date = $data['date'];
    $hour = date("H:i", strtotime($data['hour']));

    $calendar_id = self::GetCalendar($roomid);
    if(!$calendar_id){
      throw new Exception("No calendar");
    }

    $cf_meta_value = '';
    foreach(array("Żródło"=>"LockMe","Telefon"=>$data['phone'],"Ilość osób"=>$data['people'],"Cena"=>$data['price'],"Status"=>$data['status']?"Opłacone":"Rezerwacja (max. 20 minut)") as $label=>$value){
      $cf_meta_value .= '<p class="cf-meta-value"><strong>'.$label.'</strong><br>'.$value.'</p>';
    }

    switch($message["action"]){
      case "add":
        $result = $wpdb->insert($wpdb->prefix.'wpdevart_reservations', array(
          'calendar_id' => $calendar_id,
          'single_day' => $date,
          'start_hour' => $hour,
          'count_item' => 1,
          'price' => $data['price'],
          'total_price' => $data['price'],
          'form' => array(),
          'email' => $data['email'],
          'status' => 'approved',
          'date_created' => date('Y-m-d H:i',time()),
          'is_new' => 0
        ));
        if(!$result){
          throw new Exception("Error saving to database - ".$wpdb->last_error);
        }

        $id = $wpdb->insert_id;

        $res = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE `id`=%d', $id), ARRAY_A);

        $main = new wpdevart_Main;
    		$theme_model = new wpdevart_bc_ModelThemes();
    		$calendar_model = new wpdevart_bc_ModelCalendars();
    		$form_model = new wpdevart_bc_ModelForms();
    		$extra_model = new wpdevart_bc_ModelExtras();
        $ids = $calendar_model->get_ids($calendar_id);
        $theme_option = $theme_model->get_setting_rows($ids["theme_id"]);
    		$calendar_data = $calendar_model->get_db_days_data($calendar_id);
    		$calendar_title = $calendar_model->get_calendar_rows($calendar_id);
    		$calendar_title = $calendar_title["title"];
    		$extra_field = $extra_model->get_extra_rows($ids["extra_id"]);
    		$form_option = $form_model->get_form_rows($ids["form_id"]);
        if(isset($theme_option)){
    			$theme_option = json_decode($theme_option->value, true);
    		} else {
    			$theme_option = array();
    		}
        $wpdevart_booking = new wpdevart_bc_BookingCalendar($date, $res, $calendar_id, $theme_option, $calendar_data, $form_option, $extra_field, array(), false, array(), $calendar_title);

        $reflector = new ReflectionObject($wpdevart_booking);
        $method = $reflector->getMethod('change_date_avail_count');
        $method->setAccessible(true);
        $method->invoke($wpdevart_booking, $id, true, "insert", array());

        try{
          $api = $lockme->GetApi();
          $api->EditReservation($roomid, $lockme_id, array("extid"=>$id));
          return true;
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['extid']){
          $row_id = $data["extid"];

          $old_reserv = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE `id`=%d', $row_id), ARRAY_A);

          $result = $wpdb->update($wpdb->prefix.'wpdevart_reservations', array(
            'calendar_id' => $calendar_id,
            'single_day' => $date,
            'start_hour' => $hour,
            'count_item' => 1,
            'price' => $data['price'],
            'total_price' => $data['price'],
            'form' => array(),
            'email' => $data['email'],
            'status' => 'approved',
            'date_created' => date('Y-m-d H:i',time()),
            'is_new' => 0
          ), array('id' => $row_id));

          $main = new wpdevart_Main;
      		$theme_model = new wpdevart_bc_ModelThemes();
      		$calendar_model = new wpdevart_bc_ModelCalendars();
      		$form_model = new wpdevart_bc_ModelForms();
      		$extra_model = new wpdevart_bc_ModelExtras();
          $ids = $calendar_model->get_ids($calendar_id);
          $theme_option = $theme_model->get_setting_rows($ids["theme_id"]);
      		$calendar_data = $calendar_model->get_db_days_data($calendar_id);
      		$calendar_title = $calendar_model->get_calendar_rows($calendar_id);
      		$calendar_title = $calendar_title["title"];
      		$extra_field = $extra_model->get_extra_rows($ids["extra_id"]);
      		$form_option = $form_model->get_form_rows($ids["form_id"]);
          if(isset($theme_option)){
      			$theme_option = json_decode($theme_option->value, true);
      		} else {
      			$theme_option = array();
      		}
          $wpdevart_booking = new wpdevart_bc_BookingCalendar($date, $old_reserv, $calendar_id, $theme_option, $calendar_data, $form_option, $extra_field, array(), false, array(), $calendar_title);

          $reflector = new ReflectionObject($wpdevart_booking);
          $method = $reflector->getMethod('change_date_avail_count');
          $method->setAccessible(true);
          $method->invoke($wpdevart_booking, $row_id, true, "update", $old_reserv);

          return true;
        }
        break;
      case 'delete':
        if($data["extid"]){
          $row_id = $data["extid"];

          $old_reserv = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'wpdevart_reservations WHERE `id`=%d', $row_id), ARRAY_A);

          $delete_res = $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'wpdevart_reservations WHERE id="%d"', $row_id));

          require_once(WPDEVART_PLUGIN_DIR . 'admin/controllers/Reservations.php');

          $wpdevart_booking = new wpdevart_bc_ControllerReservations();
          $reflector = new ReflectionObject($wpdevart_booking);
          $method = $reflector->getMethod('change_date_avail_count');
          $method->setAccessible(true);
          $method->invoke($wpdevart_booking, $row_id, false, $old_reserv);

          return true;
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    global $wpdb;
    set_time_limit(0);

    $sql = "SELECT * FROM {$wpdb->prefix}wpdevart_reservations WHERE `single_day` >= curdate() ORDER BY ID";
    $rows = $wpdb->get_results($sql, ARRAY_A);

    foreach($rows as $row){
      self::AddEditReservation($row);
    }
  }
}
