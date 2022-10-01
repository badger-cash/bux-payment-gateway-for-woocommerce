<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin Name: BUX.digital Gateway
 * Plugin URI: https://github.com/badger-cash/bux-payment-gateway-for-woocommerce
 * Description:  Provides a BUX.digital Payment Gateway.
 * Author: BUX.digital
 * Author URI: https://badger.cash/
 * Version: 1.0.1
 */

/**
 * BUX.digital Gateway
 * Based on the PayPal Standard Payment Gateway
 *
 * Provides a BUX.digital Payment Gateway.
 *
 * @class 		WC_BUX
 * @extends		WC_Gateway_BUX
 * @version		1.0.1
 * @package		WooCommerce/Classes/Payment
 * @author 		BUX.digital based on Coinpayments.net module
 */

add_action( 'plugins_loaded', 'bux_gateway_load', 0 );
function bux_gateway_load() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter( 'woocommerce_payment_gateways', 'wcbux_add_gateway' );

    function wcbux_add_gateway( $methods ) {
    	if (!in_array('WC_Gateway_BUX', $methods)) {
				$methods[] = 'WC_Gateway_BUX';
			}
			return $methods;
    }


    class WC_Gateway_BUX extends WC_Payment_Gateway {

	var $ipn_url;

    /**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */
	public function __construct() {
		global $woocommerce;

        $this->id           = 'bux';
        $this->icon         = apply_filters( 'woocommerce_bux_icon', plugins_url('assets/images/icons/bux.png', __FILE__) );
        $this->has_fields   = false;
        $this->method_title = __( 'BUX.digital', 'woocommerce' );
        $this->ipn_url   = add_query_arg( 'wc-api', 'WC_Gateway_BUX', home_url( '/' ) );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title 			= $this->get_option( 'title' );
		$this->description 		= $this->get_option( 'description' );
		$this->merchant_addr 	= $this->get_option( 'merchant_addr' );
		$this->ipn_secret   = $this->get_option( 'ipn_secret' );
		$this->send_shipping	= $this->get_option( 'send_shipping' );
		$this->debug_email			= $this->get_option( 'debug_email' );
		// $this->allow_zero_confirm = $this->get_option( 'allow_zero_confirm' ) == 'yes' ? true : false;
		$this->allow_zero_confirm = true;
		$this->form_submission_method = $this->get_option( 'form_submission_method' ) == 'yes' ? true : false;
		$this->invoice_prefix	= $this->get_option( 'invoice_prefix', 'WC-' );
		$this->simple_total = $this->get_option( 'simple_total' ) == 'yes' ? true : false;

		// Logs
		$this->log = new WC_Logger();

		// Actions
		add_action( 'woocommerce_receipt_bux', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_bux', array( $this, 'check_ipn_response' ) );

		if ( !$this->is_valid_for_use() ) $this->enabled = false;
    }


    /**
     * Check if this gateway is enabled and available in the user's country
     *
     * @access public
     * @return bool
     */
    function is_valid_for_use() {
        // just let it always work
        return true;
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {

		?>
		<h3><?php _e( 'BUX.digital', 'woocommerce' ); ?></h3>
		<p><?php _e( 'Completes checkout via BUX.digital', 'woocommerce' ); ?></p>

    	<?php if ( $this->is_valid_for_use() ) : ?>

			<table class="form-table">
			<?php
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
			?>
			</table><!--/.form-table-->

		<?php else : ?>
            <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'BUX.digital does not support your store currency.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
	}


    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields() {

    	$this->form_fields = array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable BUX.digital', 'woocommerce' ),
							'default' => 'yes'
						),
			'title' => array(
							'title' => __( 'Title', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'BUX.digital', 'woocommerce' ),
							'desc_tip'      => true,
						),
			'description' => array(
							'title' => __( 'Description', 'woocommerce' ),
							'type' => 'textarea',
							'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'Pay with BUX tokens (using PayPal or Credit Card)', 'woocommerce' )
						),
			'merchant_addr' => array(
							'title' => __( 'Merchant Address', 'woocommerce' ),
							'type' 			=> 'text',
							'description' => __( 'Please enter your eToken address. You can create a wallet at wallet.badger.cash', 'woocommerce' ),
							'default' => '',
						),
			'simple_total' => array(
							'title' => __( 'Compatibility Mode', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( "This may be needed for compatibility with certain addons if the order total isn't correct.", 'woocommerce' ),
							'default' => 'yes'
						),
			'send_shipping' => array(
							'title' => __( 'Collect Shipping Info?', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable Shipping Information on Checkout page', 'woocommerce' ),
							'default' => 'yes'
						),
			'invoice_prefix' => array(
							'title' => __( 'Invoice Prefix', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'Please enter a prefix for your invoice numbers. If you use your address for multiple stores ensure this prefix is unique.', 'woocommerce' ),
							'default' => 'WC-',
							'desc_tip'      => true,
						),
			'testing' => array(
							'title' => __( 'Gateway Testing', 'woocommerce' ),
							'type' => 'title',
							'description' => '',
						),
			'debug_email' => array(
							'title' => __( 'Debug Email', 'woocommerce' ),
							'type' => 'email',
							'default' => '',
							'description' => __( 'Send copies of invalid IPNs to this email address.', 'woocommerce' ),
						)
			);

    }


	/**
	 * Get BUX.digital Args
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_bux_args( $order ) {
		global $woocommerce;

		$order_id = $order->get_id();

		if ( in_array( $order->get_billing_country(), array( 'US','CA' ) ) ) {
			$order->set_billing_phone(str_replace( array( '( ', '-', ' ', ' )', '.' ), '', $order->get_billing_phone() ));
		}

		// BUX.digital Args
		$bux_args = array(
				'cmd' 					=> '_pay_auto',
				'merchant_name'			=> get_bloginfo('name'),
				'merchant_addr' 		=> $this->merchant_addr,
				'allow_extra' 				=> 0,
				// Get the currency from the order, not the active currency
				'currency' 		=> $order->get_currency(),
				'reset' 				=> 1,
				'success_url' 				=> $this->get_return_url( $order ),
				'cancel_url'			=> esc_url_raw($order->get_cancel_order_url_raw()),

				// Order key + ID
				'invoice'				=> $this->invoice_prefix . $order->get_order_number(),
				'order_key'				=> $order->get_order_key(),
				'custom' 				=> serialize( array( $order->get_id(), $order->get_order_key() ) ),

				// IPN
				'ipn_url'			=> $this->ipn_url,

				// Billing Address info
				'first_name'			=> $order->get_billing_first_name(),
				'last_name'				=> $order->get_billing_last_name(),
				'email'					=> $order->get_billing_email(),
		);

		if ($this->send_shipping == 'yes') {
			$bux_args = array_merge($bux_args, array(
				'want_shipping' => 1,
				'company'					=> $order->get_billing_company(),
				'address1'				=> $order->get_billing_address_1(),
				'address2'				=> $order->get_billing_address_2(),
				'city'					=> $order->get_billing_city(),
				'state'					=> $order->get_billing_state(),
				'zip'					=> $order->get_billing_postcode(),
				'country'				=> $order->get_billing_country(),
				'phone'					=> $order->get_billing_phone(),
			));
		} else {
			$bux_args['want_shipping'] = 0;
		}

		if ($this->simple_total) {
			$bux_args['item_name'] 	= sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
			$bux_args['quantity'] 		= 1;
			$bux_args['amount'] 		= number_format( $order->get_total(), 4, '.', '' );
			$bux_args['tax'] 				= 0.00;
			$bux_args['shipping']		= 0.00;
		} else if ( wc_tax_enabled() && wc_prices_include_tax() ) {
			$bux_args['item_name'] 	= sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
			$bux_args['quantity'] 		= 1;
			$bux_args['amount'] 		= number_format( $order->get_total() - $order->get_total_shipping() - $order->get_shipping_tax(), 4, '.', '' );
			$bux_args['shipping']		= number_format( $order->get_total_shipping() + $order->get_shipping_tax() , 4, '.', '' );
			$bux_args['tax'] 				= 0.00;
		} else {
			$bux_args['item_name'] 	= sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
			$bux_args['quantity'] 		= 1;
			$bux_args['amount'] 		= number_format( $order->get_total() - $order->get_total_shipping() - $order->get_total_tax(), 4, '.', '' );
			$bux_args['shipping']		= number_format( $order->get_total_shipping(), 4, '.', '' );
			$bux_args['tax']				= number_format( $order->get_total_tax(), 4, '.', '' );
		}

		$bux_args = apply_filters( 'woocommerce_bux_args', $bux_args );

		return $bux_args;
	}


    /**
	 * Generate the BUX button link
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_bux_url($order) {
		global $woocommerce;

		if ( $order->get_status() != 'completed' && get_post_meta($order->get_id(), 'BUX payment complete', true ) != 'Yes' ) {
			//$order->update_status('on-hold', 'Customer is being redirected to BUX...');
			$order->update_status('pending', 'Customer is being redirected to BUX...');
		}

		$bux_adr = "https://bux.digital/v1/pay?";
		$bux_args = $this->get_bux_args( $order );
		$bux_adr .= http_build_query( $bux_args, '', '&' );
		return $bux_adr;
	}


    /**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
	function process_payment( $order_id ) {

		$order          = wc_get_order( $order_id );

		return array(
				'result' 	=> 'success',
				'redirect'	=> $this->generate_bux_url($order),
		);

	}


    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
	function receipt_page( $order ) {
		echo '<p>'.__( 'Thank you for your order, please click the button below to pay with BUX.digital Gateway.', 'woocommerce' ).'</p>';

		echo $this->generate_bux_form( $order );
	}

	/**
	 * Check BUX IPN validity
	 **/
	function check_ipn_request_is_valid() {
		global $woocommerce;

		$order = false;
		$error_msg = "Unknown error";
		$auth_ok = false;

		$request = file_get_contents('php://input');
		if ($request !== FALSE && !empty($request)) {
			if (isset($_POST['merchant']) && $_POST['merchant'] == trim($this->merchant_addr)) {
				$auth_ok = true;
			} else {
				$error_msg = 'No or incorrect Merchant Address passed';
			}
		} else {
			$error_msg = 'Error reading POST data';
		}

		if ($auth_ok) {
			if (!empty($_POST['invoice']) && !empty($_POST['custom'])) {
				$order = $this->get_bux_order( $_POST );
			}

			if ($order !== FALSE) {
				if ($_POST['merchant'] == $this->merchant_addr) {
					if ($_POST['currency1'] == $order->get_currency()) {
						if ($_POST['amount1'] >= $order->get_total()) {
							if (!empty( $_POST["payment_id"])) {
								// Confirm Invoice is paid
								$pr_args = array(
									'headers' => array(
										'Accept' => 'application/payment-request'
									)
								);
								$pr_url = "https://pay.badger.cash/i/" . $_POST["payment_id"];
								$pr_response = wp_remote_get($pr_url, $pr_args);
								$pr_data     = wp_remote_retrieve_body($pr_response);
								$http_code = wp_remote_retrieve_response_code($pr_response);
								if ($http_code == 200) {
									$pr_obj = json_decode($pr_data);
									if (!empty($pr_obj->txHash) && !empty($pr_obj->callback)) {
										if ($pr_obj->callback->ipn_body->custom == $order->get_order_key()) {
											// Check to make sure proper amount was paid in transaction
											$tx_url = "https://ecash.badger.cash:8332/tx/" . $pr_obj->txHash . "?slp=true";
											$tx_response = wp_remote_get($tx_url);
											$tx_data     = wp_remote_retrieve_body($tx_response);
											$tx_obj = json_decode($tx_data);
											// Hard coding tokenId
											$bux_token_id = "7e7dacd72dcdb14e00a03dd3aff47f019ed51a6f1f4e4f532ae50692f62bc4e5";
											if (!empty($tx_obj->slpToken) && $tx_obj->slpToken->tokenId == $bux_token_id) {
												$decimals = $tx_obj->slpToken->decimals;
												$total_value = 0;
												$prefix_array = array("etoken:", "ecash:");
												$etoken_substr = substr(str_replace($prefix_array, "", $this->merchant_addr), 0, -10);
												for ($i = 0; $i < count($tx_obj->outputs); $i++) {
													$output = $tx_obj->outputs[$i];
													$output_substr = $etoken_substr = substr(str_replace($prefix_array, "", $output->address), 0, -10);
													if ($output_substr != $etoken_substr) {
														continue;
													}
													if (isset($output->slp) && $output->slp->type == "SEND") {
														if ($output->slp->tokenId == $bux_token_id) {
														$total_value += $output->slp->value; 
														}
													}
												}

												$total_received = $total_value / (10 ** $decimals);
												if ($total_received >= $order->get_total()) {
													print "IPN check OK\n";
													return true;
												} else {
													$error_msg = "Insufficient amount paid in TXID" . sanitize_text_field($pr_obj->txHash);
												}
											} else {
												$error_msg = "TXID" . sanitize_text_field($pr_obj->txHash) . " is not a valid BUX transaction";
											}
										} else {
											$error_msg = "Invalid order key";
										}
									} else {
										$error_msg = "Payment request missing required properties or is not paid!";
									}
								} else {
									$error_msg = "Invalid payment id or payment request expired! ";
								}
							} else {
								$error_msg = "No payment request id provided!";
							}
						} else {
							$error_msg = "Amount received is less than the total!";
						}
					} else {
						$error_msg = "Original currency doesn't match!";
					}
				} else {
					$error_msg = "Merchant ID doesn't match!";
				}
			} else {
				$error_msg = "Could not find order info for order id provided!";
			}
		}

		$report = "Error Message: ".$error_msg."\n\n";

		$report .= "POST Fields\n\n";
		foreach ($_POST as $key => $value) {
			$report .= sanitize_text_field($key).'='.sanitize_text_field($value)."\n";
		}

		if ($order) {
			$order->update_status('on-hold', sprintf( __( 'BUX.digital IPN Error: %s', 'woocommerce' ), $error_msg ) );
		}
		if (!empty($this->debug_email)) { mail($this->debug_email, "BUX.digital Invalid IPN", $report); }
		mail(get_option( 'admin_email' ), sprintf( __( 'BUX.digital Invalid IPN', 'woocommerce' ), $error_msg ), $report );
		die('IPN Error: '.$error_msg);
		return false;
	}

	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function successful_request( $posted ) {
		global $woocommerce;

		$posted = stripslashes_deep( $posted );

		// Custom holds post ID
	    if (!empty($_POST['invoice']) && !empty($_POST['custom'])) {
			    $order = $this->get_bux_order( $posted );
			    if ($order === FALSE) {
			    	die("IPN Error: Could not find order info for order: ".$_POST['invoice']);
			    }

			$sanitized_status = sanitize_text_field($posted['status_text']);

        	$this->log->add( 'bux', 'Order #'.$order->get_id().' payment status: ' . $sanitized_status );
         	$order->add_order_note('BUX.digital Payment Status: '.$sanitized_status );

         	if ( $order->get_status() != 'completed' && get_post_meta( $order->get_id(), 'BUX.digital payment complete', true ) != 'Yes' ) {
         		// no need to update status if it's already done
            if ( ! empty( $posted['txn_id'] ) )
             	update_post_meta( $order->get_id(), 'Transaction ID', $posted['txn_id'] );
            if ( ! empty( $posted['first_name'] ) )
             	update_post_meta( $order->get_id(), 'Payer first name', $posted['first_name'] );
            if ( ! empty( $posted['last_name'] ) )
             	update_post_meta( $order->get_id(), 'Payer last name', $posted['last_name'] );
            if ( ! empty( $posted['email'] ) )
             	update_post_meta( $order->get_id(), 'Payer email', $posted['email'] );

						if ($posted['status'] >= 100 || $posted['status'] == 2 || ($this->allow_zero_confirm && $posted['status'] >= 0 && $posted['received_confirms'] > 0 && $posted['received_amount'] >= $posted['amount2'])) {
							print "Marking complete\n";
							update_post_meta( $order->get_id(), 'BUX payment complete', 'Yes' );
             	$order->payment_complete();
						} else if ($posted['status'] < 0) {
							print "Marking cancelled\n";
              $order->update_status('cancelled', 'BUX Payment cancelled/timed out: '. $sanitized_status );
							mail( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s cancelled/timed out', 'woocommerce' ), $order->get_order_number() ), $sanitized_status );
            } else {
							print "Marking pending\n";
							$order->update_status('pending', 'BUX Payment pending: '. $sanitized_status );
						}
	        }
	        die("IPN OK");
	    }
	}

	/**
	 * Check for BUX IPN Response
	 *
	 * @access public
	 * @return void
	 */
	function check_ipn_response() {

		@ob_clean();

		if ( ! empty( $_POST ) && $this->check_ipn_request_is_valid() ) {
			$this->successful_request($_POST);
		} else {
			wp_die( "BUX.digital IPN Request Failure" );
 		}
	}

	/**
	 * get_bux_order function.
	 *
	 * @access public
	 * @param mixed $posted
	 * @return void
	 */
	function get_bux_order( $posted ) {
		$custom = maybe_unserialize( stripslashes_deep($posted['custom']) );

    	// Backwards comp for IPN requests
    	if ( is_numeric( $custom ) ) {
	    	$order_id = (int) $custom;
	    	$order_key = $posted['invoice'];
    	} elseif( is_string( $custom ) ) {
	    	$order_id = (int) str_replace( $this->invoice_prefix, '', $custom );
	    	$order_key = $custom;
    	} else {
    		list( $order_id, $order_key ) = $custom;
		}

		$order = wc_get_order( $order_id );

		if ($order === FALSE) {
			// We have an invalid $order_id, probably because invoice_prefix has changed
			$order_id 	= wc_get_order_id_by_order_key( $order_key );
			$order 		= wc_get_order( $order_id );
		}

		// Validate key
		if ($order === FALSE || $order->get_order_key() !== $order_key ) {
			return FALSE;
		}

		return $order;
	}

}

class WC_BUX extends WC_Gateway_BUX {
	public function __construct() {
		_deprecated_function( 'WC_BUX', '1.0', 'WC_Gateway_BUX' );
		parent::__construct();
	}
}
}
