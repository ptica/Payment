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
		// query string params
		//$booking_id = $this->request->query('id');
		//$token      = $this->request->query('token');
		
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
		
		// try to find existing Payment
		$cnd = array(
			'Payment.booking_id' => $booking_id,
			'Payment.token' => $token
		);

		$payment = $this->Payment->find('first', array('conditions'=>$cnd));

		if (@$payment['Payment']['status'] == 'confirmed ok') {
			$this->Session->write('remembered_payment_id', $payment_id);
			$this->redirect('/payment/ok');
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
				
				/* TRY: sign using https://github.com/sebik/webpay-php */
				$request = new WebPayRequest();
				$request->setPrivateKey($private_key, Configure::read('gp.password'));
				$request->setWebPayUrl('https://test.3dsecure.gpwebpay.com/rb/order.do');
				$request->setResponseUrl(Router::url('/pay/result', $full=true));
				$request->setMerchantNumber(Configure::read('gp.merchantid'));
				$request->setOrderInfo(49 /* webpay objednávka */, 49 /* interní objednávka */, 1 /* cena v CZK */);
				$gp_url = $request->requestUrl();

				// for view
				$this->set('locations', $this->Booking->Room->Location->find('list'));
				$this->set(compact('booking', 'payment_id', 'booking_id', 'params', 'gp_url'));
				$this->request->data = $booking;
				$rooms = $this->Booking->Room->find('list');
				$priceTypes = $this->Booking->PriceType->find('list');
				$upsells = $this->Booking->Upsell->find('list');
				$meals = $this->Booking->Meal->find('list');
				$queries = $this->Booking->Query->find('list');
				$locations = $this->Booking->Room->Location->find('list');
				$location_desc = $this->Booking->Room->Location->find('list', array('fields'=>array('id', 'desc')));
				$this->set(compact('rooms', 'priceTypes', 'upsells', 'meals', 'queries', 'locations', 'location_desc'));

				// for nok page try again link
				$this->Session->write('remembered_payment_id', $payment_id);
			} else {
				echo 'Internal error creating new payment. Please contact system administrator at jan.ptacek@gmail.com';
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
				// send out emails
				$this->send_new_booking($payment_id);
			} else {
				// TODO: redirect to nok page
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
			
			// View for user
			if ($pr_code == 0 && $sr_code == 0) {
				// send out emails
				// TODO $this->send_new_booking($payment_id);
			} else {
				// TODO: redirect to nok page
			}
		} else {
			CakeLog::write('info', "payment result called: [WRONG_DIGEST]");
		}
		
		exit();
	}

	/*
	 * Payment Not Successful with link to repeated payment
	 */
	public function nok() {
		// respect site of origin returned in 'merchantvar1'
		$this->domain_correction();

		// empty but styled
		$this->layout = 'empty';
		$this->render(Configure::read('Config.language').'/nok');
	}


	/*
	 * Payment Sucessful with Receipt
	 */
	public function ok() {
		// respect site of origin returned in 'merchantvar1'
		$this->domain_correction();

		if ($this->request->query('token')) {
			// email links have Ref & token
			// query string params
			$payment_id = $this->request->query('Ref');
			$token      = $this->request->query('token');
			$cnd = array(
				'Payment.id' => $payment_id,
				'token' => $token
			);
		} else if ($this->Session->check('remembered_payment_id')) {
			// this covers the return from the payment gate
			// as we've set the cookie
			$payment_id = $this->Session->read('remembered_payment_id');
			$cnd = array(
				'Payment.id' => $payment_id,
			);
		} else {
			$cnd = array('Payment.id' => null);
		}

		$this->Payment->recursive = 2; // include Partner & Person
		$payment = $this->Payment->find('first', array('conditions'=>$cnd));

		if (!$payment) {
			echo 'Payment not found.';
			exit();
		}

		$view_vars = $this->get_receipt_data($payment);
		$this->set($view_vars);
		$this->render(Configure::read('Config.language').'/ok');
	}

	/**
	 * notification emails
	 */
	private function send_new_booking($payment_id) {
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

		$subject = 'Your les-onze.com receipt';
		$to = $payment['Booking']['email'];

		$Email = new CakeEmail('mandrill');
		$Email->template('client_booking', $layout='mailchimp')
			->to($to)
			->subject($subject)
			->viewVars($viewVars)
			->attachments($attachments);
		$Email->send();

		/* send admin notification as well */
		$subject = 'A new booking has been paid for';
		$to = preg_split('/[ ,]+/', Configure::read('Cfg.notification.emails'), $limit=null, $flags=PREG_SPLIT_NO_EMPTY);
		$Email = new CakeEmail('mandrill');
		$Email->template('admin_booking', $layout='mailchimp')
			->to($to)
			->subject($subject)
			->viewVars($viewVars)
			->attachments($attachments);
		$Email->send();
	}
}
