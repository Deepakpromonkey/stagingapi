<?php

namespace App\Services\Shipment;

use App\Models\Shipment;
use App\Support\LoadWindow;
use Illuminate\Validation\ValidationException;

/**
 * Whether a load's drivers are free for it: not already on another open load
 * of the same company at an overlapping time.
 *
 * A driver is their phone number. The broker types it into one of a load's
 * three driver slots, and the driver app shows a driver every load whose
 * number ends in the same ten digits as theirs (the driver API's
 * ResolvesDriverLoads) - the rule used here too, so "this driver is busy"
 * means exactly "the driver app would show them both loads". An ELD load is
 * also held by the fleet driver it was booked with, phone or no phone.
 *
 * Only the company's own loads count: another broker's bookings are theirs,
 * and refusing a load because of one would tell this broker about it.
 */
class DriverAvailability
{
    /** A load lets go of its drivers once it is over. */
    private const OVER = ['completed', 'cancelled'];

    /**
     * Refuses - as a 422 the booking forms show as-is - a load whose drivers
     * are on another of the company's open loads at an overlapping time.
     *
     * A load with no dates yet can't overlap anything, so it passes.
     *
     * @param  array<int, mixed>  $phones  the load's driver phones, as typed
     *
     * @throws ValidationException
     */
    public function assertFree(
        ?int $companyId,
        array $phones,
        ?int $eldDriverId,
        ?LoadWindow $window,
        ?int $exceptShipmentId = null
    ): void {
        if ($window === null) {
            return;
        }

        $conflict = $this->conflict($companyId, $phones, $eldDriverId, $window, $exceptShipmentId);

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'driver_schedule' => [self::message($conflict['load'], $conflict['window'], $conflict['phone'])],
            ]);
        }
    }

    /**
     * The first (oldest) of the company's open loads that shares a driver with
     * the load in $window and overlaps it, or null.
     *
     * 'phone' is the shared number as typed on the new load; null when the two
     * share the ELD driver rather than a number.
     *
     * @param  array<int, mixed>  $phones  the load's driver phones, as typed
     * @return array{load: Shipment, window: LoadWindow, phone: ?string}|null
     */
    public function conflict(
        ?int $companyId,
        array $phones,
        ?int $eldDriverId,
        LoadWindow $window,
        ?int $exceptShipmentId = null
    ): ?array {
        // Last ten digits => the number as typed, each driver once.
        $drivers = [];

        foreach ($phones as $phone) {
            $suffix = self::phoneSuffix($phone);

            if ($suffix !== null) {
                $drivers[$suffix] ??= trim((string) $phone);
            }
        }

        if ($companyId === null || ($drivers === [] && $eldDriverId === null)) {
            return null;
        }

        /*
        | Only a load with a date on or after this one's start can overlap it.
        | Dates are compared as stored, in each load's own zones, so two days
        | of slack cover any offset; the exact check is overlaps() below.
        */
        $since = $window->start->utc()->subDays(2)->toDateString();

        $loads = Shipment::query()
            ->with('stops')
            ->where('company_id', $companyId)
            ->whereNotIn('status', self::OVER)
            ->when($exceptShipmentId !== null, fn ($query) => $query->whereKeyNot($exceptShipmentId))
            ->where(function ($query) use ($since) {
                $query->whereHas('stops', function ($stops) use ($since) {
                    $stops->where(function ($dated) use ($since) {
                        $dated->where('start_date', '>=', $since)
                            ->orWhere('end_date', '>=', $since);
                    });
                })
                    ->orWhere('pickup_date', '>=', $since)
                    ->orWhere('delivery_date', '>=', $since);
            })
            ->orderBy('id')
            ->get();

        foreach ($loads as $load) {
            $phone = null;

            foreach ([$load->driver_phone_1, $load->driver_phone_2, $load->driver_phone_3] as $theirs) {
                $suffix = self::phoneSuffix($theirs);

                if ($suffix !== null && isset($drivers[$suffix])) {
                    $phone = $drivers[$suffix];

                    break;
                }
            }

            $sameEldDriver = $eldDriverId !== null
                && $load->eld_driver_id !== null
                && (int) $load->eld_driver_id === $eldDriverId;

            if ($phone === null && ! $sameEldDriver) {
                continue;
            }

            // An ELD load's times are its pickup and delivery - it has no
            // stops. Any other load's are its trip sheet's stops; one whose
            // stops were never saved (a save given up on half-way) is not a
            // trip anyone can drive, and holds nobody.
            $theirWindow = $load->tracking_method === 'eld'
                ? LoadWindow::fromPickupAndDelivery($load)
                : LoadWindow::fromStops($load->stops);

            if ($theirWindow !== null && $window->overlaps($theirWindow)) {
                return ['load' => $load, 'window' => $theirWindow, 'phone' => $phone];
            }
        }

        return null;
    }

    /**
     * "The driver on (555) 010-2020 is already on load SHP-2026-000059
     * (PRO 12345) from Oct 14, 2026 9:00 AM CDT to Oct 15, 2026 5:00 PM EDT.
     * Change this load's dates, or assign another driver."
     */
    public static function message(Shipment $load, LoadWindow $window, ?string $phone): string
    {
        $driver = $phone !== null ? "The driver on {$phone}" : 'This driver';

        $name = $load->shipment_no.(filled($load->pro_number) ? " (PRO {$load->pro_number})" : '');

        return "{$driver} is already on load {$name} {$window->describe()}. "
            ."Change this load's dates, or assign another driver.";
    }

    /**
     * The last ten digits of a phone number, or null when there is no
     * plausible number there - the driver API's rule for matching a driver
     * to a load.
     */
    private static function phoneSuffix(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_int($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
