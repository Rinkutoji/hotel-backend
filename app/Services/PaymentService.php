<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * STEP 10 - the Payment module. Money, status, verification fields and
 * receipt paths are NEVER read from client input and are deliberately not
 * mass-assignable on the model; every write below is a direct property
 * assignment inside a DB transaction.
 */
class PaymentService
{
    private const TERMINAL_BOOKING_STATUSES = ['cancelled', 'checked_out'];

    private const VERIFIED_STATUSES = ['verified', 'refunded'];

    public function __construct(
        private BookingService $bookingService,
        // STEP 16 - invoice issuance runs inside this service's verify()
        // transaction; see InvoiceService::generateForPayment().
        private InvoiceService $invoices
    ) {
    }

    /**
     * Create the one-and-only payment for a booking. Amount is locked to the
     * booking's server-computed total_amount inside a locked read - the
     * client cannot influence it.
     */
    public function create(Booking $booking, string $method): Payment
    {
        if (in_array($booking->status, self::TERMINAL_BOOKING_STATUSES, true)) {
            throw new BusinessRuleException('Cannot create a payment for a ' . $booking->status . ' booking.');
        }

        return DB::transaction(function () use ($booking, $method) {
            // Lock the booking row: serializes concurrent payment creation
            // for the same booking and lets the duplicate check below read
            // the latest committed state (lockForUpdate, per STEP 09).
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if (Payment::where('booking_id', $booking->id)->exists()) {
                throw new BusinessRuleException('A payment already exists for this booking.');
            }

            $payment = new Payment(['method' => $method]);
            $payment->booking_id = $booking->id;
            $payment->amount = $locked->total_amount;
            $payment->status = 'pending';
            $payment->save();

            return $payment->fresh(['booking']);
        });
    }

    /**
     * Store a receipt on the private receipts disk and move the payment into
     * the verification flow. Handles the first upload (pending), a corrected
     * re-upload after rejection (rejected), and replacing an existing
     * receipt (receipt_uploaded). An old file is deleted only once the new
     * one is safely stored and the row updated.
     */
    public function uploadReceipt(Payment $payment, UploadedFile $file): Payment
    {
        $booking = $payment->booking;

        if ($booking === null || in_array($booking->status, self::TERMINAL_BOOKING_STATUSES, true)) {
            throw new BusinessRuleException('Cannot upload a receipt for this booking.');
        }

        if (in_array($payment->status, self::VERIFIED_STATUSES, true)) {
            throw new BusinessRuleException('This payment is already verified and cannot be changed.');
        }

        $oldPath = $payment->receipt_path;
        $newPath = $this->storeReceipt($booking, $file);

        try {
            DB::transaction(function () use ($payment, $newPath) {
                $payment->receipt_path = $newPath;
                $payment->status = 'receipt_uploaded';
                $payment->save();
            });
        } catch (\Throwable $e) {
            Storage::disk('receipts')->delete($newPath);
            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $newPath) {
            Storage::disk('receipts')->delete($oldPath);
        }

        return $payment->fresh(['booking']);
    }

    /**
     * Verify a receipted payment and, atomically, confirm its booking and
     * issue its invoice. One DB transaction covers: payment -> verified
     * (+ verified_at/by), booking pending_payment -> confirmed, room
     * available -> reserved, the room_status_logs audit row and the STEP 16
     * invoice (+ line items) - they all commit or roll back together.
     */
    public function verify(Payment $payment, Employee $employee): Payment
    {
        if ($payment->status !== 'receipt_uploaded') {
            throw new BusinessRuleException('Only payments with an uploaded receipt can be verified.');
        }

        $booking = $payment->booking;

        if ($booking === null || in_array($booking->status, self::TERMINAL_BOOKING_STATUSES, true)) {
            throw new BusinessRuleException('Cannot verify a payment for this booking.');
        }

        if ($booking->status === 'pending_payment' && $booking->isExpired()) {
            // Same rule as staff confirm: an expired hold is auto-cancelled
            // (committed first) then the caller gets its 409.
            $this->bookingService->cancelExpiredHold($booking);
            throw new BusinessRuleException('This booking has expired and has been automatically cancelled.');
        }

        return DB::transaction(function () use ($payment, $booking, $employee) {
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($lockedPayment->status !== 'receipt_uploaded') {
                throw new BusinessRuleException('Only payments with an uploaded receipt can be verified.');
            }

            $lockedPayment->status = 'verified';
            $lockedPayment->verified_at = now();
            $lockedPayment->verified_by = $employee->id;
            $lockedPayment->save();

            // pending_payment -> confirmed via the shared BookingService
            // transition (checks room status transitions, writes the room
            // status log). An already-confirmed booking (staff confirmed
            // before the customer paid) needs no transition - verifying the
            // payment is still the correct record-keeping action.
            if ($lockedBooking->status === 'pending_payment') {
                $this->bookingService->confirmWithinTransaction($lockedBooking);
            }

            // STEP 16 - issue the immutable invoice in the same transaction:
            // payment -> verified, booking -> confirmed, room -> reserved and
            // the invoice (+ its line-item snapshots) all commit or roll back
            // together. generateForPayment reads the locked rows' amounts.
            $this->invoices->generateForPayment($lockedPayment);

            return $lockedPayment->fresh(['booking', 'verifiedBy']);
        });
    }

    /**
     * Reject a receipted payment of a still-pending booking. The rejection
     * reason is captured in notes and the receipt file is kept on disk for
     * audit. The customer can re-upload a corrected receipt later
     * (rejected -> receipt_uploaded).
     */
    public function reject(Payment $payment, string $reason): Payment
    {
        if ($payment->status !== 'receipt_uploaded') {
            throw new BusinessRuleException('Only payments with an uploaded receipt can be rejected.');
        }

        $booking = $payment->booking;

        if ($booking === null || $booking->status !== 'pending_payment') {
            throw new BusinessRuleException('Cannot reject a payment for this booking.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($lockedPayment->status !== 'receipt_uploaded') {
                throw new BusinessRuleException('Only payments with an uploaded receipt can be rejected.');
            }

            $lockedPayment->status = 'rejected';
            $lockedPayment->notes = $reason;
            $lockedPayment->save();

            return $lockedPayment->fresh(['booking']);
        });
    }

    /**
     * STEP 11 - refund a verified payment. Money movement / gateway
     * processing is OUT of scope (a real hotel would debit the bank here);
     * this records the refund and, atomically, cancels the booking and
     * releases any reserved rooms. One DB transaction covers the payment
     * status, the booking cancellation and every room_status_logs audit
     * row - they all commit or roll back together.
     */
    public function refund(Payment $payment, string $reason): Payment
    {
        // Only a verified payment is refundable. pending / receipt_uploaded /
        // rejected / already-refunded are not.
        if ($payment->status !== 'verified') {
            throw new BusinessRuleException('Only verified payments can be refunded.');
        }

        $booking = $payment->booking;

        // The guest must not have stayed: a confirmed or still-pending
        // booking can be refunded; checked_in/checked_out/cancelled cannot.
        if ($booking === null || ! in_array($booking->status, ['confirmed', 'pending_payment'], true)) {
            throw new BusinessRuleException('Cannot refund a payment for this booking.');
        }

        return DB::transaction(function () use ($payment, $booking, $reason) {
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->first();

            // Re-check under lock: a concurrent refund/verify/reject may
            // have changed the payment status since the pre-check ran.
            if ($lockedPayment->status !== 'verified') {
                throw new BusinessRuleException('Only verified payments can be refunded.');
            }

            $lockedPayment->status = 'refunded';
            $lockedPayment->notes = $reason;
            $lockedPayment->save();

            // confirmed -> cancelled (rooms released) or pending_payment ->
            // cancelled (no reserved rooms to release). Runs inside this
            // transaction so the payment refund and booking cancellation
            // are atomic.
            $this->bookingService->refundBooking($lockedBooking, $reason);

            return $lockedPayment->fresh(['booking', 'verifiedBy']);
        });
    }

    private function storeReceipt(Booking $booking, UploadedFile $file): string
    {
        $extension = $file->extension() ?: 'bin';
        $filename = Str::uuid()->toString() . '.' . $extension;

        return Storage::disk('receipts')->putFileAs($booking->booking_number, $file, $filename);
    }
}