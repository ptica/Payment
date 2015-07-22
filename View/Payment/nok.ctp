<style>
	p {
		color: #58585a;
		font: 400 18px Arial;
	}
	a {
		color: #002fff;
	}
	h1 {
		margin-bottom: 45px;
		margin-top: 45px;
	}
</style>

<div>
	<h1>Payment not successful</h1>

	<?php 
		$payment_url = Router::url("/pay/$booking_id/$token");
	?>
	<p>Please <a href="<?php echo $payment_url; ?>">click here</a> to go back to the payment page once again<br>
		either to use a different payment card or to correct the payment details.</p>
</div>
