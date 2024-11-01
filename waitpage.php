<?php
/**
 * Plugin Name: Waitpage
 * Description: The only plugin you need
 * Version: 1.0
 * Author: Waitpage.io
 * Author URI: https://www.waitpage.io
 */
require_once 'vendor/autoload.php';

function waitpage_user_ip_address() {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

function waitpage_is_crawler() {
    $CrawlerDetect = new Jaybizzle\CrawlerDetect\CrawlerDetect;
    return $CrawlerDetect->isCrawler();
}

function waitpage_should_skip_on_all_requests() {
    return (
        is_super_admin()
        || waitpage_user_ip_address() === '127.0.0.1'
        || waitpage_is_crawler()
    );
}

function waitpage_should_skip_on_regular_requests() {
    return (
        waitpage_is_ajax()
        || strpos($_SERVER['REQUEST_URI'], '/wp-json/waitpage') !== false
        || strpos($_SERVER['REQUEST_URI'], '/wp-admin') !== false
        || strpos($_SERVER['REQUEST_URI'], '/wp-login') !== false
        || strpos($_SERVER['REQUEST_URI'], '/wp-config') !== false
        || $_SERVER['REQUEST_METHOD'] === 'POST'
    );
}

function waitpage_is_ajax() {
    if( ! empty( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ] ) &&
        strtolower( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ]) == 'xmlhttprequest' ) {
        return true;
    }

    if ( wp_doing_ajax() ) {
        return true;
    }

    return false;
}

// Standard run on every request
function waitpage_check_request() {

    // Check if request should be skipped
    if(waitpage_should_skip_on_all_requests() || waitpage_should_skip_on_regular_requests()) {
        return;
    }

    return run_waitpage();
}

function waitpage_check_token($siteId, $token, $options) {
    $publicKey = <<<EOD
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuWaaXhtsf7nOA4roX3uN
jHCx3luWko7thAP/dJkNg/0TIgNsua1HvIO7xcuN31vXNsAyP6nrRgloxfg7npaQ
9LM/m81C+QW/mWFjfsq6YeqJ97MeJxwM7HOpYqIxscdMEx4D+0IiGNlPlTUqp2ql
4pauNxVI9YhCluyzOYh3x87hAEppBJcQSIMeSsvTv8LCBaiIRPU1zHWB2HrBHACR
8O/he1+4ZjeqHHX/kKoSvnMvyEZQYOgb3kwD69fthm3rpIQ7JFYJnO1yGaUCK74K
HRoxCn5FNIsRC5ENUoNc1tOvoI+pNCu8u2fOK9r1jbXDKokqpOf991zt79hqt/qy
BwIDAQAB
-----END PUBLIC KEY-----
EOD;

    $decoded = \Firebase\JWT\JWT::decode($token, $publicKey, array('RS256'));

    if ($decoded->a && $decoded->s === $siteId) {
        return array(
            "valid" => true
        );
    } else {
        return array(
            "valid" => false
        );
    }

}

function waitpage_register_new_visitor($options, $prevToken) {
    $client = new \GuzzleHttp\Client();

    $url = $options['waitpage_url'] . '/nv?redirectUrl=' . $options['redirect_url'];

    $user_ip = waitpage_user_ip_address();
    if ($user_ip) {
      $url .= '&ip=' . $user_ip;
    }

    if ($prevToken) {
        $url .= '&prevToken=' . $prevToken;
    }

    $response = $client->request('GET', $url, [
        'headers' => [
            'Authorization' => 'Apikey ' . $options['apiKey']
        ]
    ]);

    $body = json_decode($response->getBody());

    if($body->q) {
        return array(
            "token" => $body->t,
            "redirect" => $body->u
        );
    } else {
        return array(
            "token" => $body->t
        );
    }
}


function run_waitpage($redirect_url = false) {
    $token = false;

    $options = array();
    $options['apiKey'] = get_option( 'waitpage_api_key');
    $options['siteId'] = (int)get_option( 'waitpage_site_id');
    $options['waitpage_base_url'] = defined('WAITPAGE_BASE_URL') ? WAITPAGE_BASE_URL : 'https://api.waitpage.io';
    $options['waitpage_url'] = $options['waitpage_base_url'] . '/' . $options['siteId'];
    $options['current_url_without_params'] = get_site_url(null, preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI']));
    $options['redirect_url'] = $redirect_url ? $redirect_url : $options['current_url_without_params'];

    // Check if token is provided in query string and write to cookie
    if(isset($_GET['token'])) {
        return array(
            "token" => sanitize_text_field($_GET['token']),
            "redirect" => $options['current_url_without_params']
        );
    }

    // Check if we have a token available
    if (isset($_COOKIE['waitpage'])) {
        $token = sanitize_text_field($_COOKIE['waitpage']);
    }

    // If we have a token, check if it's valid. If it is, just return
    if ($token) {
        try {
            $result = waitpage_check_token($options['siteId'], $token, $options);
            if($result['valid']) {
                return $result;
            }
        } catch (Exception $e) {
            // invalid token, continue
        }
    }

    // No token exists or token is no longer valid
    return waitpage_register_new_visitor($options, $token);

}

add_action('init', function(){
    try {
        $result = waitpage_check_request();

        if($result['token']) {
            setcookie('waitpage', $result['token'], 0, '/');
        }

        if($result['redirect']) {
            wp_redirect($result['redirect']);
            exit;
        }
    } catch (\Throwable $e) {
        // Something went wrong, do nothing...
    }

}, -1000000);

/**
 * This is our callback function that embeds our phrase in a WP_REST_Response
 */
function waitpage_getcookie() {
    define( 'DONOTCACHEPAGE', true ); // Don't cache this url

    if(waitpage_should_skip_on_all_requests()) {
        return;
    }

    $result = run_waitpage(get_site_url());

/*     if($result['token']) {
        setcookie('waitpage', $result['token'], 0, '/');
    } */

    $response = new WP_REST_Response($result, 200);

    // Set headers.
    $response->set_headers(array('Cache-Control' => 'no-cache'));


    return rest_ensure_response($response);
}


function register_waitpage_route() {
    register_rest_route( 'waitpage', '/getcookie', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'waitpage_getcookie',
    ) );
}
add_action( 'rest_api_init', 'register_waitpage_route' );

add_action('wp_head', 'inject_waitpage_js');
function inject_waitpage_js(){
?>
<script>
function getRandomInt(max) {
  return Math.floor(Math.random() * max);
}

if(!document.cookie.includes('waitpage=')) {
    async function waitpageCheckCookie() {
        const url = '/wp-json/waitpage/getcookie?random=' + getRandomInt(10000000)
        const result = await jQuery.get(url)

        if (result.token) {
            document.cookie = 'waitpage=' + result.token + ';path=/'
        }

        if (result.redirect) {
            window.location.replace(result.redirect)
        }
    }

    waitpageCheckCookie()
}
</script>
<?php
}

// Options page stuff
add_action( 'admin_menu', 'waitpage_add_admin_menu' );
add_action( 'admin_init', 'waitpage_settings_init' );

function waitpage_add_admin_menu(  ) {
    add_options_page( 'Waitpage Settings', 'Waitpage', 'manage_options', 'waitpage-settings-page', 'waitpage_options_page_callback' );
}

function waitpage_settings_init(  ) {
    add_settings_section(
        'waitpage_settings_section',
        'General Settings',
        'waitpage_settings_section_callback',
        'waitpage-settings-page'
    );

    register_setting( 'waitpage-settings-page', 'waitpage_site_id' );
    add_settings_field(
        'waitpage_site_id',
        'Site ID',
        'waitpage_site_id_field_render',
        'waitpage-settings-page',
        'waitpage_settings_section'
    );

    register_setting( 'waitpage-settings-page', 'waitpage_api_key' );
    add_settings_field(
        'waitpage_api_key',
        'API Key',
        'waitpage_api_key_field_render',
        'waitpage-settings-page',
        'waitpage_settings_section'
    );
}

add_filter( 'plugin_action_links_waitpage/waitpage.php', 'waitpage_wp_settings_link' );
function waitpage_wp_settings_link( $links ) {
	// Build and escape the URL.
	$url = esc_url( add_query_arg(
		'page',
		'waitpage-settings-page',
		get_admin_url() . 'options-general.php'
	) );
	// Create the link.
	$settings_link = "<a href='$url'>" . __( 'Settings' ) . '</a>';
	// Adds the link to the end of the array.
	array_push(
		$links,
		$settings_link
	);
	return $links;
}

function waitpage_api_key_field_render() {
    $current_value = get_option( 'waitpage_api_key', '' );
    ?>
    <input type='text' name='waitpage_api_key' value='<?php echo $current_value; ?>'>
    <?php
}

function waitpage_site_id_field_render() {
    $current_value = get_option( 'waitpage_site_id', '' );
    ?>
    <input type='text' name='waitpage_site_id' value='<?php echo $current_value; ?>'>
    <?php
}

function waitpage_settings_section_callback(  ) {
    echo 'General settings for Waitpage';
}

function waitpage_options_page_callback(  ) {
    ?>
    <form action='options.php' method='post'>

        <h2>Waitpage Settings</h2>

        <?php
        settings_fields( 'waitpage-settings-page' );
        do_settings_sections( 'waitpage-settings-page' );
        submit_button();
        ?>

    </form>
    <?php
}

?>
