<?php

declare(strict_types=1);

use App\Support\SupervisedProcesses;

it('reports unavailable when supervisor is not present', function () {
    // The test environment is not the single-container image, so there is no
    // supervisor config to talk to.
    expect(SupervisedProcesses::available())->toBeFalse();
});

it('reports that no restart happened rather than throwing', function () {
    // An installation must never fail because workers could not be restarted;
    // the caller tells the operator to restart the container instead.
    expect(SupervisedProcesses::restartWorkers())->toBeFalse();
});
