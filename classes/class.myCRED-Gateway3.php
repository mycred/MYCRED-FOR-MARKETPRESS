<?php
if ( ! defined( 'MYCRED_MARKET_VERSION' ) ) exit;

/**
 * MarketPress Gateway 3.x
 * @since 1.1
 * @version 1.0
 */
if ( ! class_exists( 'MP_Gateway_myCRED_New' ) && class_exists( 'MP_Gateway_API' ) ) :
	class MP_Gateway_myCRED_New extends MP_Gateway_API {

		var $plugin_name           = MYCRED_SLUG;
		var $admin_name            = MYCRED_DEFAULT_LABEL;
		var $public_name           = MYCRED_DEFAULT_LABEL;
		var $mycred_type           = MYCRED_DEFAULT_TYPE_KEY;
		var $method_img_url        = '';
		var $method_button_img_url = '';
		var $force_ssl             = false;
		var $ipn_url;
		var $skip_form             = false;

		/**
		 * Custom Constructor
		 * @since 1.1
		 * @version 1.0
		 */
		function on_creation() {

			$this->admin_name            = MYCRED_DEFAULT_LABEL;
			$this->public_name           = $this->get_setting( 'name', MYCRED_DEFAULT_LABEL );
			$this->method_img_url        = $this->get_setting( 'logo', plugins_url( 'assets/images/mycred-token-icon.png', MYCRED_MARKETPRESS ) );
			$this->method_button_img_url = $this->public_name;

			$this->mycred_type           = $this->get_setting( 'type', MYCRED_DEFAULT_TYPE_KEY );
			$this->mycred                = mycred( $this->mycred_type );

		}
	
		/**
		 * Use Exchange
		 * Checks to see if exchange is needed.
		 * @since 1.1
		 * @version 1.0
		 */
		function use_exchange() {

			return ( mp_get_setting( 'currency' ) != 'POINTS' ) ? true : false;

		}

		/**
		 * Get Points Cost
		 * Returns the carts total cost in points.
		 * @since 1.1
		 * @version 1.0
		 */
		function get_point_cost( $cart_total ) {

			$cost       = $cart_total;
			$exchange   = $this->get_setting( 'exchange', 1 );

			if ( $this->use_exchange() )
				$cost = $cart_total / $exchange;

			return apply_filters( 'mycred_marketpress_cart_cost', $cost, $exchange, $this );

		}

		/**
		 * Payment Form
		 * Used to show a user how much their order would cost in points, assuming the can use the selected point type.
		 * @since 1.1
		 * @version 1.0
		 */
		public function payment_form( $cart, $shipping_info ) {

			global $mp;
		
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $this->get_setting( 'visitors', __( 'Please login to use this payment option.', 'mycred_market' ) ) );
				$message = $this->mycred->template_tags_general( $message );

				return '<div id="mp-mycred-balance">' . $message . '</div>';

			}

			$user_id  = get_current_user_id();
			$balance  = $this->mycred->get_users_balance( $user_id, $this->mycred_type );
			$total    = $this->get_point_cost( $cart->total() );
		
			// Low balance
			if ( $balance < $total ) {

				$message      = $this->mycred->template_tags_user( $this->get_setting( 'lowfunds', __( 'Insufficient Funds', 'mycred_market' ), false, wp_get_current_user() ) );
				$instructions = '<div id="mp-mycred-balance">' . wpautop( wptexturize( $message ) ) . '</div>';
				$warn         = true;

			}
			else {

				$message      = '';
				$instructions = $this->mycred->template_tags_general( $this->get_setting( 'instructions', '' ) );
				$warn         = false;

			}
		
			// Return Cost
			return '
<div id="mp-mycred-balance">' . $instructions . '</div>
<div id="mp-mycred-cost">
	<table style="width:100%;">
		<tbody>
			<tr class="mycred-current-balance">
				<td class="info">' . __( 'Current Balance', 'mycred_market' ) . '</td>
				<td class="amount">' . $this->mycred->format_creds( $balance ) . '</td>
			</tr>
			<tr class="mycred-total-cost">
				<td class="info">' . __( 'Total Cost', 'mycred_market' ) . '</td>
				<td class="amount">' . $this->mycred->format_creds( $total ) . '</td>
			</tr>
			<tr class="mycred-balance-after-payment">
				<td class="info">' . __( 'Balance After Purchase', 'mycred_market' ) . '</td>
				<td class="amount' . ( $warn ? ' text-danger' : '' ) . '"' . ( $warn ? ' style="color: red;"' : '' ) . '>' . $this->mycred->format_creds( $balance - $total ) . '</td>
			</tr>
		</tbody>
	</table>
</div>';

		}

		/**
		 * Process Payment
		 * Will check a buyers eligibility to use this gateway, their account solvency and charge the payment
		 * if the user can afford it.
		 * @since 1.1
		 * @version 1.0
		 */
		function process_payment( $cart, $billing_info, $shipping_info ) {

			$user_id   = get_current_user_id();
			$timestamp = time();

			// This gateway requires buyer to be logged in
			if ( ! is_user_logged_in() ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_store_page_url( 'checkout', false ) ), $this->get_setting( 'visitors', __( 'Please login to use this payment option.', 'mycred_market' ) ) );
				mp_checkout()->add_error( $this->mycred->template_tags_general( $message ) );
				return;

			}

			// Make sure current user is not excluded from using myCRED
			if ( $this->mycred->exclude_user( $user_id ) ) {
				mp_checkout()->add_error( sprintf( __( 'Sorry, but you can not use this gateway as your account is excluded. Please <a href="%s">select a different payment method</a>.', 'mycred_market' ), mp_store_page_url( 'checkout', false ) ) );
				return;
			}

			// Get users balance
			$balance = $this->mycred->get_users_balance( $user_id, $this->mycred_type );
			$total   = $this->get_point_cost( $cart->total() );
		
			// Check solvency
			if ( $balance < $total ) {

				$message = str_replace( '%login_url_here%', wp_login_url( mp_checkout_step_url( 'checkout' ) ), $this->get_setting( 'lowfunds', __( 'Insufficient Funds', 'mycred_market' ) ) );
				mp_checkout()->add_error( $message . ' <a href="' . mp_store_page_url( 'checkout', false ) . '">' . __( 'Go Back', 'mycred_market' ) . '</a>' );
				return;

			}

			// Let others decline a store order
			$decline = apply_filters( 'mycred_decline_store_purchase', false, $cart, $this );
			if ( $decline !== false ) {

				mp_checkout()->add_error( $decline );
				return;

			}

			// Create payment info
			$payment_info                         = array();
			$payment_info['gateway_public_name']  = $this->public_name;
			$payment_info['gateway_private_name'] = $this->admin_name;
			$payment_info['status'][ $timestamp ] = __( 'Paid', 'mycred_market' );
			$payment_info['total']                = $cart->total();
			$payment_info['currency']             = mp_get_setting( 'currency' );
			$payment_info['method']               = $this->public_name;
			$payment_info['transaction_id']       = 'MMP' . $timestamp;

			// Generate and save new order
			$order    = new MP_Order();
			$order->save( array(
				'cart'         => $cart,
				'payment_info' => $payment_info,
				'paid'         => true
			) );
			$order_id = $order->ID;

			// Charge users account
			$this->mycred->add_creds(
				'marketpress_payment',
				$user_id,
				0 - $total,
				$this->get_setting( 'paymentlog', __( 'Payment for Order: #%order_id%', 'mycred_market' ) ),
				$order_id,
				array( 'ref_type' => 'post' ),
				$this->mycred_type
			);

			// Profit Sharing
			$this->process_profit_sharing( $cart, $order_id );

			wp_redirect( $order->tracking_url( false ) );
			exit;

		}

		/**
		 * Process Profit Sharing
		 * If used.
		 * @since 1.1
		 * @version 1.0
		 */
		function process_profit_sharing( $cart = NULL, $order_id = 0 ) {

			$payouts      = array();
			$profit_share = apply_filters( 'mycred_marketpress_profit_share', $this->get_setting( 'profitshare', 0 ), $cart, $this );

			// If profit share is enabled
			if ( $cart !== NULL && $profit_share > 0 ) {

				if ( $cart->is_global ) {

					$carts = $cart->get_all_items();
					foreach ( $carts as $blog_id => $items ) {
						if ( count( $items ) > 0 ) {

							foreach ( $items as $item ) {

								$price = $this->get_point_cost( $item->get_price( 'lowest' ) * $item->qty );
								$share = ( $profit_share / 100 ) * $price;

								$post  = get_post( $item->ID );
								if ( isset( $post->post_author ) )
									$payouts[ $post->post_author ] = array(
										'product_id' => $item->ID,
										'payout'     => $share
									);

							}

						}
					}

				}
				else {

					$items = $cart->get_items();
					if ( count( $items ) > 0 ) {

						foreach ( $items as $item => $qty ) {

							$product = new MP_Product( $item );
							// we will have to check this product exists or not
							if ( ! $product->exists() ) {
								continue;
							}

							$price = $this->get_point_cost( $product->get_price( 'lowest' ) * $qty );
							$share = ( $profit_share / 100 ) * $price;

							$post  = get_post( $item->ID );
							if ( isset( $post->post_author ) )
								$payouts[ $post->post_author ] = array(
									'product_id' => $item->ID,
									'payout'     => $share
								);

						}

					}

				}

				if ( ! empty( $payouts ) ) {

					$log_template = $this->get_setting( 'sharelog', __( 'Order #%order_id% payout', 'mycred_market' ) );

					foreach ( $payouts as $user_id => $payout ) {

						$data = array( 'ref_type' => 'post', 'postid' => $payout['product_id'] );

						// Make sure we only payout once for each order
						if ( ! $this->mycred->has_entry( 'marketpress_sale', $order_id, $user_id, $data, $this->mycred_type ) )
							$this->mycred->add_creds(
								'marketpress_sale',
								$user_id,
								$payout['payout'],
								$log_template,
								$order_id,
								$data,
								$this->mycred_type
							);

					}

				}

			}

		}

		/**
		 * Order Confirmation
		 * Not used
		 * @since 1.1
		 * @version 1.0
		 */
		function order_confirmation( $order ) { }

		/**
		 * Email Confirmation Message
		 * @since 1.1
		 * @version 1.0
		 */
		function order_confirmation_email( $msg, $order ) {

			if ( $email_text = $this->get_setting( 'email' ) ) {
				$msg = mp_filter_email( $order, $email_text );
			}

			return $msg;

		}

		/**
		 * Setup Gateway Settings
		 * @since 1.1
		 * @version 1.0
		 */
		public function init_settings_metabox() {

			$metabox = new WPMUDEV_Metabox( array(
				'id'          => $this->generate_metabox_id(),
				'page_slugs'  => array( 'store-settings-payments', 'store-settings_page_store-settings-payments' ),
				'title'       => sprintf( __( '%s Settings', 'mycred_market' ), $this->admin_name ),
				'option_name' => 'mp_settings',
				'desc'        => __( 'Let users pay using their point balance.', 'mycred_market' ),
				'conditional' => array(
					'name'        => 'gateways[allowed][' . $this->plugin_name . ']',
					'value'       => 1,
					'action'      => 'show',
				),
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'name' ),
				'default_value' => $this->public_name,
				'label'         => array( 'text' => __( 'Method Name', 'mycred_market' ) ),
				'desc'          => __( 'Enter a public name for this payment method that is displayed to users - No HTML', 'mycred_market' ),
				'save_callback' => array( 'strip_tags' ),
			) );

			$metabox->add_field( 'select', array(
				'name'          => $this->get_field_name( 'type' ),
				'label'         => array( 'text' => __( 'Point Type', 'mycred_market' ) ),
				'options'       => mycred_get_types(),
				'desc'          => __( 'Select the point type you wish to accept as payment in your store. If this store only accepts points as payment, make sure you select the same point type you set as your store currency!', 'mycred_market' )
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'exchange' ),
				'default_value' => 1,
				'label'         => array( 'text' => __( 'Exchange Rate', 'mycred_market' ) ),
				'desc'          => __( 'The Exchange rate between your stores selected currency and the selected point type. If this is a points store, make sure this is set to 1. Example: 100 points = 1 USD the exchange rate would be: 0.01', 'mycred_market' ),
				'save_callback' => array( 'strip_tags' ),
			) );

			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'visitors' ),
				'label'	 => array( 'text' => __( 'Visitors', 'mycred_market' ) ),
				'desc'	 => __( 'Message to show when the gateway is viewed by someone who is not logged in on your website. Only logged in users can use this gateway!', 'mycred_market' ),
			) );
			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'excluded' ),
				'label'	 => array( 'text' => __( 'Excluded', 'mycred_market' ) ),
				'desc'	 => __( 'Message to show when the gateway is viewed by someone who has been excluded from using the selected point type.', 'mycred_market' ),
			) );
			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'lowfunds' ),
				'label'	 => array( 'text' => __( 'Insufficient Funds', 'mycred_market' ) ),
				'desc'	 => __( 'Message to show when the gateway is viewed by someone who can not afford to pay using points.', 'mycred_market' ),
			) );

			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'paymentlog' ),
				'default_value' => __( 'Payment for Order: #%order_id%', 'mycred_market' ),
				'label'         => array( 'text' => __( 'Payment Log Template', 'mycred_market' ) ),
				'desc'          => __( 'The log entry template used for each point payment. This template is what the buyer will see in their points history. CAN NOT BE EMPTY!', 'mycred_market' )
			) );

			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'profitshare' ),
				'default_value' => 0,
				'label'         => array( 'text' => __( 'Profit Sharing', 'mycred_market' ) ),
				'desc'          => __( 'Option to share a percentage of the product price with the product owner each time a product is purchased using points. Use zero to disable.', 'mycred_market' )
			) );
			$metabox->add_field( 'text', array(
				'name'          => $this->get_field_name( 'sharelog' ),
				'default_value' => 1,
				'label'         => array( 'text' => __( 'Profit Share Log Template', 'mycred_market' ) ),
				'desc'          => __( 'The log entry template used for each profit share payout. This template is what the seller will see in their points history. Ignored if profit sharing is disabled.', 'mycred_market' )
			) );

			$metabox->add_field( 'wysiwyg', array(
				'name'	 => $this->get_field_name( 'instruction' ),
				'label'	 => array( 'text' => __( 'User Instructions', 'mycred_market' ) ),
				'desc'	 => __( 'Optional instructions to show users when selecting to pay using points. Their current balance, the total cost of the order in points along with their future balance if they choose to pay is also included.', 'mycred_market' ),
			) );
			$metabox->add_field( 'textarea', array(
				'name'			 => $this->get_field_name( 'email' ),
				'label'			 => array( 'text' => __( 'Order Confirmation Email', 'mycred_market' ) ),
				'desc'			 => __( 'This is the email text to send to those who have paid using points. It overrides the default order checkout email. These codes will be replaced with order details: CUSTOMERNAME, ORDERID, ORDERINFO, SHIPPINGINFO, PAYMENTINFO, TOTAL, TRACKINGURL. No HTML allowed.', 'mycred_market' ),
				'custom'		 => array( 'rows' => 10 ),
				'save_callback'	 => array( 'strip_tags' ),
			) );

		}

		/**
		 * IPN Return
		 * not used
		 * @since 1.1
		 * @version 1.0
		 */
		function process_ipn_return() { }

	}
endif;
