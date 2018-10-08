<?php

class LockMe_Appointments
{
    private static $options;

    public static function Init()
    {
        self::$options = get_option("lockme_app");

        if (self::$options['use']) {
            add_action('wpmudev_appointments_insert_appointment', array('LockMe_Appointments', 'AddReservation'), 10, 1);
            add_action('app-appointment-inline_edit-after_save', array('LockMe_Appointments', 'AddEditReservation'), 10, 2);
            add_action('app-appointments-appointment_cancelled', array('LockMe_Appointments', 'RemoveReservation'), 10, 1);

            add_action('init', function () {
                if ($_GET['app_export']) {
                    LockMe_Appointments::ExportToLockMe();
                    $_SESSION['app_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=appointments_plugin");
                    exit;
                }
            });
        }
    }

    public static function CheckDependencies()
    {
        return is_plugin_active("appointments/appointments.php");
    }

    public static function RegisterSettings(LockMe_Plugin $lockme)
    {
        global $appointments;
        if (!self::CheckDependencies()) {
            return false;
        }

        register_setting('lockme-app', 'lockme_app');

        add_settings_section(
            'lockme_app_section',
            "Ustawienia wtyczki Appointments",
            function () {
                echo '<p>Ustawienia integracji z wtyczką Appointments</p>';
            },
            'lockme-app'
        );

        $options = self::$options;

        add_settings_field(
            "app_use",
            "Włącz integrację",
            function () use ($options) {
                echo '<input name="lockme_app[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
            },
            'lockme-app',
            'lockme_app_section',
            array()
        );

        if ($options['use'] && $lockme->tab == 'appointments_plugin') {
            $api = $lockme->GetApi();
            if ($api) {
                $rooms = $api->RoomList();
            }
            $services = appointments_get_services();
            foreach ($services as $service) {
                $workers = appointments_get_workers_by_service($service->ID);
                foreach ($workers as $worker) {
                    $user = get_userdata($worker->ID);
                    add_settings_field(
                        "service_".$service->ID."_".$worker->ID,
                        "Pokój dla ".$service->name." - ".$user->user_login,
                        function () use ($options, $rooms, $service, $worker) {
                            echo '<select name="lockme_app[service_'.$service->ID.'_'.$worker->ID.']">';
                            echo '<option value="">--wybierz--</option>';
                            foreach ($rooms as $room) {
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
                function () {
                    echo '<a href="?page=lockme_integration&tab=appointments_plugin&app_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-app',
                'lockme_app_section',
                array()
            );
        }
    }

    public static function DrawForm(LockMe_Plugin $lockme)
    {
        global $appointments, $wpdb;
        if (!self::CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
//     $sql = "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `start` > now() ORDER BY ID";
//     $rows = $wpdb->get_results($sql);

        if ($_SESSION['app_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['app_export']);
        }
        settings_fields('lockme-app');
        do_settings_sections('lockme-app');
    }

    private static function AppData($id)
    {
        global $appointments;

        $app = json_decode(json_encode(appointments_get_appointment($id)), true);
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
            'extid'=>$id
        );
    }

    private static function Add($app_id, $app)
    {
        global $appointments, $lockme, $wpdb;
        if ($app['status'] == 'removed') {
            return;
        }

        $api = $lockme->GetApi();
        $appdata = self::AppData($app_id);

        try {
            $id = $api->AddReservation($appdata);
        } catch (Exception $e) {
        }
    }

    private static function Update($app_id, $app)
    {
        global $appointments, $lockme, $wpdb;

        if ($app['status'] == 'removed') {
            return self::Delete($app_id, $app);
        }

        $api = $lockme->GetApi();
        $appdata = self::AppData($app_id);

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$app_id}");
        } catch (Exception $e) {
        }

        try {
            if (!$lockme_data) { //Add new
                $id = $api->AddReservation($appdata);
            } else { //Update
                $api->EditReservation($appdata["roomid"], "ext/{$app_id}", $appdata);
            }
        } catch (Exception $e) {
        }
    }

    private static function Delete($app_id, $app)
    {
        global $appointments, $lockme, $wpdb;

        $api = $lockme->GetApi();
        $appdata = self::AppData($app_id);

        try {
            $api->DeleteReservation($appdata["roomid"], "ext/{$app_id}");
        } catch (Exception $e) {
        }
    }

    public static function AddReservation($id)
    {
        global $appointments, $lockme;

        $app = json_decode(json_encode(appointments_get_appointment($id)), true);

        self::Add($id, $app);
    }

    public static function AddEditReservation($id, $data = array())
    {
        global $appointments, $lockme;

        $id = $id ?: $data['ID'];
        $app = $data ?: json_decode(json_encode(appointments_get_appointment($id)), true);

        self::Update($id, $app);
    }

    public static function RemoveReservation($id)
    {
        global $appointments, $lockme;

        $app = json_decode(json_encode(appointments_get_appointment($id)), true);

        self::Delete($id, $app);
    }

    private static function GetService($roomid, $people)
    {
        global $appointments;

        $services = appointments_get_services();
        foreach ($services as $k=>$v) {
            $workers = appointments_get_workers_by_service($v->ID);
            foreach ($workers as $worker) {
                if (self::$options["service_".$v->ID."_".$worker->ID] == $roomid) {
                    return array($v, $worker->ID);
                }
            }
        }
        throw new Exception("No service");
    }

    public static function GetMessage($message)
    {
        global $appointments, $wpdb, $lockme;
        if (!self::$options['use'] || !self::CheckDependencies()) {
            return;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];
        $start = strtotime($data['date'].' '.$data['hour']);

        list($service, $worker) = self::GetService($roomid, $data['people']);

        switch ($message["action"]) {
            case "add":
                $result = $wpdb->insert(
                    $wpdb->prefix . 'app_appointments',
                    array(
                        'created'	=>	date("Y-m-d H:i:s", $appointments->local_time),
                        'user'  =>  0,
                        'name'		=>	$data['name'],
                        'email'		=>	$data['email'],
                        'phone'		=>	$data['phone'],
                        'service'	=>	$service->ID,
                        'worker'	=> 	$worker,
                        'price'		=>	$data['price'],
                        'status'	=>	$data['status'] ? 'paid' : 'pending',
                        'start'		=>	date("Y-m-d H:i:s", $start),
                        'end'		=>	date("Y-m-d H:i:s", $start + ($service->duration * 60)),
                        'note'		=>	$data['comment']."\n\n#LOCKME!"
                    )
                );
                if (!$result) {
                    throw new Exception("Error saving to database");
                }
                appointments_clear_cache();

                $row_id = $wpdb->insert_id;

                try {
                    $api = $lockme->GetApi();
                    $api->EditReservation($roomid, $lockme_id, array("extid"=>$row_id));
                    return true;
                } catch (Exception $e) {
                }
                break;
            case "edit":
                if ($data['extid']) {
                    $app = $wpdb->get_row(
                        "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `ID` = ".intval($data['extid'])
                    );
                    if (!$app) {
                        throw new Exception('No appointment');
                    }
                    $result = $wpdb->update(
                        $wpdb->prefix . 'app_appointments',
                        array(
                            'user'  =>  0,
                            'name'		=>	$data['name'],
                            'email'		=>	$data['email'],
                            'phone'		=>	$data['phone'],
                            'service'	=>	$service->ID,
                            'worker'	=> 	$worker,
                            'price'		=>	$data['price'],
                            'status'	=>	$data['status'] ? 'paid' : 'pending',
                            'start'		=>	date("Y-m-d H:i:s", $start),
                            'end'		=>	date("Y-m-d H:i:s", $start + ($service->duration * 60)),
                            'note'		=>	$data['comment']."\n\n#LOCKME!"
                        ),
                        array(
                            'ID'=>$app->ID
                        )
                    );
                    if (!$result) {
                        throw new Exception("Error saving to database");
                    }
                    appointments_clear_cache();
                }
                return true;
                break;
            case 'delete':
                if ($data["extid"]) {
                    $app = $wpdb->get_row(
                        "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `ID` = ".intval($data['extid'])
                    );
                    if (!$app) {
                        throw new Exception('No appointment');
                    }
                    $result = $wpdb->update(
                        $wpdb->prefix . 'app_appointments',
                        array(
                            'status'=>'removed'
                        ),
                        array(
                            'ID'=>$app->ID
                        )
                    );
                    if (!$result) {
                        throw new Exception("Error saving to database");
                    }
                    appointments_clear_cache();
                }
                return true;
                break;
        }
    }

    public static function ExportToLockMe()
    {
        global $appointments, $wpdb, $lockme;

        $sql = "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `start` > now() ORDER BY ID";
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            self::AddEditReservation($row->ID);
        }
    }
}
