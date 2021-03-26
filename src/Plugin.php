<?php
namespace LockmeIntegration;

use Exception;
use League\OAuth2\Client\Token\AccessToken;
use Lockme\SDK\Lockme;
use LockmeIntegration\Plugins\Appointments;
use LockmeIntegration\Plugins\Booked;
use LockmeIntegration\Plugins\Bookly;
use LockmeIntegration\Plugins\Cpabc;
use LockmeIntegration\Plugins\Dopbsp;
use LockmeIntegration\Plugins\Easyapp;
use LockmeIntegration\Plugins\Ezscm;
use LockmeIntegration\Plugins\Woo;
use LockmeIntegration\Plugins\WPBooking;
use LockmeIntegration\Plugins\WPDevArt;

include_once ABSPATH.'wp-admin/includes/plugin.php';

class Plugin
{
    public $options;
    public $tab;
    private $url_key;
    private $plugins = [
        'appointments'=> Appointments::class,
        'booked'=> Booked::class,
        'bookly' => Bookly::class,
        'cpabc' => Cpabc::class,
        'dopbsp'=> Dopbsp::class,
        'easyapp'=> Easyapp::class,
        'ezscm' => Ezscm::class,
        'woo'=> Woo::class,
        'wpdevart'=> WPDevArt::class,
        'wp_booking' => WPBooking::class,
    ];
    /**
     * @var PluginInterface[]
     */
    private $available_plugins = [];

    public function __construct()
    {
        if (!session_id()) {
            session_start();
        }

        $this->options = get_option('lockme_settings');

        $this->url_key = get_option('lockme_url_key');
        if (!$this->url_key) {
            try {
                $this->url_key = bin2hex(random_bytes(10));
            } catch (Exception $e) {
                $this->url_key = '39836295616564325481';
            }
            update_option('lockme_url_key', $this->url_key);
        }

        add_action('init', array(&$this, 'api_call'));

        foreach ($this->plugins as $k=>$v) {
            /** @var PluginInterface $plugin */
            $plugin = new $v($this);
            if($plugin->CheckDependencies()){
                $this->available_plugins[$k] = $plugin;
            }
        }

        if (is_admin()) {
            $this->tab = $_GET['tab'] ?? 'api_options';

            add_action('admin_menu', array(&$this, 'admin_init'));
            add_action('admin_init', array(&$this, 'admin_register_settings'));
        }
    }

    public function api_call(): void
    {
        // Check for OAuth2 state
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        if ($code && $state) {
            try {
                $api = $this->GetApi();
                $token = $api->getTokenForCode($code, $state);
                if ($token instanceof AccessToken) {
                    update_option('lockme_oauth2_token', $token);
                }
                wp_redirect('options-general.php?page=lockme_integration&tab=api_options');
                exit;
            } catch (Exception $e) {
                wp_redirect('options-general.php?page=lockme_integration&tab=api_options');
                exit;
            }
        }

        if(isset($_POST['oauth_token'])){
            $token = stripslashes($_POST['oauth_token']);
            $api = $this->GetApi();
            try {
                $api->loadAccessToken(
                    function () use ($token) { return json_decode($token, true); },
                    function ($token) { update_option('lockme_oauth2_token', $token); }
                );
            }catch (Exception $e){
            }
        }

        // Proceed with API callback
        $api = $_GET['lockme_api'] ?? null;
        if ($api !== $this->url_key) {
            return;
        }

        define('LOCKME_MESSAGING', 1);

        try {
            $messageid = $_SERVER['HTTP_X_MESSAGEID'];
            $api = $this->GetApi();
            $message = $api->GetMessage((int) $messageid);

            foreach ($this->available_plugins as $k=>$plugin) {
                if ($plugin->GetMessage($message)) {
                    $api->MarkMessageRead((int) $messageid);
                    break;
                }
            }

            echo 'OK';
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        die();
    }

    public function GetApi(): ?Lockme
    {
        if ($this->options['client_id'] && $this->options['client_secret']) {
            $lm = new Lockme([
                'clientId' => $this->options['client_id'],
                'clientSecret' => $this->options['client_secret'],
                'redirectUri' => get_admin_url().'options-general.php?page=lockme_integration&tab=api_options',
                'api_domain' => $this->options['api_domain'] ?: 'https://api.lock.me'
            ]);
            try{
                $lm->loadAccessToken(
                    function () { return get_option('lockme_oauth2_token'); },
                    function ($token) { update_option('lockme_oauth2_token', $token); }
                );
            } catch (Exception $e) {
                return $lm;
            }
            return $lm;
        }
        return null;
    }

    public function admin_init(): void
    {
        add_options_page('Integracja z Lockme', 'Lockme', 'manage_options', 'lockme_integration', array(&$this, 'admin_page'));
    }

    public function admin_register_settings(): void
    {
        register_setting('lockme-admin', 'lockme_settings');

        add_settings_section(
            'lockme_settings_section',
            'Ustawienia API',
            static function () {
                echo '<p>Podstawowe dane partnera LockMe - dostępne na <a href="https://panel.lockme.pl/" target="_blank">panel.lockme.pl</a></p>';
            },
            'lockme-admin'
        );

        add_settings_field(
            'client_id',
            'Client ID',
            function () {
                echo '<input name="lockme_settings[client_id]"  type="text" value="'.$this->options['client_id'].'" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'client_secret',
            'Client secret',
            function () {
                echo '<input name="lockme_settings[client_secret]" type="text" value="'.$this->options['client_secret'].'" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'rodo_mode',
            'RODO mode (anonymize data)',
            function () {
                echo '<input type="checkbox" name="lockme_settings[rodo_mode]" value="1"  '.checked(1, $this->options['rodo_mode'], false).' /> <small>Jeśli włączysz tę funkcję to w ramach wymiany danych przez API pomiędzy Twoją stroną a Lockme będzie wysyłana jedynie informacja o dacie i godzinie wizyty. Wszystkie dane osobowe klienta zostaną tylko na Twojej stronie. <strong>Uwaga!</strong> jeśli taką rezerwację wyedytujesz z poziomu panelu lockme to istnieje ryzyko, że skasuje to dane w Twoim systemie rezerwacyjnym. Pamiętaj aby takimi rezerwacjami zarządzać tylko przez swój system.</small>';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'api_domain',
            'API domain',
            function () {
                echo '<input name="lockme_settings[api_domain]" type="text" value="'.$this->options['api_domain'].'" placeholder="https://api.lock.me" />';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'api_url',
            'URL callback dla Lockme',
            function () {
                echo '<input readonly type="text" value="'.get_site_url().'/?lockme_api='.$this->url_key.'" onfocus="select()" /> - remember to enable OAuth2 based callbacks!';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        add_settings_field(
            'redirect_uri',
            'Redirect URI',
            function () {
                echo '<input readonly type="text" value="'.get_admin_url().'options-general.php?page=lockme_integration&tab=api_options" onfocus="select()" /> - set in Lockme panel to be able to connect';
            },
            'lockme-admin',
            'lockme_settings_section',
            array()
        );

        foreach ($this->available_plugins as $k=>$plugin) {
            $plugin->RegisterSettings();
        }
    }

    public function admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h2>Integracja LockMe</h2>';

        echo '<h2 class="nav-tab-wrapper">';
        echo '    <a href="?page=lockme_integration&tab=api_options" class="nav-tab '.($this->tab ===
                                                                                       'api_options'?'nav-tab-active':'').'">Ustawienia API</a>';
        foreach ($this->available_plugins as $k=>$plugin) {
            echo '    <a href="?page=lockme_integration&tab='.$k.'_plugin" class="nav-tab '.($this->tab === $k.'_plugin'?'nav-tab-active':'').'">Wtyczka '.$plugin->getPluginName().'</a>';
        }
        echo '</h2>';

        echo '<form method="post" action="options.php">';

        if ($this->tab === 'api_options') {
            settings_fields('lockme-admin');
            do_settings_sections('lockme-admin');

            $api = $this->GetApi();
            if ($api) {
                try {
                    $test = $api->Test();
                    if ($test === 'OK') {
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
                echo '<p><a href="'.$authorizationUrl.'">Kliknij tutaj</a>, aby połączyć wtyczkę z Lockme.</p>';
                echo '<p>Custom Oauth2 Access Token (use <b>only if you know what are you doing</b>):</p>';
                echo '<p><textarea name="oauth_token"></textarea></p>';
            } else {
                echo '<p><strong>BRAK połączenia z API</strong>.</p>';
            }
            $token = get_option('lockme_oauth2_token');
            echo '<p>Current access token (don\'t share with anyone!)</p>';
            echo '<p><textarea readonly>'.\json_encode($token).'</textarea></p>';
        } else {
            foreach ($this->available_plugins as $k=>$plugin) {
                if ($this->tab === $k.'_plugin') {
                    try {
                        $plugin->DrawForm();
                    }catch (Exception $e){
                        echo '<p>Configuration error. Details: '.$e->getMessage().'</p>';
                    }
                }
            }
        }

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function AnonymizeData(array $data): array
    {
        if($this->options['rodo_mode']){
            return array_filter(
                $data,
                static function($key) {
                    return in_array(
                        $key,
                        [
                            'roomid',
                            'date',
                            'hour',
                            'status',
                            'extid'
                        ]
                    );
                },
                ARRAY_FILTER_USE_KEY
            );
        }
        return $data;
    }
}
