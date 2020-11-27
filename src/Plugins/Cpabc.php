<?php

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeDep\League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use RuntimeException;

class Cpabc implements PluginInterface {
    private $options;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_cpabc');

        if($this->options['use']){
            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function(){
                $this->CheckAdminActions();
                if($_GET['cpabc_export']){
                    $this->ExportToLockMe();
                    $_SESSION['cpabc_export'] = 1;
                    wp_redirect('?page=lockme_integration&tab=cpabc_plugin');
                    exit;
                }
            });
        }
    }

    public function CheckDependencies(): bool
    {
        return is_plugin_active('appointment-booking-calendar/cpabc_appointments.php');
    }

    public function RegisterSettings(): void
    {
        global $wpdb;
        if(!$this->CheckDependencies()) {
            return;
        }

        register_setting( 'lockme-cpabc', 'lockme_cpabc' );

        add_settings_section(
            'lockme_cpabc_section',
            'Ustawienia wtyczki Appointment Booking Calendar',
            static function(){
                echo '<p>Ustawienia integracji z wtyczką Appointment Booking Calendar</p>';
            },
            'lockme-cpabc');

        $options = $this->options;

        add_settings_field(
            'cpabc_use',
            'Włącz integrację',
            static function() use($options){
                echo '<input name="lockme_cpabc[use]" type="checkbox" value="1"  '.checked(1, $options['use'], false).' />';
            },
            'lockme-cpabc',
            'lockme_cpabc_section',
            array());

        if($options['use'] && $this->plugin->tab === 'cpabc_plugin'){
            $api = $this->plugin->GetApi();
            $rooms = [];
            if($api){
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }

            $calendars = $wpdb->get_results('SELECT * FROM '.CPABC_APPOINTMENTS_CONFIG_TABLE_NAME);
            foreach($calendars as $calendar){
                add_settings_field(
                    'calendar_'.$calendar->id,
                    'Pokój dla '.$calendar->uname,
                    static function() use($options, $rooms, $calendar){
                        echo '<select name="lockme_cpabc[calendar_'.$calendar->id.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach($rooms as $room){
                            echo '<option value="'.$room['roomid'].'" '.selected(1, $room['roomid']==$options['calendar_'.$calendar->id], false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-cpabc',
                    'lockme_cpabc_section',
                    array()
                );
            }
            add_settings_field(
                'export_cpabc',
                'Wyślij dane do LockMe',
                static function(){
                    echo '<a href="?page=lockme_integration&tab=cpabc_plugin&cpabc_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-cpabc',
                'lockme_cpabc_section',
                array()
            );
        }
    }

    public function DrawForm(): void
    {
        if(!$this->CheckDependencies()){
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
        //Format: m/d/Y H:i
//     $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where  `calendar` = %d and `booked_time_unformatted` = %s", 14, '2017-02-21 19:00:00'), ARRAY_A);
//     var_dump($res);
//     var_dump($this->AddEditReservation($row['id']));
//     $this->Delete(3010);

        if($_SESSION['cpabc_export']){
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['cpabc_export']);
        }
        settings_fields( 'lockme-cpabc' );
        do_settings_sections( 'lockme-cpabc' );
    }

    private function AppData($resid): array
    {
        global $wpdb;
        $res = $wpdb->get_row($wpdb->prepare(
            'select * from '.CPABC_APPOINTMENTS_TABLE_NAME.' a join '.CPABC_TDEAPP_CALENDAR_DATA_TABLE.
            ' b on(b.reference = a.id) where b.id = %d', $resid), ARRAY_A);
        if(!$res){
            return [];
        }

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid'=>$this->options['calendar_'.$res['calendar']],
                    'date'=>date('Y-m-d', strtotime($res['booked_time_unformatted'])),
                    'hour'=>date('H:i:s', strtotime($res['booked_time_unformatted'])),
                    'name'=>$res['name'],
                    'pricer'=> 'API',
                    'email'=>$res['email'],
                    'phone'=>$res['phone'],
                    'status'=>1,
                    'extid'=>$resid
                ]
            );
    }

    public function AddEditReservation($id){
        global $wpdb;
        if(!is_numeric($id)){
            return null;
        }
        if(defined('LOCKME_MESSAGING')){
            return null;
        }

        $res = $wpdb->get_row($wpdb->prepare(
            'select * from '.CPABC_APPOINTMENTS_TABLE_NAME.' a join '.CPABC_TDEAPP_CALENDAR_DATA_TABLE.
            ' b on(b.reference = a.id) where b.id = %d', $id), ARRAY_A);
        if(!$res){
            return null;
        }

        if($res['is_cancelled']){
            return $this->Delete($id);
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($id);
        $lockme_data = null;
        try{
            $lockme_data = $api->Reservation($appdata['roomid'], "ext/{$id}");
        }catch(Exception $e){
        }

        try{
            if(!$lockme_data){ //Add new
                $api->AddReservation($appdata);
            }else{ //Update
                $api->EditReservation($appdata['roomid'], "ext/{$id}", $appdata);
            }
        }catch(Exception $e){
        }
        return true;
    }

    public function Delete($id, $force = false){
        global $wpdb;

        if(defined('LOCKME_MESSAGING')){
            return false;
        }

        $res = $wpdb->get_row($wpdb->prepare(
            'select * from '.CPABC_APPOINTMENTS_TABLE_NAME.' a join '.CPABC_TDEAPP_CALENDAR_DATA_TABLE.
            ' b on(b.reference = a.id) where b.id = %d', $id), ARRAY_A);

        if(!$force && $res && !$res['is_cancelled']){
            return $this->AddEditReservation($id);
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($id);

        try{
            $api->DeleteReservation($appdata['roomid'], "ext/{$id}");
        }catch(Exception $e){
        }
        return true;
    }

    public function ShutDown(): void
    {
        global $wpdb;
        //Add from website
        if ($_POST['cpabc_item'] && $_POST['dateAndTime']) {
            foreach ($_POST['dateAndTime'] as $dat) {
                $res = $wpdb->get_row(
                    $wpdb->prepare(
                        'select * from '.CPABC_APPOINTMENTS_TABLE_NAME.' a join '.CPABC_TDEAPP_CALENDAR_DATA_TABLE.
                        ' b on(b.reference = a.id) where  `calendar` = %d and `booked_time_unformatted` = %s',
                        $_POST['cpabc_item'], $dat
                    ), ARRAY_A
                );
                if ($res) {
                    $this->AddEditReservation($res['id']);
                }
            }
        }

        //Admin
        if($_GET['page'] === 'cpabc_appointments' && is_admin()) {
            if (isset($_GET['nocancel']) && $_GET['nocancel'] != ''){
                $this->AddEditReservation($_GET['nocancel']);
            }else if($_GET['edit'] && $_POST){
                $this->AddEditReservation($_GET['edit']);
            }
        }
    }

    public function CheckAdminActions(): void
    {
        //Admin
        if($_GET['page'] === 'cpabc_appointments' && is_admin()) {
            if (isset($_GET['delmark']) && $_GET['delmark'] != ''){
                for ($i=0; $i<=50; $i++){
                    $index = 'c'.$i;
                    if (isset($_GET[$index]) && $_GET[$index] != ''){
                        $this->Delete($_GET[$index], true);
                    }
                }
            }else if (isset($_GET['ld']) && $_GET['ld'] != ''){
                $this->Delete($_GET['ld'], true);
            }else if (isset($_GET['cancel']) && $_GET['cancel'] != ''){
                $this->Delete($_GET['cancel'], true);
            }
        }
    }

    private function GetCalendar($roomid){
        global $wpdb;
        $calendars = $wpdb->get_results('SELECT * FROM '.CPABC_APPOINTMENTS_CONFIG_TABLE_NAME);
        foreach($calendars as $calendar){
            if($this->options['calendar_'.$calendar->id] == $roomid){
                return $calendar->id;
            }
        }
        return null;
    }

    public function GetMessage(array $message): bool
    {
        global $wpdb;
        if(!$this->options['use'] || !$this->CheckDependencies()){
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $hour = $data['hour'];
        $datetime = strtotime($data['date'].' '.$hour);

        $calendar_id = $this->GetCalendar($roomid);

        switch($message['action']){
            case 'add':
                $rows_affected = $wpdb->insert( CPABC_APPOINTMENTS_TABLE_NAME,array(
                    'calendar' => $calendar_id,
                    'time' => current_time('mysql'),
                    'booked_time' => date('Y-m-d H:i:s', $datetime),
                    'booked_time_customer' => date('Y-m-d H:i:s', $datetime),
                    'booked_time_unformatted' => date('Y-m-d H:i:s', $datetime),
                    'name' => $data['name'].' '.$data['surname'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'question' => "Źródło: LockMe\nIlość osób: {$data['people']}\nCena: {$data['price']}",
                    'quantity' => 1,
                    'quantity_a' => $data['people'],
                    'quantity_s' => 0,
                    'buffered_date' => serialize($data),
                    'who_added' => 0
                ) );
                if(!$rows_affected){
                    throw new RuntimeException('Błąd zapisu: '.$wpdb->last_error);
                }
                $tid = $wpdb->insert_id;

                $rows_affected = $wpdb->insert( CPABC_TDEAPP_CALENDAR_DATA_TABLE, array(
                    'appointment_calendar_id' => $calendar_id,
                    'datatime' => date('Y-m-d H:i:s', $datetime),
                    'title' => $data['email'],
                    'reminder' => 0,
                    'quantity' => 1,
                    'quantity_a' => $data['people'],
                    'quantity_s' => 0,
                    'description' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
                    'description_customer' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
                    'reference' => $tid,
                    'who_added' => 0
                ) );

                if(!$rows_affected){
                    throw new RuntimeException('Błąd zapisu 2: '.$wpdb->last_error);
                }
                $id = $wpdb->insert_id;

                try{
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, array('extid' =>$id));
                    return true;
                }catch(Exception $e){
                }
                break;
            case 'edit':
                if($data['extid']){
                    $event = $wpdb->get_results('SELECT * FROM '.CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME.' WHERE id='.esc_sql($data['extid']));
                    $event = $event[0];

                    $data1 = array(
                        'datatime' => date('Y-m-d H:i:s', $datetime),
                        'quantity' => $data['people'],
                        'title' => $data['email'],
                        'description' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
                        'who_edited' => 0
                    );

                    $data2 = array(
                        'booked_time_unformatted' => date('Y-m-d H:i:s', $datetime),
                        'booked_time' => date('Y-m-d H:i:s', $datetime),
                        'quantity' => $data['people'],
                        'buffered_date' => serialize($data)
                    );

                    $wpdb->update ( CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME, $data1, array( 'id' => $data['extid'] ));
                    if ($event->reference != '') {
                        $wpdb->update(CPABC_APPOINTMENTS_TABLE_NAME, $data2, ['id' => $event->reference]);
                    }
                    return true;
                }
                break;
            case 'delete':
                if($data['extid']){
                    $wpdb->query('DELETE FROM `'.CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME.'` WHERE id='.$data['extid']);
                    return true;
                }
                break;
        }
        return false;
    }

    public function ExportToLockMe(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'select * from '.CPABC_APPOINTMENTS_TABLE_NAME.' a join '.CPABC_TDEAPP_CALENDAR_DATA_TABLE.
            ' b on(b.reference = a.id) where date(`datatime`) >= curdate()', ARRAY_A);
        foreach($rows as $row){
            $this->AddEditReservation($row['id']);
        }
    }

    /**
     * @inheritDoc
     */
    public function getPluginName(): string
    {
        return 'Appointment Booking Calendar';
    }
}
