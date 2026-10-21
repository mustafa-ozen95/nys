<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Zabbix\ItemKey;
use PHPUnit\Framework\TestCase;

final class ItemKeyTest extends TestCase
{
    public function test_it_extracts_the_snmp_interface_index(): void
    {
        $this->assertSame(518, ItemKey::ifIndex('net.if.in[ifHCInOctets.518]'));
        $this->assertSame(1, ItemKey::ifIndex('net.if.status[ifOperStatus.1]'));
    }

    public function test_it_returns_null_when_the_key_has_no_index(): void
    {
        $this->assertNull(ItemKey::ifIndex('system.cpu.util[jnxOperatingCPU.1.2.0]x'));
        $this->assertNull(ItemKey::ifIndex('icmpping'));
    }

    public function test_it_extracts_the_interface_name_from_an_item_name(): void
    {
        $this->assertSame('ge-0/0/1', ItemKey::portName('Interface ge-0/0/1(): Bits received'));
        $this->assertSame('xe-0/1/0', ItemKey::portName('Interface xe-0/1/0: Operational status'));
    }

    public function test_it_skips_sub_ports(): void
    {
        $this->assertNull(ItemKey::portName('Interface ge-0/0/1.0(): Bits received'));
    }

    public function test_it_returns_null_for_unrelated_item_names(): void
    {
        $this->assertNull(ItemKey::portName('CPU utilization'));
    }

    public function test_it_matches_any_of_the_given_prefixes(): void
    {
        $prefixes = ['system.net.uptime[sysUpTime', 'system.hw.uptime[hrSystemUptime'];

        $this->assertTrue(ItemKey::startsWithAny('system.hw.uptime[hrSystemUptime.0]', $prefixes));
        $this->assertFalse(ItemKey::startsWithAny('icmpping', $prefixes));
    }

    public function test_it_extracts_the_interface_alias_from_the_item_name(): void
    {
        $this->assertSame('UP-Link-2/1', ItemKey::portAlias('Interface ge-0/1/2(UP-Link-2/1): Speed'));
        $this->assertSame('UP-Link', ItemKey::portAlias('Interface ae0(UP-Link): Bits received'));
    }

    public function test_an_empty_alias_is_treated_as_missing(): void
    {
        $this->assertNull(ItemKey::portAlias('Interface ge-0/0/0(): Speed'));
        $this->assertNull(ItemKey::portAlias('Interface ge-0/0/0(   ): Speed'));
        $this->assertNull(ItemKey::portAlias('CPU utilization'));
    }
}
