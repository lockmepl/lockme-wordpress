<?php

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use RuntimeException;

class Dopbsp implements PluginInterface
{
    private $options;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_dopbsp');

        if ($this->options['use'] && $this->CheckDependencies()) {
            add_action('dopbsp_action_book_after', [$this, 'AddReservation'], 5);
            add_action('woocommerce_payment_complete', [$this, 'AddWooReservation'], 20, 1);
            add_action('woocommerce_thankyou', [$this, 'AddWooReservation'], 20, 1);
            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function () {
                if ($_GET['dopbsp_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['dopbsp_export'] = 1;
                    wp_redirect('?page=lockme_integration&tab=dopbsp_plugin');
                    exit;
                }

                if ($_GET['dopbsp_fix']) {
                    $this->FixSettings();
                    $_SESSION['dopbsp_fix'] = 1;
                    wp_redirect('?page=lockme_integration&tab=dopbsp_plugin');
                    exit;
                }
            });
        }
    }

    public function getPluginName()
    {
        return 'Booking System PRO';
    }

    public function ExportToLockMe()
    {
        global $DOPBSP, $wpdb;
        set_time_limit(0);

        $sql = 'SELECT * FROM '.$DOPBSP->tables->reservations.' WHERE `check_in` >= curdate() ORDER BY ID';
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $this->AddEditReservation($row->id);
        }
    }

    public function AddEditReservation($id)
    {
        global $wpdb, $DOPBSP;

        $data = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$DOPBSP->tables->reservations.' WHERE `id` = %d', $id),
            ARRAY_A);
        if (!$data) {
            return;
        }

        $this->Update($id, $data);
    }

    private function Update($id, $res)
    {
        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($res);
        $lockme_data = null;

        try {
            $lockme_data = $api->Reservation($appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return $this->Add($res);
        }
        if (in_array($res['status'], ['canceled', 'rejected'])) {
            return $this->Delete($id);
        }

        try {
            $api->EditReservation($appdata['roomid'], "ext/{$id}", $appdata);
        } catch (Exception $e) {
        }
        return null;
    }

    private function AppData($res)
    {

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['calendar_id']],
                    'date' => date('Y-m-d', strtotime($res['check_in'])),
                    'hour' => date('H:i:s', strtotime($res['start_hour'])),
                    'people' => 0,
                    'pricer' => 'API',
                    'price' => $res['price'],
                    'email' => $res['email'],
                    'status' => $res['status'] === 'approved',
                    'extid' => $res['id']
                ]
            );
    }

    private function Add($res)
    {
        if (in_array($res['status'], ['canceled', 'rejected'])) {
            return;
        }

        $api = $this->plugin->GetApi();

        try {
            $api->AddReservation($this->AppData($res));
        } catch (Exception $e) {
        }
    }

    private function Delete($id)
    {
        global $DOPBSP, $wpdb;

        $data = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$DOPBSP->tables->reservations.' WHERE `id` = %d', $id),
            ARRAY_A);
        if (!$data) {
            return;
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($data);
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return;
        }

        try {
            $api->DeleteReservation($appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }
    }

    public function FixSettings()
    {
        global $DOPBSP, $wpdb;

        $settings = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->settings_calendar} WHERE `unique_key` = 'hours_definitions' and (`value` like '%-%' or `value` like '%.%')",
            ARRAY_A);
        foreach ($settings as $setting) {
            $val = json_decode($setting['value'], true);
            foreach ($val as &$v) {
                $v['value'] = $this->FixVal($v['value']);
            }
            unset($v);
            $wpdb->update($DOPBSP->tables->settings_calendar, ['value' => json_encode($val)], ['id' => $setting['id']]);
        }

        $days = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->days} WHERE (`data` like '%-%' or `data` like '%.%')",
            ARRAY_A);
        foreach ($days as $day) {
            $data = json_decode($day['data'], true);
            foreach ($data['hours_definitions'] as &$v) {
                $v['value'] = $this->FixVal($v['value']);
            }
            unset($v);
            $hours = [];
            foreach ($data['hours'] as $k => $v) {
                $hours[$this->FixVal($k)] = $v;
            }
            $data['hours'] = $hours;
            $wpdb->update($DOPBSP->tables->days, ['data' => json_encode($data)], ['unique_key' => $day['unique_key']]);
        }

        $reses = $wpdb->get_results("SELECT * FROM {$DOPBSP->tables->reservations} WHERE (`days_hours_history` like '%-%' or `days_hours_history` like '%.%') or (`start_hour` like '%-%' or `start_hour` like '%.%')",
            ARRAY_A);
        foreach ($reses as $res) {
            $start_hour = $this->FixVal($res['start_hour']);
            $history = json_decode($res['days_hours_history'], true);
            $hist = [];
            foreach ($history as $k => $v) {
                $hist[$this->FixVal($k)] = $v;
            }
            $wpdb->update($DOPBSP->tables->reservations,
                ['start_hour' => $start_hour, 'days_hours_history' => json_encode($hist)], ['id' => $res['id']]);
        }
    }

    private function FixVal($val)
    {
        if (preg_match("#^\d\d\.\d\d$#", $val)) {
            return strtr($val, ['.' => ':']);
        }

        if (preg_match("#^\d\d([.:])\d\d ?-.*$#", $val)) {
            $pos = mb_strpos($val, '-');
            if ($pos === false) {
                return $val;
            }
            return trim(strtr(mb_substr($val, 0, $pos), ['.' => ':']));
        }
        return $val;
    }

    public function RegisterSettings()
    {
        global $wpdb, $DOPBSP;
        if (!$this->CheckDependencies()) {
            return;
        }

        register_setting('lockme-dopbsp', 'lockme_dopbsp');

        add_settings_section(
            'lockme_dopbsp_section',
            'Ustawienia wtyczki Booking System PRO',
            static function () {
                echo '<p>Ustawienia integracji z wtyczką Booking System PRO</p>';
            },
            'lockme-dopbsp');

        add_settings_field(
            'dopbsp_use',
            'Włącz integrację',
            function () {
                echo '<input name="lockme_dopbsp[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-dopbsp',
            'lockme_dopbsp_section',
            []);

        if ($this->options['use'] && $this->plugin->tab === 'dopbsp_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }
            $calendars = $wpdb->get_results('SELECT * FROM '.$DOPBSP->tables->calendars.' ORDER BY id DESC');

            foreach ($calendars as $calendar) {
                add_settings_field(
                    'calendar_'.$calendar->id,
                    'Pokój dla '.$calendar->name,
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_dopbsp[calendar_'.$calendar->id.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar->id],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-dopbsp',
                    'lockme_dopbsp_section',
                    []
                );
            }
            add_settings_field(
                'export_dopbsp',
                'Wyślij dane do LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-dopbsp',
                'lockme_dopbsp_section',
                []
            );
            add_settings_field(
                'fix_dopbsp',
                'Napraw ustawienia',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_fix=1" onclick="return confirm(\'Na pewno wykonać naprawę? Pamiętaj o backupie bazy danych! Nie ponosimy odpowiedzialności za skutki automatycznej naprawy!\');">Kliknij tutaj</a> aby naprawić ustawienia godzin BSP ("11:20-12:30" -> "11:20"). Ta operacja powinna być wykonywana tylko raz, <b>po uprzednim zbackupowaniu bazy danych!</b>';
                },
                'lockme-dopbsp',
                'lockme_dopbsp_section',
                []
            );
        }
    }

    public function CheckDependencies()
    {
        return is_plugin_active('dopbsp/dopbsp.php') || is_plugin_active('booking-system/dopbs.php');
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }
//     var_dump($DOPBSP->classes->backend_calendar_schedule->setApproved(1740));

        if ($_SESSION['dopbsp_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['dopbsp_export']);
        } elseif ($_SESSION['dopbsp_fix']) {
            echo '<div class="updated">';
            echo '  <p>Ustawienia zostały naprawione. <b>Sprawdź działanie kalendarza i migrację ustawień!</b></p>';
            echo '</div>';
            unset($_SESSION['dopbsp_fix']);
        }
        settings_fields('lockme-dopbsp');
        do_settings_sections('lockme-dopbsp');
    }

    public function AddWooReservation($order_id)
    {
        global $wpdb, $DOPBSP;
        $datas = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM '.$DOPBSP->tables->reservations.' WHERE `transaction_id` = %d',
            $order_id), ARRAY_A);
        foreach ($datas as $data) {
            if ($data) {
                $this->AddEditReservation($data['id']);
            }
        }
    }

    public function ShutDown()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            switch ($_POST['action']) {
                case 'dopbsp_reservations_add_book':
                    $this->AddReservation();
                    break;
                case 'dopbsp_reservation_reject':
                case 'dopbsp_reservation_delete':
                case 'dopbsp_reservation_cancel':
                    $id = $_POST['reservation_id'];
                    $this->Delete($id);
                    break;
                case 'dopbsp_reservation_approve':
                    $id = $_POST['reservation_id'];
                    $this->AddEditReservation($id);
                    break;
            }
        }
    }

    public function AddReservation()
    {
        global $wpdb, $DOPBSP;

        $cart = $_POST['cart_data'];
        $calendar_id = $_POST['calendar_id'];

        foreach ($cart as $reservation) {
            $data = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM '.$DOPBSP->tables->reservations.
                ' WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d',
                $reservation['check_in'], $reservation['start_hour'], $calendar_id), ARRAY_A);
            foreach ($data as $res) {
                $this->Add($res);
            }
        }
    }

    public function GetMessage(array $message)
    {
        global $DOPBSP, $wpdb;
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $hour = date('H:i', strtotime($data['hour']));

        $calendar_id = $this->GetCalendar($roomid);

        $form = [
            [
                'id' => '1',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Imię',
                'value' => $data['name']
            ],
            [
                'id' => '2',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Nazwisko',
                'value' => $data['surname']
            ],
            [
                'id' => '3',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Email',
                'value' => $data['email']
            ],
            [
                'id' => '4',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Telefon',
                'value' => $data['phone']
            ],
            [
                'id' => '5',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Dodatkowe uwagi',
                'value' => $data['comment']
            ],
            [
                'id' => '6',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Źródło',
                'value' => in_array($data['source'], ['panel', 'web', 'widget']) ? 'LockMe' : ''
            ],
            [
                'id' => '7',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Cena',
                'value' => $data['price']
            ]
        ];

        switch ($message['action']) {
            case 'add':
                $day_data = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$DOPBSP->tables->days.' WHERE calendar_id=%d AND day="%s"',
                    $calendar_id, $data['date']));
                $day = json_decode($day_data->data, false);
                $history = [
                    $hour => $day->hours->$hour
                ];
                $result = $wpdb->insert($DOPBSP->tables->reservations,
                    [
                        'calendar_id' => $calendar_id,
                        'language' => 'pl',
                        'currency' => 'zł',
                        'currency_code' => 'PLN',
                        'check_in' => $data['date'],
                        'check_out' => '',
                        'start_hour' => $hour,
                        'end_hour' => '',
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
                        'days_hours_history' => json_encode($history),
                        'form' => json_encode($form),
                        'email' => $data['email'] ?: '',
                        'status' => $data['status'] ? 'approved' : 'pending',
                        'payment_method' => 'none',
                        'token' => '',
                        'transaction_id' => ''
                    ]
                );
                if ($result === false) {
                    throw new RuntimeException('Error saving to database - '.$wpdb->last_error);
                }
                $id = $wpdb->insert_id;
                $DOPBSP->classes->backend_calendar_schedule->setApproved($id);
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ['extid' => $id]);
                    return true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $res = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT * FROM '.$DOPBSP->tables->reservations.
                            ' WHERE `id` = %d',
                            $data['extid']
                        )
                    );
                    if (!$res) {
                        throw new RuntimeException('No reservation');
                    }
                    if ($data['from_date'] && $data['from_hour'] &&
                        ($data['from_date'] != $data['date'] || $data['from_hour'] != $data['hour'])) {
                        $DOPBSP->classes->backend_calendar_schedule->setCanceled($res->id);

                        $day_data = $wpdb->get_row(
                            $wpdb->prepare(
                                'SELECT * FROM '.$DOPBSP->tables->days.' WHERE calendar_id=%d AND day="%s"',
                                $calendar_id, $data['date']
                            )
                        );
                        $day = json_decode($day_data->data, false);
                        $history = [
                            $hour => $day->hours->$hour
                        ];
                        $result = $wpdb->update(
                            $DOPBSP->tables->reservations,
                            [
                                'check_in' => $data['date'],
                                'start_hour' => $hour,
                                'days_hours_history' => json_encode($history)
                            ],
                            [
                                'id' => $res->id
                            ]
                        );
                        if ($result === false) {
                            throw new RuntimeException('Error saving to database 1 ');
                        }
                        $DOPBSP->classes->backend_calendar_schedule->setApproved($res->id);
                    }
                    $result = $wpdb->update(
                        $DOPBSP->tables->reservations,
                        [
                            'email' => $data['email'],
                            'form' => json_encode($form),
                            'price' => $data['price'],
                            'price_total' => $data['price'],
                            'status' => $data['status'] ? 'approved' : 'pending'
                        ],
                        [
                            'id' => $res->id
                        ]
                    );
                    if ($result === false) {
                        throw new RuntimeException('Error saving to database 2 ');
                    }
                    return true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    $res = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT * FROM '.$DOPBSP->tables->reservations.
                            ' WHERE `id` = %d',
                            $data['extid']
                        )
                    );
                    if (!$res) {
                        throw new RuntimeException('No reservation');
                    }
                    $result = $wpdb->update(
                        $DOPBSP->tables->reservations,
                        [
                            'status' => 'canceled'
                        ],
                        [
                            'id' => $res->id
                        ]
                    );
                    if ($result === false) {
                        throw new RuntimeException('Error saving to database');
                    }
                    $DOPBSP->classes->backend_calendar_schedule->setCanceled($res->id);
                    $wpdb->delete($DOPBSP->tables->reservations, ['id' => $res->id]);
                    return true;
                }
                break;
        }
        return false;
    }

    /**
     * @param $roomid
     * @return mixed
     * @throws Exception
     */
    private function GetCalendar($roomid)
    {
        global $DOPBSP, $wpdb;

        $calendars = $wpdb->get_results('SELECT * FROM '.$DOPBSP->tables->calendars.' ORDER BY id DESC');
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_'.$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        throw new RuntimeException('No calendar');
    }
}
