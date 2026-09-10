<?php

namespace App\Http\Controllers\Api\V1\CarrierPortal;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\CarrierPortal\CarrierUpdateProfilePhotoRequest;
use App\Http\Resources\CarrierUserResource;
use App\Services\Carrier\CarrierAccountService;
use App\Services\Carrier\CarrierPortalProfileService;
use Illuminate\Http\Request;

/**
 * The carrier portal's "My Profile" screen: the person, the company card and
 * the onboarding completeness checklist behind the percentage.
 *
 * Readable by every seat — it is the carrier's own record. The only field the
 * screen may write is the profile picture: identity, authority and company
 * details come from FMCSA and from the onboarding the broker holds, so a
 * carrier editing them here would only be editing a copy. Passwords are
 * changed through CarrierAuthController::changePassword.
 */
class CarrierProfileController extends BaseController
{
    public function __construct(
        protected CarrierPortalProfileService $carrierPortalProfileService,
        protected CarrierAccountService $carrierAccountService
    ) {}

    public function show(Request $request)
    {
        return $this->success(
            $this->carrierPortalProfileService->for($request->user())
        );
    }

    public function updatePhoto(CarrierUpdateProfilePhotoRequest $request)
    {
        $carrierUser = $this->carrierAccountService->updateProfilePhoto(
            $request->user(),
            $request->file('profile_image')
        );

        return $this->success(
            new CarrierUserResource($carrierUser),
            'Profile picture updated.'
        );
    }

    public function deletePhoto(Request $request)
    {
        $carrierUser = $this->carrierAccountService->removeProfilePhoto(
            $request->user()
        );

        return $this->success(
            new CarrierUserResource($carrierUser),
            'Profile picture removed.'
        );
    }
}
