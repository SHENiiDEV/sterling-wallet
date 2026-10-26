<?php

namespace App\Merchants;

use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Settlements\SettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Deletes one MID together with what it produced: its operations (bank and
 * gateway rows booked to it), daily reports with their files, reserve
 * entries and the settlement lines built from them. Refused while any of its
 * reports sits in an approved or paid settlement. Draft settlements lose the
 * lines and are recalculated. Raw provider files stay: one file covers many MIDs.
 */
class MidPurger
{
    public function __construct(private SettlementService $settlements) {}

    /**
     * What a purge would remove.
     *
     * @return array{operations: int, daily_reports: int, reserve_entries: int, settlement_lines: int, locked_settlements: list<string>}
     */
    public function summary(MerchantMid $mid): array
    {
        return [
            'operations' => $mid->operations()->count(),
            'daily_reports' => $mid->dailyReports()->count(),
            'reserve_entries' => $mid->reserveEntries()->count(),
            'settlement_lines' => $this->lines($mid)->count(),
            'locked_settlements' => $this->lockedSettlements($mid),
        ];
    }

    /**
     * @return array<string, int> what was deleted
     */
    public function purge(MerchantMid $mid, User $by): array
    {
        if ($locked = $this->lockedSettlements($mid)) {
            throw ValidationException::withMessages(['mid' => 'Its reports are in approved or paid settlements ('.implode(', ', $locked).'). Cancel those first.']);
        }

        $summary = $this->summary($mid);
        $reportFiles = $mid->dailyReports()
            ->get(['generated_xlsx_path', 'generated_pdf_path', 'generated_operations_path'])
            ->flatMap(fn (DailyReportTask $t) => [$t->generated_xlsx_path, $t->generated_pdf_path, $t->generated_operations_path])
            ->filter()->all();
        $label = $mid->mid;
        $merchantId = $mid->merchant_id;
        $midId = $mid->id;

        DB::transaction(function () use ($mid, $midId) {
            // Lines of draft settlements: remove them, then recalculate those settlements.
            $settlementIds = $this->lines($mid)->pluck('settlement_id')->unique()->all();
            $this->lines($mid)->delete();

            // Operations only null their MID link on delete: remove them explicitly.
            MerchantOperation::query()->where('merchant_mid_id', $midId)->select('id')->chunkById(1000, function ($chunk) {
                MerchantOperation::query()->whereIn('id', $chunk->pluck('id'))->delete();
            });

            $mid->reserveEntries()->delete();
            $mid->dailyReports()->delete(); // report sources cascade

            // A bot account left with no MIDs is switched off: an empty list would mean "all MIDs".
            IntegrationAccount::query()->whereNotNull('mid_ids')->get()->each(function (IntegrationAccount $account) use ($midId) {
                $left = array_values(array_diff($account->mid_ids ?? [], [$midId]));
                if ($left !== ($account->mid_ids ?? [])) {
                    $account->update($left === [] ? ['mid_ids' => null, 'is_active' => false] : ['mid_ids' => $left]);
                }
            });

            $mid->delete();

            foreach (Settlement::query()->whereIn('id', $settlementIds)->get() as $settlement) {
                $this->settlements->recalculate($settlement);
            }
        });

        Storage::disk(config('sterling.reports.disk'))->delete($reportFiles);

        AuditLogger::log('mid.purged', null, ['mid' => $label, 'merchant_id' => $merchantId, 'by' => $by->id, ...$summary]);

        return $summary;
    }

    /**
     * Empties a terminal of everything it produced and keeps the terminal itself, so its history can be loaded again
     * from the provider. Same rules as a purge: reports in approved or paid settlements must be cancelled first.
     *
     * @return array<string, int> what was deleted
     */
    public function clear(MerchantMid $mid, ?User $by = null): array
    {
        if ($locked = $this->lockedSettlements($mid)) {
            throw ValidationException::withMessages(['mid' => "MID {$mid->mid}: its reports are in approved or paid settlements (".implode(', ', $locked).'). Cancel those first.']);
        }

        $summary = $this->summary($mid);
        $reportFiles = $mid->dailyReports()
            ->get(['generated_xlsx_path', 'generated_pdf_path', 'generated_operations_path'])
            ->flatMap(fn (DailyReportTask $t) => [$t->generated_xlsx_path, $t->generated_pdf_path, $t->generated_operations_path])
            ->filter()->all();

        DB::transaction(function () use ($mid) {
            $settlementIds = $this->lines($mid)->pluck('settlement_id')->unique()->all();
            $this->lines($mid)->delete();

            MerchantOperation::query()->where('merchant_mid_id', $mid->id)->select('id')->chunkById(1000, function ($chunk) {
                MerchantOperation::query()->whereIn('id', $chunk->pluck('id'))->delete();
            });

            $mid->reserveEntries()->delete();
            $mid->dailyReports()->delete();

            foreach (Settlement::query()->whereIn('id', $settlementIds)->get() as $settlement) {
                $this->settlements->recalculate($settlement);
            }
        });

        Storage::disk(config('sterling.reports.disk'))->delete($reportFiles);

        AuditLogger::log('mid.cleared', $mid, ['mid' => $mid->mid, 'by' => $by?->id, ...$summary]);

        return $summary;
    }

    /**
     * @return Builder<SettlementLine>
     */
    private function lines(MerchantMid $mid): Builder
    {
        $reportIds = $mid->dailyReports()->pluck('id');
        $entryIds = $mid->reserveEntries()->pluck('id');

        return SettlementLine::query()->where(fn (Builder $q) => $q
            ->whereIn('daily_report_task_id', $reportIds)
            ->orWhereIn('reserve_ledger_entry_id', $entryIds));
    }

    /**
     * @return list<string> numbers of approved / paid settlements that contain its reports
     */
    private function lockedSettlements(MerchantMid $mid): array
    {
        return Settlement::query()
            ->whereIn('status', [SettlementStatus::Approved->value, SettlementStatus::Settled->value])
            ->whereIn('id', $this->lines($mid)->select('settlement_id'))
            ->pluck('number')
            ->all();
    }
}
