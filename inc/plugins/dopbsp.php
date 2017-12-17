<?php

class LockMe_dopbsp{
  static private $options;

  static public function Init(){
    self::$options = get_option("lockme_dopbsp");

    if(self::$options['use']){
      add_action('dopbsp_action_book_after', array('LockMe_dopbsp', 'AddReservation'), 5);
      add_action('woocommerce_payment_complete', array('LockMe_dopbsp', 'AddWooReservation'), 20, 1);
      add_action('woocommerce_thankyou', array('LockMe_dopbsp', 'AddWooReservation'), 20, 1);
      register_shutdown_function(array('LockMe_dopbsp', 'ShutDown'));

      add_action('init', function(){
        if($_GET['dopbsp_export']){
          LockMe_dopbsp::ExportToLockMe();
          $_SESSION['dopbsp_export'] = 1;
          wp_redirect("?page=lockme_integration&tab=dopbsp_plugin");
          exit;
        }else if($_GET['dopbsp_fix']){
          LockMe_dopbsp::FixSettings();
          $_SESSION['dopbsp_fix'] = 1;
          wp_redirect("?page=lockme_integration&tab=dopbsp_plugin");
          exit;
        }
      });
    }
  }

  static public function CheckDependencies(){
    return is_plugin_active("dopbsp/dopbsp.php") || is_plugin_active("booking-system/dopbs.php");
  }

  static public function RegisterSettings(LockMe_Plugin $lockme){
    global $wpdb, $DOPBSP;
    if(!self::CheckDependencies()) return false;

    register_setting( 'lockme-dopbsp', 'lockme_dopbsp' );

    add_settings_section(
          'lockme_dopbsp_section',
          "Ustawienia wtyczki Booking System PRO",
          function(){
            echo '<p>Ustawienia integracji z wtyczką Booking System PRO</p>';
          },
          'lockme-dopbsp');

    $options = self::$options;

    add_settings_field(
      "dopbsp_use",
      "Włącz integrację",
      function() use($options){
        echo '<input name="lockme_dopbsp[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
      },
      'lockme-dopbsp',
      'lockme_dopbsp_section',
      array());

    if($options['use'] && $lockme->tab == 'dopbsp_plugin'){
      $api = $lockme->GetApi();
      if($api){
        $rooms = $api->RoomList();
      }
      $calendars = $wpdb->get_results('SELECT * FROM '.$DOPBSP->tables->calendars.' ORDER BY id DESC');

      foreach($calendars as $calendar){
        add_settings_field(
          "calendar_".$calendar->id,
          "Pokój dla ".$calendar->name,
          function() use($options, $rooms, $calendar){
            echo '<select name="lockme_dopbsp[calendar_'.$calendar->id.']">';
            echo '<option value="">--wybierz--</option>';
            foreach($rooms as $room){
              echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
            }
            echo '</select>';
          },
          'lockme-dopbsp',
          'lockme_dopbsp_section',
          array()
        );
      }
      add_settings_field(
        "export_dopbsp",
        "Wyślij dane do LockMe",
        function(){
          echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
        },
        'lockme-dopbsp',
        'lockme_dopbsp_section',
        array()
      );
      add_settings_field(
        "fix_dopbsp",
        "Napraw ustawienia",
        function(){
          echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_fix=1" onclick="return confirm(\'Na pewno wykonać naprawę? Pamiętaj o backupie bazy danych! Nie ponosimy odpowiedzialności za skutki automatycznej naprawy!\');">Kliknij tutaj</a> aby naprawić ustawienia godzin BSP ("11:20-12:30" -> "11:20"). Ta operacja powinna być wykonywana tylko raz, <b>po uprzednim zbackupowaniu bazy danych!</b>';
        },
        'lockme-dopbsp',
        'lockme_dopbsp_section',
        array()
      );
    }
  }

  static public function DrawForm(LockMe_Plugin $lockme){
    global $wpdb, $DOPBSP;
    if(!self::CheckDependencies()){
      echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
      return;
    }
//     var_dump($DOPBSP->classes->backend_calendar_schedule->setApproved(1740));

    if($_SESSION['dopbsp_export']){
      echo '<div class="updated">';
      echo '  <p>Eksport został wykonany.</p>';
      echo '</div>';
      unset($_SESSION['dopbsp_export']);
    }else if($_SESSION['dopbsp_fix']){
      echo '<div class="updated">';
      echo '  <p>Ustawienia zostały naprawione. <b>Sprawdź działanie kalendarza i migrację ustawień!</b></p>';
      echo '</div>';
      unset($_SESSION['dopbsp_fix']);
    }
    settings_fields( 'lockme-dopbsp' );
    do_settings_sections( 'lockme-dopbsp' );
  }

  static private function AppData($res){

    return array(
      'roomid'=>self::$options['calendar_'.$res['calendar_id']],
      'date'=>date("Y-m-d", strtotime($res['check_in'])),
      'hour'=>date("H:i:s", strtotime($res['start_hour'])),
      'people'=>0,
      'pricer'=>"API",
      'price'=>$res['price'],
      'email'=>$res['email'],
      'status'=>in_array($res['status'], array('approved')),
      'extid'=>$res['id']
    );
  }

  static private function Add($res){
    global $DOPBSP, $lockme, $wpdb;

    if(in_array($res['status'], array('canceled','rejected'))){
      return;
    }

    $api = $lockme->GetApi();

    try{
      $id = $api->AddReservation(self::AppData($res));
    }catch(Exception $e){
    }
  }

  static private function Update($id, $res){
    global $DOPBSP, $lockme, $wpdb;

    $api = $lockme->GetApi();
    $appdata = self::AppData($res);

    try{
      $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }

    if(!$lockme_data){
      return self::Add($res);
    }
    if(in_array($res['status'], array('canceled','rejected'))){
      return self::Delete($id);
    }

    try{
      $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
    }catch(Exception $e){
    }
  }

  static private function Delete($id){
    global $DOPBSP, $lockme, $wpdb;

    $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `id` = %d", $id), ARRAY_A);
    if(!$data){
      return;
    }

    $api = $lockme->GetApi();
    $appdata = self::AppData($data);

    try{
      $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }

    if(!$lockme_data){
      return;
    }

    try{
      $api->DeleteReservation($appdata["roomid"], "ext/{$id}");
    }catch(Exception $e){
    }
  }

  static public function AddReservation(){
    global $wpdb, $DOPBSP, $lockme;

    $cart = $_POST['cart_data'];
    $calendar_id = $_POST['calendar_id'];

    foreach($cart as $reservation){
      $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d", $reservation['check_in'], $reservation['start_hour'], $calendar_id), ARRAY_A);
      foreach($data as $res){
        self::Add($res);
      }
    }
  }

  static public function AddWooReservation($order_id){
    global $wpdb, $DOPBSP, $lockme;
    $datas = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `transaction_id` = %d", $order_id), ARRAY_A);
    foreach ($datas as $data){
      if($data){
        self::AddEditReservation($data['id']);
      }
    }
  }

  static public function AddEditReservation($id){
    global $wpdb, $DOPBSP, $lockme;

    $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `id` = %d", $id), ARRAY_A);
    if(!$data){
      return;
    }

    self::Update($id, $data);
  }

  static public function ShutDown(){
    if (defined('DOING_AJAX') && DOING_AJAX) {
      switch($_POST['action']){
        case 'dopbsp_reservations_add_book':
          self::AddReservation();
          break;
        case 'dopbsp_reservation_reject':
        case 'dopbsp_reservation_delete':
        case 'dopbsp_reservation_cancel':
          $id = $_POST['reservation_id'];
          self::Delete($id);
          break;
        case 'dopbsp_reservation_approve':
          $id = $_POST['reservation_id'];
          self::AddEditReservation($id);
          break;
      }
    }
  }

  static private function GetCalendar($roomid){
    global $DOPBSP, $wpdb;

    $calendars = $wpdb->get_results('SELECT * FROM '.$DOPBSP->tables->calendars.' ORDER BY id DESC');
    foreach($calendars as $calendar){
      if(self::$options["calendar_".$calendar->id] == $roomid){
        return $calendar->id;
      }
    }
    throw new Exception("No calendar");
  }

  static public function GetMessage($message){
    global $DOPBSP, $wpdb, $lockme;
    if(!self::$options['use'] || !self::CheckDependencies()){
      return;
    }

    $data = $message["data"];
    $roomid = $message["roomid"];
    $lockme_id = $message["reservationid"];
    $hour = date("H:i", strtotime($data['hour']));

    $calendar_id = self::GetCalendar($roomid);

    $form = array(
      array(
        "id"=>"1",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Imię",
        "value"=>$data['name']
      ),
      array(
        "id"=>"2",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Nazwisko",
        "value"=>$data['surname']
      ),
      array(
        "id"=>"3",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Email",
        "value"=>$data['email']
      ),
      array(
        "id"=>"4",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Telefon",
        "value"=>$data['phone']
      ),
      array(
        "id"=>"5",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Dodatkowe uwagi",
        "value"=>$data['comment']
      ),
      array(
        "id"=>"6",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Źródło",
        "value"=>in_array($data['source'],array('panel','web','widget')) ? 'LockMe' : ''
      ),
      array(
        "id"=>"7",
        "is_email"=>"false",
        "add_to_day_hour_info"=>"false",
        "add_to_day_hour_body"=>"false",
        "translation"=>"Cena",
        "value"=>$data['price']
      )
    );

    switch($message["action"]){
      case "add":
        $day_data = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$DOPBSP->tables->days.' WHERE calendar_id=%d AND day="%s"',
                                                          $calendar_id, $data['date']));
        $day = json_decode($day_data->data);
        $history = array(
          $hour => $day->hours->$hour
        );
        $result = $wpdb->insert($DOPBSP->tables->reservations,
          array(
            'calendar_id' => $calendar_id,
            'language' => "pl",
            'currency' => "zł",
            'currency_code' => "PLN",
            'check_in' => $data['date'],
            'check_out' => "",
            'start_hour' => $hour,
            'end_hour' => "",
            'no_items' => 1,
            'price' => $data['price'],
            'price_total' => $data['price'],
            'extras' => '',
            'extras_price' => 0,
            'discount' => '{}',
            'discount_price' => 0,
            'coupon' => '{}',
            'coupon_price' => 0,
            'fees' => '{}',
            'fees_price' => 0,
            'deposit' => '{}',
            'deposit_price' => 0,
            'days_hours_history' =>json_encode($history),
            'form' => json_encode($form),
            'email' => $data['email'] ?: '',
            'status' => $data['status'] ? 'approved' : 'pending',
            'payment_method' => 'none',
            'token' => '',
            'transaction_id' => ''
          )
        );
        if(!$result){
          throw new Exception("Error saving to database - ".$wpdb->last_error);
        }
        $id = $wpdb->insert_id;
        $DOPBSP->classes->backend_calendar_schedule->setApproved($id);
        try{
          $api = $lockme->GetApi();
          $api->EditReservation($roomid, $lockme_id, array("extid"=>$id));
          return true;
        }catch(Exception $e){
        }
        break;
      case "edit":
        if($data['from_date'] && $data['from_hour'] && ($data['from_date'] != $data['date'] || $data['from_hour'] != $data['hour'])){
          $res = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d", $data['from_date'], date("H:i", strtotime($data['from_hour'])), $calendar_id)
          );
          $DOPBSP->classes->backend_calendar_schedule->setCanceled($res->id);

          $day_data = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$DOPBSP->tables->days.' WHERE calendar_id=%d AND day="%s"',
                                                          $calendar_id, $data['date']));
          $day = json_decode($day_data->data);
          $history = array(
            $hour => $day->hours->$hour
          );
          $result = $wpdb->update( $DOPBSP->tables->reservations,
            array(
              'check_in'=>$data['date'],
              'start_hour'=>$hour,
              'days_hours_history' =>json_encode($history)
            ),
            array(
              'id'=>$res->id
            )
          );
          if($result === false){
            throw new Exception("Error saving to database 1 ");
          }
          $DOPBSP->classes->backend_calendar_schedule->setApproved($res->id);
        }
        $res = $wpdb->get_row(
          $wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d", $data['date'], $hour, $calendar_id)
        );
        if(!$res){
          throw new Exception('No reservation');
        }
        $result = $wpdb->update( $DOPBSP->tables->reservations,
          array(
            'email' => $data['email'],
            'form' => json_encode($form),
            'price' => $data['price'],
            'price_total' => $data['price'],
            'status' => $data['status'] ? 'approved' : 'pending'
          ),
          array(
            'id'=>$res->id
          )
        );
        if($result === false){
          throw new Exception("Error saving to database 2 ");
        }
        return true;
        break;
      case 'delete':
        $res = $wpdb->get_row(
          $wpdb->prepare("SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d", $data['date'], $hour, $calendar_id)
        );
        if(!$res){
          throw new Exception('No reservation');
        }
        $result = $wpdb->update( $DOPBSP->tables->reservations,
          array(
            'status'=>'canceled'
          ),
          array(
            'id'=>$res->id
          )
        );
        if($result === false){
          throw new Exception("Error saving to database");
        }
        $DOPBSP->classes->backend_calendar_schedule->setCanceled($res->id);
        $wpdb->delete($DOPBSP->tables->reservations, array('id' => $res->id));
        return true;
        break;
    }
    return false;
  }

  static function ExportToLockMe(){
    global $DOPBSP, $wpdb, $lockme;
    set_time_limit(0);

    $sql = "SELECT * FROM ".$DOPBSP->tables->reservations." WHERE `check_in` >= curdate() ORDER BY ID";
    $rows = $wpdb->get_results($sql);

    foreach($rows as $row){
      self::AddEditReservation($row->id);
    }
  }

  static public function FixSettings(){
    global $DOPBSP, $wpdb, $lockme;

    $settings = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->settings_calendar} WHERE `unique_key` = 'hours_definitions' and (`value` like '%-%' or `value` like '%.%')", ARRAY_A);
    foreach($settings as $setting){
      $val = json_decode($setting['value'], true);
      foreach($val as &$v){
        $v['value'] = self::FixVal($v['value']);
      }
      unset($v);
      $wpdb->update($DOPBSP->tables->settings_calendar, array('value'=>json_encode($val)), array('id'=>$setting['id']));
    }

    $days = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->days} WHERE (`data` like '%-%' or `data` like '%.%')", ARRAY_A);
    foreach($days as $day){
      $data = json_decode($day['data'], true);
      foreach($data['hours_definitions'] as &$v){
        $v['value'] = self::FixVal($v['value']);
      }
      unset($v);
      $hours = array();
      foreach($data['hours'] as $k=>$v){
        $hours[self::FixVal($k)] = $v;
      }
      $data['hours'] = $hours;
      $wpdb->update($DOPBSP->tables->days, array('data'=>json_encode($data)), array('unique_key'=>$day['unique_key']));
    }

    $reses = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->reservations} WHERE (`days_hours_history` like '%-%' or `days_hours_history` like '%.%') or (`start_hour` like '%-%' or `start_hour` like '%.%')", ARRAY_A);
    foreach($reses as $res){
      $start_hour = self::FixVal($res['start_hour']);
      $history = json_decode($res['days_hours_history'], true);
      $hist = array();
      foreach($history as $k=>$v){
        $hist[self::FixVal($k)] = $v;
      }
      $wpdb->update($DOPBSP->tables->reservations, array('start_hour'=>$start_hour,'days_hours_history'=>json_encode($hist)), array('id'=>$res['id']));
    }
  }

  static private function FixVal($val){
    if(preg_match("#^\d\d\.\d\d$#", $val)){
      return strtr($val, array("."=>":"));
    }else if(preg_match("#^\d\d(\.|:)\d\d ?\-.*$#", $val)){
      $pos = mb_strpos($val, '-');
      if($pos === false){
        return $val;
      }
      return trim(strtr(mb_substr($val, 0, $pos), array("."=>":")));
    }
    return $val;
  }
}
