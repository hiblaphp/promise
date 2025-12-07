<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Handlers\TimerHandler;

describe('Promise Cancellation Chain Propagation', function () {

    test('cancel child promise does not affect parent', function () {
        $timerHandler = new TimerHandler();
        $parent = $timerHandler->delay(0.5);

        $child1 = $parent->then(function () {
            return 'child1 done';
        });

        $child2 = $parent->then(function () {
            return 'child2 done';
        });

        Loop::addTimer(0.1, function () use ($child1) {
            $child1->cancel();
        });

        Loop::addTimer(0.2, function () use ($parent, $child1, $child2) {
            expect($parent->isCancelled())->toBeFalse();
            expect($child1->isCancelled())->toBeTrue();
            expect($child2->isCancelled())->toBeFalse();
            expect($parent->isPending())->toBeTrue();
        });

        Loop::run();
    });

    test('cancel parent promise cancels all children', function () {
        $timerHandler = new TimerHandler();
        $parent2 = $timerHandler->delay(0.5);

        $child1_2 = $parent2->then(function () {
            return 'child1_2 done';
        });

        $child2_2 = $parent2->then(function () {
            return 'child2_2 done';
        });

        Loop::addTimer(0.1, function () use ($parent2) {
            $parent2->cancel();
        });

        Loop::addTimer(0.2, function () use ($parent2, $child1_2, $child2_2) {
            expect($parent2->isCancelled())->toBeTrue();
            expect($child1_2->isCancelled())->toBeTrue();
            expect($child2_2->isCancelled())->toBeTrue();
            expect($parent2->isPending())->toBeFalse();
        });

        Loop::run();
    });

    test('cancel parent with deeply nested chain cancels all descendants', function () {
        $timerHandler = new TimerHandler();
        $parent3 = $timerHandler->delay(0.5);

        $chain1 = $parent3->then(function () {
            return 'chain1';
        });

        $chain2 = $chain1->then(function () {
            return 'chain2';
        });

        $chain3 = $chain2->then(function () {
            return 'chain3';
        });

        $chain4 = $chain3->then(function () {
            return 'chain4';
        });

        Loop::addTimer(0.1, function () use ($parent3) {
            $parent3->cancel();
        });

        Loop::addTimer(0.2, function () use ($parent3, $chain1, $chain2, $chain3, $chain4) {
            expect($parent3->isCancelled())->toBeTrue();
            expect($chain1->isCancelled())->toBeTrue();
            expect($chain2->isCancelled())->toBeTrue();
            expect($chain3->isCancelled())->toBeTrue();
            expect($chain4->isCancelled())->toBeTrue();
        });

        Loop::run();
    });

    test('cancel middle promise in chain only affects descendants', function () {
        $timerHandler = new TimerHandler();
        $parent4 = $timerHandler->delay(0.5);

        $chain1_4 = $parent4->then(function () {
            return 'chain1_4';
        });

        $chain2_4 = $chain1_4->then(function () {
            return 'chain2_4';
        });

        $chain3_4 = $chain2_4->then(function () {
            return 'chain3_4';
        });

        Loop::addTimer(0.1, function () use ($chain2_4) {
            $chain2_4->cancel();
        });

        Loop::addTimer(0.2, function () use ($parent4, $chain1_4, $chain2_4, $chain3_4) {
            expect($parent4->isCancelled())->toBeFalse();
            expect($chain1_4->isCancelled())->toBeFalse();
            expect($chain2_4->isCancelled())->toBeTrue();
            expect($chain3_4->isCancelled())->toBeTrue();
        });

        Loop::run();
    });

    test('cancel one branch does not affect sibling branches', function () {
        $timerHandler = new TimerHandler();
        $parent5 = $timerHandler->delay(0.5);

        $branchA1 = $parent5->then(function () {
            return 'branchA1';
        });

        $branchA2 = $branchA1->then(function () {
            return 'branchA2';
        });

        $branchB1 = $parent5->then(function () {
            return 'branchB1';
        });

        $branchB2 = $branchB1->then(function () {
            return 'branchB2';
        });

        $branchC1 = $parent5->then(function () {
            return 'branchC1';
        });

        Loop::addTimer(0.1, function () use ($branchA1) {
            $branchA1->cancel();
        });

        Loop::addTimer(0.6, function () use ($parent5, $branchA1, $branchA2, $branchB1, $branchB2, $branchC1) {
            expect($parent5->isCancelled())->toBeFalse();
            expect($branchA1->isCancelled())->toBeTrue();
            expect($branchA2->isCancelled())->toBeTrue();
            expect($branchB1->isCancelled())->toBeFalse();
            expect($branchB2->isCancelled())->toBeFalse();
            expect($branchC1->isCancelled())->toBeFalse();
        });

        Loop::run();
    });

    test('cancel leaf node does not affect parent chain', function () {
        $timerHandler = new TimerHandler();
        $parent6 = $timerHandler->delay(0.5);

        $chain1_6 = $parent6->then(function () {
            return 'chain1_6';
        });

        $chain2_6 = $chain1_6->then(function () {
            return 'chain2_6';
        });

        $leaf = $chain2_6->then(function () {
            return 'leaf';
        });

        Loop::addTimer(0.1, function () use ($leaf) {
            $leaf->cancel();
        });

        Loop::addTimer(0.6, function () use ($parent6, $chain1_6, $chain2_6, $leaf) {
            expect($parent6->isCancelled())->toBeFalse();
            expect($chain1_6->isCancelled())->toBeFalse();
            expect($chain2_6->isCancelled())->toBeFalse();
            expect($leaf->isCancelled())->toBeTrue();
        });

        Loop::run();
    });
});
