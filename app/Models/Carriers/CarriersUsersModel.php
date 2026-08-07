<?php

namespace App\Models\Carriers;

use App\Core\CoreModel;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Auth\Authenticatable;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\URL;

use App\Models\CMS\CMSEmailModel;
use App\Models\Subscriptions\SubscriptionPlansModel;

use App\Models\carriers\CarriersUsersRolesModel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Mail;




class CarriersUsersModel extends CoreModel
{
    /**
     * Lives in the carrier database on EC2, not the local database.
     */
    protected $connection = 'external_db';

	use Authenticatable, HasApiTokens, Notifiable;
    	const STATUS_ENABLED = 1;
	const STATUS_DISABLED = 0;

    	protected $table = 'carriers_users';
	public $timestamps = false;

    	function __construct(){
        $this->setTableIndex('row_id');
	}
	
	public function roles_row(){
        return $this->belongsTo(\App\Models\carriers\CarriersUsersRolesModel::class, 'roles', 'row_id');
     }

    public function format($user = false){

		if($user){
			$user->added_on_formatted 	= Carbon::parse($user->added_on)->format('d M, Y');


			$user->first_name = ucwords(clean_display($user->first_name));
			$user->last_name = ucwords(clean_display($user->last_name));

			$user->profile_pic_url = '';
			if($user->profile_pic != ''){
                	$user->profile_pic_url = Storage::disk('public')->url('uploads/profile_pic/' . clean_display($user->profile_pic));
			}

			$user->role_names = '';
			if($user->roles != ''){
				$roleRow = CarriersUsersRolesModel::where('row_id', $user->roles)->first();
				if(isset($roleRow->id)){
					$user->role_names = $roleRow->role_title;
				}

				if(!isset($user->roles_row->id)){
					$user->roles_row = $roleRow;
				}

			}

			
			unset($user->password);
		}

		return $user;
	}


    

	public function users_list($request, $user){
        $query = self::with(['roles_row'])->where('users_of', $user['row_id'])->orderBy('id', 'desc');
        //$query = $query->get();
        return $query;
	}

	public function user_save_before($post = [], $action = '', $fields = [], $user = false, $account_token = ''){

		$return = [];

		$return['carrier_id'] = $user['carrier_id'];
		$return['status'] = 1;

		if(isset($post['password'])){
            $password = Hash::make(trim($post['password']));
            $return['password'] = ($password);
		}

		return $return;
	}

	public function user_invite_before($post = [], $action = '', $fields = [], $user = false, $account_token = ''){

		$return = [];

		$return['users_of'] = $user['row_id'];
		$return['add_type'] = 'invite';
		$return['status'] = 1;

		if(isset($post['password'])){
            $password = Hash::make(trim($post['password']));
            //$return['password'] = ($password);
		}

		return $return;
	}

	public function user_invite_after($request, $post_data = [], $row_id = false, $action = 'save', $user = false){
		$user = self::where('row_id', $row_id)->first();
		if(isset($user->row_id) && $user->add_type=='invite' && $user->send_invite==0){
			$user		= $this->format($user);
			$name		= $user->first_name.' '.$user->last_name;
			$role_names	= $user->role_names;
			$email		= $user->email;
			$password_read	= rand(1000, 9999);

			$password = Hash::make(trim($password_read));

			$inviteLink	= '';
			$year		= date('Y');

			$html = '<!DOCTYPE html>
					<html>
					<head>
					<meta charset="utf-8">
						<title>DollarTraq Invitation</title>
					</head>
					<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;">

					<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:40px 0;">
					<tr>
						<td align="center">

							<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;">
								
								<!-- Header -->
								<tr>
									<td style="background:#155dfc;padding:30px;text-align:center;">
										<h1 style="color:#ffffff;margin:0;font-size:28px;">DollarTraq</h1>
										<p style="color:#dce4f8;margin-top:8px;">Freight Visibility Simplified</p>
									</td>
								</tr>

								<!-- Body -->
								<tr>
									<td style="padding:40px;">
										<h2 style="color:#1a1a1a;margin-top:0;">You are Invited!</h2>

										<p style="font-size:16px;color:#555;">Hello '.$name.',</p>

										<p style="font-size:16px;color:#555;line-height:1.7;"> You have been invited to join the <strong>DollarTraq</strong> platform. Please use the details below to access your account. </p>

									<table cellpadding="10" cellspacing="0" width="100%" style="background:#f8f9fc;border-radius:8px;margin:25px 0;"> 
										<tr> 
											<td width="180"><strong>Assigned Role:</strong></td> 
											<td>'. $role_names .' </td> 
										</tr> 
										
										<tr> 
											<td><strong>Username:</strong></td> 
											<td>'. $email .' </td> 
										</tr> 

										<tr> 
											<td><strong>Password:</strong></td> 
											<td>'. $password_read .' </td> 
										</tr> 
									</table>

									<div style="text-align:center;margin:35px 0;">
										<a href="'. $inviteLink .' "
											style="background:#0F62FE;
												color:#ffffff;
												text-decoration:none;
												padding:14px 30px;
												border-radius:8px;
												display:inline-block;
												font-size:16px;
												font-weight:bold;">
												Login Here
										</a>
									</div>

									<p style="font-size:14px;color:#777;">
										If the button doesnot work, copy and paste the following URL into your browser:
									</p>

									</td>
								</tr>

								<!-- Footer -->
								<tr>
									<td style="background:#f8f9fc;padding:20px;text-align:center;">
									<p style="margin:0;color:#888;font-size:13px;">
										© '. $year .'  DollarTraq. All rights reserved.
									</p>
									</td>
								</tr>

							</table>

						</td>
					</tr>
					</table>

					</body>
					</html>';

			//$admin_email = env('ADMIN_EMAIL');
			//echo $html;
			//dd($user);
			Mail::send([], [], function ($message) use ($email, $html, $user) {
				$message->to($email)
				->subject('DollarTraq Invitation')
				->html($html);
			});

			self::where('row_id', $row_id)->update([
				'send_invite'  => 1,
				'password'  => $password,
				'updated_on' => now(),
			]);
		}
	}

	public function user_login($request, $user){
		//dd($request);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if($validator->fails()){
            return ['status' => false, 'message' => $validator->errors()];
        }

        $user = self::where('email', $request->email)->first();
		//echo $password = Hash::make(trim($request->password));
		//exit;
        if(!$user || !Hash::check($request->password, $user->password)){
            return [
                'status' => false,
                'message' => 'The provided credentials do not match our records.',
            ];
        }

        if($user->status==0){
            return [
                'status' => false,
                'message' => 'Your account is currently inactive..',
            ];
        }

        $token = $user->createToken('carrier_user_api_token')->plainTextToken;
        unset($user->password);
        unset($user->password_hash);
        unset($user->email_verified_code);
        unset($user->forgot_password_code);

	   self::where('row_id', $user->row_id)->update(['last_login' => now(),]);
        return [
            'status' => true,
            'message' => 'Account logged in successfully',
            'user' => $this->format($user),
            'account_token' => $token, 
            'token_type' => 'Bearer',
        ];
    }


    public function update_password($request, $user){
		$password = $request->password;
		$new_password = $request->new_password;
		$confirm_password = $request->confirm_password;

		$user = self::where('row_id', $user['row_id'])->first();
		//dd($new_password);
		if ($user && $password != '' && $new_password != '' && $confirm_password != '') {

			// Old password check
			if(Hash::check($password, $user->password)){

				if ($new_password === $confirm_password) {

					$user->password = Hash::make(trim($new_password));
					$user->forgot_password_code = '';
					$user->forgot_password_datetime = '0000-00-00 00:00:00';
					$user->updated_on = now();

					$user->save();

					return [
						'status'  => true,
						'message' => 'Password has been updated successfully. Please login into your account.'
					];

				} else {

					return [
						'status'  => false,
						'message' => 'Password must be same as confirm password.'
					];
				}

			} else {

				return [
					'status'  => false,
					'message' => 'Invalid Old password.',
				];
			}				

		} else {

			return [
				'status'  => false,
				'message' => 'Input fields missing.'
			];
		}
	}

	

}
