<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use FPDF;
use Picqer\Barcode\Types\TypeCode128;

use function array_map;
use function base64_encode;
use function bin2hex;
use function count;
use function env;
use function hash;
use function in_array;
use function random_bytes;
use function rawurlencode;
use function rtrim;
use function sprintf;

/**
 * Posting labels: `/v2/posting/fbs/package-label/create` queues a big and a small label task for the postings,
 * `/v1/posting/fbs/package-label/get` reports `pending` → `in_progress` → `completed` or `error`. The result is fixed when the
 * task completes (`operationDelaySeconds` later by the cabinet clock): postings in `awaiting_deliver` are printed, the others
 * go to `unprinted_postings`. A repeated get returns the same file; the file is a synthetic local test document.
 */
final readonly class FbsLabelService
{
    public const array PATHS = ['/v2/posting/fbs/package-label/create', '/v1/posting/fbs/package-label/get'];

    public const array WRITE_PATHS = ['/v2/posting/fbs/package-label/create'];

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $clientId, string $path, array $input, int $now): array
    {
        return $path === '/v2/posting/fbs/package-label/create' ? $this->create($state, $input['posting_number'], $now) : $this->get($state, $clientId, (int) $input['task_id'], $now);
    }

    /** @param list<string> $numbers */
    private function create(CabinetState $state, array $numbers, int $now): array
    {
        if ($numbers === []) {
            throw new SellerApiException('posting_number required', 400, 3);
        }
        foreach ($numbers as $number) {
            FbsExemplarService::posting($state, (string) $number);
        }
        $fail = ($state->data['fbs']['scenario']['labelFailures'] ?? 0) > 0;
        if ($fail) {
            --$state->data['fbs']['scenario']['labelFailures'];
        }
        $tasks = [];
        foreach (['big_label', 'small_label'] as $type) {
            $id                                     = $state->id();
            $state->data['fbs']['label_tasks'][$id] = [
                'task_id'  => $id, 'type' => $type, 'postings' => array_map('strval', $numbers), 'created_at' => $now,
                'ready_at' => $now + $state->config()['operationDelaySeconds'], 'fail' => $fail, 'result' => null,
            ];
            $tasks[] = ['task_id' => $id, 'task_type' => $type];
        }
        $state->event('fbs.labels.requested', $now, ['task_ids' => array_map(static fn (array $t): int => $t['task_id'], $tasks)]);

        return ['result' => ['tasks' => $tasks]];
    }

    private function get(CabinetState $state, string $clientId, int $id, int $now): array
    {
        $task = $state->data['fbs']['label_tasks'][$id] ?? throw new SellerApiException('Label task not found', 404, 5);
        if ($task['result'] === null && $now < $task['ready_at']) {
            $status = $now < $task['created_at'] + ($task['ready_at'] - $task['created_at']) / 2 ? 'pending' : 'in_progress';

            return ['result' => ['error' => '', 'file_url' => '', 'printed_postings_count' => 0, 'status' => $status, 'unprinted_postings' => [], 'unprinted_postings_count' => 0]];
        }
        if ($task['result'] === null) {
            $task['result']                         = $this->complete($state, $clientId, $task, $now);
            $state->data['fbs']['label_tasks'][$id] = $task;
            $state->event('fbs.labels.' . $task['result']['status'], $now, ['task_id' => $id]);
        }

        return ['result' => $task['result']];
    }

    private function complete(CabinetState $state, string $clientId, array $task, int $now): array
    {
        $printed   = [];
        $unprinted = [];
        foreach ($task['postings'] as $number) {
            $posting = $state->data['fbs']['postings'][$number];
            $reason  = match (true) {
                $posting['status'] === 'cancelled'        => 'Posting is cancelled',
                $posting['status'] !== 'awaiting_deliver' => 'The next postings aren\'t ready',
                default                                   => null,
            };
            if ($reason === null) {
                $printed[] = $posting;
            } else {
                $unprinted[] = ['msg' => $reason, 'posting_number' => $number];
            }
        }
        $error = match (true) {
            $task['fail']   => 'LABEL_GENERATION_FAILED',
            $printed === [] => 'NO_POSTINGS_TO_PRINT',
            default         => '',
        };
        $fileUrl = '';
        if ($error === '') {
            $documentId                            = 'fbs-label-' . $task['task_id'];
            $token                                 = bin2hex(random_bytes(32));
            $state->data['documents'][$documentId] = [
                'kind'     => 'fbs', 'capability_hash' => hash('sha256', $token), 'expires_at' => $now + 86400,
                'postings' => array_map(static fn (array $p): string => $p['posting_number'], $printed), 'pdf' => base64_encode($this->render($task['type'], $printed)),
            ];
            $fileUrl = rtrim((string) env('APP_URL', 'http://localhost:8080'), '/') . '/documents/label?client_id=' . rawurlencode($clientId) . '&document_id=' . $documentId . '&token=' . $token;
        }

        return [
            'error'  => $error, 'file_url' => $fileUrl, 'printed_postings_count' => $error === '' ? count($printed) : 0,
            'status' => $error === '' ? 'completed' : 'error', 'unprinted_postings' => $unprinted, 'unprinted_postings_count' => count($unprinted),
        ];
    }

    /** One page per posting: 75×120 mm for the big label, 58×40 mm for the small one; Code 128 of the lower barcode. Core PDF fonts are Latin-only. */
    private function render(string $type, array $postings): string
    {
        $big     = $type === 'big_label';
        [$w, $h] = $big ? [75, 120] : [58, 40];
        $pdf     = new FPDF('P', 'mm', [$w, $h]);

        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Ozon local FBS test labels');
        $pdf->SetCreator('ozon-seller-emulator');
        foreach ($postings as $posting) {
            $barcode = FbsPostingGenerator::barcodes($posting['posting_number'])['lower_barcode'];
            $pdf->AddPage();
            $pdf->SetMargins(3, 3);
            $pdf->SetXY(3, 3);
            $pdf->SetFont('Helvetica', 'B', $big ? 11 : 7);
            $pdf->Cell($w - 6, $big ? 6 : 4, 'OZON LOCAL TEST - NOT FOR DELIVERY', 0, 1, 'C');
            $pdf->SetFont('Helvetica', 'B', $big ? 14 : 9);
            $pdf->Cell($w - 6, $big ? 8 : 5, $posting['posting_number'], 0, 1, 'C');
            if ($big) {
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->Cell($w - 6, 5, sprintf('Order %s, method %d', $posting['order_number'], $posting['delivery_method_id']), 0, 1);
                $pdf->Cell($w - 6, 5, 'Warehouse ' . $posting['warehouse_id'], 0, 1);
                foreach ($posting['products'] as $line) {
                    $pdf->Cell($w - 6, 5, sprintf('SKU %d x %d', $line['sku'], $line['quantity']), 0, 1);
                }
            }
            $code = new TypeCode128()->getBarcode($barcode);

            $unit = ($w - 10) / $code->getWidth();
            $x    = 5;
            $top  = $big ? $h - 40 : 18;
            foreach ($code->getBars() as $bar) {
                if ($bar->isBar()) {
                    $pdf->Rect($x, $top, $bar->getWidth() * $unit, $big ? 22 : 13, 'F');
                }
                $x += $bar->getWidth() * $unit;
            }
            $pdf->SetXY(3, $top + ($big ? 24 : 14));
            $pdf->SetFont('Helvetica', '', $big ? 10 : 7);
            $pdf->Cell($w - 6, 5, $barcode, 0, 1, 'C');
        }

        return $pdf->Output('S');
    }
}
