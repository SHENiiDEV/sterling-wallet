<?php

namespace App\Bots;

use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One unit of bot work: fetch this account's file for this report period,
 * covering these MIDs. `params` holds connector specifics (e.g. the Corefy
 * commerce account). Stored on bot_runs.target so a run can be replayed.
 */
final readonly class Target
{
    /**
     * @param  Collection<int, MerchantMid>  $mids
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public IntegrationAccount $account,
        public ReportPeriod $period,
        public Collection $mids,
        public array $params = [],
    ) {}

    /**
     * Identity used for locking and de-duplication of runs.
     */
    public function key(): string
    {
        $suffix = $this->params['key'] ?? null;

        return $this->period->reportDate->toDateString().($suffix ? ':'.$suffix : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->account->id,
            ...$this->period->toArray(),
            'mid_ids' => $this->mids->pluck('id')->all(),
            'mids' => $this->mids->pluck('mid')->all(),
            'params' => $this->params,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(IntegrationAccount $account, array $data): self
    {
        return new self(
            $account,
            new ReportPeriod(
                CarbonImmutable::parse($data['report_date']),
                CarbonImmutable::parse($data['from']),
                CarbonImmutable::parse($data['to']),
            ),
            MerchantMid::query()->whereKey($data['mid_ids'] ?? [])->get(),
            $data['params'] ?? [],
        );
    }
}
