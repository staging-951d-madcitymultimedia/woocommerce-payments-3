<?php
/**
 * Class WC_Payment_Gateway_WCPay_Test
 *
 * @package WooCommerce\Payments\Tests
 */

use PHPUnit\Framework\MockObject\MockObject;
use WCPay\Core\Server\Request\Cancel_Intention;
use WCPay\Core\Server\Request\Capture_Intention;
use WCPay\Core\Server\Request\Create_And_Confirm_Intention;
use WCPay\Core\Server\Request\Create_And_Confirm_Setup_Intention;
use WCPay\Core\Server\Request\Get_Charge;
use WCPay\Core\Server\Request\Get_Intention;
use WCPay\Core\Server\Request\Update_Intention;
use WCPay\Core\Server\Response;
use WCPay\Constants\Order_Status;
use WCPay\Constants\Payment_Type;
use WCPay\Constants\Payment_Intent_Status;
use WCPay\Exceptions\Amount_Too_Small_Exception;
use WCPay\Exceptions\API_Exception;
use WCPay\Fraud_Prevention\Fraud_Prevention_Service;
use WCPay\Payment_Information;
use WCPay\WooPay\WooPay_Utilities;
use WCPay\Session_Rate_Limiter;
use WCPay\WC_Payments_Checkout;

// Need to use WC_Mock_Data_Store.
require_once dirname( __FILE__ ) . '/helpers/class-wc-mock-wc-data-store.php';

/**
 * WC_Payment_Gateway_WCPay unit tests.
 */
class WC_Payment_Gateway_WCPay_Test extends WCPAY_UnitTestCase {

	const NO_REQUIREMENTS      = false;
	const PENDING_REQUIREMENTS = true;

	/**
	 * System under test.
	 *
	 * @var WC_Payment_Gateway_WCPay
	 */
	private $wcpay_gateway;

	/**
	 * Mock WC_Payments_API_Client.
	 *
	 * @var WC_Payments_API_Client|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_api_client;

	/**
	 * Mock WC_Payments_Customer_Service.
	 *
	 * @var WC_Payments_Customer_Service|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_customer_service;

	/**
	 * Mock WC_Payments_Token_Service.
	 *
	 * @var WC_Payments_Token_Service|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_token_service;

	/**
	 * Mock WC_Payments_Action_Scheduler_Service.
	 *
	 * @var WC_Payments_Action_Scheduler_Service|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_action_scheduler_service;

	/**
	 * WC_Payments_Account instance.
	 *
	 * @var WC_Payments_Account|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_wcpay_account;

	/**
	 * Session_Rate_Limiter instance.
	 *
	 * @var Session_Rate_Limiter|PHPUnit_Framework_MockObject_MockObject
	 */
	private $mock_rate_limiter;

	/**
	 * WC_Payments_Order_Service instance.
	 *
	 * @var WC_Payments_Order_Service
	 */
	private $order_service;

	/**
	 * WooPay_Utilities instance.
	 *
	 * @var WooPay_Utilities
	 */
	private $woopay_utilities;

	/**
	 * WC_Payments_Checkout instance.
	 * @var WC_Payments_Checkout
	 */
	private $payments_checkout;

	/**
	 * @var string
	 */
	private $mock_charge_id = 'ch_mock';

	/**
	 * @var integer
	 */
	private $mock_charge_created = 1653076178;

	/**
	 * Pre-test setup
	 */
	public function set_up() {
		parent::set_up();

		$this->mock_api_client = $this
			->getMockBuilder( 'WC_Payments_API_Client' )
			->disableOriginalConstructor()
			->setMethods(
				[
					'get_account_data',
					'is_server_connected',
					'get_blog_id',
					'create_intention',
					'create_and_confirm_intention',
					'create_and_confirm_setup_intent',
					'get_setup_intent',
					'get_payment_method',
					'get_timeline',
				]
			)
			->getMock();
		$this->mock_api_client->expects( $this->any() )->method( 'is_server_connected' )->willReturn( true );
		$this->mock_api_client->expects( $this->any() )->method( 'get_blog_id' )->willReturn( 1234567 );

		$this->mock_wcpay_account = $this->createMock( WC_Payments_Account::class );

		// Mock the main class's cache service.
		$this->_cache     = WC_Payments::get_database_cache();
		$this->mock_cache = $this->createMock( WCPay\Database_Cache::class );
		WC_Payments::set_database_cache( $this->mock_cache );

		$this->mock_customer_service = $this->createMock( WC_Payments_Customer_Service::class );

		$this->mock_token_service = $this->createMock( WC_Payments_Token_Service::class );

		$this->mock_action_scheduler_service = $this->createMock( WC_Payments_Action_Scheduler_Service::class );

		$this->mock_rate_limiter = $this->createMock( Session_Rate_Limiter::class );

		$this->order_service = new WC_Payments_Order_Service( $this->mock_api_client );

		$this->wcpay_gateway = new WC_Payment_Gateway_WCPay(
			$this->mock_api_client,
			$this->mock_wcpay_account,
			$this->mock_customer_service,
			$this->mock_token_service,
			$this->mock_action_scheduler_service,
			$this->mock_rate_limiter,
			$this->order_service
		);

		$this->woopay_utilities = new WooPay_Utilities();

		$this->payments_checkout = new WC_Payments_Checkout(
			$this->wcpay_gateway,
			$this->woopay_utilities,
			$this->mock_wcpay_account,
			$this->mock_customer_service
		);
	}

	/**
	 * Post-test teardown
	 */
	public function tear_down() {
		parent::tear_down();

		delete_option( 'woocommerce_woocommerce_payments_settings' );

		// Restore the cache service in the main class.
		WC_Payments::set_database_cache( $this->_cache );

		// Fall back to an US store.
		update_option( 'woocommerce_store_postcode', '94110' );
		$this->wcpay_gateway->update_option( 'saved_cards', 'yes' );
	}

	public function test_attach_exchange_info_to_order_with_no_conversion() {
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->set_currency( 'USD' );
		$order->save();

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( 'usd' );

		$this->wcpay_gateway->attach_exchange_info_to_order( $order, $charge_id );

		// The meta key should not be set.
		$this->assertEquals( '', $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate' ) );
	}

	public function test_attach_exchange_info_to_order_with_different_account_currency_no_conversion() {
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->set_currency( 'USD' );
		$order->save();

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( 'jpy' );

		$this->wcpay_gateway->attach_exchange_info_to_order( $order, $charge_id );

		// The meta key should not be set.
		$this->assertEquals( '', $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate' ) );
	}

	public function test_attach_exchange_info_to_order_with_zero_decimal_order_currency() {
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->set_currency( 'JPY' );
		$order->save();

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( 'usd' );

		$charge_request = $this->mock_wcpay_request( Get_Charge::class, 1, 'ch_mock' );

		$charge_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn(
				[
					'id'                  => 'ch_123456',
					'amount'              => 4500,
					'balance_transaction' => [
						'amount'        => 4450,
						'fee'           => 50,
						'currency'      => 'USD',
						'exchange_rate' => 0.9414,
					],
				]
			);

		$this->wcpay_gateway->attach_exchange_info_to_order( $order, $charge_id );
		$this->assertEquals( 0.009414, $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate' ) );
	}

	public function test_attach_exchange_info_to_order_with_different_order_currency() {
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->set_currency( 'EUR' );
		$order->save();

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_default_currency' )
			->willReturn( 'usd' );

		$charge_request = $this->mock_wcpay_request( Get_Charge::class, 1, 'ch_mock' );
		$charge_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn(
				[
					'id'                  => 'ch_123456',
					'amount'              => 4500,
					'balance_transaction' => [
						'amount'        => 4450,
						'fee'           => 50,
						'currency'      => 'USD',
						'exchange_rate' => 0.853,
					],
				]
			);

		$this->wcpay_gateway->attach_exchange_info_to_order( $order, $charge_id );
		$this->assertEquals( 0.853, $order->get_meta( '_wcpay_multi_currency_stripe_exchange_rate' ) );
	}

	public function test_payment_fields_outputs_fields() {
		$this->wcpay_gateway->payment_fields();

		$this->expectOutputRegex( '/<div id="wcpay-card-element"><\/div>/' );
	}

	public function test_save_card_checkbox_not_displayed_when_saved_cards_disabled() {
		$this->wcpay_gateway->update_option( 'saved_cards', 'no' );

		$this->refresh_payments_checkout();

		// Use a callback to get and test the output (also suppresses the output buffering being printed to the CLI).
		$this->setOutputCallback(
			function ( $output ) {
				$result = preg_match_all( '/.*<input.*id="wc-woocommerce_payments-new-payment-method".*\/>.*/', $output );

				$this->assertSame( 0, $result );
			}
		);

		$this->wcpay_gateway->payment_fields();
	}

	public function test_save_card_checkbox_not_displayed_for_non_logged_in_users() {
		$this->wcpay_gateway->update_option( 'saved_cards', 'yes' );
		wp_set_current_user( 0 );

		$this->refresh_payments_checkout();

		// Use a callback to get and test the output (also suppresses the output buffering being printed to the CLI).
		$this->setOutputCallback(
			function ( $output ) {
				$result = preg_match_all( '/.*<input.*id="wc-woocommerce_payments-new-payment-method".*\/>.*/', $output );

				$this->assertSame( 0, $result );
			}
		);

		$this->wcpay_gateway->payment_fields();
	}

	public function test_save_card_checkbox_displayed_for_logged_in_users() {
		$this->wcpay_gateway->update_option( 'saved_cards', 'yes' );
		wp_set_current_user( 1 );

		$this->refresh_payments_checkout();

		// Use a callback to get and test the output (also suppresses the output buffering being printed to the CLI).
		$this->setOutputCallback(
			function ( $output ) {
				$result = preg_match_all( '/.*<input.*id="wc-woocommerce_payments-new-payment-method".*\/>.*/', $output );

				$this->assertSame( 1, $result );
			}
		);

		$this->wcpay_gateway->payment_fields();
	}

	public function test_fraud_prevention_token_added_when_enabled() {
		$token_value                   = 'test-token';
		$fraud_prevention_service_mock = $this->get_fraud_prevention_service_mock();
		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'is_enabled' )
			->willReturn( true );
		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'get_token' )
			->willReturn( $token_value );

		$this->refresh_payments_checkout();

		// Use a callback to get and test the output (also suppresses the output buffering being printed to the CLI).
		$this->setOutputCallback(
			function ( $output ) use ( $token_value ) {
				$result = preg_match_all( '/.*<input.*type="hidden".*name="wcpay-fraud-prevention-token".*value="' . $token_value . '".*\/>.*/', $output );

				$this->assertSame( 0, $result );
			}
		);

		$this->wcpay_gateway->payment_fields();
	}

	protected function create_mock_item( $name, $quantity, $subtotal, $total_tax, $product_id ) {
		// Setup the item.
		$mock_item = $this
			->getMockBuilder( WC_Order_Item_Product::class )
			->disableOriginalConstructor()
			->setMethods(
				[
					'get_name',
					'get_quantity',
					'get_subtotal',
					'get_total_tax',
					'get_total',
					'get_variation_id',
					'get_product_id',
				]
			)
			->getMock();

		$mock_item
			->method( 'get_name' )
			->will( $this->returnValue( $name ) );

		$mock_item
			->method( 'get_quantity' )
			->will( $this->returnValue( $quantity ) );

		$mock_item
			->method( 'get_total' )
			->will( $this->returnValue( $subtotal ) );

		$mock_item
			->method( 'get_subtotal' )
			->will( $this->returnValue( $subtotal ) );

		$mock_item
			->method( 'get_total_tax' )
			->will( $this->returnValue( $total_tax ) );

		$mock_item
			->method( 'get_variation_id' )
			->will( $this->returnValue( false ) );

		$mock_item
			->method( 'get_product_id' )
			->will( $this->returnValue( $product_id ) );

		return $mock_item;
	}

	protected function mock_level_3_order(
			$shipping_postcode,
			$with_fee = false,
			$with_negative_price_product = false,
			$quantity = 1,
			$basket_size = 1,
			$product_id = 30
	) {
		$mock_items[] = $this->create_mock_item( 'Beanie with Logo', $quantity, 18, 2.7, $product_id );

		if ( $with_fee ) {
			// Setup the fee.
			$mock_fee = $this
				->getMockBuilder( WC_Order_Item_Fee::class )
				->disableOriginalConstructor()
				->setMethods( [ 'get_name', 'get_quantity', 'get_total_tax', 'get_total' ] )
				->getMock();

			$mock_fee
				->method( 'get_name' )
				->will( $this->returnValue( 'fee' ) );

			$mock_fee
				->method( 'get_quantity' )
				->will( $this->returnValue( 1 ) );

			$mock_fee
				->method( 'get_total' )
				->will( $this->returnValue( 10 ) );

			$mock_fee
				->method( 'get_total_tax' )
				->will( $this->returnValue( 1.5 ) );

			$mock_items[] = $mock_fee;
		}

		if ( $with_negative_price_product ) {
			$mock_items[] = $this->create_mock_item( 'Negative Product Price', $quantity, -18.99, 2.7, 42 );
		}

		if ( $basket_size > 1 ) {
			// Keep the formely created item/fee and add duplicated items to the basket.
			$mock_items = array_merge( $mock_items, array_fill( 0, $basket_size - 1, $mock_items[0] ) );
		}

		// Setup the order.
		$mock_order = $this
			->getMockBuilder( WC_Order::class )
			->disableOriginalConstructor()
			->setMethods(
				[
					'get_id',
					'get_items',
					'get_currency',
					'get_shipping_total',
					'get_shipping_tax',
					'get_shipping_postcode',
				]
			)
			->getMock();

		$mock_order
			->method( 'get_id' )
			->will( $this->returnValue( 210 ) );

		$mock_order
			->method( 'get_items' )
			->will( $this->returnValue( $mock_items ) );

		$mock_order
			->method( 'get_currency' )
			->will( $this->returnValue( 'USD' ) );

		$mock_order
			->method( 'get_shipping_total' )
			->will( $this->returnValue( 30 ) );

		$mock_order
			->method( 'get_shipping_tax' )
			->will( $this->returnValue( 8 ) );

		$mock_order
			->method( 'get_shipping_postcode' )
			->will( $this->returnValue( $shipping_postcode ) );

		return $mock_order;
	}

	public function test_full_level3_data() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012' );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_full_level3_data_with_product_id_longer_than_12_characters() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 123456789123,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', false, false, 1, 1, 123456789123456 );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_full_level3_data_with_fee() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
				(object) [
					'product_code'        => 'fee',
					'product_description' => 'fee',
					'unit_cost'           => 1000,
					'quantity'            => 1,
					'tax_amount'          => 150,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', true );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_full_level3_data_with_negative_price_product() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
				(object) [
					'product_code'        => 42,
					'product_description' => 'Negative Product Price',
					'unit_cost'           => 0,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 1899,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', false, true, 1, 1 );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_us_store_level_3_data() {
		// Use a non-us customer postcode to ensure it's not included in the level3 data.
		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '9000' );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertArrayNotHasKey( 'shipping_address_zip', $level_3_data );
	}

	public function test_us_customer_level_3_data() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
		];

		// Use a non-US postcode.
		update_option( 'woocommerce_store_postcode', '9000' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012' );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_non_us_customer_level_3_data() {
		$expected_data = [];

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'CA' );
		$mock_order   = $this->mock_level_3_order( 'K0A' );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_full_level3_data_with_float_quantity() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 450,
					'quantity'            => 4,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', false, false, 3.7 );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_full_level3_data_with_float_quantity_zero() {
		$expected_data = [
			'merchant_reference'   => '210',
			'customer_reference'   => '210',
			'shipping_amount'      => 3800,
			'line_items'           => [
				(object) [
					'product_code'        => 30,
					'product_description' => 'Beanie with Logo',
					'unit_cost'           => 1800,
					'quantity'            => 1,
					'tax_amount'          => 270,
					'discount_amount'     => 0,
				],
			],
			'shipping_address_zip' => '98012',
			'shipping_from_zip'    => '94110',
		];

		update_option( 'woocommerce_store_postcode', '94110' );

		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', false, false, 0.4 );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertEquals( $expected_data, $level_3_data );
	}

	public function test_level3_data_bundle() {
		$items = (array) [
			(object) [
				'product_code'        => 'abcd',
				'product_description' => 'product description',
				'unit_cost'           => 1000,
				'quantity'            => 4,
				'tax_amount'          => 200,
				'discount_amount'     => 500,
			],
			(object) [
				'product_code'        => 'abcd',
				'product_description' => 'product description',
				'unit_cost'           => 5000,
				'quantity'            => 3,
				'tax_amount'          => 1000,
				'discount_amount'     => 200,
			],
		];

		$bundle_data = $this->wcpay_gateway->bundle_level3_data_from_items( $items );

		$this->assertSame( $bundle_data->product_description, '2 more items' );

		// total_unit_cost = sum( unit_cost * quantity ).
		$this->assertSame( $bundle_data->unit_cost, 19000 );

		// quantity of the bundle = 1.
		$this->assertSame( $bundle_data->quantity, 1 );

		// total_tax_amount = sum( tax_amount ).
		$this->assertSame( $bundle_data->tax_amount, 1200 );

		// total_discount_amount = sum( discount_amount ).
		$this->assertSame( $bundle_data->discount_amount, 700 );
	}

	public function test_level3_data_bundle_for_orders_with_more_than_200_items() {
		$this->mock_wcpay_account->method( 'get_account_country' )->willReturn( 'US' );
		$mock_order   = $this->mock_level_3_order( '98012', true, false, 1, 500 );
		$level_3_data = $this->wcpay_gateway->get_level3_data_from_order( $mock_order );

		$this->assertSame( count( $level_3_data['line_items'] ), 200 );

		$bundled_data = end( $level_3_data['line_items'] );

		$this->assertSame( $bundled_data->product_description, '301 more items' );
	}

	public function test_capture_charge_success() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::REQUIRES_CAPTURE ] );

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' )
			->with(
				$this->callback(
					function( $argument ) {
						return is_array( $argument ) && ! empty( $argument );
					}
				)
			);
		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention() );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$notes             = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		);
		$latest_wcpay_note = $notes[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => Payment_Intent_Status::SUCCEEDED,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 200,
			],
			$result
		);
		$this->assertStringContainsString( 'successfully captured', $latest_wcpay_note->content );
		$this->assertStringContainsString( wc_price( $order->get_total() ), $latest_wcpay_note->content );
		$this->assertEquals( Payment_Intent_Status::SUCCEEDED, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::PROCESSING, $order->get_status() );
	}

	public function test_capture_charge_success_non_usd() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention(
			[
				'status'   => Payment_Intent_Status::REQUIRES_CAPTURE,
				'currency' => 'eur',
			]
		);

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );

		$update_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention( [ 'currency' => 'eur' ] ) );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$notes             = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		);
		$latest_wcpay_note = $notes[0];

		$note_currency = WC_Payments_Explicit_Price_Formatter::get_explicit_price( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ), $order );

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => Payment_Intent_Status::SUCCEEDED,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 200,
			],
			$result
		);
		$this->assertStringContainsString( 'successfully captured', $latest_wcpay_note->content );
		$this->assertStringContainsString( $note_currency, $latest_wcpay_note->content );
		$this->assertEquals( Payment_Intent_Status::SUCCEEDED, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::PROCESSING, $order->get_status() );
	}

	public function test_capture_charge_failure() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::REQUIRES_CAPTURE ] );

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );
		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => Payment_Intent_Status::REQUIRES_CAPTURE,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 502,
			],
			$result
		);
		$this->assertStringContainsString( 'failed', $note->content );
		$this->assertStringContainsString( wc_price( $order->get_total() ), $note->content );
		$this->assertEquals( Payment_Intent_Status::REQUIRES_CAPTURE, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::ON_HOLD, $order->get_status() );
	}

	public function test_capture_charge_failure_non_usd() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );
		$order->set_currency( 'EUR' );

		$mock_intent = WC_Helper_Intention::create_intention(
			[
				'status'   => Payment_Intent_Status::REQUIRES_CAPTURE,
				'currency' => 'eur',
			]
		);

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );
		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		$note_currency = WC_Payments_Explicit_Price_Formatter::get_explicit_price( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ), $order );

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => Payment_Intent_Status::REQUIRES_CAPTURE,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 502,
			],
			$result
		);
		$this->assertStringContainsString( 'failed', $note->content );
		$this->assertStringContainsString( $note_currency, $note->content );
		$this->assertEquals( Payment_Intent_Status::REQUIRES_CAPTURE, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::ON_HOLD, $order->get_status() );
	}

	public function test_capture_charge_api_failure() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::REQUIRES_CAPTURE ] );

		$request = $this->mock_wcpay_request( Get_Intention::class, 2, $intent_id );

		$request->expects( $this->exactly( 2 ) )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );
		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'test exception', 'server_error', 500 ) ) );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => 'failed',
				'id'        => $intent_id,
				'message'   => 'test exception',
				'http_code' => 500,
			],
			$result
		);
		$this->assertStringContainsString( 'failed', $note->content );
		$this->assertStringContainsString( 'test exception', $note->content );
		$this->assertStringContainsString( wc_price( $order->get_total() ), $note->content );
		$this->assertEquals( Payment_Intent_Status::REQUIRES_CAPTURE, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::ON_HOLD, $order->get_status() );
	}

	public function test_capture_charge_api_failure_non_usd() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );
		WC_Payments_Utils::set_order_intent_currency( $order, 'EUR' );

		$mock_intent = WC_Helper_Intention::create_intention(
			[
				'status'   => Payment_Intent_Status::REQUIRES_CAPTURE,
				'currency' => 'jpy',
			]
		);

		$request = $this->mock_wcpay_request( Get_Intention::class, 2, $intent_id );

		$request->expects( $this->exactly( 2 ) )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );

		$update_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'test exception', 'server_error', 500 ) ) );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		$note_currency = WC_Payments_Explicit_Price_Formatter::get_explicit_price( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ), $order );

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => 'failed',
				'id'        => $intent_id,
				'message'   => 'test exception',
				'http_code' => 500,
			],
			$result
		);
		$this->assertStringContainsString( 'failed', $note->content );
		$this->assertStringContainsString( 'test exception', $note->content );
		$this->assertStringContainsString( $note_currency, $note->content );
		$this->assertEquals( Payment_Intent_Status::REQUIRES_CAPTURE, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::ON_HOLD, $order->get_status() );
	}

	public function test_capture_charge_expired() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::CANCELED ] );

		$request = $this->mock_wcpay_request( Get_Intention::class, 2, $intent_id );

		$request->expects( $this->exactly( 2 ) )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );
		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'test exception', 'server_error', 500 ) ) );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => 'failed',
				'id'        => $intent_id,
				'message'   => 'test exception',
				'http_code' => 500,
			],
			$result
		);
		$this->assertStringContainsString( 'expired', $note->content );
		$this->assertEquals( Payment_Intent_Status::CANCELED, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::CANCELLED, $order->get_status() );
	}

	public function test_capture_charge_metadata() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$charge = $this->create_charge_object();

		$mock_intent = WC_Helper_Intention::create_intention(
			[
				'status'   => Payment_Intent_Status::REQUIRES_CAPTURE,
				'metadata' => [
					'customer_name' => 'Test',
				],
			]
		);

		$merged_metadata = [
			'customer_name'  => 'Test',
			'customer_email' => $order->get_billing_email(),
			'site_url'       => esc_url( get_site_url() ),
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'order_key'      => $order->get_order_key(),
			'payment_type'   => Payment_Type::SINGLE(),
		];

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' )
			->with( $merged_metadata );

		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention() );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_account_country' )
			->willReturn( 'US' );

		$result = $this->wcpay_gateway->capture_charge( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertSame(
			[
				'status'    => Payment_Intent_Status::SUCCEEDED,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 200,
			],
			$result
		);
		$this->assertStringContainsString( 'successfully captured', $note->content );
		$this->assertStringContainsString( wc_price( $order->get_total() ), $note->content );
		$this->assertSame( $order->get_meta( '_intention_status', true ), Payment_Intent_Status::SUCCEEDED );
		$this->assertSame( $order->get_status(), Order_Status::PROCESSING );
	}

	public function test_capture_charge_without_level3() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$mock_intent = WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::REQUIRES_CAPTURE ] );

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( $mock_intent );

		$update_intent_request = $this->mock_wcpay_request( Update_Intention::class, 1, $intent_id );
		$update_intent_request->expects( $this->once() )
			->method( 'set_metadata' );

		$capture_intent_request = $this->mock_wcpay_request( Capture_Intention::class, 1, $intent_id );
		$capture_intent_request->expects( $this->once() )
			->method( 'set_amount_to_capture' )
			->with( $mock_intent->get_amount() );

		$capture_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention() );

		$this->mock_wcpay_account
			->expects( $this->never() )
			->method( 'get_account_country' ); // stand-in for get_level3_data_from_order.

		$result = $this->wcpay_gateway->capture_charge( $order, false );

		$notes             = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		);
		$latest_wcpay_note = $notes[0];

		// Assert the returned data contains fields required by the REST endpoint.
		$this->assertEquals(
			[
				'status'    => Payment_Intent_Status::SUCCEEDED,
				'id'        => $intent_id,
				'message'   => null,
				'http_code' => 200,
			],
			$result
		);
		$this->assertStringContainsString( 'successfully captured', $latest_wcpay_note->content );
		$this->assertStringContainsString( wc_price( $order->get_total() ), $latest_wcpay_note->content );
		$this->assertEquals( Payment_Intent_Status::SUCCEEDED, $order->get_meta( '_intention_status', true ) );
		$this->assertEquals( Order_Status::PROCESSING, $order->get_status() );
	}

	public function test_cancel_authorization_handles_api_exception_when_canceling() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$cancel_intent_request = $this->mock_wcpay_request( Cancel_Intention::class, 1, $intent_id );
		$cancel_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'test exception', 'test', 123 ) ) );

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );
		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention( [ 'status' => Payment_Intent_Status::CANCELED ] ) );

		$this->wcpay_gateway->cancel_authorization( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		$this->assertStringContainsString( 'cancelled', $note->content );
		$this->assertEquals( Order_Status::CANCELLED, $order->get_status() );
	}

	public function test_cancel_authorization_handles_all_api_exceptions() {
		$intent_id = 'pi_mock';
		$charge_id = 'ch_mock';

		$order = WC_Helper_Order::create_order();
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intention_status', Payment_Intent_Status::REQUIRES_CAPTURE );
		$order->update_status( Order_Status::ON_HOLD );

		$cancel_intent_request = $this->mock_wcpay_request( Cancel_Intention::class, 1, $intent_id );
		$cancel_intent_request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'test exception', 'test', 123 ) ) );

		$request = $this->mock_wcpay_request( Get_Intention::class, 1, $intent_id );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new API_Exception( 'ignore this', 'test', 123 ) ) );

		$this->wcpay_gateway->cancel_authorization( $order );

		$note = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		)[0];

		$this->assertStringContainsString( 'failed', $note->content );
		$this->assertStringContainsString( 'test exception', $note->content );
		$this->assertEquals( Order_Status::ON_HOLD, $order->get_status() );
	}

	public function test_add_payment_method_no_method() {
		$result = $this->wcpay_gateway->add_payment_method();
		$this->assertEquals( 'error', $result['result'] );
	}

	public function test_create_and_confirm_setup_intent_existing_customer() {
		$_POST = [ 'wcpay-payment-method' => 'pm_mock' ];

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( 'cus_12345' ) );

		$this->mock_customer_service
			->expects( $this->never() )
			->method( 'create_customer_for_user' );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Setup_Intention::class );

		$request->expects( $this->once() )
			->method( 'set_customer' )
			->with( 'cus_12345' );

		$request->expects( $this->once() )
			->method( 'set_payment_method' )
			->with( 'pm_mock' );

		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( new Response( [ 'id' => 'pm_mock' ] ) );

		$result = $this->wcpay_gateway->create_and_confirm_setup_intent();

		$this->assertEquals( 'pm_mock', $result['id'] );
	}

	public function test_create_and_confirm_setup_intent_no_customer() {
		$_POST = [ 'wcpay-payment-method' => 'pm_mock' ];

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( null ) );

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'create_customer_for_user' )
			->will( $this->returnValue( 'cus_12345' ) );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Setup_Intention::class );
		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( new Response( [ 'id' => 'pm_mock' ] ) );

		$result = $this->wcpay_gateway->create_and_confirm_setup_intent();

		$this->assertEquals( 'pm_mock', $result['id'] );
	}

	public function test_add_payment_method_no_intent() {
		$result = $this->wcpay_gateway->add_payment_method();
		$this->assertEquals( 'error', $result['result'] );
	}

	public function test_add_payment_method_success() {
		$_POST = [ 'wcpay-setup-intent' => 'sti_mock' ];

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( 'cus_12345' ) );

		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_setup_intent' )
			->with( 'sti_mock' )
			->willReturn(
				[
					'status'         => Payment_Intent_Status::SUCCEEDED,
					'payment_method' => 'pm_mock',
				]
			);

		$this->mock_token_service
			->expects( $this->once() )
			->method( 'add_payment_method_to_user' )
			->with( 'pm_mock', wp_get_current_user() );

		$result = $this->wcpay_gateway->add_payment_method();

		$this->assertEquals( 'success', $result['result'] );
	}

	public function test_add_payment_method_no_customer() {
		$_POST = [ 'wcpay-setup-intent' => 'sti_mock' ];

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( null ) );

		$this->mock_api_client
			->expects( $this->never() )
			->method( 'get_setup_intent' );

		$this->mock_token_service
			->expects( $this->never() )
			->method( 'add_payment_method_to_user' );

		$result = $this->wcpay_gateway->add_payment_method();

		$this->assertEquals( 'error', $result['result'] );
	}

	public function test_add_payment_method_canceled_intent() {
		$_POST = [ 'wcpay-setup-intent' => 'sti_mock' ];

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( 'cus_12345' ) );

		$this->mock_api_client
			->expects( $this->once() )
			->method( 'get_setup_intent' )
			->with( 'sti_mock' )
			->willReturn( [ 'status' => Payment_Intent_Status::CANCELED ] );

		$this->mock_token_service
			->expects( $this->never() )
			->method( 'add_payment_method_to_user' );

		$result = $this->wcpay_gateway->add_payment_method();

		$this->assertEquals( 'error', $result['result'] );
		wc_clear_notices();
	}

	public function test_schedule_order_tracking_with_wrong_payment_gateway() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'square' );

		// If the payment gateway isn't WC Pay, this function should never get called.
		$this->mock_action_scheduler_service
			->expects( $this->never() )
			->method( 'schedule_job' );

		$this->wcpay_gateway->schedule_order_tracking( $order->get_id(), $order );
	}

	public function test_schedule_order_tracking_with_sift_disabled() {
		$order = WC_Helper_Order::create_order();

		$this->mock_action_scheduler_service
			->expects( $this->never() )
			->method( 'schedule_job' );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_fraud_services_config' )
			->willReturn(
				[
					'stripe' => [],
				]
			);

		$this->wcpay_gateway->schedule_order_tracking( $order->get_id(), $order );
	}

	public function test_schedule_order_tracking_with_no_payment_method_id() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->delete_meta_data( '_new_order_tracking_complete' );

		$this->mock_action_scheduler_service
			->expects( $this->never() )
			->method( 'schedule_job' );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_fraud_services_config' )
			->willReturn(
				[
					'stripe' => [],
					'sift'   => [],
				]
			);

		$this->wcpay_gateway->schedule_order_tracking( $order->get_id(), $order );
	}

	public function test_schedule_order_tracking() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->update_meta_data( '_wcpay_mode', WC_Payments::mode()->is_test() ? 'test' : 'prod' );
		$order->delete_meta_data( '_new_order_tracking_complete' );
		$order->save_meta_data();
		$this->mock_action_scheduler_service
			->expects( $this->once() )
			->method( 'schedule_job' );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_fraud_services_config' )
			->willReturn(
				[
					'stripe' => [],
					'sift'   => [],
				]
			);

		$this->wcpay_gateway->schedule_order_tracking( $order->get_id(), $order );
	}

	public function test_schedule_order_tracking_on_already_created_order() {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->add_meta_data( '_new_order_tracking_complete', 'yes' );
		$order->update_meta_data( '_payment_method_id', 'pm_123' );
		$order->save_meta_data();

		$this->mock_action_scheduler_service
			->expects( $this->once() )
			->method( 'schedule_job' );

		$this->mock_wcpay_account
			->expects( $this->once() )
			->method( 'get_fraud_services_config' )
			->willReturn(
				[
					'stripe' => [],
					'sift'   => [],
				]
			);

		$this->wcpay_gateway->schedule_order_tracking( $order->get_id(), $order );
	}

	public function test_outputs_payments_settings_screen() {
		ob_start();
		$this->wcpay_gateway->output_payments_settings_screen();
		$output = ob_get_clean();
		$this->assertStringMatchesFormat( '%aid="wcpay-account-settings-container"%a', $output );
	}

	public function test_outputs_express_checkout_settings_screen() {
		$_GET['method'] = 'foo';
		ob_start();
		$this->wcpay_gateway->output_payments_settings_screen();
		$output = ob_get_clean();
		$this->assertStringMatchesFormat( '%aid="wcpay-express-checkout-settings-container"%a', $output );
		$this->assertStringMatchesFormat( '%adata-method-id="foo"%a', $output );
	}

	/**
	 * Tests account statement descriptor validator
	 *
	 * @dataProvider account_statement_descriptor_validation_provider
	 */
	public function test_validate_account_statement_descriptor_field( $is_valid, $value, $expected = null ) {
		$key = 'account_statement_descriptor';
		if ( $is_valid ) {
			$validated_value = $this->wcpay_gateway->validate_account_statement_descriptor_field( $key, $value );
			$this->assertEquals( $expected ?? $value, $validated_value );
		} else {
			$this->expectExceptionMessage( 'Customer bank statement is invalid.' );
			$this->wcpay_gateway->validate_account_statement_descriptor_field( $key, $value );
		}
	}

	public function account_statement_descriptor_validation_provider() {
		return [
			'valid'          => [ true, 'WCPAY dev' ],
			'allow_digits'   => [ true, 'WCPay dev 2020' ],
			'allow_special'  => [ true, 'WCPay-Dev_2020' ],
			'allow_amp'      => [ true, 'WCPay&Dev_2020' ],
			'strip_slashes'  => [ true, 'WCPay\\\\Dev_2020', 'WCPay\\Dev_2020' ],
			'allow_long_amp' => [ true, 'aaaaaaaaaaaaaaaaaaa&aa' ],
			'trim_valid'     => [ true, '   good_descriptor  ', 'good_descriptor' ],
			'empty'          => [ false, '' ],
			'short'          => [ false, 'WCP' ],
			'long'           => [ false, 'WCPay_dev_WCPay_dev_WCPay_dev_WCPay_dev' ],
			'no_*'           => [ false, 'WCPay * dev' ],
			'no_sqt'         => [ false, 'WCPay \'dev\'' ],
			'no_dqt'         => [ false, 'WCPay "dev"' ],
			'no_lt'          => [ false, 'WCPay<dev' ],
			'no_gt'          => [ false, 'WCPay>dev' ],
			'req_latin'      => [ false, 'дескриптор' ],
			'req_letter'     => [ false, '123456' ],
			'trim_too_short' => [ false, '  aaa    ' ],
		];
	}

	public function test_payment_request_form_field_defaults() {
		// need to delete the existing options to ensure nothing is in the DB from the `setUp` phase, where the method is instantiated.
		delete_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertEquals(
			[
				'product',
				'cart',
				'checkout',
			],
			$this->wcpay_gateway->get_option( 'payment_request_button_locations' )
		);
		$this->assertEquals(
			'default',
			$this->wcpay_gateway->get_option( 'payment_request_button_size' )
		);

		$form_fields = $this->wcpay_gateway->get_form_fields();

		$this->assertEquals(
			[
				'default',
				'buy',
				'donate',
				'book',
			],
			array_keys( $form_fields['payment_request_button_type']['options'] )
		);
		$this->assertEquals(
			[
				'dark',
				'light',
				'light-outline',
			],
			array_keys( $form_fields['payment_request_button_theme']['options'] )
		);
	}

	public function test_payment_gateway_enabled_for_supported_currency() {
		$current_currency = strtolower( get_woocommerce_currency() );
		$this->mock_wcpay_account->expects( $this->once() )->method( 'get_account_customer_supported_currencies' )->will(
			$this->returnValue(
				[
					$current_currency,
				]
			)
		);
		$this->assertTrue( $this->wcpay_gateway->is_available_for_current_currency() );
	}

	public function test_payment_gateway_enabled_for_empty_supported_currency_list() {
		// We want to avoid disabling the gateway in case the API doesn't give back any currency suppported.
		$this->mock_wcpay_account->expects( $this->once() )->method( 'get_account_customer_supported_currencies' )->will(
			$this->returnValue(
				[]
			)
		);
		$this->assertTrue( $this->wcpay_gateway->is_available_for_current_currency() );
	}

	public function test_payment_gateway_disabled_for_unsupported_currency() {
		$this->mock_wcpay_account->expects( $this->once() )->method( 'get_account_customer_supported_currencies' )->will(
			$this->returnValue(
				[
					'btc',
				]
			)
		);
		$this->assertFalse( $this->wcpay_gateway->is_available_for_current_currency() );
	}

	public function test_process_payment_for_order_not_from_request() {
		// There is no payment method data within the request. This is the case e.g. for the automatic subscription renewals.
		$_POST['payment_method'] = '';

		$expected_upe_payment_method = 'card';
		$order                       = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );
		$order->set_total( 100 );
		$order->save();

		$pi = new Payment_Information( 'pm_test', $order );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Intention::class );
		$request->expects( $this->once() )
			->method( 'set_payment_methods' )
			->with( [ $expected_upe_payment_method ] );
		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention( [ 'status' => 'success' ] ) );

		$this->wcpay_gateway->process_payment_for_order( WC()->cart, $pi );
	}

	public function test_process_payment_for_order_rejects_with_cached_minimum_amount() {
		set_transient( 'wcpay_minimum_amount_usd', '50', DAY_IN_SECONDS );

		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );
		$order->set_total( 0.45 );
		$order->save();

		$pi = new Payment_Information( 'pm_test', $order );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'The selected payment method requires a total amount of at least $0.50.' );
		$this->wcpay_gateway->process_payment_for_order( WC()->cart, $pi );
	}

	public function test_process_payment_for_order_cc_payment_method() {
		$payment_method                              = 'woocommerce_payments';
		$expected_upe_payment_method_for_pi_creation = 'card';
		$order                                       = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );
		$order->set_total( 100 );
		$order->save();

		$_POST['wcpay-fraud-prevention-token'] = 'correct-token';
		$_POST['payment_method']               = $payment_method;
		$pi                                    = new Payment_Information( 'pm_test', $order );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Intention::class );
		$request->expects( $this->once() )
			->method( 'set_payment_methods' )
			->with( [ $expected_upe_payment_method_for_pi_creation ] );
		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention( [ 'status' => 'success' ] ) );

		$this->wcpay_gateway->process_payment_for_order( WC()->cart, $pi );
	}

	public function test_process_payment_for_order_upe_payment_method() {
		$payment_method                              = 'woocommerce_payments_sepa_debit';
		$expected_upe_payment_method_for_pi_creation = 'sepa_debit';
		$order                                       = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );
		$order->set_total( 100 );
		$order->save();

		$_POST['wcpay-fraud-prevention-token'] = 'correct-token';
		$_POST['payment_method']               = $payment_method;
		$pi                                    = new Payment_Information( 'pm_test', $order );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Intention::class );
		$request->expects( $this->once() )
			->method( 'set_payment_methods' )
			->with( [ $expected_upe_payment_method_for_pi_creation ] );
		$request->expects( $this->once() )
			->method( 'format_response' )
			->willReturn( WC_Helper_Intention::create_intention( [ 'status' => 'success' ] ) );

		$this->wcpay_gateway->process_payment_for_order( WC()->cart, $pi );
	}

	public function test_process_payment_caches_mimimum_amount_and_displays_error_upon_exception() {
		delete_transient( 'wcpay_minimum_amount_usd' );

		$amount   = 0.45;
		$customer = 'cus_12345';

		$order = WC_Helper_Order::create_order();
		$order->set_total( $amount );
		$order->save();

		$this->mock_customer_service
			->expects( $this->once() )
			->method( 'get_customer_id_by_user_id' )
			->will( $this->returnValue( $customer ) );

		$_POST = [ 'wcpay-payment-method' => $pm = 'pm_mock' ];

		$this->get_fraud_prevention_service_mock()
			->expects( $this->once() )
			->method( 'is_enabled' )
			->willReturn( false );

		$request = $this->mock_wcpay_request( Create_And_Confirm_Intention::class );

		$request->expects( $this->once() )
			->method( 'set_amount' )
			->with( (int) ( $amount * 100 ) );

		$request->expects( $this->once() )
			->method( 'set_payment_method' )
			->with( $pm );

		$request->expects( $this->once() )
			->method( 'set_customer' )
			->with( $customer );

		$request->expects( $this->once() )
			->method( 'set_capture_method' )
			->with( false );

		$request->expects( $this->once() )
			->method( 'set_metadata' )
			->with(
				$this->callback(
					function( $metadata ) {
						$required_keys = [ 'customer_name', 'customer_email', 'site_url', 'order_id', 'order_number', 'order_key', 'payment_type' ];
						foreach ( $required_keys as $key ) {
							if ( ! array_key_exists( $key, $metadata ) ) {
								return false;
							}
						}
						return true;
					}
				)
			);

		$request->expects( $this->once() )
			->method( 'format_response' )
			->will( $this->throwException( new Amount_Too_Small_Exception( 'Error: Amount must be at least $60 usd', 6000, 'usd', 400 ) ) );
		$this->expectException( Exception::class );
		$price   = html_entity_decode( wp_strip_all_tags( wc_price( 60, [ 'currency' => 'USD' ] ) ) );
		$message = 'The selected payment method requires a total amount of at least ' . $price . '.';
		$this->expectExceptionMessage( $message );

		try {
			$this->wcpay_gateway->process_payment( $order->get_id() );
		} catch ( Exception $e ) {
			$this->assertEquals( '6000', get_transient( 'wcpay_minimum_amount_usd' ) );
			throw $e;
		}
	}

	public function test_process_payment_rejects_if_missing_fraud_prevention_token() {
		$order = WC_Helper_Order::create_order();

		$fraud_prevention_service_mock = $this->get_fraud_prevention_service_mock();

		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'is_enabled' )
			->willReturn( true );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( "We're not able to process this payment. Please refresh the page and try again." );
		$this->wcpay_gateway->process_payment( $order->get_id() );
	}

	public function test_process_payment_rejects_if_invalid_fraud_prevention_token() {
		$order = WC_Helper_Order::create_order();

		$fraud_prevention_service_mock = $this->get_fraud_prevention_service_mock();

		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'is_enabled' )
			->willReturn( true );

		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'verify_token' )
			->with( 'incorrect-token' )
			->willReturn( false );

		$_POST['wcpay-fraud-prevention-token'] = 'incorrect-token';

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( "We're not able to process this payment. Please refresh the page and try again." );
		$this->wcpay_gateway->process_payment( $order->get_id() );
	}

	public function test_process_payment_continues_if_valid_fraud_prevention_token() {
		$order = WC_Helper_Order::create_order();

		$fraud_prevention_service_mock = $this->get_fraud_prevention_service_mock();

		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'is_enabled' )
			->willReturn( true );

		$fraud_prevention_service_mock
			->expects( $this->once() )
			->method( 'verify_token' )
			->with( 'correct-token' )
			->willReturn( true );

		$_POST['wcpay-fraud-prevention-token'] = 'correct-token';

		$this->mock_rate_limiter
			->expects( $this->once() )
			->method( 'is_limited' )
			->willReturn( false );

		$mock_wcpay_gateway = $this->get_partial_mock_for_gateway( [ 'prepare_payment_information', 'process_payment_for_order' ] );
		$mock_wcpay_gateway
			->expects( $this->once() )
			->method( 'prepare_payment_information' );
		$mock_wcpay_gateway
			->expects( $this->once() )
			->method( 'process_payment_for_order' );

		$mock_wcpay_gateway->process_payment( $order->get_id() );
	}

	public function test_get_upe_enabled_payment_method_statuses_with_empty_cache() {
		$this->mock_wcpay_account
			->expects( $this->any() )
			->method( 'get_cached_account_data' )
			->willReturn( [] );

		$this->assertEquals(
			[
				'card_payments' => [
					'status'       => 'active',
					'requirements' => [],
				],
			],
			$this->wcpay_gateway->get_upe_enabled_payment_method_statuses()
		);
	}

	public function test_get_upe_enabled_payment_method_statuses_with_cache() {
		$caps             = [
			'card_payments'       => 'active',
			'sepa_debit_payments' => 'active',
		];
		$cap_requirements = [
			'card_payments'       => [],
			'sepa_debit_payments' => [],
		];
		$this->mock_wcpay_account
			->expects( $this->any() )
			->method( 'get_cached_account_data' )
			->willReturn(
				[
					'capabilities'            => $caps,
					'capability_requirements' => $cap_requirements,
				]
			);

		$this->assertEquals(
			[
				'card_payments'       => [
					'status'       => 'active',
					'requirements' => [],
				],
				'sepa_debit_payments' => [
					'status'       => 'active',
					'requirements' => [],
				],
			],
			$this->wcpay_gateway->get_upe_enabled_payment_method_statuses()
		);
	}

	public function test_is_woopay_enabled_returns_true() {
		$this->mock_cache->method( 'get' )->willReturn( [ 'platform_checkout_eligible' => true ] );
		$this->wcpay_gateway->update_option( 'platform_checkout', 'yes' );
		$this->assertTrue( $this->woopay_utilities->should_enable_woopay( $this->wcpay_gateway ) );

		// This will return TRUE because woopay_utilities->should_enable_woopay() will return true.
		$this->assertTrue( $this->payments_checkout->get_payment_fields_js_config()['isWooPayEnabled'] );
	}

	public function test_should_use_stripe_platform_on_checkout_page_not_woopay_eligible() {
		$this->mock_cache->method( 'get' )->willReturn( [ 'platform_checkout_eligible' => false ] );
		$this->assertFalse( $this->wcpay_gateway->should_use_stripe_platform_on_checkout_page() );
	}

	public function test_should_use_stripe_platform_on_checkout_page_not_woopay() {
		$this->mock_cache->method( 'get' )->willReturn( [ 'platform_checkout_eligible' => true ] );
		$this->wcpay_gateway->update_option( 'platform_checkout', 'no' );

		$this->assertFalse( $this->wcpay_gateway->should_use_stripe_platform_on_checkout_page() );
	}

	public function test_force_network_saved_cards_is_returned_as_true_if_should_use_stripe_platform() {
		$mock_wcpay_gateway = $this->get_partial_mock_for_gateway( [ 'should_use_stripe_platform_on_checkout_page' ] );

		$mock_wcpay_gateway
			->expects( $this->once() )
			->method( 'should_use_stripe_platform_on_checkout_page' )
			->willReturn( true );

		$payments_checkout = new WC_Payments_Checkout(
			$mock_wcpay_gateway,
			$this->woopay_utilities,
			$this->mock_wcpay_account,
			$this->mock_customer_service
		);

		$this->assertTrue( $payments_checkout->get_payment_fields_js_config()['forceNetworkSavedCards'] );
	}

	public function test_is_woopay_enabled_returns_false_if_ineligible() {
		$this->mock_cache->method( 'get' )->willReturn( [ 'platform_checkout_eligible' => false ] );
		$this->assertFalse( $this->payments_checkout->get_payment_fields_js_config()['isWooPayEnabled'] );
	}

	public function test_is_woopay_enabled_returns_false_if_ineligible_and_enabled() {
		$this->wcpay_gateway->update_option( 'platform_checkout', 'yes' );
		$this->assertFalse( $this->payments_checkout->get_payment_fields_js_config()['isWooPayEnabled'] );
	}

	public function test_return_icon_url() {
		$returned_icon = $this->payments_checkout->get_payment_fields_js_config()['icon'];
		$this->assertNotNull( $returned_icon );
		$this->assertStringContainsString( 'assets/images/payment-methods/cc.svg', $returned_icon );
	}

	public function is_woopay_falsy_value_provider() {
		return [
			[ '0' ],
			[ 0 ],
			[ null ],
			[ false ],
			'(bool) true is not strictly equal to (int) 1' => [ true ],
			[ 'foo' ],
			[ [] ],
		];
	}

	/**
	 * @expectedDeprecated is_in_dev_mode
	 */
	public function test_is_in_dev_mode() {
		$mode = WC_Payments::mode();

		$mode->dev();
		$this->assertTrue( $this->wcpay_gateway->is_in_dev_mode() );

		$mode->test();
		$this->assertFalse( $this->wcpay_gateway->is_in_dev_mode() );

		$mode->live();
		$this->assertFalse( $this->wcpay_gateway->is_in_dev_mode() );
	}

	/**
	 * @expectedDeprecated is_in_test_mode
	 */
	public function test_is_in_test_mode() {
		$mode = WC_Payments::mode();

		$mode->dev();
		$this->assertTrue( $this->wcpay_gateway->is_in_test_mode() );

		$mode->test();
		$this->assertTrue( $this->wcpay_gateway->is_in_test_mode() );

		$mode->live();
		$this->assertFalse( $this->wcpay_gateway->is_in_test_mode() );
	}

	/**
	 * Create a partial mock for WC_Payment_Gateway_WCPay class.
	 *
	 * @param array $methods Method names that need to be mocked.
	 * @return MockObject|WC_Payment_Gateway_WCPay
	 */
	private function get_partial_mock_for_gateway( array $methods = [] ) {
		return $this->getMockBuilder( WC_Payment_Gateway_WCPay::class )
			->setConstructorArgs(
				[
					$this->mock_api_client,
					$this->mock_wcpay_account,
					$this->mock_customer_service,
					$this->mock_token_service,
					$this->mock_action_scheduler_service,
					$this->mock_rate_limiter,
					$this->order_service,
				]
			)
			->setMethods( $methods )
			->getMock();
	}


	/**
	 * Tests that no payment is processed when the $_POST 'is-woopay-preflight-check` is present.
	 */
	public function test_no_payment_is_processed_for_woopay_preflight_check_request() {
		$_POST['is-woopay-preflight-check'] = true;

		// Arrange: Create an order to test with.
		$order_data = [
			'status' => 'draft',
			'total'  => '100',
		];

		$order = wc_create_order( $order_data );

		$mock_wcpay_gateway = $this->get_partial_mock_for_gateway( [ 'process_payment_for_order' ] );

		// Assert: No payment was processed.
		$mock_wcpay_gateway
			->expects( $this->never() )
			->method( 'process_payment_for_order' );

		// Act: process payment.
		$response = $mock_wcpay_gateway->process_payment( $order->get_id() );
	}

	/**
	 * Mocks Fraud_Prevention_Service.
	 *
	 * @return MockObject|Fraud_Prevention_Service
	 */
	private function get_fraud_prevention_service_mock() {
		$fraud_prevention_service_mock = $this->getMockBuilder( Fraud_Prevention_Service::class )
			->disableOriginalConstructor()
			->getMock();

		Fraud_Prevention_Service::set_instance( $fraud_prevention_service_mock );

		return $fraud_prevention_service_mock;
	}

	private function create_charge_object() {
		$created = new DateTime();
		$created->setTimestamp( $this->mock_charge_created );

		return new WC_Payments_API_Charge( $this->mock_charge_id, 1500, $created );
	}

	private function refresh_payments_checkout() {
		remove_all_actions( 'wc_payments_add_payment_fields' );

		$this->payments_checkout = new WC_Payments_Checkout(
			$this->wcpay_gateway,
			$this->woopay_utilities,
			$this->mock_wcpay_account,
			$this->mock_customer_service
		);
	}
}
