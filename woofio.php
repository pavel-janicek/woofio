<?php
/*
Plugin Name: Woocommerce - FIO převod
Plugin URL: https://cleverstart.cz
Description: Automaticky spáruje platby platebním převodem do FIO banky
Version: 1.0.24
Author: Pavel Janíček
Author URI: https://cleverstart.cz
*/

require __DIR__ . '/vendor/autoload.php';

if (!class_exists('Cleverstart_Woofio')){
  class Cleverstart_Woofio extends WC_Integration{
    public function __construct() {
      $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
        'https://plugins.cleverstart.cz/?action=get_metadata&slug=woofio',
        __FILE__, //Full path to the main plugin file or functions.php.
        'woofio'
        );
        $this->id = "woofio";
        $this->method_title       = __( 'FIO API', 'woofio' );
		    $this->method_description = __( 'Umožní automatické párování plateb zaslaných do FIO banky.', 'woofio' );
		// Load the settings.
		    $this->init_form_fields();
		    $this->init_settings();
        $options = get_option('woocommerce_woofio_settings');
        //$this->api_key= $this->$options[ 'fio_token' ];
        //add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
        //add_action( 'admin_init', array( $this,'woofio_options_init') );
        add_action( 'admin_init', array( $this,'woofio_display_notice') );
        //add_action( 'admin_menu', array( $this,'woofio_options_page') );
        add_filter( 'woocommerce_integrations', array( $this,'woofio_add_section') );
        require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');
        add_action('woofio_hourly', array( $this,'woofio_create_haystack'));
        add_action('init', array($this , 'debug' ));
        if ( ! wp_next_scheduled( 'woofio_hourly' ) ) {
          wp_schedule_event(time(), 'hourly', 'woofio_hourly');
        }
        // Actions.
		    add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
         // Filters.
		    add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'sanitize_settings' ) );

      }

      public function debug(){
        if (isset($_GET['listener']) and ($_GET['listener'] == 'woofio') ){
          $options = get_option('woocommerce_woofio_settings');
          $pending_orders = $this->woofio_pending_variables();
          echo "<p>pending orders <br /></p>";
          print_r($pending_orders);
          echo "<p>all transactions from fio<br /></p>";
          $downloader = new FioApi\Downloader($options['fio_token']);
        	$transactionList = $downloader->downloadSince(new \DateTime('-1 week'));
          $allTransactions = $transactionList->getTransactions();
          echo "<p>array column</p>";
          print_r(array_column($pending_orders, 'number'));
        	foreach ($allTransactions as $transaction) {
            if ($transaction->getAmount()>0){
              echo "<p>Variable: " . $transaction->getVariableSymbol() ."<br /></p>";
              echo "<p>Amount: " . $transaction->getAmount() . "<br/></p>";
              echo "<p> currency: " . $transaction->getCurrency() . "<br/></p>";
              for($i=0;$i<count($pending_orders);$i++) {
                if($transaction->getVariableSymbol()==$pending_orders[$i]['number']){
                  echo "<p>transaction->getVariableSymbol()==pending_orders[i][\"number\"]</p>";
                }

              }
            }
          }
          exit;
        }
      }



      public function woofio_add_section( $sections ) {

	       $sections['woofio'] ='Cleverstart_Woofio';
	        return $sections;

        }



    public function init_form_fields() {
  	   $this->form_fields = array(
         'fio_token' => array(
  			               'title'     => __( 'Fio API Token', 'woofio' ),
                       'type'     => 'text',
                       'description'     => __( 'Vložte vygenerovaný token z FIO bankovnictví (Nastavení - API. Přidat nový token, práva Pouze sledování účtu, neomezená platnost)', 'woofio' ),
                       'default'       => ''

  		)
    );


  }

      public function woofio_options_init(){
            register_setting(
                'woofio_options_group',
                'woofio_options',
                'woofio_options_validate'
            );
        }

      public function woofio_no_token_notice(){
       $class = "error";
       $link = get_home_url() ."/wp-admin/admin.php?page=wc-settings&tab=integration";
       $message = "Pro automatické párování plateb s FIO bankou je potřeba zadat váš API token. Zadejte jej prosím ";
       $message .= "<a href=\"";
       $message .= $link;
       $message .= "\">zde.</a>";
       echo  "<div class=\"$class\"> <p>$message</p></div>";
      }

      public function woofio_display_notice(){
        $ssc_options = get_option( 'woocommerce_woofio_settings' );
        if  (empty($ssc_options['fio_token'])) {
          add_action( 'admin_notices', array( $this,'woofio_no_token_notice') );
        }

      }

      /**
	 * Validate the API key
	 * @see validate_settings_fields()
	 */
	public function validate_api_key_field( $key ) {
		// get the posted value
		$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
		// check if the API key is longer than 20 characters. Our imaginary API doesn't create keys that large so something must be wrong. Throw an error which will prevent the user from saving.
		if ( isset( $value ) &&
			 5 < strlen( $value ) ) {
			$this->errors[] = $key;
		}
		return $value;
	}

  /**
	 * Display errors by overriding the display_errors() method
	 * @see display_errors()
	 */
	public function display_errors( ) {
		// loop through each error and display it
		foreach ( $this->errors as $key => $value ) {
			?>
			<div class="error">
				<p><?php _e( 'Vypadá to, že jste špatně zadali FIO API klíč. Prosím zkontrolujte, zda jste vložili hodnotu správně.', 'woofio' ); ?></p>
			</div>
			<?php
		}
	}

      public function woofio_create_haystack(){
      	$options = get_option('woocommerce_woofio_settings');
      	if(!isset($options['fio_token']) || empty($options['fio_token'])){
      		return;
      	}
      	$pending_variables = $this->woofio_pending_variables();
      	if (empty($pending_variables)){
      		return;
      	}
      	$downloader = new FioApi\Downloader($options['fio_token']);
      	$transactionList = $downloader->downloadSince(new \DateTime('-1 week'));
        $allTransactions = $transactionList->getTransactions();
      	foreach ($allTransactions as $transaction) {
      		if ($transaction->getAmount()>0){
            for($i=0;$i<count($pending_variables);$i++) {
              if($transaction->getVariableSymbol()==$pending_variables[$i]['number']){
                $this->woofio_maybe_complete_payment($pending_variables[$i]['id'],$transaction->getAmount(),$transaction->getCurrency());
              }
            }

      		}
      	}
      }


      public function woofio_pending_variables(){
      	$query = array(
      	'status' => 'on-hold',
      	'limit'=>-1
      	);
      	$pending_orders = wc_get_orders( $query );

      	$found_variables = array();
        foreach ($pending_orders as $order){
          $foundit = array(
            'id' =>  $order->id,
            'number' => trim( str_replace( '#', '', $order->get_order_number() ) ),
            'total' => $order->get_total(),
            'currency' => $order->get_currency()
           );
           array_push($found_variables,$foundit);
        }
      	return $found_variables;
      }

      public function woofio_maybe_complete_payment($variable,$paid_amount,$currency){
      	$order = new WC_Order($variable);
        if (($order->get_total() == $paid_amount) and ($currency == $order->get_currency())){
          $order -> payment_complete();
					$order -> add_order_note('Objednávka úspěšně automaticky spárována s platbou na FIO banku');
      	}
      }

      /**
	 * Santize our settings
	 * @see process_admin_options()
	 */
	public function sanitize_settings( $settings ) {

		if ( isset( $settings ) &&
		     isset( $settings['fio_token'] ) ) {
			$settings['fio_token'] = $settings['fio_token'];
		}
		return $settings;
	}


  }
}

register_deactivation_hook( __FILE__, 'woofio_uninstall' );

function woofio_uninstall(){
  wp_clear_scheduled_hook('woofio_hourly');
}

$cleverstart_woofio = new Cleverstart_Woofio();
