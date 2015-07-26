<div>
	<h1>MTM 2015 Registration Payment</h1>
	
	<div class="row" style="margin-top: 45px;">
		<div class="col-md-9">
			<?php echo $this->Form->create('Booking', array('role'=>'form', 'class'=>'form-horizontal')); ?>
				<div class="form-group" style="margin-top:-15px; color: gray;">
					<label for="QueryQuery" class="col-sm-2 control-label"></label>
					<div class="col-sm-8 input-group">
						<p class="form-control-static">
							Please review your registration details.<br>
							You may edit some of the details (not affecting price)
							<a href="<?php echo Router::url('/edit/'.$this->request->params['pass'][1]);?>"><b>here</b></a>.<br>
							Once everything is ok please proceed to the payment.
						</p>
						<p class="form-control-static">
							To confirm your booking, you need to make the payment <b style="color:#000">before July 31</b>.	
						</p>
					</div>
				</div>
			
				<div class="form-group">
					<label for="BookingName" class="col-sm-2 control-label">Your Name</label>
					<p class="form-control-static">
						<?php echo $booking['Booking']['name']; ?>
					</p>
				</div>

				<div class="form-group">
					<label for="BookingInstitution" class="col-sm-2 control-label">Institution</label>
					<p class="form-control-static">
						<?php echo $booking['Booking']['institution']; ?>
					</p>
				</div>

				<div class="form-group">
					<label for="BookingCountry" class="col-sm-2 control-label">Country</label>
					<p class="form-control-static">
						<?php echo $booking['Booking']['country']; ?>
					</p>
				</div>
				

				<div class="form-group">
					<label for="BookingAddress" class="col-sm-2 control-label">Address</label>
					<div class="col-sm-8 input-group">
						<textarea disabled="disabled" name="data[Booking][address]" class="form-control" placeholder="Address" cols="30" rows="6" id="BookingAddress"><?php echo $booking['Booking']['address']; ?></textarea>
					</div>
				</div>

				<?php if ($booking['Booking']['room_id']) { ?>
					<?php
						$location_id   = $this->request->data['Room']['location_id'];
						$room_name     = $this->request->data['Room']['name'];
						$location_name = $locations[$location_id];
					?>
					<div class="form-group">
						<label for="BookingBeds" class="col-sm-2 control-label">Beds</label>
						<p class="form-control-static">
							<?php echo $this->request->data['Booking']['beds']; ?>
						</p>
					</div>
					<div class="form-group">
						<label for="BookingLocation" class="col-sm-2 control-label">Location</label>
						<p class="form-control-static">
							<?php echo "$room_name @ $location_name"; ?>
						</p>
					</div>
					<div class="form-group" style="margin-top:-15px; color: gray;">
						<label for="QueryQuery" class="col-sm-2 control-label"></label>
						<div class="col-sm-8 input-group">
							<p class="form-control-static">
								<?php echo $location_desc[$location_id]; ?>
							</p>
						</div>
					</div>
				<?php } ?>
				
				<div class="form-group">
					<label for="BookingStart" class="col-sm-2 control-label">Arrival</label>
					<p class="form-control-static">
						<?php echo $this->Time->format($booking['Booking']['start'], '%-d.%-m. %Y'); ?>
					</p>
				</div>
				<div class="form-group">
					<label for="BookingEnd" class="col-sm-2 control-label">Departure</label>
					<p class="form-control-static">
						<?php echo $this->Time->format($booking['Booking']['end'], '%-d.%-m. %Y'); ?>
					</p>
				</div>
				<div class="form-group">
					<label for="BookingEmail" class="col-sm-2 control-label">Email</label>
					<p class="form-control-static">
						<?php echo $booking['Booking']['email']; ?>
					</p>
				</div>
				<div class="form-group">
					<label for="BookingFellowEmail" class="col-sm-2 control-label">Room Fellows</label>
					<p class="form-control-static">
						<?php echo $booking['Booking']['fellow_email']; ?>
					</p>
				</div>
				
				<?php if (!empty($booking['Booking']['Upsell'])) { ?>
					<div class="form-group">
						<?php echo $this->Form->input('Upsell', array('multiple'=>'checkbox', 'class'=>'form-control', 'placeholder'=>__('Fellow Email')));?>
					</div>
				<?php } ?>
				<div class="form-group">
					<?php echo $this->Form->input('Meal', array('label'=>'Lunches', 'disabled'=>'disabled', 'multiple'=>'checkbox', 'class'=>'form-control', 'placeholder'=>__('Meals')));?>
				</div>
				<div class="form-group">
					<?php echo $this->Form->input('Query', array('label'=>'MTM Content', 'disabled'=>'disabled', 'multiple'=>'checkbox', 'class'=>'form-control', 'placeholder'=>__('Queries')));?>
				</div>
				
				<div class="form-group totalPriceDiv">
					<label for="UpsellUpsell" class="col-sm-2 control-label">Total price</label>
					<div class="col-sm-7 input-group totalPrice"><span class="glyphicon glyphicon-tag"></span>&nbsp;&nbsp;<?php echo $params['AMOUNT'] / 100; ?> CZK</div>
				</div>
		
				<div class="form-group" style="margin-top:15px; color: gray;">
                                        <label for="QueryQuery" class="col-sm-2 control-label"></label>
                                        <div class="col-sm-8 input-group">
                                                <!--p class="form-control-static">
							If you need to modify paid parts of the registration or cancel your booking altogether, please contact Anna Kotesovcova (<a href="mailto:kotesovcova@ufal.mff.cuni.cz">kotesovcova@ufal.mff.cuni.cz</a>).
							Note that cancellation fees apply:
							<ul>
								<li>bank fee (2 % of the price applies always),</li>
								<li>accommodation (up to 100 % if cancelled too late; depends on the accommodation),</li>
								<li>lunches (no cancellation fee if cancelled before August 31).</li>
							</ul>
                                                </p-->
                                                <p class="form-control-static">
							If you need to modify paid parts of the registration or cancel your booking altogether, please contact Anna Kotesovcova (<a href="mailto:kotesovcova@ufal.mff.cuni.cz">kotesovcova@ufal.mff.cuni.cz</a>).<br>
							Cancellation policy:
							<ul>
							    <li>75% of the accommodation fee will be charged when cancelled till July 31;
							    <li>100% of accommodation fee after July 31.
							    <li>75% of lunches fee when cancelled after August 31.
							</ul>
						</p>
                                        </div>
                                </div>
				
			</form>

		</div><!-- end col md 12 -->
	</div><!-- end row -->

	
	
	<form id="payment_post" name="MERCHANTFORM" method="post" action="<?php echo Configure::read('gp.gateway_url'); ?>">
		<?php
			foreach ($params as $key => $value) {
				echo "<input type=\"hidden\" name=\"$key\" value=\"$value\" />\n";
			}
		?>
		<div class="form-group">
			<div class="col-sm-offset-2 col-sm-8" style="margin-bottom:75px">
				<div class="submit"><input class="btn btn-primary btn-lg" style="width:390px" type="submit" value="Proceed to Payment"></div>
			</div>
		</div>
	</form>
	
</div>
