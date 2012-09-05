<?php
/*
Plugin Name: WPEC Currency Changer
Plugin URI: http://www.getshopped.org/premium-plugins
Description: A multi-currency tool for WPEC. Requires stock theme hooks and WPEC 3.8.9+
Version: 0.1
Author: JustinSainton 
Author URI: http://www.zao.is
*/

//todo - make sure taxes/coupons are converted	
class WPEC_Multi_Currency {
	
	private static $instance;

	public function __construct() {
		
		add_action( 'wpsc_includes'                    , array( $this, 'includes' )  );
		add_action( 'wpsc_constants'                   , array( $this, 'constants' )  );
		add_action( 'wp_enqueue_scripts'               , array( $this, 'enqueue_scripts' ) );
		add_action( 'wpsc_bottom_of_shopping_cart'     , array( $this, 'display_fancy_currency_notification' ) );
		add_action( 'wpsc_additional_sales_amount_info', array( $this, 'wpsc_show_currency_price' ), 10 );
		add_action( 'wpsc_before_submit_checkout'      , array( $this, 'wpsc_reset_prices' ) );
		add_action( 'wpsc_save_cart_item'              , array( $this, 'wpsc_save_currency_info' ), 10, 2 );
		add_action( 'wp_head'                          , array( $this, 'load_wpsc_converter' ) );
		add_action( 'widgets_init'                     , array( $this, 'register_widget' ) );

		add_filter( 'wpsc_convert_total_shipping'       , array( $this, 'wpsc_convert_price' ) );
		add_filter( 'wpsc_do_convert_price'             , array( $this, 'wpsc_convert_price' ) );
		add_filter( 'wpsc_item_shipping_amount_db'      , array( $this, 'wpsc_convert_price' ) );
		add_filter( 'wpsc_price'                        , array( $this, 'wpsc_convert_price' ) );
		add_filter( 'wpsc_product_postage_and_packaging', array( $this, 'wpsc_convert_price' ) );

		add_filter( 'wpsc_currency_display'             , array( $this, 'wpsc_add_currency_code' ) );
		add_filter( 'wpsc_toggle_display_currency_code' , array( $this, 'override_currency_symbol' ) );
		add_filter( 'get_post_metadata'                 , array( $this, 'convert_per_item_shipping' ), 10, 4 );
	
	}

	/**
	 * Get active object instance
	 *
	 * @since 0.1
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {
		if ( ! self::$instance )
			self::$instance = new WPEC_Multi_Currency();

		return self::$instance;
	}
	
	public static function register_widget() {

		return register_widget( 'WPSC_Widget_Currency_Converter' );
	}

	public static function constants() {

		define( 'WPSC_CURRENCY_FOLDER', dirname( __FILE__ ) );
		define( 'WPSC_CURRENCY_URL'   , plugins_url( __FILE__ ) );
	}
	/*
	 * Sets constants, loads currency helper functions and widget.
	 */
	public static function includes() {
		
		include_once WPSC_CURRENCY_FOLDER . '/currency.helpers.php';
		include_once WPSC_CURRENCY_FOLDER . '/widgets/currency_chooser_widget.php';
	}
	
	/*
	 * Enqueues javascript and css...which are entirely blank at this point. 
	 */
	public static function enqueue_scripts() {

		if ( ! defined( 'WPSC_CURRENCY_URL' ) )
			self::load();

		wp_enqueue_script( 'wpsc-currency-js', WPSC_CURRENCY_URL.'/js/currency.js', array( 'jquery' ), '2.0' );
		wp_enqueue_style( 'wpsc-currency-css', WPSC_CURRENCY_URL.'/css/currency.css', false, '2.0' );
	}
	
	/*
	 * This, I imagine, was a notification at some point...or was intended to be eventually.
	 */
	
	public function display_fancy_currency_notification() {
		global $wpsc_cart;

		if ( $wpsc_cart->selected_currency_code == ( $currency = wpsc_get_customer_meta( 'wpsc_base_currency_code' ) ) )
			return;
		
		$output = "<div id='wpsc_currency_notification'>";
		$output .= '<p>' . __( 'By clicking Make Purchase, you will be redirected to the gateway, and the cart prices will be converted to the store\'s local currency, ', 'wpsc' ) . ' ' . $currency . '</p>';
		$output .= '</div>';
		
		echo $output;
		
	}
	
	/**
	* Description show currency price shows converted price viewed by user when proceeding through checkout
	*
	* @param purchaselog id
	* @return none
	*/
	public function wpsc_show_currency_price( $purchaselog_id ){
		
		global $wpdb, $purchlogitem;
				
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT `id` FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `purchaseid` = %d", $purchaselog_id ) );
		
		foreach ( $ids as $id ) {
			$conversion_rate = wpsc_get_cartmeta( $id, 'wpsc_currency_conversion_rate' );
			if ( is_numeric( $conversion_rate ) )
				break;
		}
		
		if ( $conversion_rate ) {
			$conversion_rate = maybe_unserialize( $conversion_rate );
			foreach ( (array) $conversion_rate as $key => $value ) {
				echo '<br />' . $key . ' ' . wpsc_currency_display( $purchlogitem->extrainfo->totalprice * $value, array( 'display_as_html' => false ) );
				break;
			}
		}

	}
	
	/**
	 * Saves currency info as conversion rate for cart.
	 *
	 * @param cart_id
	 * @param product_id
	 * @return nada
	 */
	public function wpsc_save_currency_info( $cart_id, $product_id ){

		global $wpsc_cart;

		$meta_key = 'wpsc_currency_conversion_rate';
		$meta_value = array( $wpsc_cart->selected_currency_code => $wpsc_cart->currency_conversion );
		wpsc_update_cartmeta( $cart_id, 'wpsc_currency_conversion_rate', $meta_value );

	}
	
	/**
	* Converts prices for all prices, called through filters
	*
	* @param price double numeric
	* @return number calculated price
	*/
	public function wpsc_convert_price( $price ) {
		
		global $wpsc_cart;
		
		if ( $wpsc_cart->use_currency_converter )
			$price = $price * $wpsc_cart->currency_conversion;
	
		return $price;
	}
	
	public function override_currency_symbol( $args ) {
		global $wpsc_cart;

		$args['isocode'] = $wpsc_cart->selected_currency_isocode;
		return $args;
	}
	
	/**
	* Adds Currency Country Code to Prices
	*
	* @param string $total price + currency symbol
	* @return string country code, currency symbol and total price
	*/
	public function wpsc_add_currency_code( $total ) {

		global $wpsc_cart;
		
		if ( false === ( $base_symbol = wpsc_get_customer_meta( 'wpsc_base_currency_symbol' ) ) )
			return $total;

		$total = $wpsc_cart->selected_currency_code . ' ' . str_replace( $base_symbol, $wpsc_cart->selected_currency_symbol, $total );

		return $total;
	}
	
	/**
	 * Reset prices to the default, and calculates new total cart price.
	 *
	 * @param none
	 * @return none
	 */
	public function wpsc_reset_prices() {
		
		global $wpsc_cart;
		
		$wpsc_cart->use_currency_converter = false;
		$wpsc_cart->total_price = null;
		$wpsc_cart->subtotal = null;
		
		
		foreach ( (array) $wpsc_cart->cart_items as $item )
			$item->refresh_item();	
	}
	
	/**
	* Sets up the converter data, does initial conversion for for 1.00 of base currency
	* 
	* @return none
	*/
	public function load_wpsc_converter() {
		global $wpsc_cart, $wpdb;
		
		if ( ! isset( $_REQUEST['wpsc_admin_action'] ) || ( isset( $_REQUEST['wpsc_admin_action'] ) && 'change_currency_country' != $_REQUEST['wpsc_admin_action'] ) )
			return;
		
		$wpsc_cart->use_currency_converter = true;
		
		$currency_code = $wpdb->get_results( "SELECT `code`, `symbol_html` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id`='" . get_option( 'currency_type' ) . "' LIMIT 1", ARRAY_A );
		$local_currency_code = $currency_code[0]['code'];
		$local_currency_symbol_html = $currency_code[0]['symbol_html'];
		
		wpsc_update_customer_meta( 'wpsc_base_currency_symbol', $local_currency_symbol_html );
		wpsc_update_customer_meta( 'wpsc_base_currency_code', $local_currency_code );
		
		if ( ! isset( $_POST['reset'] ) ) {
			$currency         = isset( $_POST['currency_option'] ) ? $_POST['currency_option'] : '';
			$foreign_currency = $wpdb->get_row( $wpdb->prepare( "SELECT `code`, `symbol`, `isocode` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id` = %d LIMIT 1", $currency ) );
			$foreign_currency_code = $foreign_currency->code;

			wpsc_update_customer_meta( 'wpsc_currency_code', absint( $currency ) );

			$wpsc_cart->selected_currency_code    = $foreign_currency->code;
			$wpsc_cart->selected_currency_symbol  = $foreign_currency->symbol;
			$wpsc_cart->selected_currency_isocode = $foreign_currency->isocode;
		
		} else {
			wpsc_update_customer_meta( 'wpsc_currency_code', get_option( 'currency_type' ) );
			$wpsc_cart->selected_currency_code = $local_currency_code;
			$foreign_currency_code             = $local_currency_code;
		}
		
		if ( ! empty( $foreign_currency_code ) || $foreign_currency_code != $local_currency_code )
			$wpsc_cart->currency_conversion = wpsc_convert_currency( 1, $local_currency_code, $foreign_currency_code );
		
		$wpsc_cart->wpsc_refresh_cart_items();
		
		$wpsc_cart->subtotal = null;
		$wpsc_cart->total_price = null;
		$wpsc_cart->total_tax = null;

		foreach ( $wpsc_cart->cart_items as $item )
			$item->total_price = round( $this->wpsc_convert_price( $item->total_price ), 3 );
	}

	/**
	 * Need to investigate what this was intended to do.  Imagine it's for the per-item shipping.  Check key, return null, remove_filter
	 * 
	 * @param type $check 
	 * @param type $object_id 
	 * @param type $meta_key 
	 * @param type $single 
	 * @return type
	 */
	public static function convert_per_item_shipping( $check, $object_id, $meta_key, $single ) {
		return $check;
	}
}

WPEC_Multi_Currency::get_instance();		

?>