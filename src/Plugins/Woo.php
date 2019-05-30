<?php

namespace LockmeIntegration\Plugins;

use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use WC_Booking;
use WP_Query;

class Woo implements PluginInterface
{
    private $options;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option("lockme_woo");

        if ($this->options['use']) {
            add_action('woocommerce_new_booking', [$this, 'AddEditReservation'], 5, 1);
            foreach (['unpaid', 'pending-confirmation', 'confirmed', 'paid', 'complete', 'in-cart'] as $action) {
                add_action('woocommerce_booking_'.$action, [$this, 'AddEditReservation'], 5, 1);
            }
            add_action('woocommerce_booking_cancelled', [$this, 'Delete'], 5, 1);
            add_action('woocommerce_booking_trash', [$this, 'Delete'], 5, 1);
            add_action('woocommerce_booking_was-in-cart', [$this, 'Delete'], 5, 1);
            add_action('before_delete_post', [$this, 'Delete'], 5, 1);
            add_action('edit_post', [$this, 'AddEditReservation']);

            add_action('init', function () {
                if ($_GET['woo_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['woo_export'] = 1;
                    wp_redirect("?page=lockme_integration&tab=woo_plugin");
                    exit;
                }
            });
        }
    }

    public function ExportToLockMe()
    {
        $args = [
            'post_type' => 'wc_booking',
            'meta_key' => '_booking_start',
            'meta_value' => date('YmdHis'),
            'meta_compare' => '>=',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ];
        $loop = new WP_Query($args);
        while ($loop->have_posts()) {
            $loop->the_post();
            $post = $loop->post;
            var_dump($post);
            $this->AddEditReservation($post->ID);
        }
    }

    public function CheckDependencies()
    {
        return is_plugin_active("woocommerce-bookings/woocommmerce-bookings.php");
    }

    public function RegisterSettings()
    {
        if (!$this->CheckDependencies()) {
            return false;
        }

        register_setting('lockme-woo', 'lockme_woo');

        add_settings_section(
            'lockme_woo_section',
            "Ustawienia wtyczki Woocommerce Bookings",
            function () {
                echo '<p>Ustawienia integracji z wtyczką Woocommerce Bookings</p>';
            },
            'lockme-woo'
        );

        add_settings_field(
            "woo_use",
            "Włącz integrację",
            function () {
                echo '<input name="lockme_woo[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-woo',
            'lockme_woo_section',
            []
        );

        if ($this->options['use'] && $this->plugin->tab == 'woo_plugin') {
            add_settings_field(
                "slot_length",
                "Dłogośc slota (w min)",
                function () {
                    echo '<input name="lockme_woo[slot_length]" type="text" value="'.$this->options["slot_length"].'" />';
                },
                'lockme-woo',
                'lockme_woo_section',
                []
            );

            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }

            $args = [
                'post_type' => 'product'
            ];
            $calendars = get_posts($args);

            foreach ($calendars as $calendar) {
                add_settings_field(
                    "calendar_".$calendar->ID,
                    "Pokój dla ".$calendar->post_title,
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_woo[calendar_'.$calendar->ID.']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar->ID],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-woo',
                    'lockme_woo_section',
                    []
                );
            }
            add_settings_field(
                "export_woo",
                "Wyślij dane do LockMe",
                function () {
                    echo '<a href="?page=lockme_integration&tab=woo_plugin&woo_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-woo',
                'lockme_woo_section',
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
//     $booking = new WC_Booking(2918);
//     var_dump(get_post_meta(2918));

        if ($_SESSION['woo_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['woo_export']);
        }
        settings_fields('lockme-woo');
        do_settings_sections('lockme-woo');
    }

    public function AddEditReservation($postid)
    {
        global $lockme;
        if (!is_numeric($postid)) {
            return false;
        }
        if (defined("LOCKME_MESSAGING")) {
            return false;
        }
        clean_post_cache($postid);
        $booking = new WC_Booking($postid);
        /** @noinspection PhpUndefinedFieldInspection */
        if (!$booking->populated || $booking->post->post_type != 'wc_booking') {
            return false;
        }

        if (in_array($booking->status, ['cancelled', 'trash', 'was-in-cart'])) {
            return $this->Delete($postid);
        }

        $api = $lockme->GetApi();
        $appdata = $this->AppData($booking);

        $lockme_data = null;
        try {
            $lockme_data = $api->Reservation($appdata["roomid"], "ext/{$postid}");
        } catch (Exception $e) {
        };

        try {
            if (!$lockme_data) { //Add new
                $api->AddReservation($appdata);
            } else { //Update
                $api->EditReservation($appdata["roomid"], "ext/{$postid}", $appdata);
            }
        } catch (Exception $e) {
        }
        return true;
    }

    public function Delete($postid)
    {
        global $lockme;

        if (defined("LOCKME_MESSAGING")) {
            return null;
        }
        clean_post_cache($postid);
        $booking = new WC_Booking($postid);
        /** @noinspection PhpUndefinedFieldInspection */
        if (!$booking->populated || $booking->post->post_type != 'wc_booking') {
            return null;
        }

        if (!in_array($booking->status, ['cancelled', 'trash', 'was-in-cart'])) {
            return $this->AddEditReservation($postid);
        }

        $api = $lockme->GetApi();
        $appdata = $this->AppData($booking);

        try {
            $api->DeleteReservation($appdata["roomid"], "ext/{$booking->id}");
        } catch (Exception $e) {
        }
        return null;
    }

    public function GetMessage(array $message)
    {
        global $lockme;
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message["data"];
        $roomid = $message["roomid"];
        $lockme_id = $message["reservationid"];
        $date = $data['date'];
        $hour = date("H:i:s", strtotime($data['hour']));
        $start = strtotime($date.' '.$hour);

        $calendar_id = $this->GetCalendar($roomid);

        switch ($message["action"]) {
            case "add":
                $booking = create_wc_booking(
                    $calendar_id,
                    [
                        'product_id' => $calendar_id,
                        'start_date' => $start,
                        'end_date' => $start + $this->options['slot_length']*60,
                        'persons' => $data['people'],
                        'cost' => $data['price']
                    ],
                    'pending-confirmation',
                    true
                );

                if ($booking) {
                    try {
                        $api = $lockme->GetApi();
                        $api->EditReservation($roomid, $lockme_id, ["extid" => $booking->id]);
                        return true;
                    } catch (Exception $e) {
                    }
                } else {
                    throw new Exception("Saving error");
                }
                break;
            case "edit":
                if ($data['extid']) {
                    $booking = new WC_Booking($data['extid']);
                    /** @noinspection PhpUndefinedFieldInspection */
                    if (!$booking->populated || $booking->post->post_type != 'wc_booking') {
                        return false;
                    }

                    if ($booking->status != 'confirmed' && $data['status']) {
                        $booking->update_status('confirmed');
                    }

                    $meta_args = [
                        '_booking_persons' => $data['people'],
                        '_booking_cost' => $data['price'],
                        '_booking_start' => date('YmdHis', $start),
                        '_booking_end' => date('YmdHis', $start + $this->options['slot_length']*60)
                    ];
                    foreach ($meta_args as $key => $value) {
                        update_post_meta($booking->id, $key, $value);
                    }
                    return true;
                }
                break;
            case 'delete':
                if ($data["extid"]) {
                    wp_delete_post($data["extid"]);
                    return true;
                }
                break;
        }
        return false;
    }

    private function AppData($booking)
    {
        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$booking->product_id],
                    'date' => date('Y-m-d', $booking->start),
                    'hour' => date("H:i:s", $booking->start),
                    'pricer' => "API",
                    'price' => $booking->cost,
                    'status' => $booking->status == 'in-cart' ? 0 : 1,
                    'people' => is_array($booking->persons) ? count($booking->persons) : $booking->persons,
                    'extid' => $booking->id
                ]
            );
    }

    private function GetCalendar($roomid)
    {
        $args = [
            'post_type' => 'product'
        ];
        $calendars = get_posts($args);
        foreach ($calendars as $calendar) {
            if ($this->options["calendar_".$calendar->ID] == $roomid) {
                return $calendar->ID;
            }
        }
        return null;
    }

    public function getPluginName()
    {
        return "WooCommerce Bookings";
    }
}
