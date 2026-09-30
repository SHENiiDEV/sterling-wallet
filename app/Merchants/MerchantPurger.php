<?php

namespace App\Merchants;

use App\Enums\MerchantStatus;
use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\IntegrationAccount;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use App\Models\Settlement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a closed merchant with everything it produced: MIDs, operations,
 * daily reports and their files, reserve, settlements, wallets and
 * onboarding. Documents stay with the company (they are only unlinked);
 * raw provider files stay too, because one file covers many merchants.
 */
class MerchantPurger
{
    /**
     * What a purge would remove.
     *
     * @return array<string, int>
     */
    public function summary(Merchant $merchant): array
    {
        return [
            'mids' => $merchant->mids()->count(),
            'operations' => $this->operations($merchant)->count(),
            'daily_reports' => $merchant->dailyReports()->count(),
            'settlements' => Settlement::query()->where('merchant_id', $merchant->id)->count(),
            'paid_settlements' => Settlement::query()->where('merchant_id', $merchant->id)->where('status', SettlementStatus::Settled)->count(),
            'reserve_entries' => $merchant->reserveEntries()->count(),
            'wallets' => $merchant->wallets()->count(),
            'bank_links' => $merchant->acquirers()->count(),
            'documents_kept' => $merchant->documents()->count(),
        ];
    }

    /**
     * @return array<string, int> what was deleted
     */
    public function purge(Merchant $merchant, User $by): array
    {
        if ($merchant->status !== MerchantStatus::Closed) {
            throw ValidationException::withMessages(['merchant' => 'Only a closed merchant can be deleted. Set its status to Closed first.']);
        }

        $summary = $this->summary($merchant);
        $reportFiles = DailyReportTask::query()->where('merchant_id', $merchant->id)
            ->get(['generated_xlsx_path', 'generated_pdf_path', 'generated_operations_path'])
            ->flatMap(fn (DailyReportTask $t) => [$t->generated_xlsx_path, $t->generated_pdf_path, $t->generated_operations_path])
            ->filter()->all();
        $proofFiles = Settlement::query()->where('merchant_id', $merchant->id)->pluck('proof_path')->filter()->all();
        $midIds = $merchant->mids()->pluck('id')->all();

        DB::transaction(function () use ($merchant, $midIds) {
            // Operations only null their merchant links on delete: remove them explicitly.
            $this->operations($merchant)->select('id')->chunkById(1000, function ($chunk) {
                MerchantOperation::query()->whereIn('id', $chunk->pluck('id'))->delete();
            });

            // Settlement lines, report sources, reserve, MIDs, wallets and bank links cascade.
            Settlement::query()->where('merchant_id', $merchant->id)->delete();
            $merchant->reserveEntries()->delete();
            $merchant->dailyReports()->delete();
            $merchant->documents()->update(['merchant_id' => null]);

            // Bot accounts limited to these MIDs forget them. An account left
            // with none is switched off: an empty list would mean "all MIDs".
            IntegrationAccount::query()->whereNotNull('mid_ids')->get()->each(function (IntegrationAccount $account) use ($midIds) {
                $left = array_values(array_diff($account->mid_ids ?? [], $midIds));
                if ($left !== ($account->mid_ids ?? [])) {
                    $account->update($left === [] ? ['mid_ids' => null, 'is_active' => false] : ['mid_ids' => $left]);
                }
            });

            $merchant->delete();
        });

        Storage::disk(config('sterling.reports.disk'))->delete($reportFiles);
        Storage::disk('local')->delete($proofFiles);

        AuditLogger::log('merchant.purged', null, ['merchant' => $merchant->name, 'public_id' => $merchant->public_id, 'by' => $by->id, ...$summary]);

        return $summary;
    }

    /**
     * @return Builder<MerchantOperation>
     */
    private function operations(Merchant $merchant): Builder
    {
        $midIds = $merchant->mids()->pluck('id');

        return MerchantOperation::query()->where(fn (Builder $q) => $q
            ->where('merchant_id', $merchant->id)
            ->orWhereIn('merchant_mid_id', $midIds));
    }
}
