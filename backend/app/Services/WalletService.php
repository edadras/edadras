<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** In app credit the member can top up and spend on renewals or the shop. */
class WalletService
{
    public function wallet(Member $member): Wallet
    {
        return Wallet::firstOrCreate(
            ['member_id' => $member->id],
            ['tenant_id' => $member->tenant_id, 'balance' => 0],
        );
    }

    public function credit(Member $member, float $amount, ?string $description = null, ?Model $reference = null): WalletTransaction
    {
        return $this->apply($member, abs($amount), 'credit', $description, $reference);
    }

    public function debit(Member $member, float $amount, ?string $description = null, ?Model $reference = null): WalletTransaction
    {
        return $this->apply($member, -abs($amount), 'debit', $description, $reference);
    }

    protected function apply(Member $member, float $delta, string $type, ?string $description, ?Model $reference): WalletTransaction
    {
        return DB::transaction(function () use ($member, $delta, $type, $description, $reference) {
            $wallet = $this->wallet($member);
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            $balance = round((float) $wallet->balance + $delta, 2);

            if ($balance < 0) {
                throw new RuntimeException('Wallet balance cannot go negative.');
            }

            $wallet->update(['balance' => $balance]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => abs($delta),
                'balance_after' => $balance,
                'description' => $description,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'created_by' => auth()->id(),
            ]);
        });
    }
}
