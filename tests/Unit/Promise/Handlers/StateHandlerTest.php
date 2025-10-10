<?php

describe('StateHandler', function () {
    describe('initial state', function () {
        it('should be pending initially', function () {
            $handler = stateHandler();
            
            expect($handler->isPending())->toBeTrue()
                ->and($handler->isResolved())->toBeFalse()
                ->and($handler->isRejected())->toBeFalse()
            ;
        });

        it('should have null value and reason initially', function () {
            $handler = stateHandler();
            
            expect($handler->getValue())->toBeNull()
                ->and($handler->getReason())->toBeNull()
            ;
        });

        it('should be settleable initially', function () {
            $handler = stateHandler();
            
            expect($handler->canSettle())->toBeTrue();
        });
    });

    describe('resolution', function () {
        it('should resolve with a value', function () {
            $handler = stateHandler();
            $value = 'test value';

            $handler->resolve($value);

            expect($handler->isResolved())->toBeTrue()
                ->and($handler->isPending())->toBeFalse()
                ->and($handler->isRejected())->toBeFalse()
                ->and($handler->getValue())->toBe($value)
                ->and($handler->canSettle())->toBeFalse()
            ;
        });

        it('should resolve with null value', function () {
            $handler = stateHandler();
            
            $handler->resolve(null);

            expect($handler->isResolved())->toBeTrue()
                ->and($handler->getValue())->toBeNull()
            ;
        });

        it('should resolve with complex objects', function () {
            $handler = stateHandler();
            $value = (object) ['key' => 'value'];

            $handler->resolve($value);

            expect($handler->getValue())->toBe($value);
        });

        it('should ignore multiple resolution attempts', function () {
            $handler = stateHandler();
            $firstValue = 'first';
            $secondValue = 'second';

            $handler->resolve($firstValue);
            $handler->resolve($secondValue);

            expect($handler->getValue())->toBe($firstValue);
        });
    });

    describe('rejection', function () {
        it('should reject with throwable reason', function () {
            $handler = stateHandler();
            $reason = new Exception('test error');

            $handler->reject($reason);

            expect($handler->isRejected())->toBeTrue()
                ->and($handler->isPending())->toBeFalse()
                ->and($handler->isResolved())->toBeFalse()
                ->and($handler->getReason())->toBe($reason)
                ->and($handler->canSettle())->toBeFalse()
            ;
        });

        it('should wrap string reason in Exception', function () {
            $handler = stateHandler();
            $reason = 'string error';

            $handler->reject($reason);

            expect($handler->isRejected())->toBeTrue()
                ->and($handler->getReason())->toBeInstanceOf(Exception::class)
                ->and($handler->getReason()->getMessage())->toBe($reason)
            ;
        });

        it('should wrap non-string reason in Exception', function () {
            $handler = stateHandler();
            $reason = ['array', 'error'];

            $handler->reject($reason);

            expect($handler->isRejected())->toBeTrue()
                ->and($handler->getReason())->toBeInstanceOf(Exception::class)
                ->and($handler->getReason()->getMessage())->toContain('Promise rejected with array:')
            ;
        });

        it('should handle object with toString method', function () {
            $handler = stateHandler();
            $reason = new class () {
                public function __toString(): string
                {
                    return 'custom error';
                }
            };

            $handler->reject($reason);

            expect($handler->getReason())->toBeInstanceOf(Exception::class)
                ->and($handler->getReason()->getMessage())->toBe('custom error')
            ;
        });

        it('should ignore multiple rejection attempts', function () {
            $handler = stateHandler();
            $firstReason = new Exception('first error');
            $secondReason = new Exception('second error');

            $handler->reject($firstReason);
            $handler->reject($secondReason);

            expect($handler->getReason())->toBe($firstReason);
        });

        it('should ignore rejection after resolution', function () {
            $handler = stateHandler();
            $value = 'resolved';
            $reason = new Exception('rejected');

            $handler->resolve($value);
            $handler->reject($reason);

            expect($handler->isResolved())->toBeTrue()
                ->and($handler->isRejected())->toBeFalse()
                ->and($handler->getValue())->toBe($value)
            ;
        });

        it('should ignore resolution after rejection', function () {
            $handler = stateHandler();
            $reason = new Exception('rejected');
            $value = 'resolved';

            $handler->reject($reason);
            $handler->resolve($value);

            expect($handler->isRejected())->toBeTrue()
                ->and($handler->isResolved())->toBeFalse()
                ->and($handler->getReason())->toBe($reason)
            ;
        });
    });
});