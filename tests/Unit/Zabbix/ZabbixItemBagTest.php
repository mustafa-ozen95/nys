<?php

declare(strict_types=1);

namespace Tests\Unit\Zabbix;

use App\Support\Zabbix\ZabbixItemBag;
use PHPUnit\Framework\TestCase;

final class ZabbixItemBagTest extends TestCase
{
    private function bag(): ZabbixItemBag
    {
        return ZabbixItemBag::fromApi([
            ['key_' => 'icmpping', 'lastvalue' => '1'],
            ['key_' => 'system.cpu.util[jnxOperatingCPU.1.1.0.0]', 'lastvalue' => '34'],
            ['key_' => 'system.cpu.util[jnxOperatingCPU.1.2.0.0]', 'lastvalue' => '88'],
            ['key_' => 'system.hw.uptime[hrSystemUptime.0]', 'lastvalue' => '900'],
            ['key_' => 'net.if.status[ifOperStatus.518]', 'lastvalue' => '1'],
            ['key_' => 'net.if.status[ifOperStatus.519]', 'lastvalue' => '2'],
            ['key_' => 'bos.deger', 'lastvalue' => ''],
        ]);
    }

    public function test_it_returns_the_first_matching_value(): void
    {
        $this->assertSame('1', $this->bag()->value('icmpping'));
        $this->assertSame('34', $this->bag()->value('system.cpu.util[jnxOperatingCPU'));
    }

    public function test_it_tries_each_prefix_in_order(): void
    {
        $bag = $this->bag();

        $this->assertSame('900', $bag->value(['system.net.uptime[sysUpTime', 'system.hw.uptime[hrSystemUptime']));
        $this->assertNull($bag->value(['yok.bir', 'yok.iki']));
    }

    public function test_an_empty_value_counts_as_missing(): void
    {
        $this->assertNull($this->bag()->value('bos.deger'));
    }

    public function test_it_returns_the_highest_value_of_a_prefix(): void
    {
        $this->assertSame(88.0, $this->bag()->max('system.cpu.util[jnxOperatingCPU'));
        $this->assertNull($this->bag()->max('olmayan.onek'));
    }

    public function test_it_maps_values_by_interface_index(): void
    {
        $this->assertSame(
            [518 => '1', 519 => '2'],
            $this->bag()->byIfIndex('net.if.status[ifOperStatus.'),
        );
    }

    public function test_a_non_numeric_value_is_not_returned_as_a_number(): void
    {
        $bag = ZabbixItemBag::fromApi([['key_' => 'system.name', 'lastvalue' => 'sw-test-1']]);

        $this->assertSame('sw-test-1', $bag->value('system.name'));
        $this->assertNull($bag->number('system.name'));
    }

    public function test_an_empty_bag_reports_itself_as_empty(): void
    {
        $this->assertTrue(ZabbixItemBag::fromApi([])->isEmpty());
    }
}
