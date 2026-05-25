<?php

declare(strict_types=1);

use App\Domain\Custodian\Connectors\MockBankConnector;
use App\Domain\Custodian\Services\CustodianHealthMonitor;
use App\Domain\Custodian\Services\CustodianRegistry;

beforeEach(function () {
    $this->registry = new CustodianRegistry();
    $this->registry->register('mock', new MockBankConnector(['name' => 'Mock Bank']));
    $this->monitor = app(CustodianHealthMonitor::class, ['registry' => $this->registry]);
});

it('iterates over registered custodians for getAllCustodiansHealth', function () {
    $health = $this->monitor->getAllCustodiansHealth();

    expect($health)->toHaveKey('mock');
    expect($health['mock'])->toHaveKeys(['custodian', 'status', 'available']);
    expect($health['mock']['custodian'])->toBe('mock');
});

it('iterates over registered custodians for getHealthiestCustodian', function () {
    $healthiest = $this->monitor->getHealthiestCustodian('USD');

    expect($healthiest)->toBe('mock');
});
