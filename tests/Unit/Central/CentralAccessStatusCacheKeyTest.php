<?php

namespace Tests\Unit\Central;

use App\Modules\Central\Services\CentralAccessStatusCache;
use PHPUnit\Framework\TestCase;

class CentralAccessStatusCacheKeyTest extends TestCase
{
    /** Must match CENTRAL_SPECS.md §5's exact key literally: central:tenant:{id}:access_status */
    public function test_key_matches_the_spec_literal_format(): void
    {
        $this->assertSame('central:tenant:001:access_status', CentralAccessStatusCache::key('001'));
    }

    public function test_key_is_stable_per_tenant_id(): void
    {
        $this->assertSame(CentralAccessStatusCache::key('042'), CentralAccessStatusCache::key('042'));
        $this->assertNotSame(CentralAccessStatusCache::key('001'), CentralAccessStatusCache::key('002'));
    }
}
