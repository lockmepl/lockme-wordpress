<?php

class LockMe_booked{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_booked");

    if(self::$options['use']){
      add_action('booked_new_appointment_created', array('LockMe_booked', 'AddEditReservation'), 5);
      add_action('transition_post_status', array('LockMe_booked', 'AddEditReservation'), 10, 3 );
      add_action('before_delete_post', array('LockMe_booked', 'Delete'));
      add_action('edit_post', array('LockMe_booked', 'AddEditReservation'));
      add_action('updated_post_meta', function($meta_id, $post_id){
        LockMe_booked::AddEditReservation($post_id);
      }, 10, 2);

      add_action('init', function(){
        if($_GET['booked_export']){
          LockMe_booked::ExportToLockMe();
          $_SESSION['booked_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=booked_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("booked/booked.php") || is_plugin_active("bookedall/booked.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-booked', 'lockme_booked' );

    add_settings_section(
          'lockme_booked_section',
          "Ustawienia wtyczki Booked",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Booked</p>';
          },
          'lockme-booked');

    $options = self::$options;

    add_settings_field(
      "booked_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_booked[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-booked',
      'lockme_booked_section',
      array());

    if($options['use'] && $lockme->tab == 'booked_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      add_settings_field(
        "calendar_default",
        "Pokój dla domyślnego kalendarza",
        function() use($options, $rooms){
          echo '<select name="lockme_booked[calendar_default]">';
          echo '<option value="">--wybierz--</option>';
          foreach($rooms as $room){
            echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_default'], false).'>'.$room['room'].' ('.$room['department'].')</options>';
          }
          echo '</select>';
        },
        'lockme-booked',
        'lockme_booked_section',
        array()
      );

      $calendars = get_terms('booked_custom_calendars','orderby=slug&hide_empty=0');
      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->term_id,
          "Pokój dla ".$calendar->name,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_booked[calendar_'.$calendar->term_id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->term_id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-booked',
          'lockme_booked_section',
          array()
        );
      }
      add_settings_field(
        "export_booked",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=booked_plugin&booked_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-booked',
        'lockme_booked_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    global $wpdb, $lockme;
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
    // $api = $lockme->GetApi();
    // $lockme_data = $api->Reservation(1365, "ext/10");
    // var_dump($lockme_data);
//     $appt_id = 1907;
//     $timeslot = get_post_meta($appt_id);
//
//     var_dump($timeslot);

    if($_SESSION['booked_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['booked_export']);
    }
    settings_fields( 'lockme-booked' );
    do_settings_sections( 'lockme-booked' );
  }

  static private function AppData($res){
    $cal = wp_get_object_terms($res->ID, 'booked_custom_calendars');
    $timeslot = explode('-', get_post_meta($res->ID, '_appointment_timeslot',true));
    $time = str_split($timeslot[0], 2);

    if($res->post_author){
      $user_info = get_userdata($res->post_author);
      $name = booked_get_name($res->post_author);
			$email = $user_info->user_email;
			$phone = get_user_meta($res->post_author, 'booked_phone', true);
    }
    $name = get_post_meta($res->ID, '_appointment_guest_name',true) ?: $name;
    $email = get_post_meta($res->ID, "_appointment_guest_email", true) ?: $email;

    return array(
      'roomid'=>self::$options['calendar_'.($cal[0] ? $cal[0]->term_id : 'default')],
      'date'=>date('Y-m-d', get_post_meta($res->ID, '_appointment_timestamp',true)),
      'hour'=>date("H:i:s", strtotime("{$time[0]}:{$time[1]}:00")),
      'name'=>$name,
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

    if($type && (get_post_type($id) != 'booked_appointments')){
      return;
    }

    $post = get_post($id);
    $appdata = self::AppData($post);

    if(!$post || get_post_status($id) == 'trash'){
      return self::Delete($id);
    }

    $api = $lockme->GetApi();

    try{
      $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }

    try{
      if(!$lockme_data){ //Add new
        $id = $api->AddReservation($appdata);
      }else{ //Update
        $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
      }
    }catch(Exception $e){
    }
  }

  static public function Delete($id){
    global $lockme;

    if(defined("LOCKME_MESSAGING")){
      return;
    }

    $post = get_post($id);
    $appdata = self::AppData($post);

    $api = $lockme->GetApi();

    try{
      $api->DeleteReservation($appdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }
  }

  static private function GetCalendar($roomid){

    $calendars = get_terms('booked_custom_calendars','orderby=slug&hide_empty=0');
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->term_id] == $roomid){
        return $calendar->term_id;
      }
    }
    return null;
  }

  static private function GetSlot($calendar_id, $date, $hour){
    $booked_defaults = get_option('booked_defaults_'.$calendar_id);
    if (!$booked_defaults){
      $booked_defaults = get_option('booked_defaults');
    }

		$day_name = date('D',$date);
    $formatted_date = date_i18n('Ymd',$date);
    if(function_exists("booked_apply_custom_timeslots_details_filter")){
      $booked_defaults = booked_apply_custom_timeslots_details_filter($booked_defaults,$calendar_id);
    }else if(function_exists("booked_apply_custom_timeslots_filter")){
      $booked_defaults = booked_apply_custom_timeslots_filter($booked_defaults,$calendar_id);
    }

    if (isset($booked_defaults[$formatted_date]) && !empty($booked_defaults[$formatted_date])){
			$todays_defaults = (is_array($booked_defaults[$formatted_date]) ? $booked_defaults[$formatted_date] : json_decode($booked_defaults[$formatted_date],true));
		}elseif (isset($booked_defaults[$formatted_date]) && empty($booked_defaults[$formatted_date])){
			$todays_defaults = false;
		}elseif (isset($booked_defaults[$day_name]) && !empty($booked_defaults[$day_name])){
			$todays_defaults = $booked_defaults[$day_name];
		}else{
			$todays_defaults = false;
		}

		$hour = date("Hi", strtotime($hour));
		foreach($todays_defaults as $h=>$cnt){
      if(preg_match("/^{$hour}/", $h)){
        return $h;
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

    $calendar_id = self::GetCalendar($roomid);
    $hour = self::GetSlot($calendar_id, $date, $data['hour']);
    if(!$hour){
      throw new Exception("No time slot");
    }
		$time_format = get_option('time_format');
		$date_format = get_option('date_format');

    $cf_meta_value = '';
    foreach(array("Żródło"=>"LockMe","Telefon"=>$data['phone'],"Ilość osób"=>$data['people'],"Cena"=>$data['price'],"Status"=>$data['status']?"Opłacone":"Rezerwacja (max. 20 minut)") as $label=>$value){
      $cf_meta_value .= '<p class="cf-meta-value"><strong>'.$label.'</strong><br>'.$value.'</p>';
    }

    switch($message["action"]){
      case "add":
				$post = apply_filters('booked_new_appointment_args', array(
					'post_title' => date_i18n($date_format,$date).' @ '.date_i18n($time_format,$date).' (User: Guest)',
					'post_content' => '',
					'post_status' => $data['status'] ? 'publish' : 'draft',
					'post_date' => date('Y',strtotime($date)).'-'.date('m',strtotime($date)).'-01 00:00:00',
					'post_type' => 'booked_appointments'
				));
        $row_id = wp_insert_post( $post );
        if(!$row_id || is_wp_error($row_id)){
          if(is_wp_error($row_id)){
            throw new Exception($row_id->get_error_message());
          }else{
            throw new Exception("Error saving to database: ".$wpdb->last_error);
          }
        }
				update_post_meta($row_id, '_appointment_guest_name', $data['name'].' '.$data['surname']);
				update_post_meta($row_id, '_appointment_guest_email', $data['email']);
				update_post_meta($row_id, '_appointment_timestamp', $date);
				update_post_meta($row_id, '_appointment_timeslot', $hour);
				update_post_meta($row_id, '_appointment_source', "LockMe");
				update_post_meta($row_id, '_cf_meta_value', $cf_meta_value);

        if (isset($calendar_id) && $calendar_id){
          wp_set_object_terms($row_id, $calendar_id, 'booked_custom_calendars');
        }

        try{
          $api = $lockme->GetApi();
          $api->EditReservation($roomid, $lockme_id, array("extid"=>$row_id));
          return true;
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['extid']){
          $row_id = $data["extid"];
          $post = apply_filters('booked_new_appointment_args', array(
            'ID' => $row_id,
            'post_title' => date_i18n($date_format,$date).' @ '.date_i18n($time_format,$date).' (User: Guest)',
            'post_content' => '',
            'post_status' => $data['status'] ? 'publish' : 'draft',
            'post_date' => date('Y',strtotime($date)).'-'.date('m',strtotime($date)).'-01 00:00:00',
            'post_type' => 'booked_appointments'
          ));
          wp_update_post($post, $wp_error);
          update_post_meta($row_id, '_appointment_guest_name', $data['name'].' '.$data['surname']);
          update_post_meta($row_id, '_appointment_guest_email', $data['email']);
          update_post_meta($row_id, '_appointment_timestamp', $date);
          update_post_meta($row_id, '_appointment_timeslot', $hour);
          if(get_post_meta($row_id, '_appointment_source', true) == 'LockMe'){
            update_post_meta($row_id, '_cf_meta_value', $cf_meta_value);
          }
          return true;
        }
        break;
      case 'delete':
        if($data["extid"]){
          wp_delete_post($data["extid"]);
          return true;
        }
        break;
    }
    return false;
  }

  static public function ExportToLockMe(){
    $args = array(
      'post_type' => 'booked_appointments',
      'orderby' => 'meta_value',
      'meta_key' => '_appointment_timestamp',
      'posts_per_page' => -1
    );
    $loop = new WP_Query( $args );
    while ( $loop->have_posts() ){
      $loop->the_post();
      $post = $loop->post;
      if(get_post_meta($post->ID, "_appointment_timestamp", true) >= strtotime("today")){
        self::AddEditReservation($post->ID);
      }
    }
  }
}
