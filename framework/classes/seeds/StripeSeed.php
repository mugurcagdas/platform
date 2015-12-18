<?php
/**
 * The StripeSeed class speaks to the Stripe API.
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * began with official Paypal SDK examples, much editing later...
 * original script(s) here:
 * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/library_download_sdks#NVP
 *
 * Copyright (c) 2013, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 *
 * This file is generously sponsored by Justin Miranda
 *
 **/
//namespace Seeds\StripeSeed;

require CASH_PLATFORM_ROOT  . '/lib/stripe/init.php';

class StripeSeed extends SeedBase {
	protected $client_id, $client_secret, $publishable_key, $error_message;

	public function __construct($user_id, $connection_id) {
		$this->settings_type = 'com.stripe';
		$this->user_id = $user_id;
		$this->connection_id = $connection_id;

		if ($this->getCASHConnection()) {

			$this->client_id  = $this->settings->getSetting('client_id');
			$this->client_secret = $this->settings->getSetting('client_secret');
			$this->publishable_key = $this->settings->getSetting('publishable_key');
			$sandboxed           = $this->settings->getSetting('sandboxed');

			\Stripe\Stripe::setApiKey($this->client_secret);

			if (!$this->client_id || !$this->client_secret || !$this->publishable_key) {
				$connections = CASHSystem::getSystemSettings('system_connections');

				if (isset($connections['com.stripe'])) {
					$this->client_id     = $connections['com.stripe']['client_id'];
					$this->client_secret   = $connections['com.stripe']['client_secret'];
					$this->publishable_key  	   = $connections['com.stripe']['publishable_key'];
					$sandboxed         = $connections['com.stripe']['sandboxed'];

					if ($sandboxed) {
						//sandboxed
					}
				}
			}
		} else {
			$this->error_message = 'could not get connection settings';
		}
	}

	public static function getRedirectMarkup($data=false) {
		$connections = CASHSystem::getSystemSettings('system_connections');
		if (isset($connections['com.stripe'])) {
			$login_url = StripeSeed::getAuthorizationUrl($this->client_id, $this->secret);
			$return_markup = '<h4>Stripe</h4>'
				. '<p>This will redirect you to a secure login at Stripe and bring you right back.</p>'
				. '<a href="' . $login_url . '" class="button">Connect your Stripe</a>';
			return $return_markup;
		} else {
			return 'Please add default stripe api credentials.';
		}
	}

	protected function setErrorMessage($msg) {
		$this->error_message = $msg;
	}

	public function getErrorMessage() {
		return $this->error_message;
	}

	public function setCheckout(
		$payment_amount,
		$ordersku,
		$ordername,
		$return_url,
		$cancel_url,
		$request_shipping_info=true,
		$allow_note=false,
		$currency_id='USD', /* 'USD', 'GBP', 'EUR', 'JPY', 'CAD', 'AUD' */
		$payment_type='sale', /* 'Sale', 'Order', or 'Authorization' */
		$invoice=false,
		$shipping_amount=false
	) {

		$payer = new Payer();
		$payer->setPaymentMethod("paypal");



		$amount = new Amount();
		$amount->setCurrency($currency_id)
			->setTotal($payment_amount);

		error_log("shipping + ". $shipping_amount);
		if ($request_shipping_info && $shipping_amount > 0) {
			$shipping = new Details();
			$shipping->setShipping($shipping_amount)
				//->setTax(1.3)
				->setSubtotal($payment_amount - $shipping_amount);
				//TODO: assumes shipping cost is passed in as part of the total $payment_amount

			$amount->setDetails($shipping);
		}

		$transaction = new Transaction();
		$transaction->setAmount($amount)
			->setDescription($ordername)
			->setInvoiceNumber($ordersku."farts");

		$redirectUrls = new RedirectUrls();
		$redirectUrls->setReturnUrl($return_url."&success=true")
					 ->setCancelUrl($cancel_url."&success=false");


		$payment = new Payment();
		$payment->setIntent($payment_type)
			->setPayer($payer)
			->setRedirectUrls($redirectUrls)
			->setTransactions(array($transaction));


		try { $payment->create($this->api_context); } catch (Exception $ex) {

			$error = json_decode($ex->getData());
			$this->setErrorMessage($error->message);
		}

		$approval_url = $payment->getApprovalLink();

		if (!empty($approval_url)) {
			return array(
				'redirect_url' => $approval_url,
				'data_sent' => json_encode($payment->getTransactions() )
			);
		} else {
			// approval link isn't set, return to page and post error
			$this->setErrorMessage('There was an error contacting PayPal for this payment.');
		}
		return true;
	}

	public function getCheckout() {

		// check if we got a PayPal token in the return url; if not, cheese it!
		if (!empty($_GET['token'])) {

		} else {
			$this->setErrorMessage("No PayPal token was found.");
			return false;
		}

		// Determine if the user approved the payment or not
		if (!empty($_GET['success']) && $_GET['success'] == 'true' &&
			!empty($_GET['paymentId']) && !empty($_GET['PayerID'])
			) {

			// Get the payment Object by passing paymentId
			// payment id was previously stored in session in
			// CreatePaymentUsingPayPal.php
			$this->payment_id = $_GET['paymentId'];
			$payment = Payment::get($this->payment_id, $this->api_context);

			// ### Payment Execute
			// PaymentExecution object includes information necessary
			// to execute a PayPal account payment.
			// The payer_id is added to the request query parameters
			// when the user is redirected from paypal back to your site
			$execution = new PaymentExecution();
			$execution->setPayerId($_GET['PayerID']);

			try {
				// Execute the payment
				$result = $payment->execute($execution, $this->api_context);

				try {
					$payment = Payment::get($this->payment_id, $this->api_context);
				} catch (Exception $ex) {
					return false;
				}
			} catch (Exception $ex) {

				return false;
			}

			// let's return a standardized array to generalize for multiple payment types
			$details = $payment->toArray();
			// nested array for data received, standard across seeds
			//TODO: this is set for single item transactions for now; should be expanded for cart transactions

			$order_details = array(
				'transaction_description' => '',
				'customer_email' => $details['payer']['payer_info']['email'],
				'customer_first_name' => $details['payer']['payer_info']['first_name'],
				'customer_last_name' => $details['payer']['payer_info']['last_name'],
				'customer_name' => $details['payer']['payer_info']['first_name'] . " " . $details['payer']['payer_info']['last_name'],
				'customer_shipping_name' => '',
				'customer_address1' => '',
				'customer_address2' => '',
				'customer_city' => '',
				'customer_region' => '',
				'customer_postalcode' => '',
				'customer_country' => '',
				'customer_countrycode' => '',
				'customer_phone' => '',
				/* 																*/
				'transaction_date' 	=> strtotime($details['create_time']),
				'transaction_id' 	=> $details['id'],
				'sale_id'			=> $details['transactions'][0]['related_resources'][0]['sale']['id'],
				'items' 			=> array(),
				'total' 			=> $details['transactions'][0]['amount']['total'],
				'other_charges' 	=> array(),
				'transaction_fees'  => $details['transactions'][0]['related_resources'][0]['sale']['transaction_fee']['value'],
				);

			return array('total' => $details['transactions'][0]['amount']['total'],
						'payer' => $details['payer']['payer_info'],
						'timestamp' => strtotime($details['create_time']),
						'transaction_id' => $details['id'],
						'transaction_fee' => $details['transactions'][0]['related_resources'][0]['sale']['transaction_fee'],
						'order_details' => json_encode($order_details)
						);
		} else {
			return false;
		}

	}

	public function doRefund($sale_id,$refund_amount=0,$currency_id='USD') {

		$amt = new Amount();
		$amt->setCurrency($currency_id);
		$amt->setTotal($refund_amount);

		$refund = new Refund();
		$refund->setAmount($amt);

		$sale = new Sale();
		$sale->setId($sale_id);

		$refund_response = $sale->refund($refund, $this->api_context);

		if (!$refund_response) {
			$this->setErrorMessage('RefundTransaction failed: ' . $this->getErrorMessage());
			error_log($this->getErrorMessage());
			return false;
		} else {
			return $refund_response;
		}

	}
} // END class
?>