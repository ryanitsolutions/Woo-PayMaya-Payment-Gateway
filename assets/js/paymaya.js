(function ( $ ) {

  function payMayaPaymentsEventHandler(){

    var $paymaya_form = $( 'form.checkout, form#order_review, form#add_payment_method' );
    
    if ( ( $( '#payment_method_rits_paymaya' ).is( ':checked' ) && 'new' === $( 'input[name="wc-rits_paymaya-payment-token"]:checked' ).val() ) || ( '1' === $( '#woocommerce_add_payment_method' ).val() ) ) {

      if ( 0 === $( 'input.rits_paymaya-token' ).length ) {

         $paymaya_form.block({
           message: null,
           overlayCSS: {
             background: '#fff',
             opacity: 0.6
           }
         });

       var cardnumber     = $( '#rits_paymaya-card-number' ).val(),
           expiry         = $.payment.cardExpiryVal( $( '#rits_paymaya-card-expiry' ).val() ),
           addressZip     = $paymaya_form.find( '#billing_postcode' ).val() || '';

       addressZip = addressZip.replace( /-/g, '' );
       cardnumber = cardnumber.replace( /\s/g, '' );


       // Fetch details required for the createToken call to PayMaya

       if( PayMaya_params.pm_is_account_page == 1 ){

          var pm_first_name     = PayMaya_params.pm_billing_first_name;
          var pm_last_name      = PayMaya_params.pm_billing_last_name;
          var pm_email          = PayMaya_params.pm_billing_email;
          var pm_phone          = PayMaya_params.pm_billing_phone;
          var pm_address_line1  = PayMaya_params.pm_address_line1;
          var pm_address_line2  = PayMaya_params.pm_address_line2;
          var pm_address_city   = PayMaya_params.pm_address_city;
          var pm_address_state  = PayMaya_params.pm_address_state;
          var pm_address_postcode = PayMaya_params.pm_address_postcode;
          var pm_address_country  = PayMaya_params.pm_address_country;

       } else {

          var pm_first_name         = $paymaya_form.find( '#billing_first_name' ).val() || '';
          var pm_last_name          = $paymaya_form.find( '#billing_last_name' ).val() || '';
          var pm_email              = $paymaya_form.find( '#billing_email' ).val() || '';
          var pm_phone              = $paymaya_form.find( '#billing_phone' ).val() || '';
          var pm_address_line1      = $paymaya_form.find( '#billing_address_1' ).val() || '';
          var pm_address_line2      = $paymaya_form.find( '#billing_address_2' ).val() || '';
          var pm_address_city       = $paymaya_form.find( '#billing_city' ).val() || '';
          var pm_address_state      = $paymaya_form.find( '#billing_state' ).val() || '';
          var pm_address_postcode   = addressZip;
          var pm_address_country    = $paymaya_form.find( '#billing_country' ).val() || '';

       }


      var data = {
          card_number:         cardnumber,
          card_expiry_month:   expiry.month,
          card_expiry_year:    expiry.year,
          card_cvc:            $( '#rits_paymaya-card-cvc' ).val(),
          
          
        };

        var $paymentForm = { 
                              'card-number' : data.card_number,
                              'card-expiry-month' : data.card_expiry_month,
                              'card-expiry-year' : data.card_expiry_year,
                              'card-cvc' : data.card_cvc,
                              'enviroment' : PayMaya_params.pm_env
                             }

       

        var $req = {
            'action' : 'pm-ajax-request',
            'method' : 'WC_Paymaya_Gateway',
            'func'   : 'create_token',
            'data'   : $paymentForm
        }

        $( "form#place_order").prop( "disabled" , true );

        jQuery.post( pm_ajax_service.ajax_url, $req, function(response) {
             $( "form#place_order").prop( "disabled" , false );
             if( response.success === true ){

                $( "#wc-rits_paymaya-cc-form" ).append( '<input type="hidden" class="rits_paymaya-cc-expiry-year" name="rits_paymaya-cc-expiry-year" value="' + data.card_expiry_year + '"/>' );
                $( "#wc-rits_paymaya-cc-form" ).append( '<input type="hidden" class="rits_paymaya-cc-expiry-month" name="rits_paymaya-cc-expiry-month" value="' + data.card_expiry_month + '"/>' );

                payMayaHandleSuccess(response.data.token);

                  
                  

             } else {
                payMayaHandleError(response.response_message, response.data.parameters);
             }
            return false;
        });

        } else {
          return false;
        }
      } else {
         
         $paymaya_form.submit();
      } 

    return true;

  }

  
 function payMayaHandleSuccess(paymentToken) {
   
   var $pm_form  = $( 'form.checkout, form#order_review, form#add_payment_method' );
    $( "#wc-rits_paymaya-cc-form" ).append( '<input type="hidden" class="rits_paymaya-token" name="rits_paymaya_token" value="' + paymentToken + '"/>' );
    //console.log(paymentToken);
    $pm_form.submit();

  }

  function payMayaHandleError(err_msg, error) {

    var $pm_form  = $( 'form.checkout, form#order_review, form#add_payment_method' ),
      cc_form = $( '#wc-rits_paymaya-cc-form' );

    $( '.woocommerce-error, .rits_paymaya-token', cc_form ).remove();
    $pm_form.unblock();

    if (error) {

      errorList = '<strong>' + err_msg + '</strong> <br>' ;

      $.each(error, function(index, paramError) {

          errorList += '<li>' +  paramError.field + ' - ' + paramError.description + '</li>';
      });

      cc_form.prepend( '<ul class="woocommerce-error">' + errorList + '</ul>' );
    } else {
      errorList = '<li>' + err_msg + '</li>' ;     
      cc_form.prepend( '<ul class="woocommerce-error">' + errorList + '</ul>' );
    }
  }



jQuery( document ).ready( function( $ ) {

    $( document.body ).on( 'checkout_error', function () {
       $( '.rits_paymaya-token' ).remove();
       $( '.rits_paymaya-cc-expiry-year' ).remove();
       $( '.rits_paymaya-cc-expiry-month' ).remove();
    });


    $( "body" ).on( 'click', '#place_order', function( e ){
      
        if (  $( '#payment_method_rits_paymaya' ).is( ':checked' ) ) {
          
          payMayaPaymentsEventHandler();
          return false;    
        }

    });


    /* Checkout Form */
    //$( 'form.checkout' ).on( 'checkout_place_order_paymaya', function () {
    //  console.log( 'checkout_place_order_paymaya' );
     // return payMayaPaymentsEventHandler();
    //});

    /* Pay Page Form */
    //$( 'form#order_review' ).on( 'submit', function () { 
    //  console.log( ' form#order_review submit' );
    //  return payMayaPaymentsEventHandler();
    //});

    /* Pay Page Form */
    //$( 'form#add_payment_method' ).on( 'submit', function () {
    //   console.log( 'form#add_payment_method submit' );
    //  return payMayaPaymentsEventHandler();
    //});

    /* Both Forms */
    $( 'form.checkout, form#order_review, form#add_payment_method' ).on( 'change', '#wc-rits_paymaya-cc-form input', function() {
      $( '.rits_paymaya-token' ).remove();
      $( '.rits_paymaya-cc-expiry-year' ).remove();
      $( '.rits_paymaya-cc-expiry-month' ).remove();
    });

});

}( jQuery ) );
