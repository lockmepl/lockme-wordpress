<?php

class LockMe_easyapp
{
    private static $options;
    private static $models;
    private static $resdata;

    public static function Init()
    {
        global $wpdb;
        self::$options = get_option("lockme_easyapp");

        if (self::$options['use'] && self::CheckDependencies()) {
            self::$models = new EADBModels($wpdb, new EATableColumns);

            if ($_GET['action'] == "ea_appointment" && $_GET['id']) {
                self::$resdata = self::AppData($_GET['id']);
            }

            register_shutdown_function(array('LockMe_easyapp', 'ShutDown'));

            add_action('init', function () {
                if ($_GET['easyapp_export']) {
                    LockMe_easyapp::ExportToLockMe();
                    $_SESSION['easyapp_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=easyapp_plugin");
                    exit;
                }
            });
        }
    }

    public static function CheckDependencies()
    {
        return is_plugin_active("easy-appointments/main.php");
    }

    public static function RegisterSettings(LockMe_Plugin $lockme)
    {
        global $wpdb;
        if (!self::CheckDependencies()) {
            return false;
        }

        register_setting('lockme-easyapp', 'lockme_easyapp');

        add_settings_section(
          'lockme_easyapp_section',
          "Ustawienia wtyczki Easy Appointments",
          function () {
              echo '<p>Ustawienia integracji z wtyczką Easy Appointments</p>';
          },
          'lockme-easyapp'
        );

        $options = self::$options;

        add_settings_field(
          "easyapp_use",
          "Włącz integrację",
          function () use ($options) {
              echo '<input name="lockme_easyapp[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
          },
          'lockme-easyapp',
          'lockme_easyapp_section',
          array()
        );

        if ($options['use'] && $lockme->tab == 'easyapp_plugin') {
            $api = $lockme->GetApi();
            if ($api) {
                $rooms = $api->RoomList();
            }
            $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');

            foreach ($calendars as $calendar) {
                add_settings_field(
                  "calendar_".$calendar->id,
                  "Pokój dla ".$calendar->name,
                  function () use ($options, $rooms, $calendar) {
                      echo '<select name="lockme_easyapp[calendar_'.$calendar->id.']">';
                      echo '<option value="">--wybierz--</option>';
                      foreach ($rooms as $room) {
                          echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
                      }
                      echo '</select>';
                  },
                  'lockme-easyapp',
                  'lockme_easyapp_section',
                  array()
                );
            }
            add_settings_field(
                "export_easyapp",
                "Wyślij dane do LockMe",
                function () {
                    echo '<a href="?page=lockme_integration&tab=easyapp_plugin&easyapp_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-easyapp',
                'lockme_easyapp_section',
                array()
            );
        }
    }

    public static function DrawForm(LockMe_Plugin $lockme)
    {
        global $wpdb;
        if (!self::CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }

        if ($_SESSION['easyapp_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['easyapp_export']);
        } elseif ($_SESSION['easyapp_fix']) {
            echo '<div class="updated">';
            echo '  <p>Ustawienia zostały naprawione. <b>Sprawdź działanie kalendarza i migrację ustawień!</b></p>';
            echo '</div>';
            unset($_SESSION['easyapp_fix']);
        }
        settings_fields('lockme-easyapp');
        do_settings_sections('lockme-easyapp');
    }

    private static function AppData($resid)
    {
        $res = self::$models->get_appintment_by_id($resid);

        return array(
            'roomid'=>self::$options['calendar_'.$res['location'].'_'.$res['service'].'_'.$res['worker']],
            'date'=>date("Y-m-d", strtotime($res['date'])),
            'hour'=>date("H:i:s", strtotime($res['start'])),
            'people'=>0,
            'pricer'=>"API",
            'price'=>$res['price'],
            'name'=>$res['name'],
            'email'=>$res['email'],
            'phone'=>$res['phone'],
            'status'=>in_array($res['status'], array('confirmed')),
            'extid'=>$res['id']
        );
    }

    private static function Add($res)
    {
        global $lockme, $wpdb;

        if (in_array($res['status'], array('canceled','abandoned'))) {
            return;
        }

        $api = $lockme->GetApi();

        try {
            $id = $api->AddReservation(self::AppData($res['id']));
        } catch (Exception $e) {
        }
    }

    private static function Update($id, $res)
    {
        global $lockme, $wpdb;

        $api = $lockme->GetApi();
        $appdata = self::AppData($res['id']);

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return self::Add($res);
        }
        if (in_array($res['status'], array('canceled','abandoned'))) {
            return self::Delete($id);
        }

        try {
            $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
    }

    private static function Delete($resid)
    {
        global $lockme, $wpdb;

        $res = self::$models->get_appintment_by_id($resid);
        if (!$res) {
            return;
        }

        $api = $lockme->GetApi();
        $appdata = self::AppData($res['id']);

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$resid}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return;
        }

        try {
            $api->DeleteReservation($appdata["roomid"], "ext/{$resid}");
        } catch (Exception $e) {
        }
    }

    public static function AddEditReservation($id)
    {
        global $wpdb, $lockme;

        $data = self::$models->get_appintment_by_id($id);
        if (!$data) {
            return;
        }

        self::Update($id, $data);
    }

    public static function ShutDown()
    {
        global $wpdb, $lockme;
        if (defined('DOING_AJAX') && DOING_AJAX) {
            switch ($_GET['action']) {
                case 'ea_res_appointment':
                case 'nopriv_ea_res_appointment':
                    $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s", $_GET['location'], $_GET['service'], $_GET['worker'], $_GET['date'], date("H:i:s", strtotime($_GET['start']))), ARRAY_A);
                    if ($res) {
                        self::Add($res);
                    }
                    break;
                case 'ea_cancel_appointment':
                case 'nopriv_ea_cancel_appointment':
                    self::Delete($_GET['id']);
                    break;
                case 'ea_appointment':
                    $api = $lockme->GetApi();
                    switch ($_GET['_method']) {
                        case 'PUT':
                            self::AddEditReservation($_GET['id']);
                            break;
                        case 'DELETE':
                            try {
                                $api->DeleteReservation(self::$resdata["roomid"], "ext/{$_GET['id']}");
                            } catch (Exception $e) {
                            }
                            break;
                        default:
                            $input = json_decode(file_get_contents('php://input'), true);
                            if (!$_GET['_method'] && $input['date']) {
                                $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s", $input['location'], $input['service'], $input['worker'], $input['date'], date("H:i:s", strtotime($input['start']))), ARRAY_A);
                                if ($res) {
                                    self::Add($res);
                                }
                            }
                    }
                    break;
            }
        }
    }

    private static function GetCalendar($roomid)
    {
        global $wpdb;

        $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');
        foreach ($calendars as $calendar) {
            if (self::$options["calendar_".$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        throw new Exception("No calendar");
    }

    public static function GetMessage($message)
    {
        global $wpdb, $lockme;
        if (!self::$options['use'] || !self::CheckDependencies()) {
            return;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];

        $calendar_id = explode('_', self::GetCalendar($roomid));
        $location = $calendar_id[0];
        $service = $calendar_id[1];
        $worker = $calendar_id[2];

        switch ($message["action"]) {
            case "add":
                $s = self::$models->get_row('ea_services', $service);
                $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                $id = self::$models->replace('ea_appointments', [
                    'location'=>$location,
                    'service'=>$service,
                    'worker'=>$worker,
                    'name'=>$data['name'],
                    'email'=>$data['email'],
                    'phone'=>$data['phone'],
                    'date'=>$data['date'],
                    'start'=>$data['hour'],
                    'end_date'=>$data['date'],
                    'end'=>date('H:i:s', $end_time),
                    'price'=>$data['price'],
                    'description'=>"LOCKME!",
                    'status'=>$data['status'] ? 'confirmed' : 'pending'
                ], true);
                try {
                    $api = $lockme->GetApi();
                    $api->EditReservation($roomid, $lockme_id, array("extid"=>$id->id));
                    return true;
                } catch (Exception $e) {
                }
                break;
            case "edit":
                if ($data["extid"]) {
                    $s = self::$models->get_row('ea_services', $service);
                    $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                    $id = self::$models->replace('ea_appointments', [
                        'id'=>$data["extid"],
                        'location'=>$location,
                        'service'=>$service,
                        'worker'=>$worker,
                        'name'=>$data['name'],
                        'email'=>$data['email'],
                        'phone'=>$data['phone'],
                        'date'=>$data['date'],
                        'start'=>$data['hour'],
                        'end_date'=>$data['date'],
                        'end'=>date('H:i:s', $end_time),
                        'price'=>$data['price'],
                        'description'=>"LOCKME!",
                        'status'=>$data['status'] ? 'confirmed' : 'pending'
                    ], true);
                    return true;
                }
                break;
            case 'delete':
                if ($data["extid"]) {
                    self::$models->delete('ea_appointments', ['id'=>$data["extid"]]);
                    return true;
                }
                break;
        }
        return false;
    }

    public static function ExportToLockMe()
    {
        global $wpdb, $lockme;
        set_time_limit(0);

        $start = new \DateTime;
        $end = new \DateTime;
        $end->add(new \DateInterval("P1Y"));
        $rows = self::$models->get_all_appointments(["from"=>$start->format("Y-m-d"), "to"=>$end->format("Y-m-d")]);

        foreach ($rows as $row) {
            self::Update($row->id, (array)$row);
        }
    }
}
