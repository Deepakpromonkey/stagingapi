<?php

namespace App\Models\Carriers;

use App\Modules\Base\Models\BaseModel;
use Illuminate\Support\Facades\DB;

use App\Models\Carriers\CarrierAuthoritiesModel;

use App\Models\Connect\CarrierConnectRequestsModel;

use App\Models\Payments\StripeModel;

class CarriersModel extends BaseModel
{
	
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

	protected $table = 'carriers';
	public $timestamps = false;

	function __construct(){
	
		$this->setTableIndex('row_id');
	}

	public function format($carrier = false){

		if($carrier){

			$carrier->dot_number = clean_display($carrier->dot_number);

			$carrier->legal_name = ucwords(clean_display(strtolower($carrier->legal_name)));
			$carrier->dba_name = ucwords(clean_display($carrier->dba_name));

			$carrier->email_address = strtolower(clean_display($carrier->email_address));
		}

		return $carrier;
	}

	public function express_account($request, $user){

		$stripe_model = new StripeModel;

		$email = strtolower('WYATES@TGANDP.COM');
		$row_id = 'qTPzzEW16GY4TnlobqjY';

		$response = $stripe_model->createExpressAccount($email);

		if($response['status'] == true){

			$account_id = $response['account_id'];

			$this->set_post_data('stripe_express_account', $account_id);
			$this->post_update($row_id);

			$_response = $stripe_model->createOnboardingLink($account_id);

			if($_response['status']){

				return ['status' => true, 'url' => $_response['url']];
			}else{

				return ['status' => false, 'error' => $_response['error']];
			}
		}
	}
}
