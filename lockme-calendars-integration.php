<?php
use Lockme\SDK\Lockme;

/*
Plugin Name: Lockme calendars integration
Plugin URI:  https://github.com/Lustmored/lockme
Description: This plugin integrates popular booking systems with Lockme OAuth2 API.
Version:     1.1.1
Author:      Jakub Caban
Author URI:  https://lockme.pl
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');

define('LOCKME_PLUGIN_DIR', dirname(__FILE__), true);
define('LOCKME_PLUGIN_FILE', __FILE__, true);

require_once __DIR__.'/vendor/autoload.php';

$lm_plugins = [
  // 'appointments'=>'Appointments',
  'dopbsp'=>'Booking System PRO',
  // 'ezscm'=>'ez Schedule',
  'booked'=>'Booked',
  // 'em'=>'Event Manager',
  // 'birchschedule' => 'BirchSchedule',
  // 'wp-booking' => 'WP Booking Calendar',
  // 'bookly' => 'Bookly',
  // 'salon'=>'Salon Booking Plugin',
  // 'cpabc'=>'Appointment Booking Calendar',
  // 'woo'=>"WooCommerce Bookings"
  'wpdevart'=>"Booking Calendar Pro WpDevArt"
];

foreach ($lm_plugins as $k=>$v) {
    require_once LOCKME_PLUGIN_DIR.'/src/plugins/'.$k.'.php';
}
include_once ABSPATH.'wp-admin/includes/plugin.php';

class LockMe_Plugin
{
    public $options;
    private $url_key;
    public $tab;

    public function __construct()
    {
        global $lm_plugins;

        if (!session_id()) {
            session_start();
        }

        $this->options = get_option("lockme_settings");

        $this->url_key = get_option("lockme_url_key");
        if (!$this->url_key) {
            $this->url_key = bin2hex(random_bytes(10));
            update_option("lockme_url_key", $this->url_key);
        }

        add_action('init', array(&$this, 'api_call'));

        foreach ($lm_plugins as $k=>$v) {
            $class = strtr("LockMe_{$k}", ['-'=>'_']);
            $class::Init($this);
        }

        if (is_admin()) {
            $this->tab = isset($_GET['tab']) ? $_GET['tab'] : 'api_options';
            add_action('admin_menu', array(&$this, 'admin_init'));
            add_action('admin_init', array(&$this, 'admin_register_settings'));
        }
    }

    public function api_call()
    {
        global $lm_plugins;

        // Check for OAuth2 state
        $code = $_GET['code'];
        $state = $_GET['state'];
        if ($code && $state) {
            try {
                $api = $this->GetApi();
                $token = $api->getTokenForCode($code, $state);
                if ($token) {
                    update_option("lockme_oauth2_token", $token);
                }
                wp_redirect("options-general.php?page=lockme_integration&tab=api_options");
                exit;
            } catch (Exception $e) {
                wp_redirect("options-general.php?page=lockme_integration&tab=api_options");
                exit;
            }
        }

        // Proceed with API callback
        $api = $_GET['lockme_api'];
        if ($api != $this->url_key) {
            return;
        }

        define("LOCKME_MESSAGING", 1);

        try {
            $messageid = $_SERVER['HTTP_X_MESSAGEID'];
            $api = $this->GetApi();
            $message = $api->GetMessage($messageid);

            foreach ($lm_plugins as $k=>$v) {
                $class = strtr("LockMe_{$k}", ['-'=>'_']);
                if ($class::GetMessage($message)) {
                    $api->MarkMessageRead($messageid);
                    break;
                }
            }

            echo "OK";
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        die();
    }

    public function admin_init()
    {
        add_options_page('Integracja z Lockme', 'Lockme', 'manage_options', 'lockme_integration', array(&$this, 'admin_page'));
    }

    public function admin_register_settings()
    {
        global $lm_plugins;

        register_setting('lockme-admin', 'lockme_settings');

        add_settings_section(
      'lockme_settings_section',
      "Ustawienia API",
      function () {
          echo '<p>Podstawowe dane partnera LockMe - dostępne na <a href="https://panel.lockme.pl/" target="_blank">panel.lockme.pl</a></p>';
      },
      'lockme-admin'
    );

        $options = $this->options;

        add_settings_field(
      "client_id",
      "Client ID",
      function () use ($options) {
          echo '<input name="lockme_settings[client_id]"  type="text" value="'.$options["client_id"].'" />';
      },
      'lockme-admin',
      'lockme_settings_section',
      array()
    );

        add_settings_field(
      "client_secret",
      "Client secret",
      function () use ($options) {
          echo '<input name="lockme_settings[client_secret]" type="text" value="'.$options["client_secret"].'" />';
      },
      'lockme-admin',
      'lockme_settings_section',
      array()
    );

        add_settings_field(
      "api_beta",
      "Testowa wersja Lockme",
      function () use ($options) {
          echo '<input type="checkbox" name="lockme_settings[api_beta]" value="1"  '.checked(1, $options['api_beta'], false).' />';
      },
      'lockme-admin',
      'lockme_settings_section',
      array()
    );

        add_settings_field(
      "api_url",
      "URL callback dla Lockme",
      function () {
          echo '<input readonly type="text" value="'.get_site_url().'/?lockme_api='.$this->url_key.'" /> - remember to enable OAuth2 based callbacks!';
      },
      'lockme-admin',
      'lockme_settings_section',
      array()
    );

        foreach ($lm_plugins as $k=>$v) {
            $class = strtr("LockMe_{$k}", ['-'=>'_']);
            $class::RegisterSettings($this);
        }
    }

    public function admin_page()
    {
        global $lm_plugins;
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h2>Integracja LockMe</h2>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '    <a href="?page=lockme_integration&tab=api_options" class="nav-tab '.($this->tab=='api_options'?'nav-tab-active':'').'">Ustawienia API</a>';
        foreach ($lm_plugins as $k=>$v) {
            $k = strtr($k, ['-'=>'_']);
            echo '    <a href="?page=lockme_integration&tab='.$k.'_plugin" class="nav-tab '.($this->tab==$k.'_plugin'?'nav-tab-active':'').'">Wtyczka '.$v.'</a>';
        }
        echo '</h2>';

        echo '<form method="post" action="options.php">';

        switch ($this->tab) {
      case 'api_options':
        settings_fields('lockme-admin');
        do_settings_sections('lockme-admin');

        $api = $this->GetApi();
        if ($api) {
            try {
                $test = $api->Test();
                if ($test == "OK") {
                    echo '<p>Połączenie z LockMe API <strong>POPRAWNE</strong>.</p>';
                    $user = $api->getResourceOwner();
                    echo '<p>Zalogowano do Lockme jako: <strong>'.$user->toArray()['nick'].'</strong></p>';
                } else {
                    echo '<p><strong>BŁĄD</strong> odpowiedzi.</p>';
                }
            } catch (Exception $e) {
                echo '<p><strong>BŁĄD API: '.$e->getMessage().'.</p>';
            }
            $authorizationUrl = $api->getAuthorizationUrl(['rooms_manage']);
            echo '<a href="'.$authorizationUrl.'">Kliknij tutaj</a>, aby połączyć wtyczkę z Lockme.';
        } else {
            echo '<p><strong>BRAK połączenia z API</strong>.</p>';
        }
        break;
      default:
        foreach ($lm_plugins as $k=>$v) {
            $k = strtr($k, ['-'=>'_']);
            $class = "LockMe_{$k}";
            if ($this->tab == $k."_plugin") {
                $class::DrawForm($this);
            }
        }
        break;
    }

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function GetApi()
    {
        if ($this->options['client_id'] && $this->options['client_secret']) {
            $lm = new Lockme([
        "clientId" => $this->options['client_id'],
        "clientSecret" => $this->options['client_secret'],
        "beta" => $this->options["api_beta"],
        "redirectUri" => get_admin_url()."options-general.php?page=lockme_integration&tab=api_options"
      ]);
            $token = get_option("lockme_oauth2_token");
            if ($token) {
                try {
                    $new_token = $lm->setDefaultAccessToken($token);
                    update_option("lockme_oauth2_token", $new_token);
                } catch (Exception $e) {
                }
            }
            return $lm;
        }
        return null;
    }
}

global $lockme;
$lockme = new LockMe_Plugin();
