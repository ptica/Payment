<?php
App::uses('AppModel', 'Model');
class Payment extends PaymentAppModel {
	public $displayField = 'id';

	public $belongsTo = array(
		'Booking' => array(
			'className' => 'Booking',
			'foreignKey' => 'booking_id',
			'conditions' => '',
			'fields' => '',
			'order' => ''
		)
	);
}
