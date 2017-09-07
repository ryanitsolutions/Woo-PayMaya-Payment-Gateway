<?php
/*
Plugin Name: Woo PayMaya Payment Gateway
Description: WooCommerce PayMaya Payment Vault - Tokenization & Card Vault Gateway Extension.
Version: 1.1
Author: Ryan IT Solutions
Author URI: https://ryanitsolutions.wordpress.com/
*/

add_action( 'plugins_loaded', 'wc_paymaya_init', 0 );

function wc_paymaya_init(){
    if ( ! class_exists( 'WC_Payment_Gateway_CC' ) ) return;

  	include_once( 'wc-paymaya-gateway.php' );
    
    add_action( 'init', array( 'WC_Paymaya_Gateway' , 'ajax_local_script' ) );
    add_action( 'wp_ajax_nopriv_pm-ajax-request', array( 'WC_Paymaya_Gateway' , 'ajax_local_request' ) );
    add_action( 'wp_ajax_pm-ajax-request', array( 'WC_Paymaya_Gateway' , 'ajax_local_request' ) );

    add_filter( 'woocommerce_payment_gateways', 'wc_add_paymaya_gateway' );

    function wc_add_paymaya_gateway( $methods ) {
      
      $methods[] = 'WC_PayMaya_Gateway';

		return $methods;
	}

}

