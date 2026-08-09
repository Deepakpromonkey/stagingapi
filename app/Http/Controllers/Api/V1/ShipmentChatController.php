<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ShipmentMessageSent;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\ShipmentMessage;
use App\Models\ShipmentMessage as Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The broker/driver chat for a shipment, serving both sides.
 *
 * One controller rather than one per audience, because the thread and the rules
 * around it are identical — only the identity of the sender and the way the
 * shipment is reached differ, and both of those are settled in one place by
 * resolveShipment(). Splitting it would mean two implementations of "who may
 * read this conversation", which is exactly the rule that must not be allowed
 * to differ between the two.
 */
class ShipmentChatController extends BaseController
{
    /** The thread, oldest first. */
    public function index(Request $request, string $uuid)
    {
        [$shipment, $senderType] = $this->resolveShipment($request, $uuid);

        if (! $shipment) {
            return $this->error('Shipment not found.', null, 404);
        }

        $messages = $shipment->messages()->get();

        // Opening the thread marks what the other side said as read. Only the
        // other side's messages: marking your own would be meaningless and
        // would clear the counterpart's unread badge.
        $this->markRead($shipment, $senderType);

        return $this->success([
            'shipment' => [
                'uuid' => $shipment->uuid,
                'shipment_no' => $shipment->shipment_no,
                'driver_name' => $shipment->driver_name ?? null,
                'carrier_name' => $shipment->carrier_name,
            ],
            'viewer' => $senderType,
            'messages' => $messages->map->toWire()->values(),
        ], 'Messages retrieved.');
    }

    /** Posts a message and pushes it to the other side. */
    public function store(Request $request, string $uuid)
    {
        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        [$shipment, $senderType] = $this->resolveShipment($request, $uuid);

        if (! $shipment) {
            return $this->error('Shipment not found.', null, 404);
        }

        $sender = $request->user();

        $message = ShipmentMessage::create([
            'uuid' => (string) Str::uuid(),
            'shipment_id' => $shipment->id,
            'sender_type' => $senderType,
            'sender_id' => $sender->id,
            'sender_name' => $this->nameOf($sender),
            'body' => trim($request->input('body')),
        ]);

        /*
        | Broadcast to everyone on the shipment channel except the socket that
        | sent it — that client already rendered the message optimistically, and
        | echoing it back would show it twice. `toOthers()` reads the
        | X-Socket-Id header the client sends.
        */
        // setRelation rather than a reload: the event needs the shipment for the
        // channel name and we already hold it.
        $message->setRelation('shipment', $shipment);

        broadcast(new ShipmentMessageSent($message))->toOthers();

        return $this->success($message->toWire(), 'Message sent.', 201);
    }

    /**
     * Finds the shipment and establishes which side is asking.
     *
     * This is the authorisation boundary for the whole conversation. A broker
     * reaches their own company's shipments; a driver reaches only shipments
     * their phone number was named on. A caller who is neither — a carrier
     * portal token, say — gets nothing.
     *
     * @return array{0: ?Shipment, 1: ?string}
     */
    private function resolveShipment(Request $request, string $uuid): array
    {
        $user = $request->user();

        if ($user instanceof User) {
            $shipment = Shipment::where('uuid', $uuid)
                ->where('company_id', $user->company_id)
                ->first();

            return [$shipment, Message::SENDER_BROKER];
        }

        if ($user instanceof Driver) {
            if (! $user->is_active) {
                return [null, null];
            }

            $shipment = Shipment::where('uuid', $uuid)
                ->forDriverPhone($user->phone_e164)
                ->first();

            return [$shipment, Message::SENDER_DRIVER];
        }

        return [null, null];
    }

    /** Marks the counterpart's messages as read for this viewer. */
    private function markRead(Shipment $shipment, string $viewerType): void
    {
        $counterpart = $viewerType === Message::SENDER_BROKER
            ? Message::SENDER_DRIVER
            : Message::SENDER_BROKER;

        $shipment->messages()
            ->where('sender_type', $counterpart)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function nameOf($sender): ?string
    {
        if ($sender instanceof User) {
            return trim($sender->first_name.' '.$sender->last_name) ?: null;
        }

        if ($sender instanceof Driver) {
            return $sender->name ?: 'Driver';
        }

        return null;
    }
}
