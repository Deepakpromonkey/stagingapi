<?php
namespace App\Http\Controllers\DtPay;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Log;

use App\Models\User;

use App\Models\Payments\StripeModel;
use Stripe\StripeClient;

class DtPayProfileController extends Controller
{

    public function init(Request $request, StripeModel $stripe_model, User $user_model){

        $user = $request->user();

        if($user){
        
            list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();

            $stripe = new StripeClient($api_secret_key);

            $stripe_customer_id = $user->stripe_customer_id;

            if($stripe_customer_id == ''){

                /*
                Create new stripe account
                */
                
                try {
                
                    $customer = $stripe->customers->create([
                        'email' => $user->email,
                        'name' => $user->first_name . " " . $user->last_name,
                    ]);

                    $stripe_customer_id = $customer->id;

                    /*
                    Update user stripe customer id
                    */
                    $user_model->where('uuid', $user->uuid)->update(['stripe_customer_id' => $stripe_customer_id]);

                }catch (\Stripe\Exception\ApiErrorException $e) {
        
                    Log::error('Action: Stripe customer registration. Stripe integration gateway error: ' . $e->getMessage());

                    return ['status' => false, 'error' => 'Stripe integration gateway error.'];
                }
            }

            if($stripe_customer_id != ''){

                $sources = [];

                $cards = $stripe->paymentMethods->all([
                    'customer' => $stripe_customer_id,
                    'type' => 'card',
                ]);

                foreach($cards->data as $method){

                    $sources[] = ['type' => 'card', 'key' => $method->id, 'label' => strtoupper($method->card->display_brand) . "...-" . $method->card->last4, 'sub_label' => '2.9% funding fee · instant clearing'];
                }

                $paymentMethods = $stripe->paymentMethods->all([
                    'customer' => $stripe_customer_id,
                    'type' => 'us_bank_account', 
                ]);

                foreach($paymentMethods->data as $method){

                    $sources[] = ['type' => 'bank', 'key' => $method->id, 'label' => $method->us_bank_account->bank_name . "...-" . $method->us_bank_account->last4, 'sub_label' => 'Free · ACH debit enabled'];
                }
                
                return ['status' => true, 'sources' => $sources];
            }
        }

        return ['status' => false, 'message' => "Stripe account not setup!"];
    }

    public function attachPaymentMethods(Request $request, User $user_model, StripeModel $stripe_model){

        $user = $request->user();
        $type = $request->post('type');

        if($user){

            list($api_secret_id, $api_secret_key) = $stripe_model->get_credentials();
    
            $stripe = new StripeClient($api_secret_key);

            $stripe_customer_id = $user->stripe_customer_id;

            if($stripe_customer_id == ''){

                /*
                Create new stripe account
                */
                
                try {
                
                    $customer = $stripe->customers->create([
                        'email' => $user->email,
                        'name' => $user->first_name . " " . $user->last_name,
                    ]);

                    $stripe_customer_id = $customer->id;

                    /*
                    Update user stripe customer id
                    */
                    $user_model->where('uuid', $user->uuid)->update(['stripe_customer_id' => $stripe_customer_id]);

                }catch (\Stripe\Exception\ApiErrorException $e) {
        
                    Log::error('Action: Stripe customer registration. Stripe integration gateway error: ' . $e->getMessage());

                    return ['status' => false, 'error' => 'Stripe integration gateway error.'];
                }
            }

            if($type == 'card'){
            
                $setupIntent = $stripe->setupIntents->create([
                    'customer' => $stripe_customer_id,
                    'payment_method_types' => ['card'],
                    'usage' => 'off_session'
                ]);
            }else{

                $setupIntent = $stripe->setupIntents->create([
                    'customer' => $stripe_customer_id,
                    'payment_method_types' => ['us_bank_account'],
                    'payment_method_options' => [
                        'us_bank_account' => [
                            'financial_connections' => [
                                'permissions' => [
                                    'payment_method'
                                ]
                            ]
                        ]
                    ]
                ]);
            }

            return response()->json(['status' => true, 'clientSecret' => $setupIntent->client_secret], 200);
        }

        return response()->json(['status' => false, 'message' => 'Unauthorized access'], 500);
    }
}
