<?php
App::uses('AppController', 'Controller');
App::uses('CakeEmail', 'Network/Email');

class PaymentController extends PaymentAppController {
	public $components = array('Paginator', 'Session', 'Auth');
	public $uses = array('Payment.Payment', 'Booking');

	// declare public actions
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('validation', 'confirmation', 'reject', 'ok', 'nok', 'prices', 'mail', 'pay');
	}
	
	/*
	 * payment via booking_id and token
	 */
	public function pay() {
		// query string params
		$booking_id = $this->request->query('id');
		$token      = $this->request->query('token');
		
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
					'CURRENCY' => Configure::read('gp.currency.code'),
					'DEPOSITFLAG' => 1, // pozadovana okamzita uhrada
					'URL' => Router::url('/payment/payment/result', $full=true)
				);
				
				// TODO move signing into a event callback?
				$private_key = Configure::read('gp.private_key');
				$public_key  = Configure::read('gp.public_key');
				$sign = new CSignature($private_key, Configure::read('gp.password'), $private_key);
				$params_str = implode('|', array_values($params));
				$digest = $sign->sign($params_str);
				
				$params['DIGEST'] = $digest;

				// for view
				$this->set(compact('payment_id', 'booking_id', 'params'));

				// for nok page try again link
				$this->Session->write('remembered_payment_id', $payment_id);
			} else {
				echo 'Internal error creating new payment. Please contact system administrator at jan.ptacek@gmail.com';
				exit();
			}
		}
	}

	public function prices() {
		return Configure::read('csas.prices');
	}

	/*
	 * VALIDATION POST
	 * called by csas - to check data submitted to gw
	 */
	public function validation() {
		$csas    = $this->request->data;
		$payment = @$this->Payment->findById($csas['merchantref']);
		if (!$payment) { echo 'Error: Payment id not found.'; exit(); }

		if ($this->validation_check($csas, $payment)) {
			$payment['Payment']['validation'] = date('Y-m-d H:i:s');
			$payment['Payment']['status'] = 'validated ok';

			if ($this->Payment->save($payment)) {
				CakeLog::write('info', "csas validation of payment_id: $csas[merchantref]: [OK]");
				echo '<html><head></head><body>[OK]</body></html>';
				exit();
			}
		} else {
			$payment['Payment']['validation'] = date('Y-m-d H:i:s');
			$payment['Payment']['status'] = 'validation FAIL';
			$this->Payment->save($payment);
		}
		CakeLog::write('info', "csas validation of payment_id: $csas[merchantref]: [FAIL]");
		echo '[FAIL]';
		exit();
	}

	private function validation_check($csas, $payment) {
		$requirements = array(
			'merchantid'   => Configure::read('csas.merchantid'),
			'amountcents'  => $payment['Payment']['amountcents'],
			'currencycode' => $payment['Payment']['currencycode'],
			'password'     => Configure::read('csas.password'),
			'exponent' => '2'
		);
		$diff = array_diff_assoc($requirements, $csas);
		if (!$diff) {
			return true;
		}
		CakeLog::write('info', '[OUR VALIDATION FAIL] missing items in csas data:' . var_export($diff, true));
		return false;
	}

	/*
	 * CONFIRMATION POST
	 * called by csas - bank is ready to charge
	 */
	public function confirmation() {
		$csas       = $this->request->data;
		$payment_id = @$csas['merchantref'];
		$payment    = @$this->Payment->findById($payment_id);
		if (!$payment) { echo 'Error: Payment id not found.'; exit(); }

		if ($this->confirmation_check($csas, $payment)) {
			$payment['Payment']['confirmed'] = date('Y-m-d H:i:s');
			$payment['Payment']['status'] = 'confirmed ok';

			$booking = array(
				'id' => $payment['Booking']['id'],
				'status' => 'confirmed'
			);

			if ($this->Payment->save($payment) && $this->Booking->save($booking)) {
				CakeLog::write('info', "csas confirmation of payment_id: $csas[merchantref]: [OK]");
				echo '<html><head></head><body>[OK]</body></html>';

				// send out emails
				$this->send_new_booking($payment_id);

				exit();
			}
		} else {
			$payment['Payment']['confirmation'] = date('Y-m-d H:i:s');
			$payment['Payment']['status'] = 'confirmation FAIL';
			$this->Payment->save($payment);
		}
		CakeLog::write('info', "csas confirmation of payment_id: $csas[merchantref]: [FAIL]");
		echo '[FAIL]';
		exit();
	}

	private function confirmation_check($csas, $payment) {
		$requirements = array(
			'merchantid'   => Configure::read('csas.merchantid'),
			'amountcents'  => $payment['Payment']['amountcents'],
			'currencycode' => $payment['Payment']['currencycode'],
			'password'     => Configure::read('csas.password'),
		);
		$diff = array_diff_assoc($requirements, $csas);
		if (!$diff) {
			return true;
		}
		CakeLog::write('info', '[OUR CONFIRMATION FAIL] missing items in csas data:' . var_export($diff, true));
		return false;
	}

	/*
	 * REJECTION POST
	 * called by csas - payment rejected
	 */
	public function reject() {
		$csas    = $this->request->data;
		CakeLog::write('info', '[rejection] of payment_id: $csas[merchantref]: ' . var_export($csas, true));
		$payment = @$this->Payment->findById($csas['merchantref']);
		if (!$payment) { echo 'Error: Payment id not found.'; exit(); }

		$payment['Payment']['rejection'] = date('Y-m-d H:i:s');
		$payment['Payment']['error'] = $csas['errorcode'] . ':' . $csas['errorstring'];
		$payment['Payment']['status'] = 'rejected';
		$booking = array(
			'id' => $payment['Booking']['id'],
			'status' => 'rejected'
		);
		CakeLog::write('info', 'saving: ' . var_export($booking, true));

		$this->Payment->save($payment);
		$this->Booking->save($booking);

		echo '<html><head></head><body>[OK]</body></html>';
		exit();
	}

	private function domain_correction() {
		$wanted_host  = $this->request->query('merchantvar1');
		$current_host = $_SERVER['HTTP_HOST'];
		if ($wanted_host && $wanted_host != $current_host) {
			$this->set_request_scheme();
			$url = $_SERVER['REQUEST_SCHEME'] . '://' . $wanted_host . $_SERVER['REQUEST_URI'];
			$this->redirect($url, 303);
		}
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

	public function admin_index() {
		$this->Payment->recursive = 0;
		$this->set('payments', $this->Paginator->paginate());
	}

	public function admin_view($id = null) {
		if (!$this->Payment->exists($id)) {
			throw new NotFoundException(__('Invalid payment'));
		}
		$options = array('conditions' => array('Payment.' . $this->Payment->primaryKey => $id));
		$this->set('payment', $this->Payment->find('first', $options));
	}

	public function admin_add() {
		if ($this->request->is('post')) {
			$this->Payment->create();
			if ($this->Payment->save($this->request->data)) {
				$this->Session->setFlash(__('The payment has been saved.'));
				return $this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The payment could not be saved. Please, try again.'));
			}
		}
		$bookings = $this->Payment->Booking->find('list');
		$this->set(compact('bookings'));
	}

	public function admin_edit($id = null) {
		if (!$this->Payment->exists($id)) {
			throw new NotFoundException(__('Invalid payment'));
		}
		if ($this->request->is(array('post', 'put'))) {
			if ($this->Payment->save($this->request->data)) {
				$this->Session->setFlash(__('The payment has been saved.'));
				return $this->redirect(array('action' => 'index'));
			} else {
				$this->Session->setFlash(__('The payment could not be saved. Please, try again.'));
			}
		} else {
			$options = array('conditions' => array('Payment.' . $this->Payment->primaryKey => $id));
			$this->request->data = $this->Payment->find('first', $options);
		}
		$bookings = $this->Payment->Booking->find('list');
		$this->set(compact('bookings'));
	}

	public function admin_delete($id = null) {
		$this->Payment->id = $id;
		if (!$this->Payment->exists()) {
			throw new NotFoundException(__('Invalid payment'));
		}
		$this->request->allowMethod('post', 'delete');
		if ($this->Payment->delete()) {
			$this->Session->setFlash(__('The payment has been deleted.'));
		} else {
			$this->Session->setFlash(__('The payment could not be deleted. Please, try again.'));
		}
		return $this->redirect(array('action' => 'index'));
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

	private function get_receipt_data($payment) {
		$currency = $payment['Payment']['currency'];
		$amount   = $payment['Payment']['amountcents'] / 100;
		$prices   = Configure::read('csas.prices');
		$price    = sprintf($prices[$currency]['format'], $amount);
		$logo     = '/images/logo.png';
		App::import('Vendor', array('file' => 'autoload'));
		$start    = new \Moment\Moment($payment['Booking']['start'], 'Europe/London');
		$end      = new \Moment\Moment($payment['Booking']['end'],   'Europe/London');

		// adjust timezone
		$tz_def = array(
			'en' => array(
				'America/New_York' => 'US Eastern Time',
				'Europe/London' => 'Greenwich Mean Time',
				'Europe/Prague' => 'Central European Time',
			),
			'cs' => array(
				'America/New_York' => 'Východoamerický čas',
				'Europe/London' => 'Greenwichský čas',
				'Europe/Prague' => 'Středoevropský čas',
			)
		);
		$tz = $payment['Booking']['Person']['timezone'];

		// do not use Config.language as it is called by csas on the .cz domain only
		// use the persons locale!
		$locale = $payment['Booking']['Person']['locale'];
		$locale_language = reset(explode('_', $locale));
		$format_def = array(
			'en_US' => 'D F d, Y \a\t H:i',
			'en_GB' => 'D F d, Y \a\t H:i',
			'cs_CZ' => 'D d. F Y \v\e H:i',
			'en_CZ' => 'D F d, Y \a\t H:i',
		);
		$format = $format_def[$locale];
		$tz_desc = $tz_def[$locale_language][$tz];

		if ($locale == 'cs_CZ') {
			\Moment\Moment::setLocale($locale);
		}

		$start->setTimezone($tz);
		$end->setTimezone($tz);
		$booking_time = $start->format($format) . ' - ' . $end->format('H:i');

		// update birtdate format
		$format_def = array(
			'en_US' => 'D F d, Y',
			'en_GB' => 'D F d, Y',
			'cs_CZ' => 'D d. F Y',
			'en_CZ' => 'D F d, Y',
		);
		$format = $format_def[$locale];
		$birthdate = new \Moment\Moment($payment['Booking']['Person']['birth_date']);
		$payment['Booking']['Person']['birth_date'] = $birthdate->format($format);
		$birthdate = new \Moment\Moment($payment['Booking']['Partner']['birth_date']);
		$payment['Booking']['Partner']['birth_date'] = $birthdate->format($format);

		$data = array(
			'payment' => $payment,
			'bill_issued' => date('d/m/Y'),
			'booked_time' => $booking_time,
			'booked_tz' => $tz_desc,
			'price' => $price,
			'logo' => $logo,
		);
		return $data;
	}
}
