<?php

/*
Plugin Name: Lockme calendars integration
Plugin URI:  https://github.com/Lustmored/lockme
Description: This plugin integrates popular booking systems with Lockme OAuth2 API.
Version:     2.2.1
Author:      Jakub Caban
Author URI:  https://lockme.pl
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

namespace LockmeDep {
    if (function_exists('\\booked_apply_custom_timeslots_details_filter')) {
        function booked_apply_custom_timeslots_details_filter(...$args) {
            \booked_apply_custom_timeslots_details_filter(...$args);
        }
    }
    if (function_exists('\\booked_apply_custom_timeslots_filter')) {
        function booked_apply_custom_timeslots_filter(...$args) {
            \booked_apply_custom_timeslots_filter(...$args);
        }
    }
}

namespace {

    defined('ABSPATH') or die('No script kiddies please!');

    require_once __DIR__.'/build/vendor/scoper-autoload.php';

    function aliasDeps()
    {
        $global = [
            'WP_Query',
            'EADBModels',
            'EATableColumns',
            'WC_Booking',
            'wp_booking_calendar_lists',
            'wp_booking_calendar_public_reservation',
            'wp_booking_calendar_reservation',
            'wp_booking_calendar_slot',
            'wpdevart_bc_BookingCalendar',
            'wpdevart_bc_ControllerReservations',
            'wpdevart_bc_ModelCalendars',
            'wpdevart_bc_ModelExtras',
            'wpdevart_bc_ModelForms',
            'wpdevart_bc_ModelThemes',
        ];
        foreach ($global as $className) {
            $alias = "LockmeDep\\{$className}";
            if (class_exists($className) && !class_exists($alias)) {
                class_alias($className, "LockmeDep\\{$className}");
            }
        }
    }

    aliasDeps();

    $lockme = new LockmeDep\LockmeIntegration\Plugin();
}
