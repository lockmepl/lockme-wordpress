<?php

namespace LockmeIntegration\Plugins;

use Bookly\Lib\Entities\Appointment;
use Bookly\Lib\Entities\Customer;
use Bookly\Lib\Entities\CustomerAppointment;
use Bookly\Lib\Entities\Staff;
use DateTime;
use DateTimeZone;
use Exception;
use LockmeIntegration\Plugin;
use LockmeIntegration\PluginInterface;
use RuntimeException;
use function method_exists;

class Bookly implements PluginInterface
{
    public $ajaxdata;
    private $options;
    private $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->options = get_option('lockme_bookly');
        if ($this->options['use'] && $this->CheckDependencies()) {
            add_filter('wp_die_ajax_handler', [$this, 'Ajax'], 15, 1);

            add_action('init', function () {
                if (is_admin() && wp_doing_ajax()) {
                    switch ($_POST['action']) {
                        case 'bookly_delete_appointment':
                            $this->ajaxdata = $this->AppData($_POST['appointment_id']);
                            break;
                        case 'bookly_delete_customer_appointments':
                            $this->ajaxdata = [];
                            foreach ($_POST['data'] as $caid) {
                                $info = Appointment::query('a')
                                                   ->select('a.id, SUM( ca.number_of_persons ) AS total_number_of_persons, a.staff_id, a.service_id, a.start_date, a.end_date, a.internal_note')
                                                   ->leftJoin('CustomerAppointment', 'ca',
                                                       'ca.appointment_id = a.id')
                                                   ->where('ca.id', $caid)
                                                   ->fetchRow();
                                $this->ajaxdata[$info['id']] = $this->AppData($info['id'], $info);
                            }
                            break;
                    }
                }
                if ($_GET['bookly_export']) {
                    $this->ExportToLockMe();
                    $_SESSION['bookly_export'] = 1;
                    wp_redirect('?page=lockme_integration&tab=bookly_plugin');
                    exit;
                }
            });
        }
    }

    public function AppData($id, $info = null)
    {
        if ($info === null) {
            $info = Appointment::query('a')
                               ->select('SUM( ca.number_of_persons ) AS total_number_of_persons, a.staff_id, a.service_id, a.start_date, a.end_date, a.internal_note')
                               ->leftJoin('CustomerAppointment', 'ca', 'ca.appointment_id = a.id')
                               ->where('a.id', $id)
                               ->fetchRow();
        }

        return
            $this->plugin->AnonymizeData(
                [
                    'roomid' => $this->options['calendar_'.$info['staff_id']],
                    'date' => date('Y-m-d', strtotime($info['start_date'])),
                    'hour' => date('H:i:s', strtotime($info['start_date'])),
                    'pricer' => 'API',
                    'status' => 1,
                    'extid' => $id
                ]
            );
    }

    public function ExportToLockMe()
    {
        $bookings = CustomerAppointment::query('ca')
                                       ->select(
                                           '`a`.`id`,
            `a`.`staff_id`,
            `a`.`service_id`,
            `a`.`start_date`,
            DATE_ADD(`a`.`end_date`, INTERVAL `a`.`extras_duration` SECOND) AS `end_date`,
            `ca`.`extras`,
            COALESCE(`s`.`padding_left`,0) AS `padding_left`,
            COALESCE(`s`.`padding_right`,0) AS `padding_right`,
            SUM(`ca`.`number_of_persons`) AS `number_of_bookings`'
                                       )
                                       ->leftJoin('Appointment', 'a',
                                           '`a`.`id` = `ca`.`appointment_id`')
                                       ->leftJoin('StaffService', 'ss',
                                           '`ss`.`staff_id` = `a`.`staff_id` AND `ss`.`service_id` = `a`.`service_id`')
                                       ->leftJoin('Service', 's', '`s`.`id` = `a`.`service_id`')
                                       ->whereGte('a.start_date', date('Y-m-d'))
                                       ->groupBy('a.id')
                                       ->fetchArray();
        foreach ($bookings as $b) {
            $this->AddEditReservation($b['id']);
        }
    }

    public function AddEditReservation($id)
    {
        if (!is_numeric($id)) {
            return;
        }
        if (defined('LOCKME_MESSAGING')) {
            return;
        }

        $api = $this->plugin->GetApi();
        $appdata = $this->AppData($id);
        $lockme_data = [];

        try {
            $lockme_data = $api->Reservation($appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }

        try {
            if (!$lockme_data) { //Add new
                $api->AddReservation($appdata);
            } else { //Update
                $api->EditReservation($appdata['roomid'], "ext/{$id}", $appdata);
            }
        } catch (Exception $e) {
        }
    }

    public function DrawForm()
    {
        if (!$this->CheckDependencies()) {
            echo '<p>Nie posiadasz wymaganej wtyczki.</p>';
            return;
        }

        if ($_SESSION['bookly_export']) {
            echo '<div class="updated">';
            echo '  <p>Eksport został wykonany.</p>';
            echo '</div>';
            unset($_SESSION['bookly_export']);
        }
        settings_fields('lockme-bookly');
        do_settings_sections('lockme-bookly');
    }

    public function Ajax($msg)
    {
        $data = json_decode(ob_get_contents(), true);
        switch ($_REQUEST['action']) {
            case 'bookly_save_appointment_form':
                $this->AddEditReservation($data['data']['id']);
                break;
            case 'bookly_delete_appointment':
                $this->Delete($_POST['appointment_id'], $this->ajaxdata);
                break;
            case 'bookly_delete_customer_appointments':
                $this->DeleteBatch();
                break;
            case 'bookly_render_complete':
                foreach ((array)$_SESSION['bookly']['forms'][$_REQUEST['form_id']]['booking_numbers'] as $id) {
                    $this->AddEditReservation($id);
                }
                break;
        }
        return $msg;
    }

    public function DeleteBatch()
    {
        foreach ($this->ajaxdata as $id => $ca) {
            if ($id && $ca) {
                $this->Delete($id, $ca);
            }
        }
    }

    public function Delete($id, $appdata = null)
    {
        if (defined('LOCKME_MESSAGING')) {
            return;
        }

        $api = $this->plugin->GetApi();
        if ($appdata === null) {
            $appdata = $this->AppData($id);
        }

        try {
            $api->DeleteReservation($appdata['roomid'], "ext/{$id}");
        } catch (Exception $e) {
        }
    }

    public function GetMessage(array $message)
    {
        if (!$this->options['use'] || !$this->CheckDependencies()) {
            return false;
        }

        $data = $message['data'];
        $roomid = $message['roomid'];
        $lockme_id = $message['reservationid'];
        $timestamp = strtotime($data['date'].' '.$data['hour']);
        $surname = $data['surname'];
        if ($data['source'] === 'web' || $data['source'] === 'widget') {
            $surname .= ' (LockMe)';
        }

        $lm_datetimezone = new DateTimeZone('Europe/Warsaw');
        $lm_datetime = new DateTime($data['date'].' '.$data['hour'], $lm_datetimezone);
        $offset = $lm_datetimezone->getOffset($lm_datetime)/60;

        $calendar_id = $this->GetCalendar($roomid);
        if (!$calendar_id) {
            throw new RuntimeException('No calendar');
        }
        $staff = new Staff();
        $staff->load($calendar_id);
        $staff_id = $staff->getId();
        if(method_exists($staff, 'getStaffServices')) {
            $service = $staff->getStaffServices()[0]->service;
        } else {
            $service = $staff->getServicesData()[0]['service'];
        }
        $service_id = $service->getId();

        switch ($message['action']) {
            case 'add':
                $customer = new Customer();
                $customer->loadBy([
                    'full_name' => $data['name'].' '.$surname,
                    'email' => $data['email']
                ]);
                $customer->setEmail($data['email']);
                $customer->setFirstName($data['name']);
                $customer->setLastName($surname);
                $customer->setPhone($data['phone']);
                $customer->save();

                $appointment = new Appointment();
                $appointment->loadBy([
                    'service_id' => $service_id,
                    'staff_id' => $staff_id,
                    'start_date' => date('Y-m-d H:i:s', $timestamp),
                ]);
                if ($appointment->isLoaded() == false) {
                    $appointment->setServiceId($service_id);
                    $appointment->setStaffId($staff_id);
                    $appointment->setStartDate(date('Y-m-d H:i:s', $timestamp));
                    $appointment->setEndDate(date('Y-m-d H:i:s', $timestamp + $service->getDuration()));
                    $appointment->save();
                }

                $customer_appointment = new CustomerAppointment();
                $customer_appointment->setCustomerId($customer->getId())
                                     ->setAppointmentId($appointment->getId())
                                     ->setPaymentId(null)
                                     ->setNumberOfPersons($this->options['one_person'] ? 1 : $data['people'])
                                     ->setExtras('[]')
                                     ->setCustomFields('[]')
                                     ->setStatus(CustomerAppointment::STATUS_APPROVED)
                                     ->setTimeZoneOffset($offset)
                                     ->setCreatedFrom('backend')
                                     ->setCreated(date('Y-m-d H:i:s'))
                                     ->save();

                $id = $appointment->getId();
                if (!$id || !$customer_appointment->getId()) {
                    throw new RuntimeException('Save error');
                }

                try {
                    $api = $this->plugin->GetApi();
                    $api->EditReservation($roomid, $lockme_id, ['extid' => $id]);
                    return true;
                } catch (Exception $e) {
                }
                break;
            case 'edit':
                if ($data['extid']) {
                    $appointment = new Appointment();
                    $appointment->load($data['extid']);
                    $appointment->setStartDate(date('Y-m-d H:i:s', $timestamp));
                    $appointment->setEndDate(date('Y-m-d H:i:s', $timestamp + $service->getDuration()));
                    $appointment->save();

                    $ca = $appointment->getCustomerAppointments();
                    foreach ($ca as $c) {
                        $c->setTimeZoneOffset($offset);
                        $c->save();

                        $customer = new Customer();
                        $customer->load($c->getCustomerId());
                        $customer->setEmail($data['email']);
                        $customer->setLastName($surname);
                        $customer->setPhone($data['phone']);
                        $customer->save();
                    }
                    return true;
                }
                break;
            case 'delete':
                if ($data['extid']) {
                    $appointment = new Appointment();
                    $appointment->load($data['extid']);
                    $appointment->delete();
                    return true;
                }
                break;
        }
        return false;
    }

    private function GetCalendar($roomid)
    {
        $calendars = Staff::query()->sortBy('position')->fetchArray();
        foreach ($calendars as $calendar) {
            if ($this->options['calendar_'.$calendar['id']] == $roomid) {
                return $calendar['id'];
            }
        }
        return null;
    }

    public function RegisterSettings()
    {
        if (!$this->CheckDependencies()) {
            return false;
        }

        register_setting('lockme-bookly', 'lockme_bookly');

        add_settings_section(
            'lockme_bookly_section',
            'Ustawienia wtyczki bookly',
            static function () {
                echo '<p>Ustawienia integracji z wtyczką bookly</p>';
            },
            'lockme-bookly'
        );

        add_settings_field(
            'bookly_use',
            'Włącz integrację',
            function () {
                echo '<input name="lockme_bookly[use]" type="checkbox" value="1"  '.checked(1, $this->options['use'],
                        false).' />';
            },
            'lockme-bookly',
            'lockme_bookly_section',
            []
        );

        if ($this->options['use'] && $this->plugin->tab === 'bookly_plugin') {
            add_settings_field(
                'one_person',
                'Traktuj grupy jako jedną osobę',
                function () {
                    echo '<input name="lockme_bookly[one_person]" type="checkbox" value="1"  '.checked(1,
                            $this->options['one_person'], false).' />';
                },
                'lockme-bookly',
                'lockme_bookly_section',
                []
            );

            $api = $this->plugin->GetApi();
            $rooms = [];
            if ($api) {
                $rooms = $api->RoomList();
            }

            $calendars = Staff::query()->sortBy('position')->fetchArray();
            foreach ($calendars as $calendar) {
                add_settings_field(
                    'calendar_'.$calendar['id'],
                    'Pokój dla '.$calendar['full_name'],
                    function () use ($rooms, $calendar) {
                        echo '<select name="lockme_bookly[calendar_'.$calendar['id'].']">';
                        echo '<option value="">--wybierz--</option>';
                        foreach ($rooms as $room) {
                            echo '<option value="'.$room['roomid'].'" '.selected(1,
                                    $room['roomid'] == $this->options['calendar_'.$calendar['id']],
                                    false).'>'.$room['room'].' ('.$room['department'].')</options>';
                        }
                        echo '</select>';
                    },
                    'lockme-bookly',
                    'lockme_bookly_section',
                    []
                );
            }
            add_settings_field(
                'export_bookly',
                'Wyślij dane do LockMe',
                static function () {
                    echo '<a href="?page=lockme_integration&tab=bookly_plugin&bookly_export=1">Kliknij tutaj</a> aby wysłać wszystkie rezerwacje do kalendarza LockMe. Ta operacja powinna być wymagana tylko raz, przy początkowej integracji.';
                },
                'lockme-bookly',
                'lockme_bookly_section',
                []
            );
        }
        return true;
    }

    public function CheckDependencies()
    {
        return is_plugin_active('appointment-booking/main.php') || is_plugin_active(
                'bookly-responsive-appointment-booking-tool/main.php'
            );
    }

    public function getPluginName()
    {
        return 'Bookly';
    }
}
