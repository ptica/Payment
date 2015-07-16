<?php
	debug($params);
?>

<div>
	<div class="highlight">
		<h1>Payment</h1>
		<div class="total">
			MTM 2015 Registration
			<span class="price"><?php echo $params['AMOUNT'] / 100;?> CZK</span>
		</div>
	</div>
	<form id="payment_post" name="MERCHANTFORM" method="post" action="https://test.3dsecure.gpwebpay.com/kb/order.do">
		<?php
			foreach ($params as $key => $value) {
				echo "<input type=\"hidden\" name=\"$key\" value=\"$value\" />\n";
			}
		?>
		<input type="submit" value="TO PAYMENT">
	</form>
	
	<a href="<?php echo $gp_url ?>">link to payment via GET</a>
</div>