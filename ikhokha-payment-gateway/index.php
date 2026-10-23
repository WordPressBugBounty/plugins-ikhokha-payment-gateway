<?php
/*
 * Plugin Name: iKhokha Payment Gateway
 * Plugin URI: https://wordpress.org/plugins/ikhokha-payment-gateway/
 * Description: Receive online payments using the iKhokha Payment Gateway.
 * Author: iKhokha
 * Author URI: https://www.ikhokha.com/
 * Version: 3.0.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Ensure WooCommerce is Active */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
load_plugin_textdomain('index', false, trailingslashit(dirname(plugin_basename(__FILE__))));
add_action('admin_init', function () {
	if (!is_plugin_active('woocommerce/woocommerce.php')) {
		add_action('admin_enqueue_scripts', function () {
			wp_enqueue_script('requirements_js', plugins_url('assets/js/admin/requirements.js', __FILE__));
			return;
		});
	}
});
add_action('plugins_loaded', 'wc_ikhokha_gateway_init', 0);
require_once plugin_dir_path(__FILE__) . "/includes/woocommerce-blocks-support.php";

function wc_ikhokha_gateway_init()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	add_action('plugins_loaded', 'ikhokha_init_gateway_class');
}

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Register iKhokha Gateway */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
if (!function_exists('ikhokha_add_gateway_class')) {
	add_filter('woocommerce_payment_gateways', 'ikhokha_add_gateway_class');
	function ikhokha_add_gateway_class($gateways)
	{
		$gateways[] = 'WC_iKhokha_Gateway';
		return $gateways;
	}
}

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Plugin Init */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
function ikhokha_init_gateway_class()
{

	class WC_iKhokha_Gateway extends WC_Payment_Gateway
	{

		const IKHOKHA_API_ENDPOINT = 'https://api.ikhokha.com/ecomm/v1/';

		public $id;
		public $icon;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports;
		public $title;
		public $description;
		public $enabled;
		public $testmode;
		public $application_id;
		public $application_secret;
		public $site_name;

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Plugin Construct */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function __construct()
		{

			$this->id = 'ikhokha'; // payment gateway plugin ID
			$this->icon = plugins_url('assets/images/wc_ikhokha.png', __FILE__); // iKhokha logo
			$this->has_fields = false; // in case you need a custom credit card form
			$this->method_title = 'iKhokha'; // Default Title
			$this->method_description = 'Secure credit, debit card and Instant EFT payments with iKhokha.'; // will be displayed on the options page

			// Gateway supports
			$this->supports = array(
				'products',
				'refunds',
			);

			// Method with all the options fields
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');

			$this->enabled = $this->get_option('enabled');
			$this->testmode = 'yes' === $this->get_option('testmode');
			$this->application_id = $this->get_option('application_id');
			$this->application_secret = $this->get_option('application_secret');
			$this->site_name = get_bloginfo('name');

			// Actions
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options')); // save admin options
			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

			// Register callback endpoint only when gateway is enabled and configured
			if ('yes' === $this->enabled && $this->is_configured()) {
				add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'ikhokha_process_response')); // API endpoint
			}

			// disable payment based on rules
			add_filter('woocommerce_available_payment_gateways', array($this, 'ikhokha_disable_payment_rule'));
		}

		/**
		 * Check if the gateway has required credentials configured.
		 *
		 * @return bool
		 */
		public function is_configured()
		{
			return !empty($this->application_id) && !empty($this->application_secret);
		}

		/**
		 * Plugin bootstrapping.
		 */
		public static function init()
		{

			// Make the Payments gateway available to WC.
			add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));
		}

		/**
		 * Add the Payment gateway to the list of available gateways.
		 *
		 * @param array
		 */
		public static function add_gateway($gateways)
		{

			$gateways[] = 'WC_iKhokha_Gateway';

			return $gateways;
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields()
		{

			$this->form_fields = array(
				'enabled' => array(
					'title' => 'Enable/Disable',
					'label' => 'Enable iKhokha Payment Gateway',
					'type' => 'checkbox',
					'description' => '',
					'default' => 'no',
				),
				'title' => array(
					'title' => 'Title',
					'type' => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default' => 'iKhokha',
					'desc_tip' => true,
				),
				'description' => array(
					'title' => 'Description',
					'type' => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default' => 'Secure credit, debit card and Instant EFT payments with iKhokha.',
				),
				'testmode' => array(
					'title' => 'Test mode',
					'label' => 'Enable Test Mode (Use Card Number: 1111 1111 1111 1111 Expiry Month: 11 Expiry Year: 30 CVV: 111)',
					'type' => 'checkbox',
					'description' => 'Place the payment gateway in test mode and use the displayed test card details to conduct a test transaction. Note: Your website users will NOT be able to transact if this setting is enabled.',
					'default' => 'no',
					'desc_tip' => true,
				),
				'application_id' => array(
					'title' => 'Application ID',
					'type' => 'text',
				),
				'application_secret' => array(
					'title' => 'Application Secret',
					'type' => 'password',
				),
			);
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Checkout - Decide if we want to enable iKhokha on checkout */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function ikhokha_disable_payment_rule($available_gateways)
		{

			$currency = get_woocommerce_currency();

			if (!isset($currency) || $currency !== 'ZAR') {
				unset($available_gateways['ikhokha']);
			}

			return $available_gateways;
		}

		// Security check to ensure WordPress context
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Getting the WooCommerce version*/
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function wpbo_get_woo_version_number()
		{
			// If get_plugins() isn't available, require it
			if (!function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Create the plugins folder and file variables
			$plugin_folder = get_plugins('/' . 'woocommerce');
			$plugin_file = 'woocommerce.php';

			// If the plugin version number is set, return it
			if (isset($plugin_folder[$plugin_file]['Version'])) {
				return $plugin_folder[$plugin_file]['Version'];
			} else {
				// Otherwise return null
				return null;
			}
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Process Payment */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function process_payment($order_id)
		{

			global $woocommerce;
			$order = new WC_Order($order_id);

			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url(true),
			);
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Order Receipt Page */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function receipt_page($order)
		{

			echo $this->generate_post_form($order);
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Payment Form & Submission */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function generate_post_form($order_id)
		{

			$order = new WC_Order($order_id);
			$payment_page = $order->get_checkout_payment_url();
			$cart_page_id = wc_get_page_id('cart');
			$cart_page_url = $cart_page_id ? get_permalink($cart_page_id) : '';

			/* Define test or live mode */
			if ($this->get_option('testmode') == "yes") {
				$mode = true;
			} else {
				$mode = false;
			}

			// Security check to ensure WordPress context
			/* Client details  */
			if (!function_exists('get_plugin_data')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_version = get_plugin_data(__FILE__)['Version'];
			$client_details = array(
				"platformName" => "WooCommerce",
				"platformVersion" => $this->wpbo_get_woo_version_number(),
				"pluginVersion" => $plugin_version,
				"website" => get_site_url(),
			);

			/* Amount Validation */
			$getTotal = $order->get_total(); // get total
			$getDecimal = wc_get_price_decimal_separator(); // get decimal
			$resetDecimal = str_replace($getDecimal, '.', $getTotal); // replace decimal with .
			$orderAmount = number_format($resetDecimal, 2, '.', ''); // limit value to 2 decimal places

			$urlparts = parse_url(site_url());
			$domain = $urlparts['host'];
			$return_url = $this->get_return_url($order);
			
			$parsed = parse_url($return_url);
			// If there is no host in the return URL
			if (empty($parsed['host'])) {
				// 1. Try to get host from site_url(), home option, or the server header
				$host = !empty($_SERVER['HTTP_HOST']) 
					? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) 
					: parse_url(get_option('siteurl'), PHP_URL_HOST);
				// 2. Determine scheme (default to https for payment gateways)
				$scheme = (is_ssl() || get_option('woocommerce_force_ssl_checkout') === 'yes') ? 'https' : 'http';
				// 3. Prepend scheme and host to the relative path
				$return_url = $scheme . '://' . $host . '/' . ltrim($return_url, '/');
			}

			/* Payload Info */
			$callback_url = add_query_arg(
				array(
					'wc-api'    => 'WC_iKhokha_Gateway',
					'reference' => $order_id,
					'order_key' => $order->get_order_key(),
				),
				home_url('/')
			);

			$payload = array(
				"amount" => round($orderAmount * 100),
				"callbackUrl" => str_replace('http:', 'https:', $callback_url),
				"successUrl" => $return_url,
				"failUrl" => $payment_page,
				"test" => $mode,
				"customerEmail" => $order->get_billing_email(),
				"customerPhone" => $order->get_billing_phone(),
				"customerName" => $order->billing_first_name . ' ' . $order->billing_last_name,
				"client" => $client_details,
			);

			$auth = self::ikhokha_order_auth($payload);

			// replace once we have an actual auth api
			if (is_array($auth) && array_key_exists('paymentUrl', $auth)) {

				// save payment link to order
				$order->update_meta_data('ikhokha_payment_url', esc_url_raw($auth['paymentUrl']));
				$order->save();

				// Enqueue Script for loading Form & Auto submit
				wc_enqueue_js('
					$.blockUI({
							message: "' . esc_js(__('Thank you for your order. We are now redirecting you to iKhokha to make payment.', 'ikhokha-payment-gateway')) . '",
							baseZ: 99999,
							overlayCSS:
							{
								background: "#fff",
								opacity: 0.6
							},
							css: {
								padding:        "20px",
								zindex:         "9999999",
								textAlign:      "center",
								color:          "#555",
								border:         "3px solid #aaa",
								backgroundColor:"#fff",
								cursor:         "wait",
								lineHeight:		"24px",
							}
						});
					window.location.href = "' . esc_attr($auth['paymentUrl']) . '";
				');

				$html = '';
			} else {

				// make ssl if needed
				if (get_option('woocommerce_force_ssl_checkout') == 'yes') {
					$payment_page = str_replace('http:', 'https:', $payment_page);
				}

				// display an HTML error briefly before redirecting to payment page
				$html = '<div class="ikhokha-payment-notice" style="border:2px solid #ff0000;padding:10px;font-weight: 700;color: #ff0000;">Invalid connection to iKhokha - <a href="' . $payment_page . '">Try again.</a></div>';

				wp_redirect($payment_page);
			}

			return $html;
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Generate Signature */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function generate_signature($payload)
		{
			if (isset($payload) && !empty($payload) && !empty($this->application_secret)) {
				$string = "/ecomm/v1/paymentlinks" . addslashes(json_encode($payload, JSON_UNESCAPED_SLASHES));
				return hash_hmac("sha256", $string, $this->application_secret);
			}

			return false;
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Auth / Post payload */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */

		public function ikhokha_order_auth($payload)
		{

			$signature = self::generate_signature($payload);
			if ($signature) {

				$args = [
					'method' => 'POST',
					'sslverify' => true,
					'headers' => [
						'Cache-Control' => 'no-cache',
						'Content-Type' => 'application/json',
						'IK-APPID' => $this->application_id,
						'IK-SIGN' => $signature,
					],
					'body' => json_encode($payload),
				];

				$uri = self::IKHOKHA_API_ENDPOINT . 'paymentlinks';
				$request = wp_remote_post($uri, $args);
				$response = wp_remote_retrieve_body($request);
				$response = json_decode($response, true);

				if (is_wp_error($response)) {
					$error_message = $response->get_error_message();
					return $error_message;
				} else {
					return $response;
				}
			} else {
				return false;
			}
		}

		/**
		 * Safely retrieve a request header across different server environments.
		 *
		 * @param string $header_name
		 * @return string
		 */
		private function get_request_header($header_name)
		{
			$server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header_name));
			if (!empty($_SERVER[$server_key])) {
				return trim(sanitize_text_field(wp_unslash($_SERVER[$server_key])));
			}

			if (function_exists('getallheaders')) {
				$headers = getallheaders();
				if (is_array($headers)) {
					foreach ($headers as $key => $value) {
						if (strcasecmp($key, $header_name) === 0) {
							return trim(sanitize_text_field(wp_unslash($value)));
						}
					}
				}
			}

			return '';
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Generate a signature for the WC Endpoint */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function generate_callback_signature($payload)
		{
			if (isset($payload) && !empty($payload) && !empty($this->application_secret)) {
				$string = "/" . addslashes(json_encode($payload, JSON_UNESCAPED_SLASHES));
				return hash_hmac("sha256", $string, $this->application_secret);
			}

			return false;
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* WC Endpoint - Process response */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function ikhokha_process_response()
		{
			// Fail closed if gateway is disabled or credentials are not configured
			if ('yes' !== $this->enabled || !$this->is_configured()) {
				status_header(403);
				wp_die(__('iKhokha gateway is not enabled or properly configured.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 403));
			}

			// Extract payload
			$data = null;
			if (isset($_POST['status']) && !empty($_POST['status'])) {
				$data = array('status' => sanitize_text_field(wp_unslash($_POST['status'])));

				if (isset($_POST['transactionId']) && !empty($_POST['transactionId'])) {
					$data['transactionId'] = sanitize_key(wp_unslash($_POST['transactionId']));
				}
				if (isset($_POST['responseCode']) && !empty($_POST['responseCode'])) {
					$data['responseCode'] = sanitize_text_field(wp_unslash($_POST['responseCode']));
				}
				if (isset($_POST['responseMessage']) && !empty($_POST['responseMessage'])) {
					$data['responseMessage'] = sanitize_text_field(wp_unslash($_POST['responseMessage']));
				}
			} else {
				$raw_body = file_get_contents("php://input");
				if (!empty($raw_body)) {
					$data = json_decode($raw_body, true);
				}
			}

			if (!is_array($data) || empty($data['status'])) {
				status_header(400);
				wp_die(__('Invalid or missing callback payload.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			// Defensive header retrieval and verification
			$header_sign  = $this->get_request_header('IK-SIGN');
			$header_appid = $this->get_request_header('IK-APPID');

			// Treat missing or empty headers as immediate hard failure
			if (empty($header_sign) || empty($header_appid)) {
				status_header(400);
				wp_die(__('Missing required callback headers.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			// Compare Application ID using timing-safe comparison
			if (!hash_equals((string) $this->application_id, (string) $header_appid)) {
				status_header(401);
				wp_die(__('Unauthorized: Application ID mismatch.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 401));
			}

			// Generate and verify callback signature using timing-safe hash_equals
			$expected_signature = self::generate_callback_signature($data);
			if (empty($expected_signature) || !hash_equals((string) $expected_signature, (string) $header_sign)) {
				status_header(401);
				wp_die(__('Unauthorized: Invalid callback signature.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 401));
			}

			// Validate order reference parameter
			if (!isset($_GET['reference'])) {
				status_header(400);
				wp_die(__('Missing order reference parameter.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			$reference = sanitize_key(wp_unslash($_GET['reference']));
			$order = wc_get_order(is_numeric($reference) ? (int) $reference : $reference);

			if (!$order || !is_a($order, 'WC_Order')) {
				status_header(404);
				wp_die(__('Order not found.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 404));
			}

			// Bind callback to order by verifying order_key
			$order_key = '';
			if (!empty($_GET['order_key'])) {
				$order_key = sanitize_text_field(wp_unslash($_GET['order_key']));
			} elseif (!empty($data['order_key'])) {
				$order_key = sanitize_text_field($data['order_key']);
			} elseif (!empty($_POST['order_key'])) {
				$order_key = sanitize_text_field(wp_unslash($_POST['order_key']));
			}

			if (empty($order_key) || !hash_equals((string) $order->get_order_key(), (string) $order_key)) {
				status_header(401);
				wp_die(__('Unauthorized: Invalid order key.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 401));
			}

			// Confirm order was placed using this gateway
			if ($order->get_payment_method() !== $this->id) {
				status_header(400);
				wp_die(__('Order payment method does not match iKhokha.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			// Verify currency is ZAR
			if ($order->get_currency() !== 'ZAR') {
				status_header(400);
				wp_die(__('Invalid order currency.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			// Verify reported currency if provided
			$reported_currency = '';
			if (!empty($data['currency'])) {
				$reported_currency = $data['currency'];
			} elseif (!empty($_POST['currency'])) {
				$reported_currency = sanitize_text_field(wp_unslash($_POST['currency']));
			}

			if (!empty($reported_currency) && strtoupper($reported_currency) !== strtoupper($order->get_currency())) {
				status_header(400);
				wp_die(__('Reported currency does not match order currency.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
			}

			// Verify reported amount if provided
			$reported_amount = null;
			if (isset($data['amount'])) {
				$reported_amount = $data['amount'];
			} elseif (isset($_POST['amount'])) {
				$reported_amount = sanitize_text_field(wp_unslash($_POST['amount']));
			}

			if ($reported_amount !== null && $reported_amount !== '') {
				$reported_float    = (float) $reported_amount;
				$order_total       = (float) $order->get_total();
				$order_total_cents = round($order_total * 100);

				$matches_cents = abs($reported_float - $order_total_cents) < 0.01;
				$matches_total = abs($reported_float - $order_total) < 0.01;

				if (!$matches_cents && !$matches_total) {
					status_header(400);
					wp_die(__('Reported payment amount does not match order total.', 'ikhokha-payment-gateway'), 'iKhokha Payment Error', array('response' => 400));
				}
			}

			// Process order status safely
			$order_is_paid      = $order->has_status(array('processing', 'completed'));
			$transaction_status = strtolower($data['status']);

			if (!$order_is_paid && 'success' === $transaction_status) {
				$transaction_id = !empty($data['transactionId']) ? $data['transactionId'] : '';

				$order->update_meta_data('ikhokha_data', $data);
				$order->payment_complete($transaction_id);
				$order->add_order_note(
					sprintf(
						/* translators: 1: Currency, 2: Amount */
						__('%1$s %2$s was successfully processed through iKhokha', 'ikhokha-payment-gateway'),
						$order->get_currency(),
						$order->get_total()
					)
				);
				$order->save();

				global $woocommerce;
				if (isset($woocommerce->cart) && is_object($woocommerce->cart)) {
					$woocommerce->cart->empty_cart();
				}

				status_header(200);
				wp_send_json(array('status' => true));
			} elseif (!$order_is_paid && 'failed' === $transaction_status) {
				$order->update_meta_data('ikhokha_data', $data);
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: Currency, 2: Amount */
						__('%1$s %2$s payment failed through iKhokha', 'ikhokha-payment-gateway'),
						$order->get_currency(),
						$order->get_total()
					)
				);
				$order->save();

				status_header(200);
				wp_send_json(array('status' => true));
			} else {
				status_header(200);
				wp_send_json(array('status' => true, 'message' => 'Order state already finalized or unchanged.'));
			}
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Generate Refund Signatue */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function generate_refund_signature($refund_payload, $order_id)
		{

			$refund_signature = false; // default to false

			if (isset($refund_payload) && !empty($refund_payload) && !empty($this->application_secret)) {

				$transaction_id = null;

				$getData = get_post_meta($order_id, 'ikhokha_data', true) ? get_post_meta($order_id, 'ikhokha_data', true) : '';

				if (isset($getData['transactionId']) && !empty($getData['transactionId'])) {
					$transaction_id = $getData['transactionId'];
				}

				if (empty($transaction_id)) {
					$payment_url = get_post_meta($order_id, 'ikhokha_payment_url', true);
					$arr_payment = explode("/", $payment_url);
					$arr_payment_reversed = array_reverse($arr_payment);
					$transaction_id = $arr_payment_reversed[0];
				}

				$string = "/ecomm" . "/" . $transaction_id . "/refunds" . addslashes(json_encode($refund_payload, JSON_UNESCAPED_SLASHES));
				$refund_signature = hash_hmac("sha256", $string, $this->application_secret);

				return $refund_signature;
			}

			return false;
		}

		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		/* Process Refund */
		/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
		public function process_refund($order_id, $amount = null, $reason = '')
		{

			$convert = round($amount * 100);

			/* Payload Info */
			$refund_payload = array(
				"amount" => $convert,
				"reason" => $reason,
			);

			$refund_signature = self::generate_refund_signature($refund_payload, $order_id);
			if ($refund_signature) {

				$transaction_id = null;

				$getData = get_post_meta($order_id, 'ikhokha_data', true) ? get_post_meta($order_id, 'ikhokha_data', true) : '';

				if (isset($getData['transactionId']) && !empty($getData['transactionId'])) {
					$transaction_id = $getData['transactionId'];
				}

				if (empty($transaction_id)) {
					$payment_url = get_post_meta($order_id, 'ikhokha_payment_url', true);
					$arr_payment = explode("/", $payment_url);
					$arr_payment_reversed = array_reverse($arr_payment);
					$transaction_id = $arr_payment_reversed[0];
				}
				$refund_url_full = "https://api.ikhokha.com/ecomm/$transaction_id/refunds";

				$args = [
					'method' => 'POST',
					'sslverify' => true,
					'timeout' => 30,
					'headers' => [
						'Cache-Control' => 'no-cache',
						'Content-Type' => 'application/json',
						'IK-APPID' => $this->application_id,
						'IK-SIGN' => $refund_signature,
					],
					'body' => json_encode($refund_payload),
				];

				$request = wp_remote_post($refund_url_full, $args);
				$response = wp_remote_retrieve_body($request);
				$response = json_decode($response, true);

				if (is_wp_error($response)) {
					$error_message = $response->get_error_message();
					return false;
				} else {

					if (isset($response['status']) && $response['status'] == "SUCCESS") {

						$order = new WC_Order($order_id);
						$order->add_order_note(sprintf(__('%s %s was successfully refunded through iKhokha', 'ikhokha-payment-gateway'), get_woocommerce_currency(), $amount));
						return true;
					} else if (isset($response['status']) && $response['status'] == "FAILURE") {
						return new WP_Error('wc_' . $order_id . '_refund_failed', $response['responseMessage']);
					} else {
						return new WP_Error('wc_' . $order_id . '_refund_failed', 'Unable to process a refund.');
					}
				}
			} else {
				return false;
			}
		}
	} // end class
	WC_iKhokha_Gateway::init();
} // end func

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Add link to setting page */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
if (!function_exists('wc_ikhokha_settings_link')) {
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_ikhokha_settings_link');
	function wc_ikhokha_settings_link($links)
	{
		$settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=ikhokha">Settings</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
}

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Add Meta Section */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
if (!function_exists('ikhokha_meta_data')) {
	add_action('add_meta_boxes', 'ikhokha_meta_data');
	function ikhokha_meta_data()
	{
		add_meta_box('ikhokha_meta_data', __('iKhokha Data', 'woocommerce'), 'ikhokha_meta_data_display', 'shop_order', 'side', 'core');
	}
}

/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
/* Add Meta Section Display */
/* ~~~~~~~~~~~~~~~~~~~~~~~~~ */
if (!function_exists('ikhokha_meta_data_display')) {
	function ikhokha_meta_data_display()
	{

		global $post;
		$getData = get_post_meta($post->ID, 'ikhokha_data', true) ? get_post_meta($post->ID, 'ikhokha_data', true) : '';

		$html = '';
		if (isset($getData['transactionId']) && !empty($getData['transactionId'])) {
			$html .= '<table width="100%"><tbody><tr><td><strong>Transaction ID:</strong></td><td>' . $getData['transactionId'] . '</td></tr></tbody></table>';
		}

		echo wp_kses_post($html);
	}
}
