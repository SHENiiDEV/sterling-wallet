<?php

namespace App\Settlements;

use App\Models\Settlement;
use Dompdf\Dompdf;
use Dompdf\Options;

class SettlementStatementPdf
{
    public function render(Settlement $settlement): string
    {
        $settlement->load(['merchant.company', 'lines', 'wallet', 'approver:id,name', 'settler:id,name']);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('settlements.statement', ['settlement' => $settlement])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
