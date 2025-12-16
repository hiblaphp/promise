<?php

declare(strict_types=1);

use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\SettledResult;

describe('Array Ordering and Key Preservation', function () {

    describe('ConcurrencyHandler', function () {
        it('preserves order for indexed arrays in concurrent execution', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                fn () => delayedValue('first', 30),
                fn () => delayedValue('second', 10),
                fn () => delayedValue('third', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe(['first', 'second', 'third']);
            expect(array_keys($results))->toBe([0, 1, 2]);
        });

        it('preserves keys for associative arrays in concurrent execution', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'task_a' => fn () => delayedValue('result_a', 30),
                'task_b' => fn () => delayedValue('result_b', 10),
                'task_c' => fn () => delayedValue('result_c', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe([
                'task_a' => 'result_a',
                'task_b' => 'result_b',
                'task_c' => 'result_c',
            ]);
            expect(array_keys($results))->toBe(['task_a', 'task_b', 'task_c']);
        });

        it('preserves numeric keys for non-sequential arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                5 => fn () => delayedValue('fifth', 30),
                10 => fn () => delayedValue('tenth', 10),
                15 => fn () => delayedValue('fifteenth', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe([
                5 => 'fifth',
                10 => 'tenth',
                15 => 'fifteenth',
            ]);
            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('preserves order with mixed completion times and low concurrency', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                fn () => delayedValue('slow', 50),
                fn () => delayedValue('fast', 5),
                fn () => delayedValue('medium', 25),
                fn () => delayedValue('very_fast', 1),
                fn () => delayedValue('very_slow', 100),
            ];

            $results = $handler->concurrent($tasks, 2)->wait();

            expect($results)->toBe(['slow', 'fast', 'medium', 'very_fast', 'very_slow']);
        });

        it('preserves order in batch execution with indexed arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                fn () => delayedValue('batch1_item1', 20),
                fn () => delayedValue('batch1_item2', 10),
                fn () => delayedValue('batch2_item1', 30),
                fn () => delayedValue('batch2_item2', 5),
            ];

            $results = $handler->batch($tasks, 2, 2)->wait();

            expect($results)->toBe(['batch1_item1', 'batch1_item2', 'batch2_item1', 'batch2_item2']);
        });

        it('preserves keys in batch execution with associative arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'user_1' => fn () => delayedValue(['id' => 1, 'name' => 'Alice'], 20),
                'user_2' => fn () => delayedValue(['id' => 2, 'name' => 'Bob'], 10),
                'user_3' => fn () => delayedValue(['id' => 3, 'name' => 'Charlie'], 30),
                'user_4' => fn () => delayedValue(['id' => 4, 'name' => 'Diana'], 5),
            ];

            $results = $handler->batch($tasks, 2, 2)->wait();

            expect($results)->toBe([
                'user_1' => ['id' => 1, 'name' => 'Alice'],
                'user_2' => ['id' => 2, 'name' => 'Bob'],
                'user_3' => ['id' => 3, 'name' => 'Charlie'],
                'user_4' => ['id' => 4, 'name' => 'Diana'],
            ]);
            expect(array_keys($results))->toBe(['user_1', 'user_2', 'user_3', 'user_4']);
        });

        it('preserves numeric keys in batch execution with non-sequential arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                100 => fn () => delayedValue('hundred', 20),
                200 => fn () => delayedValue('two_hundred', 10),
                300 => fn () => delayedValue('three_hundred', 30),
                400 => fn () => delayedValue('four_hundred', 5),
            ];

            $results = $handler->batch($tasks, 2, 2)->wait();

            expect($results)->toBe([
                100 => 'hundred',
                200 => 'two_hundred',
                300 => 'three_hundred',
                400 => 'four_hundred',
            ]);
            expect(array_keys($results))->toBe([100, 200, 300, 400]);
        });

        it('preserves order in concurrentSettled with mixed success/failure', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                fn () => delayedValue('success_1', 30),
                fn () => delayedReject('error_1', 10),
                fn () => delayedValue('success_2', 20),
                fn () => delayedReject('error_2', 5),
            ];

            $results = $handler->concurrentSettled($tasks, 4)->wait();

            expect($results[0])->toBe(['status' => 'fulfilled', 'value' => 'success_1']);
            expect($results[1]['status'])->toBe('rejected');
            expect($results[1]['reason'])->toBe('error_1');
            expect($results[2])->toBe(['status' => 'fulfilled', 'value' => 'success_2']);
            expect($results[3]['status'])->toBe('rejected');
            expect($results[3]['reason'])->toBe('error_2');
        });

        it('preserves keys in concurrentSettled with associative arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'api_call_1' => fn () => delayedValue('response_1', 25),
                'api_call_2' => fn () => delayedReject('timeout', 15),
                'api_call_3' => fn () => delayedValue('response_3', 35),
            ];

            $results = $handler->concurrentSettled($tasks, 3)->wait();

            expect(array_keys($results))->toBe(['api_call_1', 'api_call_2', 'api_call_3']);
            expect($results['api_call_1']['status'])->toBe('fulfilled');
            expect($results['api_call_2']['status'])->toBe('rejected');
            expect($results['api_call_3']['status'])->toBe('fulfilled');
        });

        it('preserves numeric keys in concurrentSettled with non-sequential arrays', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                7 => fn () => delayedValue('lucky_seven', 25),
                13 => fn () => delayedReject('unlucky_thirteen', 15),
                21 => fn () => delayedValue('twenty_one', 35),
            ];

            $results = $handler->concurrentSettled($tasks, 3)->wait();

            expect(array_keys($results))->toBe([7, 13, 21]);
            expect($results[7]['status'])->toBe('fulfilled');
            expect($results[7]['value'])->toBe('lucky_seven');
            expect($results[13]['status'])->toBe('rejected');
            expect($results[21]['status'])->toBe('fulfilled');
            expect($results[21]['value'])->toBe('twenty_one');
        });

        it('handles empty arrays correctly', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->concurrent([], 5)->wait();
            expect($results)->toBe([]);

            $results = $handler->batch([], 5)->wait();
            expect($results)->toBe([]);

            $results = $handler->concurrentSettled([], 5)->wait();
            expect($results)->toBe([]);
        });

        it('handles single item arrays correctly', function () {
            $handler = new ConcurrencyHandler();
            $tasks = ['single' => fn () => delayedValue('result', 10)];
            $results = $handler->concurrent($tasks, 1)->wait();

            expect($results)->toBe(['single' => 'result']);
        });

        it('handles single item with numeric key correctly', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [42 => fn () => delayedValue('answer', 10)];
            $results = $handler->concurrent($tasks, 1)->wait();

            expect($results)->toBe([42 => 'answer']);
            expect(array_keys($results))->toBe([42]);
        });

        it('preserves order with Promise instances as tasks', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                fn () => delayedValue('promise_1', 30),
                fn () => delayedValue('promise_2', 10),
                fn () => delayedValue('promise_3', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe(['promise_1', 'promise_2', 'promise_3']);
        });

        it('preserves numeric keys with Promise instances as tasks', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                25 => fn () => delayedValue('quarter', 30),
                50 => fn () => delayedValue('half', 10),
                75 => fn () => delayedValue('three_quarters', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe([
                25 => 'quarter',
                50 => 'half',
                75 => 'three_quarters',
            ]);
            expect(array_keys($results))->toBe([25, 50, 75]);
        });
    });

    describe('PromiseCollectionHandler', function () {
        it('preserves order in all() with indexed arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                delayedValue('first', 30),
                delayedValue('second', 10),
                delayedValue('third', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe(['first', 'second', 'third']);
        });

        it('preserves keys in all() with associative arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'key_a' => delayedValue('value_a', 25),
                'key_b' => delayedValue('value_b', 15),
                'key_c' => delayedValue('value_c', 35),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                'key_a' => 'value_a',
                'key_b' => 'value_b',
                'key_c' => 'value_c',
            ]);
        });

        it('preserves numeric keys in all() with non-sequential arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                5 => delayedValue('fifth', 25),
                10 => delayedValue('tenth', 15),
                15 => delayedValue('fifteenth', 35),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                5 => 'fifth',
                10 => 'tenth',
                15 => 'fifteenth',
            ]);
            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('converts sequential numeric keys starting from 0 to indexed array', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                0 => delayedValue('zero', 25),
                1 => delayedValue('one', 15),
                2 => delayedValue('two', 35),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe(['zero', 'one', 'two']);
            expect(array_keys($results))->toBe([0, 1, 2]);
        });

        it('preserves non-zero starting sequential keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                1 => delayedValue('one', 25),
                2 => delayedValue('two', 15),
                3 => delayedValue('three', 35),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                1 => 'one',
                2 => 'two',
                3 => 'three',
            ]);
            expect(array_keys($results))->toBe([1, 2, 3]);
        });

        it('preserves order in allSettled() with indexed arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                delayedValue('success_1', 20),
                delayedReject('error_1', 10),
                delayedValue('success_2', 30),
            ];

            $results = $handler->allSettled($promises)->wait();

            expect($results[0])->toBeInstanceOf(SettledResult::class);
            expect($results[0]->isFulfilled())->toBeTrue();
            expect($results[0]->value)->toBe('success_1');

            expect($results[1])->toBeInstanceOf(SettledResult::class);
            expect($results[1]->isRejected())->toBeTrue();

            expect($results[2])->toBeInstanceOf(SettledResult::class);
            expect($results[2]->isFulfilled())->toBeTrue();
            expect($results[2]->value)->toBe('success_2');
        });

        it('preserves keys in allSettled() with associative arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'operation_1' => delayedValue('done_1', 15),
                'operation_2' => delayedReject('failed', 25),
                'operation_3' => delayedValue('done_3', 5),
            ];

            $results = $handler->allSettled($promises)->wait();

            expect(array_keys($results))->toBe(['operation_1', 'operation_2', 'operation_3']);

            expect($results['operation_1'])->toBeInstanceOf(SettledResult::class);
            expect($results['operation_1']->isFulfilled())->toBeTrue();
            expect($results['operation_1']->value)->toBe('done_1');

            expect($results['operation_2'])->toBeInstanceOf(SettledResult::class);
            expect($results['operation_2']->isRejected())->toBeTrue();

            expect($results['operation_3'])->toBeInstanceOf(SettledResult::class);
            expect($results['operation_3']->isFulfilled())->toBeTrue();
            expect($results['operation_3']->value)->toBe('done_3');
        });

        it('preserves numeric keys in allSettled() with non-sequential arrays', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                3 => delayedValue('third', 15),
                6 => delayedReject('sixth_failed', 25),
                9 => delayedValue('ninth', 5),
            ];

            $results = $handler->allSettled($promises)->wait();

            expect(array_keys($results))->toBe([3, 6, 9]);

            expect($results[3])->toBeInstanceOf(SettledResult::class);
            expect($results[3]->isFulfilled())->toBeTrue();
            expect($results[3]->value)->toBe('third');

            expect($results[6])->toBeInstanceOf(SettledResult::class);
            expect($results[6]->isRejected())->toBeTrue();

            expect($results[9])->toBeInstanceOf(SettledResult::class);
            expect($results[9]->isFulfilled())->toBeTrue();
            expect($results[9]->value)->toBe('ninth');
        });

        it('handles gaps in numeric keys correctly', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                0 => delayedValue('zero', 15),
                2 => delayedValue('two', 25),
                4 => delayedValue('four', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                0 => 'zero',
                2 => 'two',
                4 => 'four',
            ]);
            expect(array_keys($results))->toBe([0, 2, 4]);
        });

        it('handles negative numeric keys correctly', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                -1 => delayedValue('negative_one', 15),
                0 => delayedValue('zero', 25),
                1 => delayedValue('positive_one', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                -1 => 'negative_one',
                0 => 'zero',
                1 => 'positive_one',
            ]);
            expect(array_keys($results))->toBe([-1, 0, 1]);
        });
    });

    describe('Complex Scenarios', function () {
        it('maintains order with numeric string keys', function () {
            $concurrencyHandler = new ConcurrencyHandler();

            $tasks = [
                '0' => fn () => delayedValue('zero', 20),
                '1' => fn () => delayedValue('one', 10),
                '2' => fn () => delayedValue('two', 30),
            ];

            $results = $concurrencyHandler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([0, 1, 2]);
            expect($results)->toBe([0 => 'zero', 1 => 'one', 2 => 'two']);
        });

        it('handles mixed key types correctly', function () {
            $concurrencyHandler = new ConcurrencyHandler();

            $tasks = [
                0 => fn () => delayedValue('numeric_zero', 20),
                'string_key' => fn () => delayedValue('string_value', 10),
                5 => fn () => delayedValue('numeric_five', 30),
                'another' => fn () => delayedValue('another_string', 15),
            ];

            $results = $concurrencyHandler->concurrent($tasks, 4)->wait();

            expect(array_keys($results))->toBe([0, 'string_key', 5, 'another']);
            expect($results[0])->toBe('numeric_zero');
            expect($results['string_key'])->toBe('string_value');
            expect($results[5])->toBe('numeric_five');
            expect($results['another'])->toBe('another_string');
        });

        it('maintains order with large numeric keys', function () {
            $collectionHandler = new PromiseCollectionHandler();

            $tasks = [
                1000 => delayedValue('thousand', 30),
                2000 => delayedValue('two_thousand', 10),
                3000 => delayedValue('three_thousand', 20),
            ];

            $results = $collectionHandler->all($tasks)->wait();

            expect($results)->toBe([
                1000 => 'thousand',
                2000 => 'two_thousand',
                3000 => 'three_thousand',
            ]);
            expect(array_keys($results))->toBe([1000, 2000, 3000]);
        });

        it('maintains order with large arrays', function () {
            $concurrencyHandler = new ConcurrencyHandler();

            $tasks = [];
            $expected = [];

            for ($i = 0; $i < 50; $i++) {
                $value = "item_{$i}";
                $delay = 50 - $i;
                $tasks[] = fn () => delayedValue($value, $delay);
                $expected[] = $value;
            }

            $results = $concurrencyHandler->concurrent($tasks, 10)->wait();

            expect($results)->toBe($expected);
        });

        it('handles mixed data types in results while preserving order', function () {
            $concurrencyHandler = new ConcurrencyHandler();

            $tasks = [
                fn () => delayedValue(['array' => 'data'], 20),
                fn () => delayedValue(42, 10),
                fn () => delayedValue('string', 30),
                fn () => delayedValue(true, 5),
                fn () => delayedValue(null, 15),
            ];

            $results = $concurrencyHandler->concurrent($tasks, 5)->wait();

            expect($results[0])->toBe(['array' => 'data']);
            expect($results[1])->toBe(42);
            expect($results[2])->toBe('string');
            expect($results[3])->toBe(true);
            expect($results[4])->toBe(null);
        });

        it('preserves order with non-sequential numeric keys and mixed completion times', function () {
            $collectionHandler = new PromiseCollectionHandler();

            $promises = [
                10 => delayedValue('ten', 50),
                5 => delayedValue('five', 10),
                15 => delayedValue('fifteen', 30),
                1 => delayedValue('one', 20),
            ];

            $results = $collectionHandler->all($promises)->wait();

            expect($results)->toBe([
                10 => 'ten',
                5 => 'five',
                15 => 'fifteen',
                1 => 'one',
            ]);
            expect(array_keys($results))->toBe([10, 5, 15, 1]);
        });

        it('distinguishes between sequential and non-sequential numeric arrays', function () {
            $collectionHandler = new PromiseCollectionHandler();

            $sequential = [
                0 => delayedValue('zero', 10),
                1 => delayedValue('one', 20),
                2 => delayedValue('two', 15),
            ];

            $sequentialResults = $collectionHandler->all($sequential)->wait();
            expect($sequentialResults)->toBe(['zero', 'one', 'two']);
            expect(array_keys($sequentialResults))->toBe([0, 1, 2]);

            $nonSequential = [
                0 => delayedValue('zero', 10),
                1 => delayedValue('one', 20),
                3 => delayedValue('three', 15),
            ];

            $nonSequentialResults = $collectionHandler->all($nonSequential)->wait();
            expect($nonSequentialResults)->toBe([
                0 => 'zero',
                1 => 'one',
                3 => 'three',
            ]);
            expect(array_keys($nonSequentialResults))->toBe([0, 1, 3]);
        });
    });
});

describe('Edge Cases for Ordering', function () {

    describe('ConcurrencyHandler Edge Cases', function () {
        it('preserves order with unicode string keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'café' => fn () => delayedValue('coffee', 30),
                'naïve' => fn () => delayedValue('innocent', 10),
                '日本' => fn () => delayedValue('japan', 20),
                '🚀' => fn () => delayedValue('rocket', 5),
            ];

            $results = $handler->concurrent($tasks, 4)->wait();

            expect(array_keys($results))->toBe(['café', 'naïve', '日本', '🚀']);
            expect($results['café'])->toBe('coffee');
            expect($results['🚀'])->toBe('rocket');
        });

        it('handles numeric string keys correctly', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                '5' => fn () => delayedValue('five', 30),
                '10' => fn () => delayedValue('ten', 10),
                '15' => fn () => delayedValue('fifteen', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('preserves order with empty string key', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                '' => fn () => delayedValue('empty', 30),
                'a' => fn () => delayedValue('letter_a', 10),
                'b' => fn () => delayedValue('letter_b', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe(['', 'a', 'b']);
            expect($results[''])->toBe('empty');
        });

        it('preserves order with very long string keys', function () {
            $handler = new ConcurrencyHandler();
            $longKey1 = str_repeat('a', 1000);
            $longKey2 = str_repeat('b', 1000);
            $longKey3 = str_repeat('c', 1000);

            $tasks = [
                $longKey1 => fn () => delayedValue('long_a', 30),
                $longKey2 => fn () => delayedValue('long_b', 10),
                $longKey3 => fn () => delayedValue('long_c', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([$longKey1, $longKey2, $longKey3]);
        });

        it('preserves order with special character keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                '$money' => fn () => delayedValue('cash', 30),
                '#hashtag' => fn () => delayedValue('social', 10),
                '@mention' => fn () => delayedValue('user', 20),
                'dot.notation' => fn () => delayedValue('path', 5),
            ];

            $results = $handler->concurrent($tasks, 4)->wait();

            expect(array_keys($results))->toBe(['$money', '#hashtag', '@mention', 'dot.notation']);
        });

        it('preserves order with null values in results', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'first' => fn () => delayedValue(null, 30),
                'second' => fn () => delayedValue('value', 10),
                'third' => fn () => delayedValue(null, 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results['first'])->toBeNull();
            expect($results['second'])->toBe('value');
            expect($results['third'])->toBeNull();
            expect(array_keys($results))->toBe(['first', 'second', 'third']);
        });

        it('preserves order with extreme timing differences', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'slow' => fn () => delayedValue('10000ms', 10000),
                'fast' => fn () => delayedValue('1ms', 1),
                'medium' => fn () => delayedValue('100ms', 100),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results)->toBe([
                'slow' => '10000ms',
                'fast' => '1ms',
                'medium' => '100ms',
            ]);
            expect(array_keys($results))->toBe(['slow', 'fast', 'medium']);
        });

        it('preserves order with duplicate values across different keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'key_a' => fn () => delayedValue('same_value', 30),
                'key_b' => fn () => delayedValue('same_value', 10),
                'key_c' => fn () => delayedValue('same_value', 20),
                'key_d' => fn () => delayedValue('different', 5),
            ];

            $results = $handler->concurrent($tasks, 4)->wait();

            expect($results['key_a'])->toBe('same_value');
            expect($results['key_b'])->toBe('same_value');
            expect($results['key_c'])->toBe('same_value');
            expect($results['key_d'])->toBe('different');
            expect(array_keys($results))->toBe(['key_a', 'key_b', 'key_c', 'key_d']);
        });

        it('preserves order with very large numeric keys', function () {
            $handler = new ConcurrencyHandler();
            $largeKey1 = PHP_INT_MAX - 2;
            $largeKey2 = PHP_INT_MAX - 1;
            $largeKey3 = PHP_INT_MAX;

            $tasks = [
                $largeKey1 => fn () => delayedValue('max_minus_2', 30),
                $largeKey2 => fn () => delayedValue('max_minus_1', 10),
                $largeKey3 => fn () => delayedValue('max', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([$largeKey1, $largeKey2, $largeKey3]);
        });

        it('preserves order with zero, positive, and negative keys mixed', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                -100 => fn () => delayedValue('very_negative', 30),
                -1 => fn () => delayedValue('negative_one', 10),
                0 => fn () => delayedValue('zero', 20),
                1 => fn () => delayedValue('positive_one', 5),
                100 => fn () => delayedValue('very_positive', 15),
            ];

            $results = $handler->concurrent($tasks, 5)->wait();

            expect(array_keys($results))->toBe([-100, -1, 0, 1, 100]);
        });

        it('preserves order with tasks resolving to complex types', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'array' => fn () => delayedValue(['nested' => ['deep' => 'value']], 30),
                'object' => fn () => delayedValue((object)['prop' => 'value'], 10),
                'resource_like' => fn () => delayedValue(['type' => 'resource'], 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect($results['array'])->toBe(['nested' => ['deep' => 'value']]);
            expect($results['object']->prop)->toBe('value');
            expect(array_keys($results))->toBe(['array', 'object', 'resource_like']);
        });

        it('preserves order with single task', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'only' => fn () => delayedValue('lonely', 50),
            ];

            $results = $handler->concurrent($tasks, 1)->wait();

            expect($results)->toBe(['only' => 'lonely']);
            expect(array_keys($results))->toBe(['only']);
        });

        it('preserves order with JSON-like string keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                '{"key": "value"}' => fn () => delayedValue('json_key', 30),
                '[1, 2, 3]' => fn () => delayedValue('array_key', 10),
                'key="value"' => fn () => delayedValue('xml_key', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe(['{"key": "value"}', '[1, 2, 3]', 'key="value"']);
        });

        it('preserves order in concurrentSettled with mixed rejections and types', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'null_result' => fn () => delayedValue(null, 30),
                'rejection' => fn () => delayedReject('error', 10),
                'array_result' => fn () => delayedValue(['data' => 'value'], 20),
                'another_rejection' => fn () => delayedReject('error2', 5),
                'string_result' => fn () => delayedValue('success', 15),
            ];

            $results = $handler->concurrentSettled($tasks, 5)->wait();

            expect($results['null_result']['status'])->toBe('fulfilled');
            expect($results['null_result']['value'])->toBeNull();
            expect($results['rejection']['status'])->toBe('rejected');
            expect(array_keys($results))->toBe(['null_result', 'rejection', 'array_result', 'another_rejection', 'string_result']);
        });

        it('preserves order with whitespace in string keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'key with spaces' => fn () => delayedValue('value1', 30),
                "key\twith\ttabs" => fn () => delayedValue('value2', 10),
                "key\nwith\nnewlines" => fn () => delayedValue('value3', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([
                'key with spaces',
                "key\twith\ttabs",
                "key\nwith\nnewlines",
            ]);
        });

        it('handles tasks completing at exact same time', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'a' => fn () => delayedValue('first', 5),
                'b' => fn () => delayedValue('second', 5),
                'c' => fn () => delayedValue('third', 5),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe(['a', 'b', 'c']);
        });

        it('preserves order with very small numeric keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                -1000 => fn () => delayedValue('very_negative', 30),
                -500 => fn () => delayedValue('negative', 10),
                -1 => fn () => delayedValue('almost_zero', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe([-1000, -500, -1]);
        });

        it('preserves order with case-sensitive string keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                'Key' => fn () => delayedValue('capitalized', 30),
                'key' => fn () => delayedValue('lowercase', 10),
                'KEY' => fn () => delayedValue('uppercase', 20),
            ];

            $results = $handler->concurrent($tasks, 3)->wait();

            expect(array_keys($results))->toBe(['Key', 'key', 'KEY']);
            expect($results['Key'])->toBe('capitalized');
            expect($results['key'])->toBe('lowercase');
            expect($results['KEY'])->toBe('uppercase');
        });

        it('preserves order in batch with edge case keys', function () {
            $handler = new ConcurrencyHandler();
            $tasks = [
                '' => fn () => delayedValue('empty', 20),
                '🎯' => fn () => delayedValue('emoji', 10),
                -5 => fn () => delayedValue('negative', 30),
                'normal' => fn () => delayedValue('regular', 5),
            ];

            $results = $handler->batch($tasks, 2, 2)->wait();

            expect(array_keys($results))->toBe(['', '🎯', -5, 'normal']);
        });
    });

    describe('PromiseCollectionHandler Edge Cases', function () {
        it('preserves order with unicode string keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'café' => delayedValue('coffee', 30),
                'naïve' => delayedValue('innocent', 10),
                '日本' => delayedValue('japan', 20),
                '🚀' => delayedValue('rocket', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['café', 'naïve', '日本', '🚀']);
            expect($results['café'])->toBe('coffee');
            expect($results['🚀'])->toBe('rocket');
        });

        it('handles numeric string keys correctly', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                '5' => delayedValue('five', 30),
                '10' => delayedValue('ten', 10),
                '15' => delayedValue('fifteen', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([5, 10, 15]);
        });

        it('preserves order with empty string key', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                '' => delayedValue('empty', 30),
                'a' => delayedValue('letter_a', 10),
                'b' => delayedValue('letter_b', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['', 'a', 'b']);
            expect($results[''])->toBe('empty');
        });

        it('preserves order with very long string keys', function () {
            $handler = new PromiseCollectionHandler();
            $longKey1 = str_repeat('a', 1000);
            $longKey2 = str_repeat('b', 1000);
            $longKey3 = str_repeat('c', 1000);

            $promises = [
                $longKey1 => delayedValue('long_a', 30),
                $longKey2 => delayedValue('long_b', 10),
                $longKey3 => delayedValue('long_c', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([$longKey1, $longKey2, $longKey3]);
        });

        it('preserves order with special character keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                '$money' => delayedValue('cash', 30),
                '#hashtag' => delayedValue('social', 10),
                '@mention' => delayedValue('user', 20),
                'dot.notation' => delayedValue('path', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['$money', '#hashtag', '@mention', 'dot.notation']);
        });

        it('preserves order with null values in results', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'first' => delayedValue(null, 30),
                'second' => delayedValue('value', 10),
                'third' => delayedValue(null, 20),
            ];

            $results = $handler->all($promises)->wait();

            expect($results['first'])->toBeNull();
            expect($results['second'])->toBe('value');
            expect($results['third'])->toBeNull();
            expect(array_keys($results))->toBe(['first', 'second', 'third']);
        });

        it('preserves order with extreme timing differences', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'slow' => delayedValue('10000ms', 10000),
                'fast' => delayedValue('1ms', 1),
                'medium' => delayedValue('100ms', 100),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe([
                'slow' => '10000ms',
                'fast' => '1ms',
                'medium' => '100ms',
            ]);
            expect(array_keys($results))->toBe(['slow', 'fast', 'medium']);
        });

        it('preserves order with duplicate values across different keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'key_a' => delayedValue('same_value', 30),
                'key_b' => delayedValue('same_value', 10),
                'key_c' => delayedValue('same_value', 20),
                'key_d' => delayedValue('different', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect($results['key_a'])->toBe('same_value');
            expect($results['key_b'])->toBe('same_value');
            expect($results['key_c'])->toBe('same_value');
            expect($results['key_d'])->toBe('different');
            expect(array_keys($results))->toBe(['key_a', 'key_b', 'key_c', 'key_d']);
        });

        it('preserves order with very large numeric keys', function () {
            $handler = new PromiseCollectionHandler();
            $largeKey1 = PHP_INT_MAX - 2;
            $largeKey2 = PHP_INT_MAX - 1;
            $largeKey3 = PHP_INT_MAX;

            $promises = [
                $largeKey1 => delayedValue('max_minus_2', 30),
                $largeKey2 => delayedValue('max_minus_1', 10),
                $largeKey3 => delayedValue('max', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([$largeKey1, $largeKey2, $largeKey3]);
        });

        it('preserves order with zero, positive, and negative keys mixed', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                -100 => delayedValue('very_negative', 30),
                -1 => delayedValue('negative_one', 10),
                0 => delayedValue('zero', 20),
                1 => delayedValue('positive_one', 5),
                100 => delayedValue('very_positive', 15),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([-100, -1, 0, 1, 100]);
        });

        it('preserves order with promises resolving to complex types', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'array' => delayedValue(['nested' => ['deep' => 'value']], 30),
                'object' => delayedValue((object)['prop' => 'value'], 10),
                'resource_like' => delayedValue(['type' => 'resource'], 20),
            ];

            $results = $handler->all($promises)->wait();

            expect($results['array'])->toBe(['nested' => ['deep' => 'value']]);
            expect($results['object']->prop)->toBe('value');
            expect(array_keys($results))->toBe(['array', 'object', 'resource_like']);
        });

        it('preserves order with single promise', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'only' => delayedValue('lonely', 50),
            ];

            $results = $handler->all($promises)->wait();

            expect($results)->toBe(['only' => 'lonely']);
            expect(array_keys($results))->toBe(['only']);
        });

        it('preserves order with JSON-like string keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                '{"key": "value"}' => delayedValue('json_key', 30),
                '[1, 2, 3]' => delayedValue('array_key', 10),
                'key="value"' => delayedValue('xml_key', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['{"key": "value"}', '[1, 2, 3]', 'key="value"']);
        });

        it('preserves order in allSettled with mixed rejections and types', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'null_result' => delayedValue(null, 30),
                'rejection' => delayedReject('error', 10),
                'array_result' => delayedValue(['data' => 'value'], 20),
                'another_rejection' => delayedReject('error2', 5),
                'string_result' => delayedValue('success', 15),
            ];

            $results = $handler->allSettled($promises)->wait();

            expect($results['null_result'])->toBeInstanceOf(SettledResult::class);
            expect($results['null_result']->isFulfilled())->toBeTrue();
            expect($results['null_result']->value)->toBeNull();

            expect($results['rejection'])->toBeInstanceOf(SettledResult::class);
            expect($results['rejection']->isRejected())->toBeTrue();

            expect($results['array_result'])->toBeInstanceOf(SettledResult::class);
            expect($results['array_result']->isFulfilled())->toBeTrue();
            expect($results['array_result']->value)->toEqual(['data' => 'value']);

            expect($results['another_rejection'])->toBeInstanceOf(SettledResult::class);
            expect($results['another_rejection']->isRejected())->toBeTrue();

            expect($results['string_result'])->toBeInstanceOf(SettledResult::class);
            expect($results['string_result']->isFulfilled())->toBeTrue();
            expect($results['string_result']->value)->toBe('success');

            expect(array_keys($results))->toBe([
                'null_result',
                'rejection',
                'array_result',
                'another_rejection',
                'string_result',
            ]);
        });

        it('preserves order with whitespace in string keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'key with spaces' => delayedValue('value1', 30),
                "key\twith\ttabs" => delayedValue('value2', 10),
                "key\nwith\nnewlines" => delayedValue('value3', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([
                'key with spaces',
                "key\twith\ttabs",
                "key\nwith\nnewlines",
            ]);
        });

        it('handles promises completing at exact same time', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'a' => delayedValue('first', 5),
                'b' => delayedValue('second', 5),
                'c' => delayedValue('third', 5),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['a', 'b', 'c']);
        });

        it('preserves order with very small numeric keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                -1000 => delayedValue('very_negative', 30),
                -500 => delayedValue('negative', 10),
                -1 => delayedValue('almost_zero', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe([-1000, -500, -1]);
        });

        it('preserves order with case-sensitive string keys', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'Key' => delayedValue('capitalized', 30),
                'key' => delayedValue('lowercase', 10),
                'KEY' => delayedValue('uppercase', 20),
            ];

            $results = $handler->all($promises)->wait();

            expect(array_keys($results))->toBe(['Key', 'key', 'KEY']);
            expect($results['Key'])->toBe('capitalized');
            expect($results['key'])->toBe('lowercase');
            expect($results['KEY'])->toBe('uppercase');
        });
    });
});
