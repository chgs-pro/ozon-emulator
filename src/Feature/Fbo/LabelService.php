<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use FPDF;
use Picqer\Barcode\Types\TypeCode128;
use Psr\Clock\ClockInterface;

use function array_column;
use function array_map;
use function base64_decode;
use function base64_encode;
use function env;
use function hash;
use function hash_equals;
use function in_array;
use function rawurlencode;
use function rtrim;
use function strlen;

/** Synthetic, scannable labels. The layout is deliberately marked as a local test document. */
final readonly class LabelService
{
    public function __construct(
        private SupplyContext $context,
        private CabinetRepositoryInterface $cabinets,
        private ClockInterface $clock,
    ) {
    }

    public static function barcode(int $id): string
    {
        return 'OZTEST' . $id;
    }

    public function create(CabinetState $state, string $clientId, array $op, int $now): array
    {
        $input = $op['input'];
        $kind  = $op['kind'];
        $order = $this->context->order($state, $op['order_id']);
        $this->context->editable($order, $now);
        $labels   = [];
        $versions = [];
        foreach ($order['supplies'] as $supply) {
            $id = $supply['supply_id'];
            if ($kind !== 'label.order' && $id !== (int) $input['supply_id']) {
                continue;
            }
            $cargo         = $this->context->cargo($state, $id);
            $versions[$id] = $cargo['version'];
            $rows          = $kind === 'label.cargo' ? $cargo['cargoes'] : $cargo['transport'];
            $selected      = $kind === 'label.cargo' ? array_column($input['cargoes'] ?? [], 'cargo_id') : ($input['transport_cargo_ids'] ?? []);
            foreach ($selected as $selectedId) {
                if (!isset($rows[$selectedId])) {
                    throw new OperationFailure('CARGOES_NOT_FOUND');
                }
            }
            foreach ($rows as $cargoId => $row) {
                if ($selected !== [] && !in_array($cargoId, array_map('intval', $selected), true)) {
                    continue;
                }
                $labels[] = ['id' => $cargoId, 'barcode' => self::barcode($cargoId), 'type' => $kind === 'label.cargo' ? $row['type'] : 'TRANSPORT PALLET', 'supply_id' => $id, 'order_id' => $order['order_id'], 'warehouse_id' => $supply['storage_warehouse']['warehouse_id'], 'version' => $cargo['version']];
            }
        }
        if ($labels === []) {
            throw new OperationFailure('SUPPLY_IS_EMPTY');
        }
        $documentId = 'document-' . $state->id();
        // Capability belongs to the queued operation and never contains the Seller API key.
        $capability                            = $op['document_capability'];
        $state->data['documents'][$documentId] = ['capability_hash' => hash('sha256', $capability), 'expires_at' => $now + 86400, 'versions' => $versions, 'order_id' => $order['order_id'], 'labels' => $labels, 'pdf' => base64_encode($this->render($labels))];
        $baseUrl                               = rtrim((string) env('APP_URL', 'http://localhost:8080'), '/');

        return ['file_url' => $baseUrl . '/documents/label?client_id=' . rawurlencode($clientId) . '&document_id=' . $documentId . '&token=' . $capability];
    }

    public function download(string $clientId, string $id, string $token): string
    {
        if ($clientId === '' || $id === '' || strlen($token) !== 64) {
            throw new SellerApiException('Document not found', 404, 5);
        }

        return $this->cabinets->change($clientId, function (CabinetState $state) use ($id, $token): string {
            $doc = $state->data['documents'][$id] ?? null;
            if ($doc === null || !hash_equals($doc['capability_hash'], hash('sha256', $token))) {
                throw new SellerApiException('Document not found', 404, 5);
            }
            $now = $this->clock->now()->getTimestamp() + $state->config()['clockOffsetSeconds'];
            if ($now >= $doc['expires_at']) {
                throw new SellerApiException('Document URL expired; request labels again', 410, 9);
            }
            // FBS posting labels: the file is valid while none of its postings is cancelled.
            if (($doc['kind'] ?? 'fbo') === 'fbs') {
                foreach ($doc['postings'] as $number) {
                    if (($state->data['fbs']['postings'][$number]['status'] ?? 'cancelled') === 'cancelled') {
                        throw new SellerApiException('Document contains a cancelled posting; request labels again', 410, 9);
                    }
                }

                return base64_decode($doc['pdf'], true);
            }
            $order = $this->context->order($state, $doc['order_id']);
            if ($order['state'] === 'CANCELLED') {
                throw new SellerApiException('Document belongs to a cancelled order', 410, 9);
            }
            foreach ($doc['versions'] as $supplyId => $version) {
                if ($this->context->cargo($state, (int) $supplyId)['version'] !== $version) {
                    throw new SellerApiException('Document belongs to a superseded cargo version', 410, 9);
                }
            }

            return base64_decode($doc['pdf'], true);
        });
    }

    public function render(array $labels): string
    {
        $pdf = new FPDF('P', 'mm', [100, 150]);

        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Ozon local FBO test labels');
        $pdf->SetCreator('ozon-seller-emulator');
        foreach ($labels as $label) {
            $pdf->AddPage();
            $pdf->SetMargins(8, 8);
            $pdf->SetFont('Helvetica', 'B', 15);
            $pdf->SetXY(8, 9);
            $pdf->Cell(84, 8, 'OZON LOCAL TEST', 0, 1, 'C');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->Cell(84, 6, 'NOT VALID FOR DELIVERY TO OZON', 0, 1, 'C');
            $pdf->Ln(6);
            $pdf->SetFont('Helvetica', 'B', 14);
            $pdf->Cell(84, 8, $label['type'], 0, 1, 'C');
            $pdf->SetFont('Helvetica', '', 11);
            foreach (['Order' => 'order_id', 'Supply' => 'supply_id', 'Warehouse' => 'warehouse_id', 'Cargo / TGM' => 'id', 'Version' => 'version'] as $title => $key) {
                $pdf->Cell(84, 7, $title . ': ' . $label[$key], 0, 1);
            }
            $barcode = new TypeCode128()->getBarcode($label['barcode']);

            $unit = 80 / $barcode->getWidth();
            $x    = 10;
            foreach ($barcode->getBars() as $bar) {
                if ($bar->isBar()) {
                    $pdf->Rect($x, 96, $bar->getWidth() * $unit, 23, 'F');
                }
                $x += $bar->getWidth() * $unit;
            }
            $pdf->SetXY(8, 123);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->Cell(84, 7, $label['barcode'], 0, 1, 'C');
        }

        return $pdf->Output('S');
    }
}
