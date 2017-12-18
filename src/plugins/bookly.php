<?php

class LockMe_bookly{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_bookly");
    if(self::$options['use']){
      add_filter('wp_die_ajax_handler', array('LockMe_bookly', 'Ajax'), 15, 1);

      add_action('init', function(){
        if($_GET['bookly_export']){
          LockMe_bookly::ExportToLockMe();
          $_SESSION['bookly_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=bookly_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("appointment-booking/main.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-bookly', 'lockme_bookly' );

    add_settings_section(
          'lockme_bookly_section',
          "Ustawienia wtyczki bookly",
          function(){
            echo '<p>Ustawienia integracji z wtyczką bookly</p>';
          },
          'lockme-bookly');

    $options = self::$options;

    add_settings_field(
      "bookly_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_bookly[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-bookly',
      'lockme_bookly_section',
      array());

    if($options['use'] && $lockme->tab == 'bookly_plugin'){

      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }

      $calendars = Bookly\Lib\Entities\Staff::query()->sortBy( 'position' )->fetchArray();
      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar['id'],
          "Pokój dla ".$calendar['full_name'],
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_bookly[calendar_'.$calendar['id'].']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar['id']], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-bookly',
          'lockme_bookly_section',
          array()
        );
      }
      add_settings_field(
        "export_bookly",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=bookly_plugin&bookly_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-bookly',
        'lockme_bookly_section',
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

    if($_SESSION['bookly_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['bookly_export']);
    }
    settings_fields( 'lockme-bookly' );
    do_settings_sections( 'lockme-bookly' );
  }

  static private function AppData($id){
    $info = Bookly\Lib\Entities\Appointment::query( 'a' )
              ->select( 'SUM( ca.number_of_persons ) AS total_number_of_persons, a.staff_id, a.service_id, a.start_date, a.end_date, a.internal_note' )
              ->leftJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
              ->where( 'a.id', $id )
              ->fetchRow();

    return array(
      'roomid'=>self::$options['calendar_'.$info['staff_id']],
      'date'=>date('Y-m-d', strtotime($info['start_date'])),
      'hour'=>date("H:i:s", strtotime($info['start_date'])),
      'pricer'=>"API",
      "status"=>1,
      'extid'=>$id
    );
  }

  static public function Ajax($msg){
    global $wpdb;

    $data = json_decode(ob_get_contents(), true);
    switch($_REQUEST['action']){
      case "bookly_save_appointment_form":
        self::AddEditReservation($data['data']['id']);
        break;
      case "bookly_delete_customer_appointments":
        self::DeleteBatch();
        break;
      case "bookly_render_complete":
        foreach((array)$_SESSION['bookly']['forms'][$_REQUEST['form_id']]['booking_numbers'] as $id){
          self::AddEditReservation($id);
        }
        break;
    }
    return $msg;
  }

  static public function AddEditReservation($id){
    global $lockme;
    if(!is_numeric($id)){
      return;
    }
    if(defined("LOCKME_MESSAGING")){
      return;
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
    }
  }

  static public function DeleteBatch(){
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

    $calendars = Bookly\Lib\Entities\Staff::query()->sortBy( 'position' )->fetchArray();
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar['id']] == $roomid){
        return $calendar['id'];
      }
    }
    return null;
  }

  static public function GetMessage($message){
    global $DOPBSP, $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $date = strtotime($data['date']);
    $timestamp = strtotime($data['date'].' '.$data['hour']);
    $name = $data['name'].' '.$data['surname'].' (LockMe)';

//     ini_set('display_errors', '1');

    $lm_datetimezone = new DateTimeZone("Europe/Warsaw");
    $lm_datetime = new DateTime($data['date'].' '.$data['hour'], $lm_datetimezone);
    $offset = $lm_datetimezone->getOffset($lm_datetime)/60;

    $calendar_id = self::GetCalendar($roomid);
    if(!$calendar_id){
      throw new Exception("No calendar");
    }
    $staff = new Bookly\Lib\Entities\Staff;
    $staff->load($calendar_id);
    $staff_id = $staff->get('id');
    $service = $staff->getStaffServices()[0]->service;
    $service_id = $service->get('id');

    switch($message["action"]){
      case "add":
        $customer = new Bookly\Lib\Entities\Customer();
        $customer->loadBy(array(
            'name'  => $name,
            'email' => $data['email']
        ));
        $customer->set('email', $data['email']);
        $customer->set('name',  $name);
        $customer->set('phone', $data['phone']);
        $customer->save();

        $appointment = new Bookly\Lib\Entities\Appointment();
        $appointment->loadBy(array(
          'service_id' => $service_id,
          'staff_id'   => $staff_id,
          'start_date' => date('Y-m-d H:i:s', $timestamp),
        ));
        if($appointment->isLoaded() == false){
            $appointment->set('service_id', $service_id);
            $appointment->set('staff_id',   $staff_id);
            $appointment->set('start_date', date( 'Y-m-d H:i:s', $timestamp));
            $appointment->set('end_date',   date( 'Y-m-d H:i:s', $timestamp+$service->get('duration')));
            $appointment->save();
        }

        $customer_appointment = new Bookly\Lib\Entities\CustomerAppointment();
        $customer_appointment->set( 'customer_id', $customer->get( 'id' ) )
            ->set( 'appointment_id',      $appointment->get( 'id' ) )
            ->set( 'location_id',         null )
            ->set( 'payment_id',          null )
            ->set( 'number_of_persons',   $data['people'] )
            ->set( 'extras',              '[]' )
            ->set( 'custom_fields',       '[]' )
            ->set( 'status',              Bookly\Lib\Entities\CustomerAppointment::STATUS_APPROVED )
            ->set( 'time_zone_offset',    $offset )
            ->save();

        $id = $appointment->get( 'id' );
        if(!$id){
          throw new Exception("Save error");
        }

        try{
          $api = $lockme->GetApi();
          $api->EditReservation($lockme_id, array("extid"=>$id));
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['extid']){
          $appointment = new Bookly\Lib\Entities\Appointment();
          $appointment->load($data['extid']);
          $appointment->set('start_date', date( 'Y-m-d H:i:s', $timestamp));
          $appointment->set('end_date',   date( 'Y-m-d H:i:s', $timestamp+$service->get('duration')));
          $appointment->save();

          $ca = $appointment->getCustomerAppointments();
          foreach($ca as $c){
            $c->set('time_zone_offset', $offset);
            $c->save();

            $customer = new Bookly\Lib\Entities\Customer();
            $customer->load($c->get('customer_id'));
            $customer->set('email', $data['email']);
            $customer->set('name',  $name);
            $customer->set('phone', $data['phone']);
            $customer->save();
          }
        }
        break;
      case 'delete':
        if($data["extid"]){
          $appointment = new Bookly\Lib\Entities\Appointment();
          $appointment->load($data['extid']);
          $appointment->delete();
        }
        break;
    }
  }

  static public function ExportToLockMe(){
    $bookings = Bookly\Lib\Entities\CustomerAppointment::query( 'ca' )
        ->select( '`a`.`id`,
            `a`.`staff_id`,
            `a`.`service_id`,
            `a`.`start_date`,
            DATE_ADD(`a`.`end_date`, INTERVAL `a`.`extras_duration` SECOND) AS `end_date`,
            `ca`.`extras`,
            COALESCE(`s`.`padding_left`,0) AS `padding_left`,
            COALESCE(`s`.`padding_right`,0) AS `padding_right`,
            SUM(`ca`.`number_of_persons`) AS `number_of_bookings`'
        )
        ->leftJoin( 'Appointment', 'a', '`a`.`id` = `ca`.`appointment_id`' )
        ->leftJoin( 'StaffService', 'ss', '`ss`.`staff_id` = `a`.`staff_id` AND `ss`.`service_id` = `a`.`service_id`' )
        ->leftJoin( 'Service', 's', '`s`.`id` = `a`.`service_id`' )
        ->whereGte( 'a.start_date', date("Y-m-d") )
        ->groupBy( 'a.id' )
        ->fetchArray();
    foreach($bookings as $b){
      self::AddEditReservation($b['id']);
    }
  }
}
