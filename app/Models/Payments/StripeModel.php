<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;

use App\Models\Customers\CustomersModel;

use Stripe\StripeClient;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\AccountLink;

class StripeModel extends Model
{
    const code = 'stripe';
	const label = 'Stripe';
	const details = 'Pay through Stripe Payment Gateway.';

	const status = 1;
	const remove = 0;

	const online = true;

	const mode = 'sandbox'; // sandbox / live

	const SANDBOX_API_KEY = 'pk_test_51TP2bME8lGA6s4DIvkKRuCU1crMqEo0NgiAfoWYTlyuLUNAlvIh6Zhqj8a3iRqLwWRlj0JO7Vfq8lXUzcEh021yQ00E3ArEngX';
	const SANDBOX_API_SECRET = 'sk_test_51TP2bME8lGA6s4DIUXXrfQ9IRjTEyanVRRdRZcloe7RC7T8UUJOI79REPnWn3tSANINSEBmP9TALcgRbMYQoT6xs00UqtLcZ5l';

	const LIVE_API_KEY = '';
	const LIVE_API_SECRET = '';

    protected $table = 'queries';

   public function get_status(){
		return self::status;
	}

	public function get_label(){
		$label = get_setting('sales_payment_methods_stripe_title');
		return $label != '' ? $label : self::label;
	}

	public function get_details(){
		$details = get_setting('sales_payment_methods_stripe_sub_title');
		return $details != '' ? $details : self::details;
	}

	public function createExpressAccount($carrier){

        list($api_secret_id, $api_secret_key) = $this->get_credentials();

		$stripe = new StripeClient($api_secret_key);

        $account = $stripe->accounts->create([
            'type' => 'express',
            'country' => 'US',
            'email' => strtolower(clean_display($carrier->email_address)),
			'business_type' => 'company',
			'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
			'company' => [
            	'name' => clean_display($carrier->legal_name),
	        ],
        ]);

        return ['status' => true, 'account_id' => $account->id];
    }

	public function createOnboardingLink($stripe_account_id = false, $connect_request_row_id){

        list($api_secret_id, $api_secret_key) = $this->get_credentials();

		try {

			$stripe = new StripeClient($api_secret_key);

			$accountLink = $stripe->accountLinks->create([
				'account' => $stripe_account_id,
				'refresh_url' => config('app.broker_app') . "/carrier/connect/" . $connect_request_row_id . "/?stripeConnect=expired",
				'return_url' => config('app.broker_app') . "/carrier/connect/" . $connect_request_row_id . "/?stripeConnect=processing",
				'type' => 'account_onboarding',
			]);

			return [
				'status' => true,
				'url' => $accountLink->url,
			];
		}catch (\Stripe\Exception\ApiErrorException $e) {
        
			return [
				'status' => false,
				'error'  => 'Stripe integration gateway error: ' . $e->getMessage(),
			];
	    }
    }

	public function expressAccountConnectStatus($stripe_account_id){

		if(!$stripe_account_id){

			return ['connected' => false, 'status' => 'no_account'];
		}

		list($api_secret_id, $api_secret_key) = $this->get_credentials();

		$stripe = new StripeClient($api_secret_key);

		try {

			$account = $stripe->accounts->retrieve($stripe_account_id, []);

			if($account->details_submitted){

				/*
				For business details matching
				*/
				// $stripeBusinessName = $stripeAccount->company->name ?? null;
    			// $myDbBusinessName = $user->company_name; // What you have stored

				return ['connected' => true, 'status' => 'active', 'charges_enabled' => $account->charges_enabled, 'payouts_enabled' => $account->payouts_enabled, 'message' => 'Your bank account has been connected successfully!'];
			}

			return ['connected' => false, 'status' => 'incomplete', 'requirements' => $account->requirements->currently_due, 'message' => 'Your bank account is not connected to Stripe.'];

		}catch(\Exception $e){

			return ['connected' => false, 'status' => 'error', 'message' => $e->getMessage()];
		}
	}

	public function payment_intent(){
		try{
		
			list($api_secret_id, $api_secret_key) = $this->get_credentials();

			$stripe = new \Stripe\StripeClient($api_secret_key);

			$setupIntent = $stripe->setupIntents->create([
				'payment_method_types' => ['card'],
			]);

			return ['status' => true, 'client_secret' => $setupIntent->client_secret];

		}catch(HttpException $ex){
			return ['status' => false, 'error' => $ex->getMessage()];
		}
	}

	public function create_order($customer, $package, $payment_method){

		list($api_secret_id, $api_secret_key) = $this->get_credentials();

		try{

			$customers_model	= new CustomersModel();

			$stripe = new \Stripe\StripeClient($api_secret_key);

			if($customer && $package){
				$amount = $package->final_price;
				$currency = 'usd';

				$stripe_customer = false;

				/* Check if customer exits */
				$stripe_customers = $stripe->customers->all(["email" => "a@t.com"]);

				if(count($stripe_customers) === 1){
					$stripe_customer = $stripe_customers->data[0];
				}

				if($stripe_customer === false){
					$stripe_customer = $stripe->customers->create([
						'email' => clean_display($customer->email), 
						'name' => clean_display($customer->name),
						'address' => [
							'line1' => clean_display($customer->address),
							'postal_code' => clean_display($customer->pincode),
							'city' => clean_display($customer->city),
							'state' => clean_display($customer->state),
							'country' => clean_display($customer->country),
						],
					]);
				}

				// print_r($stripe_customer);

				if($stripe_customer){

					$stripe->paymentMethods->attach(
						$payment_method,
						[
							'customer' => $stripe_customer->id
						]
					);

					$stripe->customers->update(
						$stripe_customer->id,
						[
							'invoice_settings' => [
								'default_payment_method' => $payment_method,
							],
						]
					);

					/* Update customer stripe id */

					if($customer->stripe_customer_id == ''){
						$customers_model->set_post_data('stripe_customer_id', $stripe_customer->id);
						$customers_model->post_update($customer->row_id);
					}
				}

				try{

					/* Check for active subscriptions */
					$active_subscriptions = $stripe->subscriptions->all([
    					'customer' => $stripe_customer->id,
						'status'   => 'active',
					]);

					/* Cancel any active subscription */
					foreach($active_subscriptions->data as $active_subscription){
						$stripe->subscriptions->cancel($active_subscription->id);
					}

					try {
    
						$subscription = $stripe->subscriptions->create([
							'customer' => $stripe_customer->id,
							'items' => [
								['price' => $package->stripe_price_id],
							],
							'payment_behavior' => 'default_incomplete',
							'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
							'expand' => ['latest_invoice.payment_intent'],
						]);


						if(isset($intent_subscription['latest_invoice'])){
							if(isset($intent_subscription['latest_invoice']['payment_intent'])){

							}else{

								$invoice_id = $subscription['latest_invoice']['id'];

								$invoice = $stripe->invoices->retrieve($invoice_id, [
									'expand' => ['payment_intent']
								]);

								file_put_contents('./payment_inte.txt', json_encode($invoice));
								
								if($invoice->payment_intent){
    
									$client_secret = $invoice->payment_intent->client_secret;
								}else{

									$client_secret = null; 
								}
							}
						}

						return ['status' => true, 'subscription' => $subscription, 'stripe_customer' => $stripe_customer];
					}catch (\Exception $e){

						return ['status' => 'error', 'error' => $e->getMessage()];
					}

					// $new_subscription = true;

					// /*
					// Check if already subscribed
					// */

					// {
					// 	$current_subscription_id = $customer->subscription_id;

					// 	if($current_subscription_id != ''){

					// 		/*
					// 		User already subscribed, switch the plan
					// 		*/

					// 		/*
					// 		Retrieve existing subscription
					// 		*/
							
					// 		$subscription = $stripe->subscriptions->retrieve($current_subscription_id);

					// 		if($subscription){

					// 			/*
					// 			Disable all the existing subscriptions
					// 			*/
					// 			$subscriptions = $stripe->subscriptions->all([
					// 				'customer' => $stripe_customer->id,
					// 				'status'   => 'active',
					// 			]);

					// 			foreach($subscriptions->data as $_subscription){

					// 				if($_subscription->id !== $current_subscription_id){
										
					// 					$stripe->subscriptions->cancel($_subscription->id);
					// 				}
					// 			}

					// 			$updated_subscription = $stripe->subscriptions->update(
					// 				$subscription->id,
					// 				[
					// 					'cancel_at_period_end' => false,
					// 					'proration_behavior' => 'always_invoice',
					// 					'items' => [
					// 						[
					// 							'id' => $subscription->items->data[0]->id,
					// 							'price' => $package->price_id,
					// 						],
					// 					],
					// 				]
					// 			);

					// 			$new_subscription = false;

					// 			return ['status' => true, 'subscription' => $updated_subscription, 'stripe_customer' => $stripe_customer, "message" => "Your subscription has been switched to the new plan."];
					// 			/*
					// 			Action subscription entry
					// 			*/

					// 			// $this->employers_subscriptions_model->set_post_data('employer_id', $employer->id);
					// 			// $this->employers_subscriptions_model->set_post_data('subscription_id', trim($subscription->id));

					// 			// $this->employers_subscriptions_model->set_post_data('package_id', $package->id);
					// 			// $this->employers_subscriptions_model->set_post_data('package_type', $package->package_type);
					// 			// $this->employers_subscriptions_model->set_post_data('points', $package->points);
					// 			// $this->employers_subscriptions_model->set_post_data('cost', $package->cost);

					// 			// $this->employers_subscriptions_model->set_post_data('subscribed_on', date("Y-m-d", $subscription->current_period_start));
					// 			// $this->employers_subscriptions_model->set_post_data('next_renewal', date("Y-m-d", $subscription->current_period_end));

					// 			// $this->employers_subscriptions_model->set_post_data('updated_on', date("Y-m-d H:i:s"));
					// 			// $this->employers_subscriptions_model->post_update($current_subscription->id);

					// 			// /*
					// 			// Subscription history entry
					// 			// */
					// 			// $this->employers_subscriptions_history_model->set_post_data('old_package_id', $current_subscription->package_id);

					// 			// /*
					// 			// Important
					// 			// */

					// 			// $client_secret = false;

					// 			// $message = "Your subscription has been switched to the new plan..";

					// 			// /*
					// 			// Current Subscription
					// 			// */
					// 			// $current_subscription = $this->employers_subscriptions_model->fetch_row_by_field('employer_id', $employer->id);
								
					// 			// if($current_subscription){

					// 			// 	$_current_subscription = $current_subscription;
					// 			// }

					// 			// $points = $this->employers_points_model->current_month_points($employer->id);
					// 		}
					// 	}
					// }
					
					// if($new_subscription){
					
					// 	/*
					// 	Monthly subscriptions
					// 	*/

					// 	$subscription = $stripe->subscriptions->create([
					// 		'customer' => $stripe_customer->id,
					// 		'items' => [
					// 			['price' => $package->stripe_price_id],
					// 		],
					// 		'payment_behavior' => 'default_incomplete',
					// 		'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
					// 		'expand' => ['latest_invoice.payment_intent'],
					// 	]);

					// 	return ['status' => true, 'subscription' => $subscription, 'stripe_customer' => $stripe_customer];
					// }
				}catch(HttpException $ex){
					return ['status' => 'error', 'error' => $ex->getMessage()];
				}
			}

		}catch(HttpException $ex){
			file_put_contents('stripe_error.txt', json_encode($ex));
			return ['status' => 'error', 'error' => $ex->getMessage()];
		}
	}

	public function add_plan($package){
		list($api_secret_id, $api_secret_key) = $this->get_credentials();
		try{
			$stripe = new \Stripe\StripeClient($api_secret_key);
			$price = $stripe->prices->create([
				'currency' => 'usd',
				'unit_amount' => $package->final_price * 100,
				'recurring' => ['interval' => 'month'],
				'product_data' => ['name' => $package->title],
			]);

			return ['status' => true, 'id' => $price->id];

		}catch(HttpException $ex){
			file_put_contents('stripe_error.txt', json_encode($ex));
			return ['status' => false, 'error' => $ex->getMessage()];
		}
	}

	public function update_plan($package){
		list($api_secret_id, $api_secret_key) = $this->get_credentials();

		try{
			$stripe = new \Stripe\StripeClient($api_secret_key);

			if($package->stripe_price_id != ''){
				try{
					$plan = $stripe->prices->retrieve($package->stripe_price_id, []);
					$price_id = $plan->id;
					if($plan->unit_amount != $package->final_price * 100){

						/* Create a new package and disable existing package */
						$stripe->prices->update($plan->id, ['active' => false]);

						$price = $stripe->prices->create([
							'currency' => 'usd',
							'unit_amount' => $package->final_price * 100,
							'recurring' => ['interval' => 'month'],
							'product_data' => ['name' => $package->title],
						]);

						$price_id = $price->id;
					}

					return ['status' => true, 'id' => $price_id];

				}catch(HttpException $ex){
					file_put_contents('stripe_error.txt', json_encode($ex));
					return ['status' => false, 'error' => $ex->getMessage()];
				}
			}			

		}catch(HttpException $ex){
			file_put_contents('stripe_error.txt', json_encode($ex));
			return ['status' => false, 'error' => $ex->getMessage()];
		}
	}

	public function get_base_url(){
		return 'https://api.stripe.com';
	}

	public function get_credentials(){
		if(self::mode == 'sandbox'){
			return [self::SANDBOX_API_KEY, self::SANDBOX_API_SECRET];
		}

		if(self::mode == 'live'){
			return [self::LIVE_API_KEY, self::LIVE_API_SECRET];
		}
	}
    
}
