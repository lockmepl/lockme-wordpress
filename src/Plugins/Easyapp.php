<?php /** @noinspection SqlDialectInspection */

namespace LockmeIntegration\Plugins;

use DateInterval;
use DateTime;
use EADBModels;
use EATableColumns;
use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;

class Easyapp implements PluginInterface
{
    private $options;
    private $models;
    private $resdata;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        global $wpdb;
        $this->plugin = $plugin;
        $this->options = get_option("lockme_easyapp");

        if ($this->options['use'] && $this->CheckDependencies()) {
            $this->models = new EADBModels($wpdb, new EATableColumns, []);

            if ($_GET['action'] == "ea_appointment" && $_GET['id']) {
                $this->resdata = $this->AppData($_GET['id']);
            }

            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function () {
                if ($_GET['easyapp_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['easyapp_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=easyapp_plugin");
                    exit;
                }
            });
        }
    }

    public function CheckDependencies()
    {
        return is_plugin_active("easy-appointments/main.php");
    }

    private function AppData($resid)
    {
        $res = $this->models->get_appintment_by_id($resid);

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['location'].'_'.$res['service'].'_'.$res['worker']],
                    'date' => date("Y-m-d", strtotime($res['date'])),
                    'hour' => date("H:i:s", strtotime($res['start'])),
                    'people' => 0,
                    'pricer' => "API",
                    'price' => $res['price'],
                    'name' => $res['name'],
                    'email' => $res['email'],
                    'phone' => $res['phone'],
                    'status' => in_array($res['status'], ['confirmed']),
                    'extid' => $res['id']
                ]
            );
    }

    public function ExportToLockMe()
    {
        set_time_limit(0);

        $start = new DateTime;
        $end = new DateTime;
        $end->add(new DateInterval("P1Y"));
        $rows = $this->models->get_all_appointments(["from" => $start->format("Y-m-d"), "to" => $end->format("Y-m-d")]);

        foreach ($rows as $row) {
            $this->Update($row->id, (array)$row);
        }
    }

    public function RegisterSettings()
    {
        global $wpdb;
        if (!$this->CheckDependencies()) {
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

        add_settings_field(
            "easyapp_use",
            "Włącz integrację",
            function () {
                echo '<input name="lockme_easyapp[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-easyapp',
            'lockme_easyapp_section',
            []
        );

        if ($this->options['use'] && $this->plugin->tab == 'easyapp_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }
            $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');

            foreach ($calendars as $calendar) {
                add_settings_field(
                    "calendar_".$calendar->id,
                    "Pokój dla ".$calendar->name,
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_easyapp[calendar_'.$calendar->id.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar->id],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-easyapp',
                    'lockme_easyapp_section',
                    []
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
                []
            );
        }
        return true;
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return false;
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
        return true;
    }

    public function AddEditReservation($id)
    {
        $data = $this->models->get_appintment_by_id($id);
        if (!$data) {
            return;
        }

        $this->Update($id, $data);
    }

    public function ShutDown()
    {
        global $wpdb;
        if (defined('DOING_AJAX') && DOING_AJAX) {
            switch ($_GET['action']) {
                case 'ea_res_appointment':
                case 'nopriv_ea_res_appointment':
                    $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s",
                        $_GET['location'], $_GET['service'], $_GET['worker'], $_GET['date'],
                        date("H:i:s", strtotime($_GET['start']))), ARRAY_A);
                    if ($res) {
                        $this->Add($res);
                    }
                    break;
                case 'ea_cancel_appointment':
                case 'nopriv_ea_cancel_appointment':
                    $this->Delete($_GET['id']);
                    break;
                case 'ea_appointment':
                    $api = $this->plugin->GetApi();
                    switch ($_GET['_method']) {
                        case 'PUT':
                            $this->AddEditReservation($_GET['id']);
                            break;
                        case 'DELETE':
                            try {
                                $api->DeleteReservation($this->resdata["roomid"], "ext/{$_GET['id']}");
                            } catch (Exception $e) {
                            }
                            break;
                        default:
                            $input = json_decode(file_get_contents('php://input'), true);
                            if (!$_GET['_method'] && $input['date']) {
                                $res = $wpdb->get_row($wpdb->prepare("select `id` from {$wpdb->prefix}ea_appointments where `location` = %d and `service` = %d and `worker` = %d and `date` = %s and `start` = %s",
                                    $input['location'], $input['service'], $input['worker'], $input['date'],
                                    date("H:i:s", strtotime($input['start']))), ARRAY_A);
                                if ($res) {
                                    $this->Add($res);
                                }
                            }
                    }
                    break;
            }
        }
    }

    public function GetMessage(array $message)
    {
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];

        $calendar_id = explode('_', $this->GetCalendar($roomid));
        $location = $calendar_id[0];
        $service = $calendar_id[1];
        $worker = $calendar_id[2];

        switch ($message["action"]) {
            case "add":
                $s = $this->models->get_row('ea_services', $service);
                $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                $id = $this->models->replace('ea_appointments', [
                    'location' => $location,
                    'service' => $service,
                    'worker' => $worker,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'date' => $data['date'],
                    'start' => $data['hour'],
                    'end_date' => $data['date'],
                    'end' => date('H:i:s', $end_time),
                    'price' => $data['price'],
                    'description' => "LOCKME!",
                    'status' => $data['status'] ? 'confirmed' : 'pending'
                ], true);
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ["extid" => $id->id]);
                    return true;
                } catch (Exception $e) {
                }
                break;
            case "edit":
                if ($data["extid"]) {
                    $s = $this->models->get_row('ea_services', $service);
                    $end_time = strtotime("{$data['hour']} + {$s->duration} minute");
                    $this->models->replace('ea_appointments', [
                        'id' => $data["extid"],
                        'location' => $location,
                        'service' => $service,
                        'worker' => $worker,
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'phone' => $data['phone'],
                        'date' => $data['date'],
                        'start' => $data['hour'],
                        'end_date' => $data['date'],
                        'end' => date('H:i:s', $end_time),
                        'price' => $data['price'],
                        'description' => "LOCKME!",
                        'status' => $data['status'] ? 'confirmed' : 'pending'
                    ], true);
                    return true;
                }
                break;
            case 'delete':
                if ($data["extid"]) {
                    $this->models->delete('ea_appointments', ['id' => $data["extid"]]);
                    return true;
                }
                break;
        }
        return false;
    }

    private function Add($res)
    {
        if (in_array($res['status'], ['canceled', 'abandoned'])) {
            return;
        }

        $api = $this->plugin->GetApi();

        try {
            $api->AddReservation($this->AppData($res['id']));
        } catch (Exception $e) {
        }
    }

    private function Update($id, $res)
    {
        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($res['id']);
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return $this->Add($res);
        }
        if (in_array($res['status'], ['canceled', 'abandoned'])) {
            return $this->Delete($id);
        }

        try {
            $api->EditReservation($appdata["roomid"], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
        return false;
    }

    private function Delete($resid)
    {
        $res = $this->models->get_appintment_by_id($resid);
        if (!$res) {
            return;
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($res['id']);
        $lockme_data = [];

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

    /**
     * @param $roomid
     * @return mixed
     * @throws Exception
     */
    private function GetCalendar($roomid)
    {
        global $wpdb;

        $calendars = $wpdb->get_results('SELECT DISTINCT concat(l.`id`,"_",s.`id`,"_",ss.`id`) `id`, concat(l.`name`," - ",s.`name`," - ",ss.`name`) `name` FROM '.$wpdb->prefix.'ea_locations l join '.$wpdb->prefix.'ea_services s join '.$wpdb->prefix.'ea_staff ss');
        foreach ($calendars as $calendar) {
            if ($this->options["calendar_".$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        throw new Exception("No calendar");
    }

    public function getPluginName()
    {
        return "Easy Appointments";
    }
}
