<?php

namespace LockmeIntegration\Plugins;

use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use LockmeIntegration\Util\Request;
use RuntimeException;

defined('ABSPATH') or die('No script kiddies please!');

// Integrates with third-party plugin tables, so direct queries are intentional here.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

class Cpabc implements PluginInterface {
    private array $options;
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_cpabc') ?: [];

        if(($this->options['use'] ?? null) && $this->CheckDependencies()){
            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function(){
                $this->CheckAdminActions();
                if (Request::getBool('cpabc_export')) {
                    $this->ExportToLockMe();
                    wp_safe_redirect('?page=lockme_integration&tab=cpabc_plugin&cpabc_exported=1');
                    exit;
                }
            }, PHP_INT_MAX);
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

        register_setting('lockme-cpabc', 'lockme_cpabc', [
            'sanitize_callback' => [$this->plugin, 'sanitizePluginOptions'],
        ]);

        add_settings_section(
            'lockme_cpabc_section',
            'Appointment Booking Calendar plugin settings',
            static function(){
                echo '<p>Integration settings with the Appointment Booking Calendar plugin</p>';
            },
            'lockme-cpabc');

        $options = $this->options;

        add_settings_field(
            'cpabc_use',
            'Enable integration',
            static function() use($options){
                echo '<input name="lockme_cpabc[use]" type="checkbox" value="1"  '.checked(1, $options['use'] ?? null, false).' />';
            },
            'lockme-cpabc',
            'lockme_cpabc_section',
            array());

        if(($this->options['use'] ?? null) && $this->plugin->tab === 'cpabc_plugin'){
            $api = $this->plugin->GetApi();
            $rooms = [];
            if($api){
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }

            $calendars = $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i', CPABC_APPOINTMENTS_CONFIG_TABLE_NAME)
            );
            foreach($calendars as $calendar){
                $optionKey = 'calendar_'.(int) $calendar->id;
                add_settings_field(
                    $optionKey,
                    'Room for '.esc_html((string) $calendar->uname),
                    static function() use($options, $rooms, $optionKey){
                        echo '<select name="lockme_cpabc['.esc_attr($optionKey).']">';
                        echo '<option value="">--select--</option>';
                        foreach($rooms as $room){
                            echo '<option value="'.esc_attr((string) $room['roomid']).'" '.selected(1, ($room['roomid'] ?? null) == ($options[$optionKey] ?? null), false).'>'.esc_html((string) ($room['room'] ?? '')).' ('.esc_html((string) ($room['department'] ?? '')).')</option>';
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
                'Send data to LockMe',
                static function(){
                    echo '<a href="?page=lockme_integration&tab=cpabc_plugin&cpabc_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
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
            echo "<p>You don't have required plugin</p>";
            return;
        }
        //Format: m/d/Y H:i
//     $res = $wpdb->get_row($wpdb->prepare("select * from ".CPABC_APPOINTMENTS_TABLE_NAME." a join ".CPABC_TDEAPP_CALENDAR_DATA_TABLE." b on(b.reference = a.id) where  `calendar` = %d and `booked_time_unformatted` = %s", 14, '2017-02-21 19:00:00'), ARRAY_A);
//     var_dump($res);
//     var_dump($this->AddEditReservation($row['id']));
//     $this->Delete(3010);

        if (Request::getBool('cpabc_exported')) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        }
        settings_fields( 'lockme-cpabc' );
        do_settings_sections( 'lockme-cpabc' );
    }

    private function AppData($resid): array
    {
        global $wpdb;
        $res = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i a JOIN %i b ON (b.reference = a.id) WHERE b.id = %d',
                CPABC_APPOINTMENTS_TABLE_NAME,
                CPABC_TDEAPP_CALENDAR_DATA_TABLE,
                $resid
            ),
            ARRAY_A
        );
        if(!$res){
            return [];
        }

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid'=>$this->options['calendar_'.$res['calendar']],
                    'date'=>gmdate('Y-m-d', strtotime($res['booked_time_unformatted'])),
                    'hour'=>gmdate('H:i:s', strtotime($res['booked_time_unformatted'])),
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

        $res = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i a JOIN %i b ON (b.reference = a.id) WHERE b.id = %d',
                CPABC_APPOINTMENTS_TABLE_NAME,
                CPABC_TDEAPP_CALENDAR_DATA_TABLE,
                $id
            ),
            ARRAY_A
        );
        if(!$res){
            return null;
        }

        if($res['is_cancelled']){
            return $this->Delete($id);
        }

        $appdata = $this->AppData($id);
        if(!$appdata['roomid']) {
            return null;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = null;
        try{
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        }catch(Exception $e){
        }

        try{
            if(!$lockme_data){ //Add new
                $api->AddReservation($appdata);
            }else{ //Update
                $api->EditReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}", $appdata);
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

        $res = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i a JOIN %i b ON (b.reference = a.id) WHERE b.id = %d',
                CPABC_APPOINTMENTS_TABLE_NAME,
                CPABC_TDEAPP_CALENDAR_DATA_TABLE,
                $id
            ),
            ARRAY_A
        );

        if(!$force && $res && !$res['is_cancelled']){
            return $this->AddEditReservation($id);
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($id);
        if(!$appdata['roomid']) {
            return false;
        }

        try{
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        }catch(Exception $e){
        }
        return true;
    }

    public function ShutDown(): void
    {
        global $wpdb;
        //Add from website
        $cpabcItem = Request::postInt('cpabc_item');
        $dateAndTime = Request::postArray('dateAndTime');
        if ($cpabcItem && $dateAndTime) {
            foreach ($dateAndTime as $dat) {
                $res = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM %i a JOIN %i b ON (b.reference = a.id) WHERE `calendar` = %d AND `booked_time_unformatted` = %s',
                        CPABC_APPOINTMENTS_TABLE_NAME,
                        CPABC_TDEAPP_CALENDAR_DATA_TABLE,
                        $cpabcItem,
                        sanitize_text_field((string) $dat)
                    ), ARRAY_A
                );
                if ($res) {
                    $this->AddEditReservation($res['id']);
                }
            }
        }

        //Admin
        if (Request::getString('page') === 'cpabc_appointments' && is_admin()) {
            if (Request::getString('nocancel') !== '') {
                $this->AddEditReservation(Request::getInt('nocancel'));
            } elseif (Request::getString('edit') !== '' && Request::hasPost()) {
                $this->AddEditReservation(Request::getInt('edit'));
            }
        }
    }

    public function CheckAdminActions(): void
    {
        //Admin
        if (Request::getString('page') === 'cpabc_appointments' && is_admin()) {
            if (Request::getString('delmark') !== '') {
                for ($i=0; $i<=50; $i++){
                    $index = 'c'.$i;
                    $value = Request::getInt($index);
                    if ($value) {
                        $this->Delete($value, true);
                    }
                }
            } elseif (Request::getString('ld') !== '') {
                $this->Delete(Request::getInt('ld'), true);
            } elseif (Request::getString('cancel') !== '') {
                $this->Delete(Request::getInt('cancel'), true);
            }
        }
    }

    private function GetCalendar($roomid){
        global $wpdb;
        $calendars = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i', CPABC_APPOINTMENTS_CONFIG_TABLE_NAME)
        );
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
        if(!($this->options['use'] ?? null) || !$this->CheckDependencies()){
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
                    'booked_time' => gmdate('Y-m-d H:i:s', $datetime),
                    'booked_time_customer' => gmdate('Y-m-d H:i:s', $datetime),
                    'booked_time_unformatted' => gmdate('Y-m-d H:i:s', $datetime),
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
                    throw new RuntimeException('Błąd zapisu');
                }
                $tid = $wpdb->insert_id;

                $rows_affected = $wpdb->insert( CPABC_TDEAPP_CALENDAR_DATA_TABLE, array(
                    'appointment_calendar_id' => $calendar_id,
                    'datatime' => gmdate('Y-m-d H:i:s', $datetime),
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
                    throw new RuntimeException('Błąd zapisu 2');
                }
                $id = $wpdb->insert_id;

                try{
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, (string) $lockme_id, array('extid' =>$id));
                    return true;
                }catch(Exception $e){
                }
                break;
            case 'edit':
                if($data['extid']){
                    $event = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT * FROM %i WHERE id = %d',
                            CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME,
                            (int) $data['extid']
                        )
                    );

                    $data1 = array(
                        'datatime' => gmdate('Y-m-d H:i:s', $datetime),
                        'quantity' => $data['people'],
                        'title' => $data['email'],
                        'description' => "Źródło: LockMe<br/>\nIlość osób: {$data['people']}<br/>\nCena: {$data['price']}",
                        'who_edited' => 0
                    );

                    $data2 = array(
                        'booked_time_unformatted' => gmdate('Y-m-d H:i:s', $datetime),
                        'booked_time' => gmdate('Y-m-d H:i:s', $datetime),
                        'quantity' => $data['people'],
                        'buffered_date' => serialize($data)
                    );

                    $wpdb->update(CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME, $data1, array('id' => (int) $data['extid']));
                    if ($event && $event->reference != '') {
                        $wpdb->update(CPABC_APPOINTMENTS_TABLE_NAME, $data2, ['id' => $event->reference]);
                    }
                    return true;
                }
                break;
            case 'delete':
                if($data['extid']){
                    $wpdb->query(
                        $wpdb->prepare(
                            'DELETE FROM %i WHERE id = %d',
                            CPABC_APPOINTMENTS_CALENDARS_TABLE_NAME,
                            (int) $data['extid']
                        )
                    );
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
            $wpdb->prepare(
                'SELECT * FROM %i a JOIN %i b ON (b.reference = a.id) WHERE date(`datatime`) >= curdate()',
                CPABC_APPOINTMENTS_TABLE_NAME,
                CPABC_TDEAPP_CALENDAR_DATA_TABLE
            ),
            ARRAY_A
        );
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
