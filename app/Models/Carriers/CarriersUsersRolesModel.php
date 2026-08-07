<?php

namespace App\Models\Carriers;

use App\Core\CoreModel;

use Illuminate\Auth\Authenticatable;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;


class CarriersUsersRolesModel extends CoreModel{

    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

	const VIEW_OWN_PROFILE_TRUST_SCORE    = 'view_own_profile_trust_score';
	const VIEW_ASSIGNED_LOADS_TRACKING    = 'view_assigned_loads_tracking';
	const MANAGE_ELD_TRACKING_CONSENT     = 'manage_eld_tracking_consent';
	const UPLOAD_ONBOARDING_DOCS          = 'upload_onboarding_docs';
	const MANAGE_LOADS                    = 'manage_loads';
	const VIEW_PAYMENT_STATUS_HISTORY     = 'view_payment_status_history';
	const EDIT_CONTACT_DISPATCH_INFO      = 'edit_contact_dispatch_info';
	const MANAGE_PAYOUT_BANKING           = 'manage_payout_banking';
	const EDIT_LEGAL_IDENTITY_TAX_INFO    = 'edit_legal_identity_tax_info';
	const COMPLETE_KYC                    = 'complete_kyc';
	const SIGN_CARRIER_AGREEMENTS         = 'sign_carrier_agreements';
	const MANAGE_CARRIER_USERS            = 'manage_carrier_users';

	////
	const PERMISSIONS_FALSE				  = false;
	const PERMISSIONS_TRUE				  = true;
	const PERMISSIONS_VIEW_OWN			  = 'view-own';



    	protected $table = 'carriers_users_roles';
	public $timestamps = false;


    	function __construct(){
        $this->setTableIndex('row_id');
	}

    	public function format($row = false){
		if($row){
			$row->added_on_formatted 	= Carbon::parse($row->added_on)->format('d M, Y');
		}
		return $row;
	}


	public function permissions_list(){
		return [
			['key' => self::VIEW_OWN_PROFILE_TRUST_SCORE,    'value' => 'View Own Profile & Trust Score'],
			['key' => self::VIEW_ASSIGNED_LOADS_TRACKING,    'value' => 'View Assigned Loads & Tracking'],
			['key' => self::MANAGE_ELD_TRACKING_CONSENT,     'value' => 'Give / Manage ELD Tracking Consent'],
			['key' => self::UPLOAD_ONBOARDING_DOCS,          'value' => 'Upload Onboarding Docs'],
			['key' => self::MANAGE_LOADS,                    'value' => 'Manage Loads'],
			['key' => self::VIEW_PAYMENT_STATUS_HISTORY,     'value' => 'View Payment Status & History'],
			['key' => self::EDIT_CONTACT_DISPATCH_INFO,      'value' => 'Edit Contact / Dispatch Info'],
			['key' => self::MANAGE_PAYOUT_BANKING,           'value' => 'Set Up / Edit Payout & Banking (DT Pay)'],
			['key' => self::EDIT_LEGAL_IDENTITY_TAX_INFO,    'value' => 'Edit Legal Identity / W-9 / Tax Info'],
			['key' => self::COMPLETE_KYC,                    'value' => 'Complete KYC (Didit Liveness)'],
			['key' => self::SIGN_CARRIER_AGREEMENTS,         'value' => 'Sign Carrier Agreements'],
			['key' => self::MANAGE_CARRIER_USERS,            'value' => 'Add & Edit Carrier Users'],
		];
	}

	public function permissions_types(){
		return [
			['key' => self::PERMISSIONS_FALSE,        'value' => 'No'],
			['key' => self::PERMISSIONS_TRUE,         'value' => 'Yes'],
			['key' => self::PERMISSIONS_VIEW_OWN,      'value' => 'View Own'],
		];
	}

	
}
