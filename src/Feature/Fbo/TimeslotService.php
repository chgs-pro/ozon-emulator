<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use DateTimeImmutable;
use DateTimeZone;

use function array_column;
use function in_array;

final readonly class TimeslotService
{
    public function slots(CabinetState $state, int $warehouseId, int $now, string $from, string $to): array
    {
        $c          = $state->config();
        $warehouses = array_column($c['warehouses'], null, 'id');
        $w          = $warehouses[$warehouseId] ?? throw new SellerApiException('Unknown warehouse');
        $zone       = new DateTimeZone($w['timezone']);

        $today = new DateTimeImmutable('@' . $now)->setTimezone($zone)->setTime(0, 0);
        foreach ([$from,$to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new SellerApiException('Invalid date');
            }
        }
        if ($from > $to || $from < $today->format('Y-m-d') || $to > $today->modify('+28 days')->format('Y-m-d')) {
            throw new SellerApiException('Invalid requested period');
        }
        $days = [];
        for ($day = 1; $day <= $c['slots']['daysAhead']; ++$day) {
            $date       = $today->modify('+' . $day . ' days');
            $dateString = $date->format('Y-m-d');
            if ($dateString < $from || $dateString > $to || in_array($dateString, $c['slots']['unavailableDates'], true)) {
                continue;
            }
            $start = $date->setTime($c['slots']['startHour'], 0);
            $slot  = ['from_in_timezone' => $start->format('Y-m-d\TH:i:s'), 'to_in_timezone' => $start->modify('+' . $c['slots']['durationHours'] . ' hours')->format('Y-m-d\TH:i:s')];
            $used  = 0;
            foreach ($state->data['drafts'] as $draft) {
                $op = $draft['supply_operation'];
                if ($op !== null && !$op['failed'] && !isset($state->data['orders'][$op['order_id']]) && $op['dropoff_id'] === $warehouseId && $op['slot'] === $slot) {
                    ++$used;
                }
            }
            foreach ($state->data['orders'] as $order) {
                if ($order['state'] === 'CANCELLED' || $order['dropoff_warehouse']['warehouse_id'] !== $warehouseId) {
                    continue;
                }
                $localFrom = new DateTimeImmutable($order['timeslot']['timeslot']['from'])->setTimezone($zone)->format('Y-m-d\TH:i:s');

                if ($localFrom === $slot['from_in_timezone']) {
                    ++$used;
                }
            }
            if ($used < $c['slots']['capacity']) {
                $days[] = ['date_in_timezone' => $dateString, 'timeslots' => [$slot]];
            }
        }

        return ['drop_off_warehouse_timeslots' => ['current_time_in_timezone' => new DateTimeImmutable('@' . $now)->setTimezone($zone)->format('Y-m-d\TH:i:s'), 'warehouse_timezone' => $w['timezone'], 'days' => $days], 'requested_date_from' => $from, 'requested_date_to' => $to];
    }
}
