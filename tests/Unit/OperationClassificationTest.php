<?php

namespace Tests\Unit;

use App\Enums\OperationType;
use App\Models\MerchantOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationClassificationTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, OperationType}>
     */
    public static function rows(): array
    {
        return [
            'plain sale' => [['trn_type' => '5', 'amount' => 100], OperationType::Sale],
            'refund by trn_type 25' => [['trn_type' => '25', 'amount' => 100], OperationType::Refund],
            'refund by trn_type 1' => [['trn_type' => 1, 'amount' => 100], OperationType::Refund],
            'refund by negative amount' => [['amount' => -40], OperationType::Refund],
            'decline by fee' => [['amount' => 100, 'decline_fee' => 0.3], OperationType::Decline],
            'decline by process_failed' => [['amount' => 100, 'processing_code' => 'process_failed'], OperationType::Decline],
            'decline by resolution' => [['amount' => 100, 'resolution' => 'insufficient_funds'], OperationType::Decline],
            'approved resolution is a sale' => [['amount' => 100, 'resolution' => 'APPROVED'], OperationType::Sale],
        ];
    }

    #[DataProvider('rows')]
    public function test_it_classifies_operations(array $row, OperationType $expected)
    {
        $this->assertSame($expected, MerchantOperation::classify($row));
    }

    public function test_chargeback_codes_come_from_config()
    {
        config(['sterling.trn_types.chargeback' => ['15']]);

        $this->assertSame(OperationType::Chargeback, MerchantOperation::classify(['trn_type' => '15', 'amount' => 100]));
    }
}
