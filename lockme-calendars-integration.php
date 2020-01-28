<?php

use LockmeIntegration\Plugin;

/*
Plugin Name: Lockme calendars integration
Plugin URI:  https://github.com/Lustmored/lockme
Description: This plugin integrates popular booking systems with Lockme OAuth2 API.
Version:     1.2.4
Author:      Jakub Caban
Author URI:  https://lockme.pl
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');

require_once __DIR__.'/vendor/autoload.php';

$lockme = new Plugin();
