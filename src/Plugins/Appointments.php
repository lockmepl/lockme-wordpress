<?php

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;

class Appointments implements PluginInterface
{
    private $options;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option("lockme_app");

        if ($this->options['use']) {
            add_action('wpmudev_appointments_insert_appointment', [$this, 'AddReservation'], 10, 1);
            add_action('app-appointment-inline_edit-after_save', [$this, 'AddEditReservation'], 10, 2);
            add_action('app-appointments-appointment_cancelled', [$this, 'RemoveReservation'], 10, 1);

            add_action('init', function () {
                if ($_GET['app_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['app_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=appointments_plugin");
                    exit;
                }
            });
        }
    }

    public function getPluginName()
    {
        return "Appointments";
    }

    public function CheckDependencies()
    {
        return is_plugin_active("appointments/appointments.php");
    }

    public function RegisterSettings()
    {
        if (!$this->CheckDependencies()) {
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

        add_settings_field(
            "app_use",
            "Włącz integrację",
            function () {
                echo '<input name="lockme_app[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-app',
            'lockme_app_section',
            []
        );

        if ($this->options['use'] && $this->plugin->tab == 'appointments_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
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
                        function () use ($rooms, $service, $worker) {
                            echo '<select name="lockme_app[service_'.$service->ID.'_'.$worker->ID.']">';
                            echo '<option value="">--wybierz--</option>';
                            foreach ($rooms as $room) {
                                echo '<option value="'.$room['roomid'].'" '.selected(1,
                                        $room['roomid'] == $this->options['service_'.$service->ID.'_'.$worker->ID],
                                        false).'>'.$room['room'].' ('.$room['department'].')</options>';
                            }
                            echo '</select>';
                        },
                        'lockme-app',
                        'lockme_app_section',
                        []
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
                []
            );
        }
        return true;
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
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

    public function AddReservation($id)
    {
        $app = json_decode(json_encode(appointments_get_appointment($id)), true);

        $this->Add($id, $app);
    }

    public function AddEditReservation($id, $data = [])
    {
        $id = $id ?: $data['ID'];
        $app = $data ?: json_decode(json_encode(appointments_get_appointment($id)), true);

        $this->Update($id, $app);
    }

    public function RemoveReservation($id)
    {
        $this->Delete($id);
    }

    public function GetMessage(array $message)
    {
        global $appointments, $wpdb;
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];
        $start = strtotime($data['date'].' '.$data['hour']);

        list($service, $worker) = $this->GetService($roomid);

        switch ($message["action"]) {
            case "add":
                $result = $wpdb->insert(
                    $wpdb->prefix.'app_appointments',
                    [
                        'created' => date("Y-m-d H:i:s", $appointments->local_time),
                        'user' => 0,
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'phone' => $data['phone'],
                        'service' => $service->ID,
                        'worker' => $worker,
                        'price' => $data['price'],
                        'status' => $data['status'] ? 'paid' : 'pending',
                        'start' => date("Y-m-d H:i:s", $start),
                        'end' => date("Y-m-d H:i:s", $start + ($service->duration*60)),
                        'note' => $data['comment']."\n\n#LOCKME!"
                    ]
                );
                if ($result === false) {
                    throw new Exception("Error saving to database");
                }
                appointments_clear_cache();

                $row_id = $wpdb->insert_id;

                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ["extid" => $row_id]);
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
                        $wpdb->prefix.'app_appointments',
                        [
                            'user' => 0,
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'phone' => $data['phone'],
                            'service' => $service->ID,
                            'worker' => $worker,
                            'price' => $data['price'],
                            'status' => $data['status'] ? 'paid' : 'pending',
                            'start' => date("Y-m-d H:i:s", $start),
                            'end' => date("Y-m-d H:i:s", $start + ($service->duration*60)),
                            'note' => $data['comment']."\n\n#LOCKME!"
                        ],
                        [
                            'ID' => $app->ID
                        ]
                    );
                    if ($result === false) {
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
                        $wpdb->prefix.'app_appointments',
                        [
                            'status' => 'removed'
                        ],
                        [
                            'ID' => $app->ID
                        ]
                    );
                    if ($result === false) {
                        throw new Exception("Error saving to database");
                    }
                    appointments_clear_cache();
                }
                return true;
                break;
        }
        return false;
    }

    public function ExportToLockMe()
    {
        global $wpdb;

        $sql = "SELECT * FROM {$wpdb->prefix}app_appointments WHERE `start` > now() ORDER BY ID";
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $this->AddEditReservation($row->ID);
        }
    }

    private function AppData($id)
    {
        global $appointments;

        $app = json_decode(json_encode(appointments_get_appointment($id)), true);
        $date = date('Y-m-d', strtotime($app['start']));
        $hour = date('H:i:s', strtotime($app['start']));

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['service_'.$app['service'].'_'.$app['worker']],
                    'date' => $date,
                    'hour' => $hour,
                    'people' => 0,
                    'pricer' => $appointments->get_service_name($app['service']),
                    'price' => $app['price'],
                    'name' => $app['name'],
                    'surname' => $app['surname'],
                    'email' => $app['email'],
                    'phone' => $app['phone'],
                    'comment' => $app['note'],
                    'status' => in_array($app['status'], ['confirmed', 'paid']),
                    'extid' => $id
                ]
            );
    }

    private function Add($app_id, $app)
    {
        if ($app['status'] == 'removed') {
            return;
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($app_id);

        try {
            $api->AddReservation($appdata);
        } catch (Exception $e) {
        }
    }

    private function Update($app_id, $app)
    {
        if ($app['status'] == 'removed') {
            return $this->Delete($app_id);
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($app_id);
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$app_id}");
        } catch (Exception $e) {
        }

        try {
            if (!$lockme_data) { //Add new
                $api->AddReservation($appdata);
            } else { //Update
                $api->EditReservation($appdata["roomid"], "ext/{$app_id}", $appdata);
            }
        } catch (Exception $e) {
        }
        return null;
    }

    private function Delete($app_id)
    {
        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($app_id);

        try {
            $api->DeleteReservation($appdata["roomid"], "ext/{$app_id}");
        } catch (Exception $e) {
        }
    }

    /**
     * @param $roomid
     * @return array
     * @throws Exception
     */
    private function GetService($roomid)
    {
        $services = appointments_get_services();
        foreach ($services as $k => $v) {
            $workers = appointments_get_workers_by_service($v->ID);
            foreach ($workers as $worker) {
                if ($this->options["service_".$v->ID."_".$worker->ID] == $roomid) {
                    return [$v, $worker->ID];
                }
            }
        }
        throw new Exception("No service");
    }
}
