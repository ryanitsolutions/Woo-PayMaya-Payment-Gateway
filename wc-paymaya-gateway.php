<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
  * WooCommerce PayMaya Payment Class
  * @version 1.0
  * @author Ryan IT Solutions
  */

class WC_PayMaya_Gateway extends WC_Payment_Gateway_CC {

    public static $log_enabled = false;
    public static $log = false;

    protected $secret_key;
    protected $public_key;

    public $endpoint_url;
    public $ipn_url;
    private $sslverify;
    protected $pf_enabled; 
    private $supported_currencies = array();

  function __construct() {

      $this->id                   = "rits_paymaya";
      $this->method_title         = __( 'PayMaya', 'woocommerce' );
      $this->method_description   = __( "PayMaya Payment Gateway Plug-in for WooCommerce", 'woocommerce' );

      $this->has_fields           = true;
      $this->ipn_url              = WC()->api_request_url( 'WC_Paymaya_Gateway' );

      $this->supported_currencies = array( "PHP" );

      // API Supports
      $this->supports = array(
          'subscriptions',
          'refunds',
          'products',
          'subscription_cancellation',
          'subscription_reactivation',
          'subscription_suspension',
          'subscription_amount_changes',
          'subscription_payment_method_change', // Subs 1.n compatibility.
          'subscription_payment_method_change_customer',
          'subscription_payment_method_change_admin',
          'subscription_date_changes',
          'multiple_subscriptions',
          'default_credit_card_form',
          'tokenization',
          'pre-orders'
      );

      $this->init_form_fields();
      $this->init_settings();

      // Settings Keys & Values
      foreach ( $this->settings as $setting_key => $value ) {
        $this->$setting_key = $value;
      }

      self::$log_enabled        = 'yes' === $this->debug;

      $this->test_secret_key    = $this->settings[ 'test_secret_key' ];
      $this->test_public_key    = $this->settings[ 'test_public_key' ];
      $this->live_secret_key    = $this->settings[ 'live_secret_key' ];
      $this->live_public_key    = $this->settings[ 'live_public_key' ];
      $this->pf_enabled         = 'yes' === $this->settings[ 'pf_enabled' ];

      // Live or Sanbox Mode
      if( $this->mode == 's' ){

          $this->endpoint_url     = "https://pg-sandbox.paymaya.com";
          $this->secret_key       = $this->test_secret_key;
          $this->public_key       = $this->test_public_key;
          $this->sslverify        = false;

          // Payment Facilitator (pf) Object
          $this->pf_smi           =  $this->settings[ 'pf_smi_test' ];
          $this->pf_smn           =  $this->settings[ 'pf_smn_test' ];          
          $this->pf_mci           =  $this->settings[ 'pf_mci_test' ];          
          $this->pf_mpc           =  $this->settings[ 'pf_mpc_test' ];          
          $this->pf_mco           =  $this->settings[ 'pf_mco_test' ];          
          $this->pf_mst           =  $this->settings[ 'pf_mst_test' ];              

      } else {

          $this->endpoint_url     = "https://pg.paymaya.com";
          $this->secret_key       = $this->live_secret_key;
          $this->public_key       = $this->live_public_key;
          $this->sslverify        = true;

          // Payment Facilitator (pf) Object
          $this->pf_smi           =  $this->settings[ 'pf_smi_live' ];
          $this->pf_smn           =  $this->settings[ 'pf_smn_live' ];          
          $this->pf_mci           =  $this->settings[ 'pf_mci_live' ];          
          $this->pf_mpc           =  $this->settings[ 'pf_mpc_live' ];          
          $this->pf_mco           =  $this->settings[ 'pf_mco_live' ];          
          $this->pf_mst           =  $this->settings[ 'pf_mst_live' ];              

      }

      if ( is_admin() ) {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      }

      add_action( 'wp_enqueue_scripts', array( $this, 'paymaya_scripts' ) );
      add_action( 'woocommerce_api_wc_paymaya_gateway' , array( $this, 'pm_handler' ) ); 


  } // End of __construct()

  /**
   * Initialise PayMaya Payment Facilatator.
   *
   */

  public function pf(){

     $current_user = wp_get_current_user();

    if( $this->pf_enabled  === true ){
      return array(
        'pf' => array(
            'smi' => $this->pf_smi,
            'smn' => $this->pf_smn, 
            'mci' => $this->pf_mci, 
            'mpc' => $this->pf_mpc,
            'mco' => $this->pf_mco, 
            'mst' => $this->pf_mst
            ),
        'description' => 'Charge for ' . $current_user->user_email
        );

    } else {
      return array(
        'description' => 'Charge for ' . $current_user->user_email
        ); 
    }
   
  }

  /**
   * Check if SSL is enabled and notify the user.
   *
   */

  public function pm_checks() {

    // Check PayMaya Method is enable
    if ( 'no' == $this->enabled ) {
      return;
    }

    if ( version_compare( phpversion(), '5.3', '<' ) ) {

      // Check PHP Version
      echo '<div class="error"><p>' . sprintf( __( 'PayMaya Error: PayMaya requires PHP 5.3 and above. You are using version %s.', 'woocommerce' ), phpversion() ) . '</p></div>';

    } elseif ( ! $this->public_key || ! $this->secret_key ) {
      // Check required fields
      echo '<div class="error"><p>' . __( 'PayMaya Error: Please enter your public and secret keys', 'woocommerce' ) . '</p></div>';

    } elseif ( 'p' == $this->mode && ! wc_checkout_is_https() ) {

      // Check SSL
      echo '<div class="error"><p>' . sprintf( __( 'PayMaya is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - PayMaya will only work in sandbox mode.', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '</p></div>';

    } elseif ( ! in_array( get_option( 'woocommerce_currency' ), $this->supported_currencies ) ) {

      // Check Currency Supported
      echo '<div class="error"><p>' . __( 'PayMaya Payment Gateway does not support your store currency.' ) . '</p></div>';

    }

  }

  /**
   * Check if this gateway is enabled.
   *
   * @return bool
   */

  public function is_available() {
    if ( 'yes' !== $this->enabled ) {
      return false;
    }

    if ( 'p' === $this->mode && ! wc_checkout_is_https() ) {
      return false;
    }

    if ( ! $this->public_key || ! $this->secret_key ) {
      return false;
    }

    return true;
  }

  /**
   * Initialise PayMaya Settings Form Fields.
   */

  public function init_form_fields(){

    $this->form_fields = array(
      'enabled' => array(
        'title'     => __( 'Enable/Disable', 'woocommerce' ),
        'type'      => 'checkbox',
        'label'     => __( 'Enable PayMaya Payments.', 'woocommerce' ),
        'default'   => 'no'
      ),
      'title' => array(
        'title'     => __( 'Title:', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
        'default'   => __( 'Credit Card', 'woocommerce' )
      ),
      'description' => array(
        'title'     => __( 'Description:', 'woocommerce' ),
        'type'      => 'textarea',
        'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
        'default'     => __( 'Pay with your credit card via PayMaya Payments.', 'woocommerce' )
      ),
      'mode'  => array(
        'title'     => __( 'Environment', 'woocommerce' ),
        'type'      => 'select',
        'description'   => '',
              'options'  => array(
                      's'   => __( 'Sandbox', 'woocommerce' ),
                      'p'   => __( 'Production', 'woocommerce' ),

              )
      ),
      
      'security_keys' => array(
        'title'       => __( 'Payment Vault API Keys', 'woocommerce' ),
        'type'        => 'header',
        'description' => '<hr>',
        'desc_tip'          => false,
      ),

      'test_secret_key' => array(
        'title'     => __( 'Test Secret Key ', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( 'Your secret key can be used with all of the API, and must be kept secure and secret at all times.You can use the following test API keys for your integration in Sandbox <a target="_blank" href="https://developers.paymaya.com/blog/entry/payment-vault-test-merchants-and-cards">here</>', 'woocommerce' ),
        'default' => 'sk-Rh5QNKLq0MlaC0XYikR2la7Hd3TubU3QwOSYcJ5yrk1'
      ),
      'test_public_key' => array(
        'title'     => __( 'Test Public Key', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( 'Your Public key can be used from insecure locations (such as browsers or mobile apps) to create cards with the cards API. You can use the following test API keys for your integration in Sandbox <a target="_blank" href="https://developers.paymaya.com/blog/entry/payment-vault-test-merchants-and-cards">here</>. ', 'woocommerce' ),
        'default' => 'pk-ewMZg2lZ5Mvh9oLfkInQw06rDt3TaHB8lsZB0P2zU5l'
      ),
      'live_secret_key' => array(
        'title'     => __( 'Live Secret Key ', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( '<a href="http://support.paymaya.com/support/home" target="_blank">Contact PayMaya</a> and you will be given a new set of API keys provisioned in production which you can use to process live payments.', 'woocommerce' ),
        'desc_tip'    => false,
      ),
      'live_public_key' => array(
        'title'     => __( 'Live Public Key', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( '<a href="http://support.paymaya.com/support/home" target="_blank">Contact PayMaya</a> and you will be given a new set of API keys provisioned in production which you can use to process live payments.', 'woocommerce' ),
        'desc_tip'  => false,
      ),

      'facilitator' => array(
        'title'       => __( 'Payment Facilitator', 'woocommerce' ),
        'type'        => 'header',
        'description' => 'Payment Facilitator Object is required to be provided for Merchants who are operating under the Payment <a href="https://usa.visa.com/dam/VCOM/download/merchants/02-MAY-2014-Visa-Payment-FacilitatorModel.pdf" target="_blank">Facilitator model</a>.<hr>',
        'desc_tip'    => false,
      ),

      'pf_enabled' => array(
        'title'     => __( 'Enable/Disable', 'woocommerce' ),
        'type'      => 'checkbox',
        'label'     => __( 'Enable PayMaya Payment Facilitator.', 'woocommerce' ),
        'default'   => 'no'
      ),

      'pf_smi_test' => array(
        'title'     => __( 'Test Sub-merchant ID ', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( 'Sub-merchant ID assigned by the payment facilitator or their acquirer [pf_id]-[hex_sequence]', 'woocommerce' ),
        'desc_tip'    => true,
        'default' => '00000123456-00'
      ),

      'pf_smn_test' => array(
        'title'     => __( 'Test Name of Sub-merchant', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => 'Sub-merch'
      ),

      'pf_mci_test' => array(
        'title'     => __( 'Test Sub-merchant City', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => 'MANILA'
      ),

      'pf_mpc_test' => array(
        'title'     => __( 'Test 3-digit Numeric Country Code', 'woocommerce' ),
        'type'      => 'text',
        'description' => __('3-digit numeric country code of the sub-merchant. It should be in ISO 3166-1 numeric code format.','woocommerce' ),
        'desc_tip'    => true,
        'default' => '608'
      ),

      'pf_mco_test' => array(
        'title'     => __( 'Test Alphabetic 3-character Country Code', 'woocommerce' ),
        'type'      => 'text',
        'description' => __('Alphabetic 3-character country code of the sub-merchant. It should be in ISO 3166-1 alpha-3 code format.', 'woocommerce' ),
        'desc_tip'    => true,
        'default' => 'PHL'
      ),

     'pf_mst_test' => array(
        'title'     => __( 'Test Sub-merchant state', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => 'UT'
      ), 

     'pf_hr' => array(
            'title'       => '',
            'type'        => 'hr',
            'description' => '',
            'desc_tip'    => false,
          ),

     'pf_smi_live' => array(
        'title'     => __( 'Live Sub-merchant ID ', 'woocommerce' ),
        'type'      => 'text',
        'description' => __( 'Sub-merchant ID assigned by the payment facilitator or their acquirer [pf_id]-[hex_sequence]', 'woocommerce' ),
        'desc_tip'    => true,
        'default' => '',

      ),

      'pf_smn_live' => array(
        'title'     => __( 'Live Name of Sub-merchant', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => ''
      ),

      'pf_mci_live' => array(
        'title'     => __( 'Live Sub-merchant City', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => ''
      ),

      'pf_mpc_live' => array(
        'title'     => __( 'Live 3-digit Numeric Country Code', 'woocommerce' ),
        'type'      => 'text',
        'description' => __('3-digit numeric country code of the sub-merchant. It should be in ISO 3166-1 numeric code format.','woocommerce' ),
        'desc_tip'    => true,
        'default' => ''
      ),

      'pf_mco_live' => array(
        'title'     => __( 'Live Alphabetic 3-character Country Code', 'woocommerce' ),
        'type'      => 'text',
        'description' => __('Alphabetic 3-character country code of the sub-merchant. It should be in ISO 3166-1 alpha-3 code format.', 'woocommerce' ),
        'desc_tip'    => true,
        'default' => ''
      ),

     'pf_mst_live' => array(
        'title'     => __( 'Live Sub-merchant state', 'woocommerce' ),
        'type'      => 'text',
        'description' => '',
        'default' => ''
      ), 

      'debug' => array(
          'title'             => __( 'Debug Log', 'woocommerce' ),
          'type'              => 'checkbox',
          'label'             => __( 'Enable logging', 'woocommerce' ),
          'default'           => 'no',
          'description'       =>  sprintf( __( 'Log %s events, such as API requests, inside <code>%s</code>', 'woocommerce' ), $this->method_title, wc_get_log_file_path( $this->id ) ),
        )
    );

  } // End of init_form_fields()


  /**
   * WooCommerce Payment Fields
   *
   * @return ''
   *
   */

  public function payment_fields(){

    $description =   wpautop( wp_kses_post( $this->description ) );

    if( in_array( get_option( 'woocommerce_currency' ), $this->supported_currencies ) ){

        if ( $this->mode == 's' ){
        $description .= sprintf( __( 'TEST MODE/SANDBOX ENABLED <br>Use a test card: %s', 'woocommerce') , '<a href="https://developers.paymaya.com/blog/entry/payment-vault-test-merchants-and-cards">https://developers.paymaya.com/blog/entry/payment-vault-test-merchants-and-cards</a>' );
        }

        if ( $description ) {
          echo wpautop( wptexturize( trim( $description ) ) );
        }

        parent::payment_fields();

    } else {
       echo __( wpautop( wptexturize( 'PayMaya Payment Gateway does not support your store currency.' ) ) );
    }

    

  } // End of payment_fields()

  /**
   * WooCommerce admin options
   *
   * @return ''
   *
   */

  public function admin_options(){
    
    echo '<h3>'.__( 'PayMaya - WooCommerce Payment Gateway', 'woocommerce'  ).'</h3>';
    echo '<p>'.__(  'Merchant Details.', 'woocommerce' ).'</p>';

    $this->pm_checks();
    echo '<table class="form-table">';
    $this->generate_settings_html();
    echo '</table>';
    

  } // End of admin_options()

  /**
   * PayMaya Payments Remote API Call
   *
   * @param array $params
   * @param string $action
   * @param string $method
   * @return array  $result
   *
   */

  public function pm_request( $params, $action , $method = 'POST' ){

    global $woocommerce;

    $endpoint_url = $this->endpoint_url;
    $secret_key   = $this->secret_key;

    $params = ! empty($params) && is_array($params) ? json_encode($params) : array();

    $response = wp_remote_post( $endpoint_url . $action ,
                    array(
                        'method'    => $method,
                        'headers'   => array(
                          'Content-Type' => 'application/json',
                          'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' )
                          ),
                        'body'      => $params ,
                        'timeout'   => 90,
                        'sslverify' => $this->sslverify,
                        'user-agent'    => 'WooCommerce ' . $woocommerce->version
                    ) );

      self::log( 'PayMaya API Request ' . print_r( $params, true ) );
      self::log( 'PayMaya API Response ' . print_r( $response, true ) );

      if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $error_message)  , "error" );
        return false;
      } else {
        $result = json_decode($response['body']);
      }

     return $result;
  }


  
  /**
   * WooCommerce validate fields 
   *
   * @return true
   *
   */

  public function validate_fields() {
    return true;
  } // End of validate_fields()

  /**
   * PayMaya addon script
   * 
   * @return ''
   *
   */   

  public function paymaya_scripts() {

    $load_payamaya_scripts = false;

    if ( is_checkout() ) {
      $load_payamaya_scripts = true;
    }

    if ( $this->is_available() ) {
      $load_payamaya_scripts = true;
    }
    
    if ( false === $load_payamaya_scripts ) {
      return;
    }

    if( is_user_logged_in() ){

      $customer_id         = get_current_user_id();

      $pm_billing_first_name  = get_user_meta( $customer_id ,'billing_first_name', true  );
      $pm_billing_last_name  = get_user_meta( $customer_id ,'billing_last_name', true  );
      $pm_billing_email  = get_user_meta( $customer_id ,'billing_email', true  );
      $pm_billing_phone  = get_user_meta( $customer_id ,'billing_phone', true  );
      $pm_address_line1  = get_user_meta( $customer_id ,'billing_address_1', true  );
      $pm_address_line2  = get_user_meta( $customer_id ,'billing_address_2', true  );
      $pm_address_city   = get_user_meta( $customer_id ,'billing_city', true  );
      $pm_address_state  = get_user_meta( $customer_id ,'billing_state', true  );
      $pm_address_postcode = get_user_meta( $customer_id ,'billing_postcode', true  );
      $pm_address_country  = get_user_meta( $customer_id ,'billing_country', true  );

    }

    $pm_app_url = WC()->api_request_url( 'WC_PayMaya_Gateway' );
    $ajax_url   = esc_url_raw(add_query_arg( 'ajax_call',true, $pm_app_url ));

    // service is not working 
    //wp_enqueue_script( 'paymaya', 'https://s3-ap-southeast-1.amazonaws.com/paymaya-assets/paymaya.js', array( 'jquery' ), WC_VERSION, true );
    wp_enqueue_script( 'wc-rits_paymaya-gateway', $this->plugins_url() . 'assets/js/paymaya.js', array( 'jquery', 'wc-credit-card-form' ), WC_VERSION, true );
    wp_localize_script( 'wc-rits_paymaya-gateway', 'PayMaya_params', array(
      //'pm_ajax_url'           => $ajax_url,
      //'pm_public_key'         => $this->public_key,
      'pm_billing_first_name' => __( !empty( $pm_billing_first_name ) ? $pm_billing_first_name : '' , 'woocommerce' ),
      'pm_billing_last_name'  => __( !empty( $pm_billing_last_name ) ? $pm_billing_last_name : '' , 'woocommerce' ),
      'pm_billing_email'      => __( !empty( $pm_billing_email ) ? $pm_billing_email : '' , 'woocommerce' ),
      'pm_billing_phone'      => __( !empty( $pm_billing_phone ) ? $pm_billing_phone : '' , 'woocommerce' ),
      'pm_address_line1'      => __( !empty( $pm_address_line1 ) ? $pm_address_line1 : '' , 'woocommerce' ),
      'pm_address_line2'      => __( !empty( $pm_address_line2 ) ? $pm_address_line2 : '' , 'woocommerce' ),
      'pm_address_city'       => __( !empty( $pm_address_city ) ? $pm_address_city : ''  , 'woocommerce' ),
      'pm_address_state'      => __( !empty( $pm_address_state ) ? $pm_address_state : '', 'woocommerce' ),
      'pm_address_postcode'   => __( !empty( $pm_address_postcode ) ? $pm_address_postcode : '', 'woocommerce' ),
      'pm_address_country'    => __( !empty( $pm_address_country ) ? $pm_address_country : '' , 'woocommerce' ),
      'pm_customer_id'        => __( !empty( $customer_id ) ? $customer_id : '' , 'woocommerce' ),
      'pm_is_user_logged_in'  => ( is_user_logged_in() === true ? true : false ) ,
      'pm_is_account_page'    => ( is_account_page() === true ? true : false ) ,
      'pm_env'                => ( $this->mode == 's' ? 'sandbox' : 'production' ) ,
    ) );

  }



  /**
   * Set WP Local Script AJAX
   *
   * @return void
   *
   */

  public static function ajax_local_script(){
    wp_register_script( 'pm_ajax_script', plugins_url( 'assets/js/pm.js' ,__FILE__  ) );  
        wp_localize_script( 'pm_ajax_script', 'pm_ajax_service', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'plugins_url' => plugins_url(),
    ) );

    wp_enqueue_script( 'pm_ajax_script' );

  }

  /**
   * Ajax Request and Response
   *
   * @return json array
   *
   */

  public static function ajax_local_request(){
    if( self::request_is_ajax() === true ){
        $method = sanitize_text_field( $_REQUEST[ 'method' ] );
        $func   = sanitize_text_field( $_REQUEST[ 'func' ] );
        $data = $_REQUEST[ 'data' ] ;

        if( method_exists( $method , $func ) ){

            $return       = call_user_func_array( "$method::$func" , array( $data ) );
            $response_code    = ( $return[ 'code' ] == true ? true : false );
            $response_message   = __( $return[ 'message' ] , 'woocommerce' );
            $response_result  = ( ! empty( $return[ 'data' ] ) ? $return[ 'data' ] : '' );

        } else {

            $response_code    =  false;
            $response_message   = __( 'Invalid method Name!', 'woocommerce' );
            $response_result  = '';
        }

        header( "Content-Type: application/json" );
        echo json_encode( array(
            'success' => $response_code,
            'response_message' => $response_message,
            'data' => $response_result,
            'time' => time()
        ) );
    } 
    exit;
  }

  /**
   * PayMaya Check if AJAX Request
   *
   * @return boolean
   *
   */

  public static function request_is_ajax(){

    global $wp_version;

    $version = '4.4';

    if ( version_compare( $wp_version, $version, '>=' ) ) {
      if( wp_doing_ajax() ){
        return true;
      }

    } else {
      $script_filename = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
      if( (defined('DOING_AJAX') && DOING_AJAX ) ){
            
            $ref = '';
            if ( ! empty( $_REQUEST['_wp_http_referer'] ) )
                $ref = wp_unslash( $_REQUEST['_wp_http_referer'] );
            elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) )
                $ref = wp_unslash( $_SERVER['HTTP_REFERER'] );

        if(((strpos($ref, admin_url()) === false) && (basename($script_filename) === 'admin-ajax.php')))
          return true;
      }  
    }
      return false;
  }


  /**
   * PayMaya Create Token
   *
   * @return json array
   *
   */

  public static function create_token( $params ){
    
    global $woocommerce;

    $data = array( 'card' => 
                    array(
                      'number' => wc_clean( $params[ 'card-number' ] ),
                      'expMonth' => wc_clean( str_pad($params[ 'card-expiry-month' ], 2, '0', STR_PAD_LEFT) ),
                      'expYear' => wc_clean( $params[ 'card-expiry-year' ] ),
                      'cvc' => wc_clean( $params[ 'card-cvc' ] )
                    ) 
                  );

    $data = json_encode($data);

    $settings_api = get_option( 'woocommerce_rits_paymaya_settings' );
    
      // Live or Sanbox Settings need to redeclare 
      if( $params['enviroment'] == 'sandbox' ){

          $endpoint_url     = "https://pg-sandbox.paymaya.com";
          $public_key       = ! empty($settings_api[ 'test_public_key' ]) ? $settings_api[ 'test_public_key' ] : '' ;
          $sslverify        = false;

      } else {

          $endpoint_url     = "https://pg.paymaya.com";
          $public_key       = ! empty($settings_api[ 'live_public_key' ]) ? $settings_api[ 'live_public_key' ] : '' ;
          $sslverify        = true;

      }

    $response = wp_remote_post( 
                    $endpoint_url . '/payments/v1/payment-tokens',
                    array(
                        'method'    => 'POST',
                        'headers'   => array( 
                          'Content-Type' => 'application/json',
                          'Authorization' => 'Basic ' . base64_encode( $public_key . ':' )),
                        'body'      => $data ,
                        'timeout'   => 90,
                        'sslverify' => $sslverify,
                        'user-agent'    => 'WooCommerce ' . $woocommerce->version
                    ) );

    //echo "<pre>"; print_r($response); echo "</pre>";

     if ( is_wp_error( $response ) ) {

        $error_message = $response->get_error_message();
        $result[ 'code' ]  == false;
        $result[ 'message' ]  = $error_message; 

      } else {

        $r = json_decode( $response['body'] );
        
        if( $r->state == 'AVAILABLE' ){

          $result[ 'code' ]  = true;
          $result[ 'data' ][ 'token' ] = $r->paymentTokenId;  

        } else {

          $result[ 'code' ]  = false;  
          $result[ 'message' ] = $r->message; 
          $result[ 'data' ][ 'err_code' ]     = $r->code; 
          $result[ 'data' ][ 'parameters' ]   = $r->parameters; 

        }

      }

      return $result;

  }

  /**
   * PayMaya Payment logger
   *
   * @param string $message
   * @return void
   *
   */

  public static function log( $message ) {
      if ( self::$log_enabled == 'yes' ) {
        if ( empty( self::$log ) ) {
          self::$log = new WC_Logger();
        }
        self::$log->add( 'PayMaya' , $message );
      }
  }

  /**
   * Woocommerce Process Payment
   * 
   * @param int $order_id
   * @return method process_payment() | process_paymaya_payments()
   *
   */

  public function process_payment( $order_id ) {
    global $woocommerce;
    $order  = wc_get_order( $order_id );

    // New Credit Card Token
    if ( isset( $_POST['rits_paymaya_token'] ) ) {
      $card_token     = wc_clean( $_POST[ 'rits_paymaya_token' ] );
    } 
    
    // Existing Token
    if ( isset( $_POST['wc-rits_paymaya-payment-token'] ) && 'new' !== $_POST['wc-rits_paymaya-payment-token'] ) {
      
      $token_id = wc_clean( $_POST['wc-rits_paymaya-payment-token'] );
      $token    = WC_Payment_Tokens::get( $token_id );
      
      if ( $token->get_user_id() !== get_current_user_id() ) {
        wc_add_notice( __( 'Please make sure your card details have been entered correctly and that your browser supports JavaScript 1.', 'woocommerce' ), 'error' );
        return;
      }

      $pm_customer_id = $this->get_paymaya_customer_id();
      return $this->process_paymaya_payments( $order, $token->get_token(), $pm_customer_id );

    } else {

      if ( is_user_logged_in() ) {

         if ( isset( $_POST[ 'wc-rits_paymaya-new-payment-method' ] ) && true === (bool) $_POST[ 'wc-rits_paymaya-new-payment-method' ] ) {
            $customer_card_token  = $this->process_customer( $order, $card_token );
            // Get Customer ID
            $pm_customer_id       = $this->get_paymaya_customer_id();  

            //echo "<pre>"; print_r($customer_card_token); echo "</pre>";
            //echo "<pre>"; print_r($pm_customer_id); echo "</pre>";
            //exit;

            if ( !is_null( $customer_card_token ) && ! empty( $pm_customer_id )  ) {
              return $this->process_paymaya_payments( $order, $customer_card_token->get_token(), $pm_customer_id );  
            }  

         } else {

            //echo "<pre>"; print_r($order); echo "</pre>";
            //echo "<pre>"; print_r($card_token); echo "</pre>";
            //exit;

            return $this->process_paymaya_payments( $order, $card_token );  
         }  
      
      } else {
         return $this->process_paymaya_payments( $order, $card_token );
      }

    }

  } // End of process_payment()


 /**
  * Processing PayMaya payments
  *
  * @param WC_Order $order
  * @param string $card_token
  * @param string $pm_customer_id
  * @return array array|WC_Add_Notice
  *
  */

  public function process_paymaya_payments( $order, $card_token = '', $pm_customer_id = '' ){

    global $woocommerce;

    if ( empty( $card_token ) ) {
      $err_msg = __( 'Please make sure your card details have been entered correctly and that your browser supports JavaScript 2.', 'woocommerce' );

      $order->add_order_note(
          sprintf(
              "%s Payments Failed with message: '%s'",
              $this->method_title,
              $err_msg
          )
      );

      wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $err_msg )  , "error" );
      return;

    }

    $pm_tokens = array();

    if ( ! empty ( $card_token ) ) {
      $pm_tokens[ 'paymentTokenId' ] = $card_token;
    }

    $ip_address = ! empty( $_SERVER['HTTP_X_FORWARD_FOR'] ) ? $_SERVER['HTTP_X_FORWARD_FOR'] : $_SERVER['REMOTE_ADDR'];
    $currency = get_post_meta( $order->id,'_order_currency',true);

    $success = add_query_arg( array(
          'response' => 'success',
          'order_id' => $order->id,
      ), $this->ipn_url );

    $failure = add_query_arg( array(
        'response' => 'failure',
        'order_id' => $order->id,
      ), $this->ipn_url );

    if (!$currency || empty($currency)) $currency = get_woocommerce_currency();


    $pm_params = array(

      'totalAmount' => array(
              'amount'   => (double) number_format($order->get_total(), 2, ".", ""),
              'currency' => $currency,
        ), 

      'buyer' => array(
             'firstName' =>$order->get_billing_first_name(),
             'lastName'  => $order->get_billing_last_name(),
             'contact' => array(
                  'phone' => $order->get_billing_phone(),
                  'email' => $order->get_billing_email(),
              ),
            'billingAddress' => array(
                  'line1'   => $order->get_billing_address_1(),
                  'line2'   => $order->get_billing_address_2(),
                  'city'    => $order->get_billing_city(),
                  'state'   => $order->get_billing_state(),
                  'zipCode' => $order->get_billing_postcode(),
                  'countryCode' => $order->get_billing_country(),
              )
        ),
      'redirectUrl' => array(
              'success' => wc_clean($success),
              'failure' => wc_clean($failure),
              'cancel' => $woocommerce->cart->get_checkout_url(),
            ), 
      'metadata' => (array) $this->pf(),
      'requestReferenceNumber' =>  $order->get_order_number(),
        
    );
    

    if( ! empty($pm_customer_id)){

      $pay_response = $this->pm_request( array(
          'totalAmount' => array(
              'amount'   => (double) number_format($order->get_total(), 2, ".", ""),
              'currency' => $currency,
            ), 
          'redirectUrl' => array(
              'success' => wc_clean($success),
              'failure' => wc_clean($failure),
              'cancel' => $woocommerce->cart->get_checkout_url(),
            ), 
          'requestReferenceNumber' =>  $order->get_order_number(),
          'metadata' => (array) $this->pf()
        ) , '/payments/v1/customers/'.$pm_customer_id.'/cards/'.$card_token.'/payments', 'POST' );
       
    } else {

      $pm_params = array_merge( $pm_params, $pm_tokens );
      // do paymaya payments
      $pay_response = $this->pm_request( $pm_params , '/payments/v1/payments', 'POST' );
    }
     
    
    $state = ! empty($pay_response->state) ? $pay_response->state : '';
    $status = ! empty($pay_response->status) ? $pay_response->status: '';

    //  3-D Secure Pre verification
    if( $state == 'PREVERIFICATION' && ! empty($pay_response)){
       // PayMaya will automatically try to charge the card upon creation of the Payment object. By default, the API call for Payment creation is blocking (synchronous).
       
       if ( ! add_post_meta( $order->id , '_transaction_id', $pay_response->id , true ) ) { 
           update_post_meta( $order->id , '_transaction_id', $pay_response->id  );
        }

        wc_add_notice( __( 'To complete this order, please check your email and click the verification link.' , 'woocommerce')  , "notice" );

         $order->add_order_note(
            sprintf(
                "%s Order On-Hold with Transaction ID '%s' %s",
                $this->method_title,
                $pay_response->id,
                __( 'Customer need to confirm the verification Url', 'woocommerce')
            )
        );

        //awaiting payment verification
        $order->update_status( 'on-hold' );

        $woocommerce->cart->empty_cart();

        return array(
          'result' => 'success',
          'redirect' => $this->get_return_url( $order )
        );

    } 

    if ( ! empty($status) ) {
        
        switch ( $status ) {
            case 'PENDING_PAYMENT':

              update_post_meta( $order->id, '_pm_paymentTokenId', $pay_response->paymentTokenId );

              //Insert Transaction ID
              if ( ! add_post_meta( $order->id , '_transaction_id', $pay_response->id , true ) ) { 
                 update_post_meta( $order->id , '_transaction_id', $pay_response->id  );
              }

              // paid false need to verify 3DS 
              if( $pay_response->isPaid === false && !empty( $pay_response->verificationUrl ) ){

                  wc_add_notice( __( 'To complete this order, please check your email and click the verification link.' , 'woocommerce')  , "notice" );

                   $order->add_order_note(
                      sprintf(
                          "%s Order On-Hold with Transaction ID '%s' %s",
                          $this->method_title,
                          $pay_response->id,
                          __( 'Customer need to confirm the verification Url', 'woocommerce')
                      )
                  );

                  //awaiting payment verification
                  $order->update_status( 'on-hold' );
              } else {
                  $order->add_order_note(
                    sprintf(
                        "%s Payments Pending with Transaction ID '%s' %s",
                        $this->method_title,
                        $pay_response->id,
                        $pay_response->description
                    )
                );
                  $order->update_status( 'pending' );  
              } 

              
              $woocommerce->cart->empty_cart();
              return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
              );

              break;

            case 'PAYMENT_SUCCESS':
            case '3DS_PAYMENT_SUCCESS':

                $order_status = $this->virtual_order_payment_complete_order_status( $order->id );

                if($order_status == 'completed' ){
                    $order->update_status( $order_status );
                    
                } 

                update_post_meta( $order->id, '_pm_paymentTokenId', $pay_response->paymentTokenId );

                $order->payment_complete( $pay_response->id );

                $order->add_order_note(
                      sprintf(
                          "%s Payments Completed with Transaction ID '%s' %s",
                          $this->method_title,
                          $pay_response->id,
                          $pay_response->description
                      )
                  );

                $woocommerce->cart->empty_cart();

                return array(
                  'result' => 'success',
                  'redirect' => $this->get_return_url( $order )
                );

              break;

            case '3DS_PAYMENT_FAILURE':  
            case 'PAYMENT_FAILED':
            case 'PAYMENT_INVALID':
            case 'VOIDED':

              $order->add_order_note(
                  sprintf(
                      "%s Payments Failed with message: '%s'",
                      $this->method_title,
                      $pay_response->description
                  )
              );

              wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $pay_response->description )  , "error" );
              return false;

              break;
            case 'REFUNDED':
              break;

            case 'FOR_AUTHENTICATION':

            $verificationUrl = $pay_response->verificationUrl;

             return array(
                  'result' => 'success',
                  'redirect' => $verificationUrl
                );

                break;  
                
            default:

              $order->add_order_note(
                  sprintf(
                      "%s Payments Failed with message: '%s - %s'",
                      $this->method_title,
                      $pay_response->code,
                      $pay_response->message
                  )
              );

              wc_add_notice( sprintf( __( 'Transaction Error: %s - %s' , 'woocommerce') , 
                  $pay_response->code, 
                  $pay_response->message ) , 
              "error" );
              return false;
              
              break;
          }  
    } else {

      $order->add_order_note(
              sprintf(
                  "%s Payments Failed with message: '%s - %s'",
                  $this->method_title,
                  $pay_response->code,
                  $pay_response->message
              )
          );

          wc_add_notice( sprintf( __( 'Transaction Error: %s - %s' , 'woocommerce') , 
              $pay_response->code, 
              $pay_response->message ) , 
          "error" );
          return false;
    }
  }

  /**
   * PayMaya Payments Plugin  Url Path
   *
   * @return '' | WP plugins_url
   *
   */

  public function plugins_url(){
    return plugins_url( '/', __FILE__ );
  }

  /**
   * PayMaya Payments virtual order checker
   *
   * @param WC_Order $order_id
   * @return WC_Order_Status  $result
   *
   */

  public function virtual_order_payment_complete_order_status( $order_id ){
        
    //$order = new WC_Order( $order_id );
    $order      = wc_get_order( $order_id );

    $virtual_order = null;

    if ( count( $order->get_items() ) > 0 ) {

        foreach( $order->get_items() as $item ) {

          if ( 'line_item' == $item['type'] ) {

              $_product = $order->get_product_from_item( $item );

                if ( ! $_product->is_virtual() ) {
                  $virtual_order = false;
                  break;
                } else {
                  $virtual_order = true;
                }
          }
        }
    }

    // virtual order, mark as completed
    if ( $virtual_order ) {
      return 'completed';
    }


    // non-virtual order, return original status
    return $order->status;
  }

  /**
   * PayMaya Payments processing customer
   *
   * @param WC_Order $order
   * @param string $card_token
   * @return array $token|null
   */

  protected function process_customer( $order,  $card_token = '' ) {

      $customer_info = array(

        'firstName' => $order->get_billing_first_name(),
        'lastName'  => $order->get_billing_last_name(),
        'phone'     => $order->get_billing_phone(),
        'email'     => $order->get_billing_email(),
        'line1'     => $order->get_billing_address_1(),
        'line2'     => $order->get_billing_address_2(),
        'city'      => $order->get_billing_city(),
        'state'     => $order->get_billing_state(),
        'zipCode'   => $order->get_billing_postcode(),
        'countryCode' => $order->get_billing_country(),

      );

      $token = $this->save_token( $card_token, $customer_info , $order->id );
      
      if ( ! is_null( $token ) ) {
        $order->add_payment_token( $token );
        return $token;
      } else{
        return null;
      }
    

  }

  /**
   * PayMaya Payments Save Token
   *
   * @param string $card_token
   * @param array $card_info
   * @return ''| null
   *
   */

  public function save_token( $card_token = '', $customer_info = '' , $order_id = '' ) {

    global $woocommerce;

    $paymaya_cc_expiry_year = wc_clean( $_POST[ 'rits_paymaya-cc-expiry-year' ]);
    $paymaya_cc_expiry_month = wc_clean( $_POST[ 'rits_paymaya-cc-expiry-month' ]);

    $pm_customer_id = $this->get_paymaya_customer_id();

    if( ! empty($order_id) ){
      $success = add_query_arg( array(
          'response' => 'success',
          'order_id' => $order_id,
      ), $this->ipn_url );

      $failure = add_query_arg( array(
          'response' => 'failure',
          'order_id' => $order_id,
        ), $this->ipn_url );

      $cancel = $woocommerce->cart->get_checkout_url();

      $redirectUrl = array(
              'success' => esc_url( $success ),
              'failure' => esc_url( $failure ),
              'cancel' => esc_url( $cancel ),
            );

    } else {

      // Customer Add Payment Method in My Account
      $myaccount_page_id = get_option( 'woocommerce_myaccount_page_id' );
      if ( $myaccount_page_id ) {
        $myaccount_page_url = get_permalink( $myaccount_page_id );
      }  

      $redirectUrl = array(
              'success' => esc_url( $myaccount_page_url . 'payment-methods' ),
              'failure' => esc_url( $myaccount_page_url . 'add-payment-method' ),
              'cancel' => esc_url( $myaccount_page_url . 'add-payment-method' ),
            );
    }
    
    
    if ( ! empty( $pm_customer_id ) ) {

      //Saves a customer's card into the card vault given a PaymentToken. 
      $pm_token = $this->pm_request( array(
          'paymentTokenId'  => $card_token ,
          'isDefault'       => false,
          'redirectUrl'     => (array) $redirectUrl, 
          'metadata'        => (array) $this->pf(),
        ), '/payments/v1/customers/'.$pm_customer_id.'/cards', 'POST' );
      

       if( $pm_token->code == 'PY0023' ){
          update_user_meta( get_current_user_id(), '_pm_customer_id', '' );
          $err_msg = "Invalid PayMaya Customer ID. Please try again.";
          wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $err_msg )  , "error" );
          return;
       }

      $cardTokenId = ! empty($pm_token->cardTokenId) ? $pm_token->cardTokenId : '';
      
      if( ! empty( $cardTokenId ) ){
          
          $token = new WC_Payment_Token_CC();
          $token->set_token( $cardTokenId );
          $token->set_gateway_id( 'rits_paymaya' );
          $card_type = explode('-', $pm_token->cardType);
          $token->set_card_type( $card_type[0] );
          $token->set_last4( $pm_token->maskedPan );
          $token->set_expiry_month( $paymaya_cc_expiry_month );
          $token->set_expiry_year( $paymaya_cc_expiry_year );

          if ( is_user_logged_in() ) {
            $token->set_user_id( get_current_user_id() );
          }

           var_dump( $token->validate() ); // bool(true)
          $result = $token->save();

          if( $result == true ){
            return $token;  
          } else {
            return null;
          }

          
      } else {

        // notice error
        $err_msg = '['.$pm_token->code . '] '. $pm_token->message;
        wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $err_msg )  , "error" );
        return;

      }
              

    } else { // New Customer and Card

       // You can save your customer information through the Customer resource. Payment tokens can be linked to a saved customer through the Card resource.
       
        $customer = $this->pm_request( array(
          'firstName' => $customer_info[ 'firstName' ],
          'lastName'  => $customer_info[ 'lastName' ],
          'contact' => array(
            'phone' => $customer_info[ 'phone' ],
            'email' => $customer_info[ 'email' ],
          ),
          'billingAddress' => array(
              'line1'   => $customer_info[ 'line1' ],
              'line2'   => $customer_info[ 'line2' ],
              'city'    => $customer_info[ 'city' ],
              'state'   => $customer_info[ 'state' ],
              'zipCode' => $customer_info[ 'zipCode' ],
              'countryCode' => $customer_info[ 'countryCode' ],
          ),
          'metadata' => (array) $this->pf()
        ) ,'/payments/v1/customers', 'POST' );

        $customer_id = ! empty($customer->id) ? $customer->id : '';

        if( ! empty($customer_id) ){

          //Saves a customer's card into the card vault given a PaymentToken. 
          $pm_token = $this->pm_request( array(
              'paymentTokenId'  => $card_token ,
              'isDefault'       => true,
              'redirectUrl'     => (array) $redirectUrl, 
              'metadata'        => (array) $this->pf(),
            ), '/payments/v1/customers/'.$customer_id.'/cards', 'POST' );


          $cardTokenId = ! empty($pm_token->cardTokenId) ? $pm_token->cardTokenId : '';

          if( ! empty( $cardTokenId ) ){

              if(add_user_meta( get_current_user_id(), '_pm_customer_id', $customer_id )) {
                update_user_meta( get_current_user_id(), '_pm_customer_id', $customer_id );
              } 

                $token = new WC_Payment_Token_CC();
                $token->set_token( $cardTokenId );
                $token->set_gateway_id( 'rits_paymaya' );

                $card_type = explode('-', $pm_token->cardType);

                $token->set_card_type( $card_type[0] );
                $token->set_last4( $pm_token->maskedPan );
                $token->set_expiry_month(  $paymaya_cc_expiry_month );
                $token->set_expiry_year(  $paymaya_cc_expiry_year );

                if ( is_user_logged_in() ) {
                  $token->set_user_id( get_current_user_id() );
                }

               // var_dump( $token->validate() ); // bool(true)
                $result = $token->save();

                WC_Payment_Tokens::set_users_default( get_current_user_id(), $token->get_id() );


                if( $result == true ){
                  return $token;
                } else {
                  return null;
                }     

          } else {
            // notice error
            $err_msg = '['.$pm_token->code . '] '. $pm_token->message;
            wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $err_msg )  , "error" );
            return;
          }

        } else {
          // notice error
          $err_msg = '['.$customer->code . '] '. $customer->message;
          wc_add_notice( sprintf( __( 'Transaction Error: %s' , 'woocommerce') , $err_msg )  , "error" );
          return;
        }

    }
  }

  public function pm_handler(){
    @ob_clean();
    header( 'HTTP/1.1 200 OK' );
      
      global $woocommerce;

      $request = ! empty( $_REQUEST ) ? $_REQUEST : false;

      $order_id = wc_clean( $request[ 'order_id' ]);
      //$order      = new WC_Order( $order_id );
      $order      = wc_get_order( $order_id );

      if( $request[ 'response' ] == 'success' && ! empty($request[ 'order_id' ])){

        $order_status = $this->virtual_order_payment_complete_order_status( $order_id );

        if($order_status == 'completed' ){
          $order->update_status( $order_status );
        } 

        $order->payment_complete();
        $order->add_order_note(
          sprintf(
            "%s Payment Completed",
            $this->method_title
          )
        );

        $woocommerce->cart->empty_cart();
        wp_redirect(  $this->get_return_url( $order ) ); exit; 

      } else {

        $message = "Error processing checkout. Please try again.";
        $order->add_order_note( sprintf( "%s Payments Failed: '%s'", $this->method_title, $message ) ); 
        wc_add_notice( __( sprintf( "Transaction Error: '%s'", $message ) , 'woocommerce'), "error" );

        wp_redirect( $woocommerce->cart->get_checkout_url() ); exit;  

      }

      exit;
  }

  /**
   * Generate Header in admin settings
   *
   * @param string $key
   * @param array $data
   * @return html
   *
   */ 

  public function generate_header_html( $key, $data ) {
    $field_key = $this->get_field_key( $key );
    $defaults  = array(
      'title'             => '',
      'label'             => '',
      'type'              => 'text',
      'description'       => ''
    );

    $data = wp_parse_args( $data, $defaults );

    if ( ! $data['label'] ) {
      $data['label'] = $data['title'];
    }


    ob_start();
    ?>
    <tr valign="top">
      <th scope="row" class="titledesc" colspan="2">
        <h2 for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h2>
        <?php echo $this->get_description_html( $data ); ?>
      </th>
    </tr>
    <?php

    return ob_get_clean();
  }

  /**
   * Generate HR Line in admin settings
   *
   * @param string $key
   * @param array $data
   * @return html
   *
   */

  public function generate_hr_html( $key, $data ) {
    $field_key = $this->get_field_key( $key );
    $defaults  = array(
      'title'             => '',
      'label'             => '',
      'type'              => 'hr',
      'description'       => ''
    );

    $data = wp_parse_args( $data, $defaults );

    if ( ! $data['label'] ) {
      $data['label'] = $data['title'];
    }

    ob_start();
    ?>
    <tr valign="top">
      <th scope="row" class="titledesc" colspan="2">
        <hr>
      </th>
    </tr>
    <?php

    return ob_get_clean();
  }

  /**
   * Get Customer ID
   * 
   * @return ID
   *
   */
  public function get_paymaya_customer_id(){
     return get_user_meta( get_current_user_id(), '_pm_customer_id', true ); 
  }

  /**
   * Add Payment Method
   * 
   * @return array array
   *
   */

  public function add_payment_method() {

    if ( empty ( $_POST[ 'rits_paymaya_token' ] ) ) {
      wc_add_notice( __( 'There was a problem adding this card.', 'woocommerce' ), 'error' );
      return;
    }

    $card_token     = wc_clean( $_POST[ 'rits_paymaya_token' ] );
    //$customer_token = $this->get_users_token();
    $current_user   = wp_get_current_user();

    $customer_info  = array(
        'firstName' => get_user_meta( $current_user->ID, 'billing_first_name', true ),
        'lastName'  => get_user_meta( $current_user->ID, 'billing_last_name', true ),
        'phone'     => get_user_meta( $current_user->ID, 'billing_phone', true ),
        'email'     => get_user_meta( $current_user->ID, 'billing_email', true ),
        'line1'     => get_user_meta( $current_user->ID, 'billing_address_1', true ),
        'line2'     => get_user_meta( $current_user->ID, 'billing_address_2', true ),
        'city'      => get_user_meta( $current_user->ID, 'billing_city', true ),
        'state'     => get_user_meta( $current_user->ID, 'billing_state', true ),
        'zipCode'   => get_user_meta( $current_user->ID, 'billing_postcode', true ),
        'countryCode' => get_user_meta( $current_user->ID, 'billing_country', true ),
      );


    $token = $this->save_token( $card_token, $customer_info );

    if ( is_null( $token ) ) {
      wc_add_notice( __( 'There was a problem adding this card.', 'woocommerce' ), 'error' );
      return;
    }

    return array(
      'result'   => 'success',
      'redirect' => wc_get_endpoint_url( 'payment-methods' ),
    );
  }

  /**
   * Get PayMaya icon.
   *
   * @access public
   * @return string
   *
   */

  public function get_icon() {
    
    $icon  = '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" />';
    $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="MasterCard" width="32" />';
    return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );

  }  

  /**
   * Process refunds.
   * WooCommerce 2.2 or later.
   *
   * @param  int $order_id
   * @param  float $amount
   * @param  string $reason
   * @return bool|WP_Error
   *
   */

  public function process_refund( $order_id, $amount = null, $reason = '' ) {

    $payment_id = get_post_meta( $order_id, '_transaction_id', true );
    $currency = get_post_meta( $order_id,'_order_currency',true);

    $refund = $this->pm_request( array(
            'reason' => $reason,
            'totalAmount' => array(
                  'amount'    => $amount ,
                  'currency'  => $currency
              )
      )
      ,'/payments/v1/payments/'.$payment_id.'/refunds', 'POST' );


    if( 'SUCCESS' == $refund->status ){
       return true;
    } else {
      return new WP_Error( 'rits_paymaya_refund_error', $e->getMessage() );
    }

    return false;
  }

}
