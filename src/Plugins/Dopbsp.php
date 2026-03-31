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

class Dopbsp implements PluginInterface
{
    private array $options;
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_dopbsp') ?: [];

        if (($this->options['use'] ?? null) && $this->CheckDependencies()) {
            add_action('dopbsp_action_book_after', [$this, 'AddReservation'], 5);
            add_action('woocommerce_payment_complete', [$this, 'AddWooReservation'], 20, 1);
            add_action('woocommerce_thankyou', [$this, 'AddWooReservation'], 20, 1);

            add_action('wp_ajax_dopbsp_reservation_reject', fn() => $this->Delete(), -10);
            add_action('wp_ajax_dopbsp_reservation_cancel', fn() => $this->Delete(), -10);
            add_action('wp_ajax_dopbsp_reservation_delete', fn() => $this->Delete(), -10);

            register_shutdown_function([$this, 'ShutDown']);

            add_action('init', function () {
                if (Request::getBool('dopbsp_export')) {
                    $this->ExportToLockMe();
                    wp_safe_redirect('?page=lockme_integration&tab=dopbsp_plugin&dopbsp_exported=1');
                    exit;
                }

                if (Request::getBool('dopbsp_fix')) {
                    $this->FixSettings();
                    wp_safe_redirect('?page=lockme_integration&tab=dopbsp_plugin&dopbsp_fixed=1');
                    exit;
                }
            }, PHP_INT_MAX);
        }
    }

    public function getPluginName(): string
    {
        return 'Booking System PRO';
    }

    public function ExportToLockMe(): void
    {
        global $DOPBSP, $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE `check_in` >= curdate() ORDER BY ID',
                $DOPBSP->tables->reservations
            )
        );

        foreach ($rows as $row) {
            $this->AddEditReservation($row->id);
        }
    }

    public function AddEditReservation($id): void
    {
        global $wpdb, $DOPBSP;

        $data = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE `id` = %d',
                $DOPBSP->tables->reservations,
                $id
            ),
            ARRAY_A
        );
        if (!$data) {
            return;
        }

        $this->Update($id, $data);
    }

    private function Update($id, $res)
    {
        $appdata = $this->AppData($res);
        if(!$appdata['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = null;

        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            $this->Add($res);

            return;
        }
        if (in_array($res['status'], ['canceled', 'rejected'])) {
            $this->Delete($id);

            return;
        }

        try {
            $api->EditReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}", $appdata);
        } catch (Exception $e) {
        }
        return null;
    }

    private function AppData($res): array
    {

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$res['calendar_id']],
                    'date' => gmdate('Y-m-d', strtotime($res['check_in'])),
                    'hour' => gmdate('H:i:s', strtotime($res['start_hour'])),
                    'people' => 0,
                    'pricer' => 'API',
                    'price' => $res['price'],
                    'email' => $res['email'],
                    'status' => $res['status'] === 'approved',
                    'extid' => $res['id']
                ]
            );
    }

    private function Add($res): void
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

    private function Delete($id = null): void
    {
        global $DOPBSP, $wpdb;

        $id ??= Request::postInt('reservation_id');

        $data = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE `id` = %d',
                $DOPBSP->tables->reservations,
                $id
            ),
            ARRAY_A
        );
        if (!$data) {
            return;
        }

        $appdata = $this->AppData($data);

        if(!$appdata['roomid']) {
            return;
        }
        $api = $this->plugin->GetApi();
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        } catch (Exception $e) {
        }

        if (!$lockme_data) {
            return;
        }

        try {
            $api->DeleteReservation((int) $appdata['roomid'], "ext/{$appdata['extid']}");
        } catch (Exception $e) {
        }
    }

    public function FixSettings(): void
    {
        global $DOPBSP, $wpdb;

        $settings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE `unique_key` = 'hours_definitions' and (`value` like '%%-%%' or `value` like '%%.%%')",
            $DOPBSP->tables->settings_calendar
        ), ARRAY_A);
        foreach ($settings as $setting) {
            $val = json_decode($setting['value'], true);
            foreach ($val as &$v) {
                $v['value'] = $this->FixVal($v['value']);
            }
            unset($v);
            $wpdb->update($DOPBSP->tables->settings_calendar, ['value' => json_encode($val)], ['id' => $setting['id']]);
        }

        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (`data` like '%%-%%' or `data` like '%%.%%')",
            $DOPBSP->tables->days
        ), ARRAY_A);
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

        $reses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (`days_hours_history` like '%%-%%' or `days_hours_history` like '%%.%%') or (`start_hour` like '%%-%%' or `start_hour` like '%%.%%')",
            $DOPBSP->tables->reservations
        ), ARRAY_A);
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

    public function RegisterSettings(): void
    {
        global $wpdb, $DOPBSP;
        if (!$this->CheckDependencies()) {
            return;
        }


        register_setting('lockme-dopbsp', 'lockme_dopbsp', [
            'sanitize_callback' => [$this->plugin, 'sanitizePluginOptions'],
        ]);

        add_settings_section(
            'lockme_dopbsp_section',
            'Booking System PRO plugin settings',
            static function () {
                echo '<p>Integration settings with the Booking System PRO plugin</p>';
            },
            'lockme-dopbsp');

        add_settings_field(
            'dopbsp_use',
            'Enable integration',
            function () {
                echo '<input name="lockme_dopbsp[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'] ?? null,
                        false).' />';
            },
            'lockme-dopbsp',
            'lockme_dopbsp_section',
            []);

        if (($this->options['use'] ?? null) && $this->plugin->tab === 'dopbsp_plugin') {
            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                try {
                    $rooms = $api->RoomList();
                } catch (IdentityProviderException $e) {
                }
            }
            $calendars = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY id DESC',
                    $DOPBSP->tables->calendars
                )
            );

            foreach ($calendars as $calendar) {
                $optionKey = 'calendar_'.(int) $calendar->id;
                add_settings_field(
                    $optionKey,
                    'Room for '.esc_html((string) $calendar->name),
                    function () use ($rooms, $optionKey) {
                        echo '<select name="lockme_dopbsp['.esc_attr($optionKey).']">';
                        echo '<option value="">--select--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.esc_attr((string) $room['roomid']).'" '.selected(1,
                                    ($room['roomid'] ?? null) == ($this->options[$optionKey] ?? null),
                                    false).'>'.esc_html((string) ($room['room'] ?? '')).' ('.esc_html((string) ($room['department'] ?? '')).')</option>';
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
                'Send data to LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_export=1">Click here</a> to send all reservations to the LockMe calendar. This operation should only be required once, during the initial integration.';
                },
                'lockme-dopbsp',
                'lockme_dopbsp_section',
                []
            );
            add_settings_field(
                'fix_dopbsp',
                'Fix settings',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=dopbsp_plugin&dopbsp_fix=1" onclick="return confirm(\'Are you sure you want to perform the repair? Remember to backup your database! We are not responsible for the consequences of automatic repair!\');">Click here</a> to repair BSP hours settings ("11:20-12:30" -> "11:20"). This operation should only be performed once, <b>after backing up the database!</b>';
                },
                'lockme-dopbsp',
                'lockme_dopbsp_section',
                []
            );
        }
    }

    public function CheckDependencies(): bool
    {
        return is_plugin_active('dopbsp/dopbsp.php') || is_plugin_active('booking-system/dopbs.php');
    }

    public function DrawForm(): void
    {
        if (!$this->CheckDependencies()) {
            echo "<p>You don't have required plugin</p>";
            return;
        }

        if (Request::getBool('dopbsp_exported')) {
            echo '<div class="updated">';
            echo '  <p>Bookings export completed.</p>';
            echo '</div>';
        } elseif (Request::getBool('dopbsp_fixed')) {
            echo '<div class="updated">';
            echo '  <p>Ustawienia zostały naprawione. <b>Sprawdź działanie kalendarza i migrację ustawień!</b></p>';
            echo '</div>';
        }
        settings_fields('lockme-dopbsp');
        do_settings_sections('lockme-dopbsp');
    }

    public function AddWooReservation($order_id): void
    {
        global $wpdb, $DOPBSP;
        $datas = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE `transaction_id` = %d',
            $DOPBSP->tables->reservations,
            $order_id
        ), ARRAY_A);
        foreach ($datas as $data) {
            if ($data) {
                $this->AddEditReservation($data['id']);
            }
        }
    }

    public function ShutDown(): void
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            switch (Request::postString('action')) {
                case 'dopbsp_reservations_add_book':
                    $this->AddReservation();
                    break;
                case 'dopbsp_reservation_approve':
                    $id = Request::postInt('reservation_id');
                    $this->AddEditReservation($id);
                    break;
            }
        }
    }

    public function AddReservation(): void
    {
        global $wpdb, $DOPBSP;

        $cart = Request::postArray('cart_data');
        $calendar_id = Request::postInt('calendar_id');

        foreach ($cart as $reservation) {
            $data = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE `check_in` = %s and `start_hour` = %s and `calendar_id` = %d',
                $DOPBSP->tables->reservations,
                $reservation['check_in'],
                $reservation['start_hour'],
                $calendar_id
            ), ARRAY_A);
            foreach ($data as $res) {
                $this->Add($res);
            }
        }
    }

    public function GetMessage(array $message): bool
    {
        global $DOPBSP, $wpdb;
        if (!($this->options['use'] ?? null) || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $hour = gmdate('H:i', strtotime($data['hour']));

        $calendar_id = $this->GetCalendar($roomid);
        if (!$calendar_id) {
            return false;
        }

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
                'value' => in_array($data['source'], ['panel', 'web', 'widget']) ? 'LockMe ('.$data['source'].')' : ''
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
        if (isset($data['invoice']) && !empty($data['invoice'])) {
            $form[] = [
                'id' => '8',
                'is_email' => 'false',
                'add_to_day_hour_info' => 'false',
                'add_to_day_hour_body' => 'false',
                'translation' => 'Faktura',
                'value' => $data['invoice']
            ];
        }

        switch ($message['action']) {
            case 'add':
                $this->ensureDayExists($calendar_id, $data['date']);

                $day_history = $DOPBSP->classes->backend_calendar_schedule->daysHoursHistory(
                    $data['date'],
                    $hour,
                    '',
                    $calendar_id
                );
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
                        'days_hours_history' => json_encode($day_history),
                        'form' => json_encode($form),
                        'email' => $data['email'] ?: '',
                        'status' => $data['status'] ? 'approved' : 'pending',
                        'payment_method' => 'none',
                        'token' => '',
                        'transaction_id' => ''
                    ]
                );
                if ($result === false) {
                    throw new RuntimeException('Error saving to database');
                }
                $id = $wpdb->insert_id;
                $DOPBSP->classes->backend_calendar_schedule->setApproved($id);
                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, (string) $lockme_id,
                        $this->plugin->AnonymizeData(['extid' => $id])
                    );
                    return true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $res = $wpdb->get_row(
                        $wpdb->prepare(
                            'SELECT * FROM %i WHERE `id` = %d',
                            $DOPBSP->tables->reservations,
                            $data['extid']
                        )
                    );
                    if (!$res) {
                        throw new RuntimeException('No reservation');
                    }
                    if ($data['from_date'] && $data['from_hour'] &&
                        ($data['from_date'] != $data['date'] || $data['from_hour'] != $data['hour'])) {
                        $DOPBSP->classes->backend_calendar_schedule->setCanceled($res->id);

                        $this->ensureDayExists($calendar_id, $data['date']);

                        $day_history = $DOPBSP->classes->backend_calendar_schedule->daysHoursHistory(
                            $data['date'],
                            $hour,
                            '',
                            $calendar_id
                        );
                        $result = $wpdb->update(
                            $DOPBSP->tables->reservations,
                            [
                                'check_in' => $data['date'],
                                'start_hour' => $hour,
                                'days_hours_history' => json_encode($day_history)
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
                            'SELECT * FROM %i WHERE `id` = %d',
                            $DOPBSP->tables->reservations,
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

    private function ensureDayExists(int $calendar_id, string $date): void
    {
        global $wpdb, $DOPBSP;

        [$year, $month, $d] = explode('-', $date);

        $day_data = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE calendar_id=%d AND day=%s',
                $DOPBSP->tables->days,
                $calendar_id,
                $date
            )
        );

        if ($day_data) {
            return;
        }

        $calendar = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id=%d',
                $DOPBSP->tables->calendars,
                $calendar_id
            )
        );

        $default_availability = $calendar->default_availability != ''
            ? $calendar->default_availability
            : '{"available": 1,"bind": 0,"price": 0,"promo": 0,"info":  "","info_body": "","info_info": "","notes": "","hours":{},"hours_definitions":[{"value":"00:00"}],"status": "available"}'
        ;

        $default_availability = json_decode($default_availability);

        $price_min  = 1000000000;
        $price_max  = 0;
        $day_data = $default_availability;
        foreach ($day_data->hours as $key_hour => $hour) {
            $day_data->hours = (array)$day_data->hours;
            $price = $day_data->hours[$key_hour]->promo == '' ? ($day_data->hours[$key_hour]->price == '' ? 0 : (float)$day_data->hours[$key_hour]->price) : (float)$day_data->hours[$key_hour]->promo;

            if ($day_data->hours[$key_hour]->price != '0') {
                $price_min = min($price, $price_min);
                $price_max = max($price, $price_max);
            }
        };

        $wpdb->insert(
            $DOPBSP->tables->days,
            [
                'unique_key' => $calendar_id.'_'.$date,
                'calendar_id' => $calendar_id,
                'day' => $date,
                'year' => $year,
                'data' => json_encode($day_data),
                'price_min' => $price_min,
                'price_max' => $price_max,
            ]
        );
    }

    /**
     * @param $roomid
     * @return mixed
     * @throws Exception
     */
    private function GetCalendar($roomid)
    {
        global $DOPBSP, $wpdb;

        $calendars = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY id DESC',
                $DOPBSP->tables->calendars
            )
        );
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_'.$calendar->id] == $roomid) {
                return $calendar->id;
            }
        }
        throw new RuntimeException('No calendar');
    }
}
