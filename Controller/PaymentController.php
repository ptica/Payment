<?php
App::uses('AppController', 'Controller');
App::uses('CakeEmail', 'Network/Email');

class PaymentController extends PaymentAppController {
	public $components = array('Paginator', 'Session', 'Auth');
	public $uses = array('Payment.Payment', 'Booking');

	// declare public actions
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('pay', 'result', 'ok', 'nok');
	}
	
	/*
	 * payment via booking_id and token
	 */
	public function pay($booking_id, $token) {
		// validate booking token versus id
		$cnd = array(
			'Booking.id'    => $booking_id,
			'Booking.token' => $token
		);

		$booking = $this->Booking->find('first', array('conditions'=>$cnd));
		if (!$booking) {
			echo 'Payment not found.';
			exit();
		}

		if (true) {
			echo "<p>Thank you for registering for MT Marathon 2016.<p>Online payments period has already ended.<p>We are looking forward to seeing you here in Prague.";
			exit();
		}
		
		// check for zero payment
		if ($booking['Booking']['web_price'] <= 0) {
			echo "<p>Thank you for registering for MT Marathon 2016.<p>There is no balance to be paid for your registration.<p>Looking forward to seeing you in Prague.";
			exit();
		}
		
		// try to find existing Payment
		$cnd = array(
			'Payment.booking_id' => $booking_id,
			'Payment.token' => $token,
			'Payment.status' => 'confirmed ok'
		);

		$payment = $this->Payment->find('first', array('conditions'=>$cnd));

		if (@$payment['Payment']['status'] == 'confirmed ok') {
			$this->redirect('/pay/ok/' . $token);
		} else {
			// create new payment for gateway
			$payment = array(
				'booking_id'   => $booking_id,
				'amountcents'  => 100 * $booking['Booking']['web_price'],
				'currencycode' => Configure::read('gp.currency.code'),
				'currency'     => Configure::read('gp.currency.ticker'),
				'token'        => $token
			);
			
			if ($this->Payment->save($payment)) {
				$payment_id = $this->Payment->id;
				
				$params = array(
					'MERCHANTNUMBER' => Configure::read('gp.merchantid'),
					'OPERATION' => 'CREATE_ORDER',
					'ORDERNUMBER' => $payment_id,
					'AMOUNT' => $payment['amountcents'],
					//'AMOUNT' => 100,
					'CURRENCY' => Configure::read('gp.currency.code'),
					'DEPOSITFLAG' => 1, // pozadovana okamzita uhrada
					'URL' => Router::url('/pay/result', $full=true)
				);
				
				// TODO move signing into a event callback?
				$private_key = Configure::read('gp.private_key');
				$public_key  = Configure::read('gp.public_key');
				$sign = new CSignature($private_key, Configure::read('gp.password'), $public_key);
				$params_str = implode('|', array_values($params));
				$digest = $sign->sign($params_str);
				
				$params['DIGEST'] = $digest;
				
				// for view
				$this->set(compact('booking', 'payment_id', 'booking_id', 'params'));
				
				$this->request->data = $booking;
				$rooms = $this->Booking->Room->find('list');
				$priceTypes = $this->Booking->PriceType->find('list');
				$upsells = $this->Booking->Upsell->find('list');
				$meals = $this->Booking->Meal->find('list');
				$queries = $this->Booking->Query->find('list');
				$locations = $this->Booking->Room->Location->find('list');
				$location_desc = $this->Booking->Room->Location->find('list', array('fields'=>array('id', 'desc')));
				$this->set(compact('rooms', 'priceTypes', 'upsells', 'meals', 'queries', 'locations', 'location_desc'));
			} else {
				echo 'Internal error while creating new payment. Please contact system administrator at jan.ptacek@gmail.com';
				exit();
			}
		}
	}
	
	/*
	 * Recieve payment result from the gateway
	 */
	public function result() {
		$params = $this->request->query;
		
		$gp_digest  = $params['DIGEST'];
		$gp_digest1 = $params['DIGEST1'];
		unset($params['DIGEST']);
		unset($params['DIGEST1']);
		
		$private_key = Configure::read('gp.private_key');
		$public_key  = Configure::read('gp.muzo_key');
		$sign = new CSignature($private_key, Configure::read('gp.password'), $public_key);
		
		// check digest
		$params_str = implode('|', array_values($params));
		$res_digest = $sign->verify($params_str, $gp_digest);
		
		// check digest1
		$params['MERCHANTNUMBER'] = Configure::read('gp.merchantid');
		$params_str = implode('|', array_values($params));
		$res_digest1 = $sign->verify($params_str, $gp_digest1);
		
		
		$payment_id = $params['ORDERNUMBER'];
		CakeLog::write('info', "payment id: $payment_id");
		
		$payment = @$this->Payment->findById($payment_id);
		if (!$payment) {
			CakeLog::write('info', "payment id not found");
			echo 'Error: Payment id not found.';
			exit();
		}
		
		if ($res_digest && $res_digest1) {
			$pr_code    = $params['PRCODE'];
			$sr_code    = $params['SRCODE'];
			$msg        = $params['RESULTTEXT'];

			CakeLog::write('info', "payment result called: [OK_DIGEST]");
			CakeLog::write('info', "payment PRCODE: [$pr_code] SRCODE: [$sr_code]");
			
			// saving payment with PRCODE & SRCODE
			if ($pr_code == 0 && $sr_code == 0) {
				$status = 'confirmed ok';
			} else {
				$status = "PRCODE:$pr_code SRCODE:$sr_code";
				echo "Payment gateway error message: " . $msg;
			}
			$payment['Payment']['confirmation'] = date('Y-m-d H:i:s');
			$payment['Payment']['status'] = $status;
			$payment['Payment']['status'] = $status;
			$payment['Payment']['msg']    = $msg;

			if ($this->Payment->save($payment)) {
				CakeLog::write('info', "Payment  saved ok");
			} else {
				CakeLog::write('info', "problem saving the Payment");
			}
			
			// Display response to user
			if ($pr_code == 0 && $sr_code == 0) {
				// $this->send_new_payment($payment_id);
				$this->redirect('/pay/ok/' . $payment['Payment']['token']);
			} else {
				$this->redirect('/pay/nok/' . $payment['Payment']['token']);
			}
		} else {
			CakeLog::write('info', "payment result called: [WRONG_DIGEST]");
		}
		
		exit();
	}

	/*
	 * Payment Not Successful with link to repeated payment
	 */
	public function nok($token) {
		// validate booking token
		$cnd = array(
			'Booking.token' => $token
		);

		$booking = $this->Booking->find('first', array('conditions'=>$cnd));
		if (!$booking) {
			echo 'Payment not found.';
			exit();
		}
		
		$this->set('booking_id', $booking['Booking']['id']);
		$this->set('token', $booking['Booking']['token']);
	}


	/*
	 * Payment Sucessful
	 */
	public function ok($token) {
		// validate booking token
		$cnd = array(
			'Booking.token' => $token
		);

		$booking = $this->Booking->find('first', array('conditions'=>$cnd));
		if (!$booking) {
			echo 'Payment not found.';
			exit();
		}
	}

	/**
	 * notification emails
	 */
	private function send_new_payment($payment_id) {
		$this->Payment->recursive = 2;
		$payment = $this->Payment->findById($payment_id);

		$viewVars = $this->get_receipt_data($payment);
		// amend for the email
		$this->set_request_scheme();
		$host = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
		$token = $payment['Payment']['token'];
		$viewVars['view_on_server_url'] = "$host/payment/ok?Ref=$payment_id&token=$token";
		$viewVars['logo'] = 'cid:logo'; // reference to attached logo

		$attachments = array(
			'logo.png' => array(
				'file' => WWW_ROOT . '/images/logo.png',
				'mimetype' => 'image/png',
				'contentId' => 'logo'
			)
		);

		$subject = 'Your receipt';
		$to = $payment['Booking']['email'];

		$Email = new CakeEmail('mandrill');
		$Email->template('client_booking', $layout='mailchimp')
			->to($to)
			->subject($subject)
			->viewVars($viewVars)
			->attachments($attachments);
		$Email->send();
	}
}
