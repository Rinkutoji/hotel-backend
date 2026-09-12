<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * STEP 16 - invoice issuance. generateForPayment() is called INSIDE an
 * existing DB transaction (PaymentService::verify); this service starts no
 * transaction of its own - the caller owns the boundary, exactly like
 * BookingService::confirmWithinTransaction/refundBooking. Money and the
 * invoice number are always server-computed, never read from client input.
 */
class InvoiceService
{
    private const INVOICE_NUMBER_PREFIX = 'INV-';

    private const INVOICE_NUMBER_MAX_RETRIES = 3;

    /**
     * Create the one-and-only immutable invoice for a payment, with
     * persisted billing line-item snapshots (D-3) taken from the booking's
     * booking_rooms.price_per_night x nights. total_amount is the payment's
     * locked amount (= bookings.total_amount) - the project's money
     * invariant. Runs inside the caller's transaction; a later failure
     * rolls the invoice and its items back together with the payment
     * verification.
     */
    public function generateForPayment(Payment $payment): Invoice
    {
        $invoice = new Invoice(['invoice_number' => $this->generateInvoiceNumber()]);
        $invoice->payment_id = $payment->id;
        $invoice->total_amount = $payment->amount;
        $invoice->issued_at = now();
        $invoice->save();

        $nights = $this->nightsFor($payment->booking);

        foreach ($payment->booking->bookingRooms as $bookingRoom) {
            $pricePerNightCents = (int) round(((float) $bookingRoom->price_per_night) * 100);

            $invoice->items()->create([
                'room_number' => (string) ($bookingRoom->room?->room_number ?? ''),
                'room_type_name' => (string) ($bookingRoom->roomType?->name ?? ''),
                'price_per_night' => $bookingRoom->price_per_night,
                'nights' => $nights,
                'line_total' => $this->formatCents($pricePerNightCents * $nights),
            ]);
        }

        return $invoice->fresh(['payment.booking.customer', 'items']);
    }

    private function nightsFor(?Booking $booking): int
    {
        $checkIn = $booking?->check_in_date?->startOfDay();
        $checkOut = $booking?->check_out_date?->startOfDay();

        if ($checkIn === null || $checkOut === null) {
            return 0;
        }

        return (int) $checkIn->diffInDays($checkOut);
    }

    private function formatCents(int $cents): string
    {
        // Integer-only formatting, mirroring BookingService::formatCents:
        // sprintf avoids reintroducing floats at render time.
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function generateInvoiceNumber(): string
    {
        // Byte-for-byte the generateBookingNumber() convention
        // (BookingService:558-573): prefix + 8 uppercase random, bounded
        // retry, UNIQUE constraint as the backstop.
        $attempts = 0;

        do {
            $number = self::INVOICE_NUMBER_PREFIX . strtoupper(Str::random(8));
            $exists = Invoice::where('invoice_number', $number)->exists();
            $attempts++;

            if (! $exists) {
                return $number;
            }
        } while ($attempts < self::INVOICE_NUMBER_MAX_RETRIES);

        throw new \RuntimeException('Failed to generate a unique invoice number.');
    }
}