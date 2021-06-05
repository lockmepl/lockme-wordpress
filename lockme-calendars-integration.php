<?php

use LockmeDep\LockmeIntegration\Plugin;

/*
Plugin Name: Lockme calendars integration
Plugin URI:  https://github.com/Lustmored/lockme
Description: This plugin integrates popular booking systems with Lockme OAuth2 API.
Version:     2.1.5
Author:      Jakub Caban
Author URI:  https://lockme.pl
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');

require_once __DIR__.'/build/vendor/autoload.php';

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

$lockme = new Plugin();
